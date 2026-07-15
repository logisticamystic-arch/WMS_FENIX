<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use App\Models\Traspaso;
use App\Models\Inventario;
use App\Models\MovimientoInventario;

class TraspasoController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $query  = Traspaso::with(['producto', 'ubicacion', 'auxiliar'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('sucursal_id', $this->getEffectiveSucursalId($user, $request));

        if (!empty($params['cliente_id'])) $query->where('cliente_id', $params['cliente_id']);
        if (!empty($params['fecha_desde'])) $query->where('created_at', '>=', $params['fecha_desde']);
        if (!empty($params['fecha_hasta'])) $query->where('created_at', '<=', $params['fecha_hasta'] . ' 23:59:59');

        return $this->ok($response, $query->orderBy('created_at', 'desc')->limit(500)->get());
    }

    public function motivos(Request $request, Response $response): Response
    {
        return $this->ok($response, Traspaso::MOTIVOS);
    }

    public function buscarStock(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $q      = $params['q'] ?? '';

        if (strlen($q) < 2) return $this->ok($response, []);

        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $stock = Inventario::select('inventarios.*')
            ->join('productos', 'productos.id', '=', 'inventarios.producto_id')
            ->join('ubicaciones', 'ubicaciones.id', '=', 'inventarios.ubicacion_id')
            ->where('inventarios.empresa_id', $empresaId)
            ->where('inventarios.sucursal_id', $sucursalId)
            ->where('inventarios.estado', 'Disponible')
            ->whereRaw('(inventarios.cantidad - inventarios.cantidad_reservada) > 0')
            ->where(function ($w) use ($q) {
                $w->where('productos.nombre', 'ilike', "%{$q}%")
                  ->orWhere('productos.codigo_interno', 'ilike', "%{$q}%");
            })
            ->with(['producto:id,codigo_interno,nombre,bloqueado', 'ubicacion:id,codigo,zona'])
            ->orderBy('productos.nombre')
            ->orderByRaw('inventarios.fecha_vencimiento ASC NULLS LAST')
            ->limit(50)
            ->get()
            ->map(function ($inv) {
                return [
                    'inventario_id'     => $inv->id,
                    'producto_id'       => $inv->producto_id,
                    'codigo_interno'    => $inv->producto->codigo_interno ?? '',
                    'nombre'            => $inv->producto->nombre ?? '',
                    'bloqueado'         => $inv->producto->bloqueado ?? false,
                    'ubicacion_id'      => $inv->ubicacion_id,
                    'ubicacion_codigo'  => $inv->ubicacion->codigo ?? '',
                    'ubicacion_zona'    => $inv->ubicacion->zona ?? '',
                    'lote'              => $inv->lote,
                    'fecha_vencimiento' => $inv->fecha_vencimiento,
                    'cantidad_disponible' => $inv->cantidad - $inv->cantidad_reservada,
                ];
            });

        return $this->ok($response, $stock);
    }

    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $required = ['producto_id', 'ubicacion_id', 'cantidad', 'motivo'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($response, "El campo {$f} es obligatorio");
        }

        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $cantidad   = (float)$data['cantidad'];

        if ($cantidad <= 0) return $this->error($response, 'La cantidad debe ser mayor a 0');

        try {
            $result = DB::connection()->transaction(function () use ($data, $empresaId, $sucursalId, $cantidad, $user) {
                // fecha_vencimiento acota a la partida exacta cuando el cliente la envía
                // (buscarStock() ya la devuelve por fila) — es el diferenciador real entre
                // partidas, no el lote, que puede repetirse entre vencimientos distintos.
                $inv = Inventario::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('producto_id', $data['producto_id'])
                    ->where('ubicacion_id', $data['ubicacion_id'])
                    ->where('estado', 'Disponible')
                    ->when(!empty($data['lote']), fn($q) => $q->where('lote', $data['lote']))
                    ->when(empty($data['lote']), fn($q) => $q->whereNull('lote'))
                    ->when(!empty($data['fecha_vencimiento']), fn($q) => $q->where('fecha_vencimiento', $data['fecha_vencimiento']))
                    ->lockForUpdate()
                    ->first();

                if (!$inv) throw new \Exception('No se encontró inventario disponible');

                // Bloqueo de producto/lote: un producto retirado por calidad o un lote
                // específico bloqueado no debe poder trasladarse — mismo criterio que
                // ya aplica el flujo de picking (asignarMultiple/_generarRutaFEFO).
                $productoBloqueado = \App\Models\Producto::withoutGlobalScopes()
                    ->where('id', $data['producto_id'])->where('bloqueado', true)->exists();
                if ($productoBloqueado) {
                    throw new \Exception('El producto está bloqueado y no puede trasladarse');
                }
                if ($inv->lote) {
                    $loteBloqueado = \App\Models\BloqueoLote::where('empresa_id', $empresaId)
                        ->where('producto_id', $data['producto_id'])
                        ->where('lote', $inv->lote)->exists();
                    if ($loteBloqueado) {
                        throw new \Exception("El lote {$inv->lote} está bloqueado y no puede trasladarse");
                    }
                }

                $disponible = $inv->cantidad - $inv->cantidad_reservada;
                if ($cantidad > $disponible) {
                    throw new \Exception("Cantidad excede el disponible ({$disponible})");
                }

                // La fecha de vencimiento real es la del inventario ya localizado, no la que
                // envía el cliente — evita desincronizar la auditoría del lote realmente movido.
                $fechaVencReal = $inv->fecha_vencimiento;

                $inv->cantidad -= $cantidad;
                if ((float)$inv->cantidad <= 0 && (float)($inv->cantidad_reservada ?? 0) <= 0) {
                    $inv->delete();
                } else {
                    $inv->save();
                }

                MovimientoInventario::create([
                    'empresa_id'         => $empresaId,
                    'sucursal_id'        => $sucursalId,
                    'producto_id'        => $data['producto_id'],
                    'ubicacion_origen_id'=> $data['ubicacion_id'],
                    'tipo_movimiento'    => 'Salida',
                    'cantidad'           => $cantidad,
                    'lote'               => $data['lote'] ?? null,
                    'fecha_vencimiento'  => $fechaVencReal,
                    'auxiliar_id'        => $user->id,
                    'fecha_movimiento'   => now(),
                    'hora_inicio'        => date('H:i:s'),
                    'observaciones'      => 'Traspaso: ' . ($data['motivo'] ?? '') . ' - ' . ($data['cliente_nombre'] ?? ''),
                ]);

                $lastId = Traspaso::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->max('id');
                $numero = 'TRP-' . str_pad(($lastId ?? 0) + 1, 6, '0', STR_PAD_LEFT);

                return Traspaso::create([
                    'empresa_id'       => $empresaId,
                    'sucursal_id'      => $sucursalId,
                    'numero_traspaso'  => $numero,
                    'producto_id'      => $data['producto_id'],
                    'ubicacion_id'     => $data['ubicacion_id'],
                    'lote'             => $data['lote'] ?? null,
                    'fecha_vencimiento'=> $fechaVencReal,
                    'cantidad'         => $cantidad,
                    'cliente_id'       => $data['cliente_id'] ?? null,
                    'cliente_nombre'   => $data['cliente_nombre'] ?? null,
                    'motivo'           => $data['motivo'],
                    'observaciones'    => $data['observaciones'] ?? null,
                    'auxiliar_id'      => $user->id,
                    'estado'           => 'Completado',
                ]);
            });

            return $this->created($response, $result, 'Traspaso realizado. Inventario actualizado.');
        } catch (\Exception $e) {
            return $this->error($response, $e->getMessage());
        }
    }
}
