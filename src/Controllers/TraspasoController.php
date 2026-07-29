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
        $query  = \App\Models\TraspasoDocumento::with(['detalles.producto', 'detalles.ubicacion', 'auxiliar'])
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
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;

        $data = $request->getParsedBody();

        $required = ['motivo', 'quien_recibe', 'detalles'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($response, "El campo {$f} es obligatorio");
        }

        $detalles = is_string($data['detalles']) ? json_decode($data['detalles'], true) : $data['detalles'];
        if (empty($detalles) || !is_array($detalles)) {
            return $this->error($response, 'Debe incluir al menos un producto');
        }

        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $firmaPath = null;
        if (!empty($data['firma_base64'])) {
            $base64Data = $data['firma_base64'];
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
                $type = strtolower($type[1]);
                if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $decodedData = base64_decode($base64Data);
                    if ($decodedData !== false) {
                        $uploadDir = __DIR__ . '/../../public/uploads/firmas/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                        $fileName = uniqid('firma_trp_') . '.' . $type;
                        file_put_contents($uploadDir . $fileName, $decodedData);
                        $firmaPath = 'uploads/firmas/' . $fileName;
                    }
                }
            }
        }

        try {
            $result = DB::connection()->transaction(function () use ($data, $detalles, $empresaId, $sucursalId, $user, $firmaPath) {
                $lastId = \App\Models\TraspasoDocumento::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->max('id');
                $numero = 'TRPD-' . str_pad(($lastId ?? 0) + 1, 6, '0', STR_PAD_LEFT);

                $doc = \App\Models\TraspasoDocumento::create([
                    'empresa_id'       => $empresaId,
                    'sucursal_id'      => $sucursalId,
                    'numero_documento' => $numero,
                    'fecha_movimiento' => date('Y-m-d'),
                    'cliente_id'       => $data['cliente_id'] ?? null,
                    'cliente_nombre'   => $data['cliente_nombre'] ?? null,
                    'quien_recibe'     => $data['quien_recibe'] ?? null,
                    'firma_path'       => $firmaPath,
                    'motivo'           => $data['motivo'],
                    'observaciones'    => $data['observaciones'] ?? null,
                    'auxiliar_id'      => $user->id,
                    'estado'           => 'Completado',
                ]);

                foreach ($detalles as $det) {
                    $cantidad = (float)$det['cantidad'];
                    if ($cantidad <= 0) continue;

                    $inv = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $det['producto_id'])
                        ->where('ubicacion_id', $det['ubicacion_id'])
                        ->where('estado', 'Disponible')
                        ->when(!empty($det['lote']), fn($q) => $q->where('lote', $det['lote']))
                        ->when(empty($det['lote']), fn($q) => $q->whereNull('lote'))
                        ->when(!empty($det['fecha_vencimiento']), fn($q) => $q->where('fecha_vencimiento', $det['fecha_vencimiento']))
                        ->lockForUpdate()
                        ->first();

                    if (!$inv) throw new \Exception('No se encontró inventario disponible para el producto ' . $det['producto_id']);

                    $productoBloqueado = \App\Models\Producto::withoutGlobalScopes()
                        ->where('id', $det['producto_id'])->where('bloqueado', true)->exists();
                    if ($productoBloqueado) {
                        throw new \Exception('El producto ' . $det['producto_id'] . ' está bloqueado y no puede trasladarse');
                    }
                    if ($inv->lote) {
                        $loteBloqueado = \App\Models\BloqueoLote::where('empresa_id', $empresaId)
                            ->where('producto_id', $det['producto_id'])
                            ->where('lote', $inv->lote)->exists();
                        if ($loteBloqueado) {
                            throw new \Exception("El lote {$inv->lote} está bloqueado y no puede trasladarse");
                        }
                    }

                    $disponible = $inv->cantidad - $inv->cantidad_reservada;
                    if ($cantidad > $disponible) {
                        throw new \Exception("Cantidad excede el disponible ({$disponible}) para el producto " . $det['producto_id']);
                    }

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
                        'producto_id'        => $det['producto_id'],
                        'ubicacion_origen_id'=> $det['ubicacion_id'],
                        'tipo_movimiento'    => 'Salida',
                        'cantidad'           => $cantidad,
                        'lote'               => $det['lote'] ?? null,
                        'fecha_vencimiento'  => $fechaVencReal,
                        'auxiliar_id'        => $user->id,
                        'fecha_movimiento'   => date('Y-m-d H:i:s'),
                        'hora_inicio'        => date('H:i:s'),
                        'observaciones'      => 'Traspaso Doc: ' . $numero . ' - ' . ($data['cliente_nombre'] ?? ''),
                    ]);

                    \App\Models\TraspasoDocumentoDetalle::create([
                        'traspaso_documento_id' => $doc->id,
                        'producto_id'      => $det['producto_id'],
                        'ubicacion_id'     => $det['ubicacion_id'],
                        'lote'             => $det['lote'] ?? null,
                        'fecha_vencimiento'=> $fechaVencReal,
                        'cantidad'         => $cantidad,
                    ]);
                }

                return $doc;
            });

            return $this->created($response, $result, 'Documento de traspaso creado. Inventario actualizado.');
        } catch (\Exception $e) {
            return $this->error($response, $e->getMessage());
        }
    }
}
