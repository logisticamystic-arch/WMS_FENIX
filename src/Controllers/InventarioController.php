<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Support\Facades\DB;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\ConteoInventario;
use App\Models\ConteoDetalle;
use App\Models\Producto;
use App\Models\Ubicacion;
use App\Models\NivelReposicion;
use App\Helpers\InventoryGuard;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\InvGeneralEvento;
use App\Models\InvGeneralAsignacion;
use App\Models\InvGeneralConteo;
use App\Models\InvGeneralDiferencia;
use App\Models\Empresa;
use Carbon\Carbon;

/**
 * InventarioController — Kardex, traslados, ajustes, conteos físicos.
 */
class InventarioController extends BaseController
{
    /**
     * Helper para estandarizar fechas de DD/MM/YYYY a YYYY-MM-DD
     */
    private function estandarizarFecha(?string $fecha): ?string
    {
        if (empty($fecha) || $fecha === 'N/A' || $fecha === '-') return null;
        
        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
                return Carbon::createFromFormat('d/m/Y', $fecha)->format('Y-m-d');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                return $fecha;
            }
            return Carbon::parse($fecha)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null; 
        }
    }
    // ── GET /api/inventario/stock ────────────────────────────────────────────
    public function getStock(Request $req, Response $res, array $args): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();

        // ── Límite defensivo: nunca devolver un dump completo sin filtro ──────
        // Con 25 usuarios simultáneos sin límite podría saturar memoria PHP.
        $limit = min((int)($params['limit'] ?? 500), 2000);

        $q = Inventario::where('inventarios.empresa_id', $user->empresa_id)
            ->where('inventarios.sucursal_id', $user->sucursal_id)
            ->join('productos',   'inventarios.producto_id',  '=', 'productos.id')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->select(
                'productos.nombre as producto_nombre',
                'productos.codigo_interno',
                'productos.unidades_caja',
                'ubicaciones.codigo as ubicacion_codigo',
                'inventarios.numero_pallet',
                'inventarios.*'
                // OPTIMIZACIÓN: last_movement_at se obtiene en batch post-query
                // (antes era un subquery correlacionado por fila = O(n) queries)
            );
            // NOTA: El soft-lock V1 (conteo_detalles/conteo_inventarios) fue removido.
            // El sistema V2 gestiona concurrencia a nivel de sesión.

        if (!empty($params['producto_id'])) {
            $q->where('inventarios.producto_id', $params['producto_id']);
        }
        if (!empty($params['ubicacion_id'])) {
            $q->where('inventarios.ubicacion_id', $params['ubicacion_id']);
        }
        if (!empty($params['estado'])) {
            $q->where('inventarios.estado', $params['estado']);
        }
        if (!empty($params['tipo_ubicacion'])) {
            $q->where('ubicaciones.tipo_ubicacion', $params['tipo_ubicacion']);
        }
        // Alerta de próximos a vencer (días configurables, default 30)
        if (!empty($params['proximos_vencer'])) {
            $dias = (int)$params['proximos_vencer'];
            $q->whereNotNull('inventarios.fecha_vencimiento')
              ->where('inventarios.fecha_vencimiento', '<=', date('Y-m-d', strtotime("+{$dias} days")))
              ->where('inventarios.fecha_vencimiento', '>=', date('Y-m-d'));
        }

        $stock = $q->orderBy('inventarios.fecha_vencimiento')->limit($limit)->get();

        // ── OPTIMIZACIÓN: last_movement_at en UNA sola query batch ───────────
        // Antes: subquery correlacionado por fila = 1 query extra × N registros.
        // Ahora: 1 sola query que trae el MAX(created_at) para todos los pares
        // (producto_id, ubicacion_id) del resultado actual.
        if ($stock->isNotEmpty()) {
            $pairs = $stock->map(fn($i) => ['p' => $i->producto_id, 'u' => $i->ubicacion_id]);

            // Construir IN con pares únicos para reducir volumen
            $productoIds  = $pairs->pluck('p')->unique()->values()->toArray();
            $ubicacionIds = $pairs->pluck('u')->unique()->values()->toArray();

            $lastMovs = Capsule::table('movimiento_inventarios')
                ->whereIn('producto_id',          $productoIds)
                ->where(function($q2) use ($ubicacionIds) {
                    $q2->whereIn('ubicacion_origen_id',  $ubicacionIds)
                       ->orWhereIn('ubicacion_destino_id', $ubicacionIds);
                })
                ->select(
                    'producto_id',
                    Capsule::raw('COALESCE(ubicacion_destino_id, ubicacion_origen_id) as ubicacion_id'),
                    Capsule::raw('MAX(created_at) as last_movement_at')
                )
                ->groupBy('producto_id', Capsule::raw('COALESCE(ubicacion_destino_id, ubicacion_origen_id)'))
                ->get()
                ->keyBy(fn($r) => "{$r->producto_id}_{$r->ubicacion_id}");

            $stock->transform(function ($item) use ($lastMovs) {
                $key = "{$item->producto_id}_{$item->ubicacion_id}";
                $item->last_movement_at = $lastMovs[$key]->last_movement_at ?? null;
                return $item;
            });
        }

        // ── Promedio de ventas del último mes (1 query para todos los productos) ──
        $prodIds = $stock->pluck('producto_id')->unique()->toArray();
        $ventas  = [];
        if (!empty($prodIds)) {
            $fecha30 = Carbon::now()->subDays(30)->toDateTimeString(); // Compatible PostgreSQL
            $rs = Capsule::table('picking_detalles')
                ->join('orden_pickings', 'picking_detalles.orden_picking_id', '=', 'orden_pickings.id')
                ->where('orden_pickings.estado', 'Completada')
                ->where('orden_pickings.created_at', '>=', $fecha30)
                ->whereIn('picking_detalles.producto_id', $prodIds)
                ->select('picking_detalles.producto_id', Capsule::raw('SUM(picking_detalles.cantidad_solicitada) as prom'))
                ->groupBy('picking_detalles.producto_id')
                ->get();
            foreach ($rs as $row) {
                $ventas[$row->producto_id] = $row->prom;
            }
        }

        $stock->transform(function ($item) use ($ventas) {
            $item->promedio_venta_mensual = $ventas[$item->producto_id] ?? 0;
            return $item;
        });

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Producto', 'Código', 'Ubicación', 'Lote', 'F.Vencimiento', 'Cantidad', 'Estado', 'Días p/Vencer'];
            $rows = $stock->map(fn($i) => [
                $i->producto_nombre,
                $i->codigo_interno,
                $i->ubicacion_codigo,
                $i->lote ?? '—',
                $i->fecha_vencimiento ?? '—',
                $i->cantidad,
                $i->estado,
                $i->fecha_vencimiento
                    ? max(0, (int)((strtotime($i->fecha_vencimiento) - time()) / 86400))
                    : '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'stock_' . date('Y-m-d'));
        }

        return $this->ok($res, $stock);
    }

    // ── POST /api/inventario/traslado ────────────────────────────────────────
    public function traslado(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $data = $req->getParsedBody() ?? [];

        $required = ['producto_id', 'ubicacion_origen_id', 'ubicacion_destino_id', 'cantidad'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        $cantidad = (int)$data['cantidad'];
        if ($cantidad <= 0) return $this->error($res, 'La cantidad debe ser mayor a 0');

        // ── Guard de integridad: verificar stock en origen antes de abrir la TX ─
        $loteGuard = trim((string)($data['lote'] ?? '')) ?: null;
        $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
        $numeroPalletGuard = !empty($data['numero_pallet']) ? (int)$data['numero_pallet'] : null;
        $check = $guard->canTransfer(
            $data['producto_id'],
            $cantidad,
            $data['ubicacion_origen_id'],
            $loteGuard,
            $numeroPalletGuard
        );
        if (!$check['ok']) {
            return $this->error($res, $check['message'], 422);
        }

        // Validación R09: Fecha de vencimiento obligatoria
        $fvencParsed = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);
        $checkDate = $guard->checkExpirationMandatory($data['producto_id'], $fvencParsed);
        if (!$checkDate['ok']) {
            return $this->error($res, $checkDate['message'], 422);
        }

        try {
            Capsule::transaction(function () use ($data, $user, $cantidad) {
                $lote  = trim((string)($data['lote'] ?? ''));
                $fvenc = trim((string)($data['fecha_vencimiento'] ?? ''));
                if ($lote === '') {
                    $lote = null;
                }
                if ($fvenc === '') {
                    $fvenc = null;
                }

                // Verificar stock origen. Permitir inventario en patio o disponible para ubicar.
                $origenQuery = Inventario::where('empresa_id',    $user->empresa_id)
                    ->where('sucursal_id',   $user->sucursal_id)
                    ->where('producto_id',   $data['producto_id'])
                    ->where('ubicacion_id',  $data['ubicacion_origen_id'])
                    ->whereIn('estado',       ['Disponible', 'En Patio'])
                    ->when($lote !== null, fn($q) => $q->where('lote', $lote))
                    ->when($lote === null, fn($q) => $q->where(function ($sub) {
                        $sub->whereNull('lote')->orWhere('lote', 'N/A');
                    }))
                    ->when(!empty($data['numero_pallet']), fn($q) => $q->where('numero_pallet', $data['numero_pallet']))
                    ->when(empty($data['numero_pallet']), fn($q) => $q->whereNull('numero_pallet'));

                $origen = $origenQuery->lockForUpdate()->first();

                if (!$origen || $origen->cantidad < $cantidad) {
                    throw new \Exception('Stock insuficiente en ubicación origen');
                }

                if (!$fvenc && $origen->fecha_vencimiento) {
                    $fvenc = $origen->fecha_vencimiento;
                }

                // Validar Bloqueo por Inventario
                $bloqueo = ConteoDetalle::join('conteo_inventarios', 'conteo_detalles.conteo_id', '=', 'conteo_inventarios.id')
                    ->where('conteo_detalles.ubicacion_id', $data['ubicacion_origen_id'])
                    ->where('conteo_inventarios.estado', 'EnConteo')
                    ->where('conteo_inventarios.usa_bloqueo', 1)
                    ->exists();
                
                if ($bloqueo) {
                    throw new \Exception('La ubicación de origen está bloqueada por un inventario activo.');
                }

                // Descontar origen
                $origen->cantidad -= $cantidad;
                if ($origen->cantidad === 0) $origen->delete();
                else $origen->save();

                // Acumular en destino
                if ($lote !== null) {
                    $destino = Inventario::firstOrCreate(
                        [
                            'empresa_id'   => $user->empresa_id,
                            'sucursal_id'  => $user->sucursal_id,
                            'producto_id'  => $data['producto_id'],
                            'ubicacion_id' => $data['ubicacion_destino_id'],
                            'lote'         => $lote,
                            'estado'       => 'Disponible',
                            'numero_pallet' => $data['numero_pallet'] ?? null,
                        ],
                        [
                            'cantidad'          => 0,
                            'fecha_vencimiento' => $fvenc,
                        ]
                    );
                } else {
                    $destino = Inventario::where('empresa_id', $user->empresa_id)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $data['producto_id'])
                        ->where('ubicacion_id', $data['ubicacion_destino_id'])
                        ->where(function ($sub) {
                            $sub->whereNull('lote')->orWhere('lote', 'N/A');
                        })
                        ->where('estado', 'Disponible')
                        ->first();

                    if (!$destino) {
                        $destino = new Inventario();
                        $destino->empresa_id = $user->empresa_id;
                        $destino->sucursal_id = $user->sucursal_id;
                        $destino->producto_id = $data['producto_id'];
                        $destino->ubicacion_id = $data['ubicacion_destino_id'];
                        $destino->lote = null;
                        $destino->numero_pallet = $data['numero_pallet'] ?? null;
                        $destino->estado = 'Disponible';
                        $destino->cantidad_reservada = 0;
                        $destino->cantidad = 0;
                        $destino->fecha_vencimiento = $fvenc;
                    }
                }

                $destino->cantidad += $cantidad;
                if ($fvenc) {
                    $destino->fecha_vencimiento = $fvenc;
                }
                $destino->save();

                // Movimiento trazable
                MovimientoInventario::create([
                    'empresa_id'          => $user->empresa_id,
                    'sucursal_id'         => $user->sucursal_id,
                    'producto_id'         => $data['producto_id'],
                    'tipo_movimiento'     => 'Traslado',
                    'cantidad'            => $cantidad,
                    'ubicacion_origen_id' => $data['ubicacion_origen_id'],
                    'ubicacion_destino_id'=> $data['ubicacion_destino_id'],
                    'lote'                => $lote,
                    'fecha_vencimiento'   => $fvenc,
                    'auxiliar_id'         => $user->id, 
                    'referencia_tipo'     => 'traslado',
                    'observaciones'       => $data['observaciones'] ?? null,
                    'fecha_movimiento'    => date('Y-m-d'),
                    'hora_inicio'         => date('H:i:s'),
                ]);
            });

            $this->audit($user, 'inventario', 'traslado', 'inventarios', $data['producto_id'],
                null, $data, "Traslado de {$cantidad} unidades del producto {$data['producto_id']}");

            return $this->ok($res, null, 'Traslado registrado exitosamente');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/ajuste ──────────────────────────────────────────
    public function ajuste(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];
        $required = ['producto_id', 'ubicacion_id', 'cantidad_nueva', 'motivo'];
        foreach ($required as $f) {
            if (!isset($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        try {
            Capsule::transaction(function () use ($data, $user) {
                $inv = Inventario::where('empresa_id',   $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $data['producto_id'])
                    ->where('ubicacion_id', $data['ubicacion_id'])
                    ->where('estado', 'Disponible')
                    ->when($data['lote'] ?? null, fn($q) => $q->where('lote', $data['lote']))
                    ->first();

                $fvencParsed = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);
                
                // Validación R09 para ajustes positivos o creación
                if ($data['cantidad_nueva'] > 0) {
                    $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
                    $checkDate = $guard->checkExpirationMandatory($data['producto_id'], $fvencParsed);
                    if (!$checkDate['ok']) {
                        throw new \Exception($checkDate['message']);
                    }
                }

                $cantidadAnterior = $inv ? $inv->cantidad : 0;
                $cantidadNueva    = (int)$data['cantidad_nueva'];
                $diferencia       = $cantidadNueva - $cantidadAnterior;

                if (!$inv) {
                    if ($cantidadNueva > 0) {
                        Inventario::create([
                            'empresa_id'       => $user->empresa_id,
                            'sucursal_id'      => $user->sucursal_id,
                            'producto_id'      => $data['producto_id'],
                            'ubicacion_id'     => $data['ubicacion_id'],
                            'lote'             => $data['lote'] ?? null,
                            'fecha_vencimiento'=> $fvencParsed,
                            'cantidad'         => $cantidadNueva,
                            'estado'           => 'Disponible',
                        ]);
                    }
                } else {
                    $inv->cantidad = $cantidadNueva;
                    if ($cantidadNueva === 0) $inv->delete();
                    else $inv->save();
                }

                $tipo = $diferencia >= 0 ? 'AjustePositivo' : 'AjusteNegativo';
                MovimientoInventario::create([
                    'empresa_id'       => $user->empresa_id,
                    'sucursal_id'      => $user->sucursal_id,
                    'producto_id'      => $data['producto_id'],
                    'tipo_movimiento'  => $tipo,
                    'cantidad'         => abs($diferencia),
                    'ubicacion_origen_id'  => $data['ubicacion_id'],
                    'ubicacion_destino_id' => $data['ubicacion_id'],
                    'lote'             => $data['lote'] ?? null,
                    'auxiliar_id'      => $user->id,
                    'referencia_tipo'  => 'ajuste',
                    'observaciones'    => $data['motivo'],
                    'fecha_movimiento' => date('Y-m-d'),
                    'hora_inicio'      => date('H:i:s'),
                ]);
            });

            $this->audit($user, 'inventario', 'ajuste', 'inventarios', $data['producto_id'],
                null, $data, "Ajuste de inventario: {$data['motivo']}");

            return $this->ok($res, null, 'Ajuste registrado');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── GET /api/inventario/kardex ────────────────────────────────────────────
    public function getKardex(Request $req, Response $res): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();
            [$ini, $fin] = $this->getDateRange($params);

            $q = MovimientoInventario::where('movimiento_inventarios.empresa_id', $user->empresa_id)
                ->where('movimiento_inventarios.sucursal_id', $user->sucursal_id)
                ->join('productos', 'movimiento_inventarios.producto_id', '=', 'productos.id')
                ->leftJoin('personal', 'movimiento_inventarios.auxiliar_id', '=', 'personal.id')
                ->leftJoin('ubicaciones as uo', 'movimiento_inventarios.ubicacion_origen_id', '=', 'uo.id')
                ->leftJoin('ubicaciones as ud', 'movimiento_inventarios.ubicacion_destino_id', '=', 'ud.id')
                ->whereBetween('movimiento_inventarios.fecha_movimiento', [
                    substr($ini, 0, 10), substr($fin, 0, 10)
                ])
                ->select(
                    'movimiento_inventarios.id',
                    'movimiento_inventarios.empresa_id',
                    'movimiento_inventarios.sucursal_id',
                    'movimiento_inventarios.producto_id',
                    'movimiento_inventarios.ubicacion_origen_id',
                    'movimiento_inventarios.ubicacion_destino_id',
                    'movimiento_inventarios.tipo_movimiento',
                    'movimiento_inventarios.cantidad',
                    'movimiento_inventarios.lote',
                    'movimiento_inventarios.fecha_vencimiento',
                    'movimiento_inventarios.referencia_tipo',
                    'movimiento_inventarios.referencia_id',
                    'movimiento_inventarios.auxiliar_id',
                    'movimiento_inventarios.fecha_movimiento',
                    'movimiento_inventarios.hora_inicio',
                    'movimiento_inventarios.hora_fin',
                    'movimiento_inventarios.observaciones',
                    'movimiento_inventarios.created_at',
                    'productos.id as producto_id',
                    'productos.nombre as producto',
                    'productos.nombre as producto_nombre',
                    'productos.codigo_interno as codigo',
                    'personal.nombre as usuario',
                    'uo.codigo as ubicacion_origen',
                    'ud.codigo as ubicacion_destino',
                    'ud.codigo as ubicacion_codigo',
                    'movimiento_inventarios.tipo_movimiento as tipo',
                    'movimiento_inventarios.referencia_tipo as referencia'
                );

            if (!empty($params['producto_id'])) {
                $q->where('movimiento_inventarios.producto_id', $params['producto_id']);
            }
            if (!empty($params['tipo'])) {
                $q->where('movimiento_inventarios.tipo_movimiento', $params['tipo']);
            }

            $movimientos = $q->orderBy('movimiento_inventarios.fecha_movimiento', 'desc')
                             ->orderBy('movimiento_inventarios.hora_inicio', 'desc')
                             ->limit($params['limit'] ?? 500) // Añadido limit
                             ->get();

            if (($params['export'] ?? '') === 'excel') {
                $headers = ['Fecha', 'Hora', 'Producto', 'Código', 'Tipo', 'Cantidad',
                            'Lote', 'F.Vencimiento', 'Origen', 'Destino', 'Usuario', 'Ref.Tipo', 'Obs.'];
                $rows = $movimientos->map(fn($m) => [
                    $m->fecha_movimiento,
                    $m->hora_inicio,
                    $m->producto,
                    $m->codigo,
                    $m->tipo_movimiento,
                    $m->cantidad,
                    $m->lote ?? '—',
                    $m->fecha_vencimiento ?? '—',
                    $m->ubicacion_origen  ?? '—',
                    $m->ubicacion_destino ?? '—',
                    $m->usuario ?? '—',
                    $m->referencia_tipo ?? '—',
                    $m->observaciones ?? '',
                ])->toArray();
                return $this->exportCsv($res, $headers, $rows, 'kardex_' . date('Y-m-d'));
            }

            return $this->ok($res, $movimientos);
        } catch (\Throwable $e) {
            error_log('getKardex error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
            return $this->error($res, 'Error al obtener kardex: ' . $e->getMessage(), 500);
        }
    }

    // ── POST /api/inventario/conteo/nuevo ────────────────────────────────────
    public function crearConteo(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];

        try {
            return Capsule::transaction(function () use ($data, $user, $res) {
                // Map tipo values from frontend to DB enum
                $tipoMap = [
                    'General'       => 'General',
                    'Ciclico'       => 'Ciclico',
                    'Inicial'       => 'Inicial',
                    'PorUbicacion'  => 'Ciclico', // Legacy mapping
                    'PorReferencia' => 'Ciclico',
                ];
                $tipoInput = $data['tipo'] ?? 'Ciclico';
                $tipoInterno = $tipoMap[$tipoInput] ?? 'Ciclico';

                $conteo = ConteoInventario::create([
                    'empresa_id'      => $user->empresa_id,
                    'sucursal_id'     => $user->sucursal_id,
                    'analista_id'     => $user->id,
                    'tipo_conteo'     => in_array($tipoInput, ['General', 'PorUbicacion', 'PorReferencia']) ? $tipoInput : 'General',
                    'tipo_interno'    => $tipoInterno,
                    'ronda_actual'    => 1,
                    'usa_bloqueo'     => (bool)($data['usa_bloqueo'] ?? false),
                    'estado'          => 'EnConteo',
                    'auxiliar_id'     => $user->id, // Default reference
                    'fecha_movimiento'=> date('Y-m-d'),
                    'hora_inicio'     => date('H:i:s'),
                    'observaciones'   => $data['observaciones'] ?? null,
                ]);

                // 2. Asignar Auxiliares (Many-to-Many)
                if (!empty($data['auxiliares_ids']) && is_array($data['auxiliares_ids'])) {
                    $conteo->auxiliares()->sync($data['auxiliares_ids']);
                    
                    // Enviar notificaciones a los auxiliares
                    foreach ($data['auxiliares_ids'] as $auxId) {
                        \App\Models\Notificacion::create([
                            'empresa_id' => $user->empresa_id,
                            'sucursal_id' => $user->sucursal_id,
                            'personal_id' => $auxId,
                            'emisor_id' => $user->id,
                            'tipo' => 'tarea',
                            'titulo' => 'Nuevo Inventario Asignado',
                            'mensaje' => "Se le ha asignado el inventario #{$conteo->id} ({$conteo->tipo_interno}). Por favor inicie el conteo.",
                            'modulo' => 'Inventario',
                            'referencia_tipo' => 'Conteo',
                            'referencia_id' => $conteo->id,
                            'link_accion' => 'viewInventario'
                        ]);
                    }
                }

                // 3. Pre-poblar líneas si es General o Wall-to-Wall (Opcional, para conteo guiado)
                // Si el usuario envió filtros específicos (pasillo, etc)
                if ($tipoInterno === 'General' || !empty($data['filtro_pasillo']) || !empty($data['filtro_ubicaciones'])) {
                    $q = Inventario::where('empresa_id', $user->empresa_id)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('estado', 'Disponible');
                    
                    if (!empty($data['filtro_pasillo'])) {
                        $q->whereHas('ubicacion', fn($uq) => $uq->where('pasillo', $data['filtro_pasillo']));
                    }
                    if (!empty($data['filtro_ubicaciones']) && is_array($data['filtro_ubicaciones'])) {
                        $q->whereIn('ubicacion_id', $data['filtro_ubicaciones']);
                    }

                    $items = $q->get();
                    foreach ($items as $item) {
                        ConteoDetalle::create([
                            'conteo_id' => $conteo->id,
                            'ronda' => 1,
                            'ubicacion_id' => $item->ubicacion_id,
                            'producto_id' => $item->producto_id,
                            'lote' => $item->lote,
                            'cantidad_sistema' => $item->cantidad,
                            'cantidad_sistema_snapshot' => $item->cantidad,
                            'estado' => 'Pendiente'
                        ]);
                    }
                }

                $this->audit($user, 'inventario', 'crear_conteo', 'conteo_inventarios', $conteo->id,
                    null, $conteo->toArray(), "Conteo #{$conteo->id} tipo {$conteo->tipo_interno} iniciado");

                return $this->created($res, $conteo->load('auxiliares'));
            });
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/conteo/{id}/linea ───────────────────────────────
    public function addLineaConteo(Request $req, Response $res, array $a): Response
    {
        $user   = $req->getAttribute('user');
        $data   = $req->getParsedBody() ?? [];
        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)->find($a['id']);

        if (!$conteo) return $this->notFound($res);
        if ($conteo->estado !== 'EnConteo') {
            return $this->error($res, 'El conteo no está activo (estado: ' . $conteo->estado . ')');
        }

        // Validaciones básicas
        $productoId = (int)($data['producto_id'] ?? 0);
        if ($productoId <= 0) return $this->error($res, 'producto_id requerido');
        
        $cantidadContada = (float)($data['cantidad_contada'] ?? 0);
        if ($cantidadContada < 0) return $this->error($res, 'cantidad_contada no puede ser negativa');

        // Resolver ubicacion_id
        $ubicacionId = null;
        if (!empty($data['ubicacion_id']) && is_numeric($data['ubicacion_id'])) {
            $ubicacionId = (int)$data['ubicacion_id'];
        } elseif (!empty($data['ubicacion_codigo'])) {
            $ubic = \App\Models\Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('codigo', trim($data['ubicacion_codigo']))
                ->first();
            if (!$ubic) return $this->error($res, "Ubicación '{$data['ubicacion_codigo']}' no encontrada");
            $ubicacionId = $ubic->id;
        }

        // Captura de tiempos
        $fechaInicio = $data['fecha_inicio'] ?? date('Y-m-d H:i:s');
        $fechaFin    = date('Y-m-d H:i:s');

        // Snapshot de sistema si no existe previo
        $cantSistema = (float)Inventario::where([
            'empresa_id'  => $user->empresa_id,
            'sucursal_id' => $user->sucursal_id,
            'producto_id' => $productoId,
            'ubicacion_id'=> $ubicacionId,
            'estado'      => 'Disponible'
        ])->sum('cantidad');

        try {
            // Buscamos si ya existe la línea para esta ronda
            $linea = ConteoDetalle::where([
                'conteo_id'   => $conteo->id,
                'ronda'       => $conteo->ronda_actual,
                'producto_id' => $productoId,
                'ubicacion_id'=> $ubicacionId,
                'lote'        => $data['lote_leido'] ?? ($data['lote'] ?? null),
            ])->first();

            if ($linea) {
                // Actualizar existente
                $linea->auxiliar_id     = $user->id;
                $linea->cantidad_fisica = $cantidadContada;
                $linea->diferencia      = $cantidadContada - $linea->cantidad_sistema_snapshot;
                $linea->fecha_fin       = $fechaFin;
                $linea->save();
            } else {
                // Crear nueva
                $linea = ConteoDetalle::create([
                    'conteo_id'        => $conteo->id,
                    'ronda'            => $conteo->ronda_actual,
                    'producto_id'      => $productoId,
                    'ubicacion_id'     => $ubicacionId,
                    'lote'             => $data['lote_leido'] ?? ($data['lote'] ?? null),
                    'auxiliar_id'      => $user->id,
                    'lote_leido'       => $data['lote_leido'] ?? ($data['lote'] ?? null),
                    'fv_leida'         => !empty($data['fecha_vencimiento']) ? $data['fecha_vencimiento'] : null,
                    'cantidad_fisica'  => $cantidadContada,
                    'cantidad_sistema' => $cantSistema,
                    'cantidad_sistema_snapshot' => $cantSistema,
                    'diferencia'       => $cantidadContada - $cantSistema,
                    'estado'           => 'Contado',
                    'fecha_inicio'     => $fechaInicio,
                    'fecha_fin'        => $fechaFin,
                ]);
            }

            return $this->ok($res, $linea);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    public function finalizarRonda(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$conteo) return $this->notFound($res);

        try {
            Capsule::transaction(function () use ($conteo, $user, $res) {
                $rondaActual = $conteo->ronda_actual;
                $detalles = ConteoDetalle::where('conteo_id', $conteo->id)
                    ->where('ronda', $rondaActual)
                    ->get();

                $discrepancias = 0;
                foreach ($detalles as $det) {
                    $diff = $det->cantidad_fisica - $det->cantidad_sistema;
                    if ($diff !== 0) {
                        $discrepancias++;
                        // Si no es la 3ra ronda, creamos línea para la siguiente ronda
                        if ($rondaActual < 3) {
                            ConteoDetalle::firstOrCreate([
                                'conteo_id'   => $conteo->id,
                                'ronda'       => $rondaActual + 1,
                                'producto_id' => $det->producto_id,
                                'ubicacion_id'=> $det->ubicacion_id,
                                'lote'        => $det->lote,
                            ], [
                                'cantidad_sistema' => $det->cantidad_fisica, // Sugerencia o base
                                'cantidad_sistema_snapshot' => $det->cantidad_sistema_snapshot,
                                'estado' => 'Pendiente'
                            ]);
                        }
                    }
                }

                if ($discrepancias > 0 && $rondaActual < 3) {
                    $conteo->ronda_actual++;
                    $conteo->save();
                    $msg = "Ronda {$rondaActual} cerrada. Se han generado {$discrepancias} líneas para la Ronda " . ($rondaActual + 1);

                    // Notificar a los auxiliares que hay una nueva ronda
                    foreach ($conteo->auxiliares as $aux) {
                        \App\Controllers\NotificacionesController::crear(
                            $conteo->empresa_id,
                            $aux->id,
                            'Nueva Ronda de Inventario',
                            "El inventario #{$conteo->id} ha pasado a la Ronda {$conteo->ronda_actual}. Por favor inicie el reconteo.",
                            'tarea',
                            $user->id,
                            'Conteo',
                            $conteo->id,
                            'viewInventario',
                            'Inventario'
                        );
                    }
                } else {
                    // Sin cambios relevantes
                }
            });
        } catch (\Throwable $e) {
            return $this->error($res, 'Error: ' . $e->getMessage());
        }
        return $this->ok($res, null, 'Operación completada');
    }

    /**
     * GET /api/inventario/conteos
     */
    public function getConteos(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        try {
            $q = ConteoInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->with(['analista', 'auxiliares']);
            
            $params = $req->getQueryParams();
            if (!empty($params['estado'])) {
                $estados = array_filter(array_map('trim', explode(',', $params['estado'])));
                if (count($estados) === 1) {
                    $q->where('estado', $estados[0]);
                } else {
                    $q->whereIn('estado', $estados);
                }
            }

            if ($user->rol === 'Auxiliar') {
                $q->whereExists(function ($query) use ($user) {
                    $query->select(\Illuminate\Database\Capsule\Manager::raw(1))
                          ->from('conteo_personal')
                          ->whereColumn('conteo_personal.conteo_id', 'conteo_inventarios.id')
                          ->where('conteo_personal.personal_id', $user->id);
                });
                
                // Asegurar que si no envían estado, igual filtramos estados no iniciados para el auxiliar
                if (empty($params['estado'])) {
                    $q->whereNotIn('estado', ['Borrador', 'Pendiente']);
                }
            }

            $conteos = $q->orderBy('created_at', 'desc')->get();
            return $this->ok($res, $conteos);
        } catch (\Throwable $e) {
            return $this->error($res, 'Error al obtener conteos: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/inventario/niveles-reposicion
     */
    public function getNivelesReposicion(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        try {
            $niveles = NivelReposicion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->with(['producto', 'ubicacion'])
                ->get();
            return $this->ok($res, $niveles);
        } catch (\Throwable $e) {
            return $this->error($res, 'Error al obtener niveles: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/inventario/dashboard
     * Resumen general para la vista de escritorio.
     */
    public function getDashboard(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $eId  = $user->empresa_id;
        $sId  = $user->sucursal_id;

        $data = [
            'total_skus'           => \App\Models\Inventario::where('empresa_id', $eId)->distinct('producto_id')->count('producto_id'),
            'total_unidades'       => \App\Models\Inventario::where('inventarios.empresa_id', $eId)
                                        ->where('inventarios.sucursal_id', $sId)
                                        ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
                                        ->where('ubicaciones.activo', 1)
                                        ->sum('inventarios.cantidad') ?: 0,
            'ubicaciones_ocupadas' => \App\Models\Inventario::where('inventarios.empresa_id', $eId)
                                        ->where('inventarios.sucursal_id', $sId)
                                        ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
                                        ->where('ubicaciones.activo', 1)
                                        ->distinct('inventarios.ubicacion_id')
                                        ->count('inventarios.ubicacion_id'),
            'movimientos_hoy'      => \App\Models\MovimientoInventario::where('empresa_id', $eId)->where('sucursal_id', $sId)
                                        ->whereDate('created_at', date('Y-m-d'))->count(),
        ];

        return $this->ok($res, $data);
    }

    // ── GET /api/inventario/conteo/{id}/dashboard ─────────────────────────────
    public function getDashboardData(Request $req, Response $res, array $a): Response
    {
        $user   = $req->getAttribute('user');
        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$conteo) return $this->notFound($res);

        try {
            $detalles = ConteoDetalle::where('conteo_id', $conteo->id)
                ->where('ronda', $conteo->ronda_actual)
                ->with(['producto:id,codigo_interno,nombre', 'ubicacion:id,codigo', 'auxiliar:id,nombre'])
                ->get();

            $total = $detalles->count();
            $contados = $detalles->whereNotIn('estado', ['Pendiente'])->count();
            $correctos = $detalles->filter(fn($d) => $d->cantidad_fisica !== null && $d->cantidad_fisica == $d->cantidad_sistema)->count();
            $discrepanciasList = $detalles->filter(fn($d) => $d->cantidad_fisica !== null && $d->cantidad_fisica != $d->cantidad_sistema);
            
            $loss_risk = 0;
            $surplus_risk = 0;
            foreach($discrepanciasList as $d) {
                $diff = (float)($d->cantidad_fisica ?? 0) - (float)($d->cantidad_sistema ?? 0);
                if ($diff < 0) $loss_risk += abs($diff);
                else $surplus_risk += $diff;
            }

            $kpis = [
                'ira'           => $total > 0 ? round(($correctos / $total) * 100, 1) : 100,
                'loss_risk'     => $loss_risk,
                'surplus_risk'  => $surplus_risk,
                'total_diffs'   => $discrepanciasList->count(),
            ];

            $progress = [
                'total_refs'   => $total,
                'counted_refs' => $contados,
                'percent'      => $total > 0 ? round(($contados / $total) * 100, 1) : 0
            ];

            return $this->ok($res, [
                'conteo'   => $conteo->load(['analista:id,nombre', 'auxiliares:id,nombre']),
                'kpis'     => $kpis,
                'progress' => $progress,
                'detalles' => $detalles,
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/conteo/{id}/finalizar ────────────────────────────
    public function finalizarConteo(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$conteo) return $this->notFound($res);
        if ($conteo->estado !== 'EnConteo') {
            return $this->error($res, 'El conteo no está activo');
        }

        try {
            Capsule::transaction(function () use ($conteo, $user) {
                $detalles = ConteoDetalle::where('conteo_id', $conteo->id)
                    ->where('ronda', $conteo->ronda_actual)
                    ->whereNotNull('cantidad_fisica')
                    ->get();

                foreach ($detalles as $det) {
                    if ($det->cantidad_fisica == $det->cantidad_sistema) continue;

                    $diferencia = $det->cantidad_fisica - $det->cantidad_sistema;
                    $tipo = $diferencia > 0 ? 'AjustePositivo' : 'AjusteNegativo';

                    // Actualizar inventario
                    $inv = Inventario::where('empresa_id',   $user->empresa_id)
                        ->where('sucursal_id',  $user->sucursal_id)
                        ->where('producto_id',  $det->producto_id)
                        ->where('ubicacion_id', $det->ubicacion_id)
                        ->where('estado', 'Disponible')
                        ->when($det->lote, fn($q) => $q->where('lote', $det->lote))
                        ->first();

                    if ($inv) {
                        $inv->cantidad = $det->cantidad_fisica;
                        if ($inv->cantidad <= 0) $inv->delete();
                        else $inv->save();
                    } elseif ($det->cantidad_fisica > 0) {
                        Inventario::create([
                            'empresa_id'   => $user->empresa_id,
                            'sucursal_id'  => $user->sucursal_id,
                            'producto_id'  => $det->producto_id,
                            'ubicacion_id' => $det->ubicacion_id,
                            'lote'         => $det->lote,
                            'cantidad'     => $det->cantidad_fisica,
                            'estado'       => 'Disponible',
                        ]);
                    }

                    MovimientoInventario::create([
                        'empresa_id'           => $user->empresa_id,
                        'sucursal_id'          => $user->sucursal_id,
                        'producto_id'          => $det->producto_id,
                        'tipo_movimiento'      => $tipo,
                        'cantidad'             => abs($diferencia),
                        'ubicacion_origen_id'  => $det->ubicacion_id,
                        'ubicacion_destino_id' => $det->ubicacion_id,
                        'lote'                 => $det->lote,
                        'auxiliar_id'          => $user->id,
                        'referencia_tipo'      => 'ConteoInventario',
                        'referencia_id'        => $conteo->id,
                        'observaciones'        => "Ajuste conteo #{$conteo->id} R{$conteo->ronda_actual}",
                        'fecha_movimiento'     => date('Y-m-d'),
                        'hora_inicio'          => date('H:i:s'),
                    ]);
                }

                $conteo->estado   = 'Finalizado';
                $conteo->hora_fin = date('H:i:s');
                $conteo->save();

                $this->audit($user, 'inventario', 'finalizar_conteo', 'conteo_inventarios', $conteo->id,
                    null, [], "Conteo #{$conteo->id} finalizado — {$detalles->count()} líneas ajustadas");
            });

            return $this->ok($res, null, 'Conteo finalizado y ajustes aplicados al inventario.');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/conteo/{id}/auxiliares ───────────────────────────
    public function syncAuxiliares(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$conteo) return $this->notFound($res);

        $data = $req->getParsedBody() ?? [];
        $ids  = $data['auxiliares_ids'] ?? [];

        if (!is_array($ids)) {
            return $this->error($res, 'auxiliares_ids debe ser un array');
        }

        try {
            $conteo->auxiliares()->sync($ids);

            // Notify added auxiliaries
            foreach ($ids as $auxId) {
                \App\Models\Notificacion::firstOrCreate(
                    ['empresa_id' => $user->empresa_id, 'personal_id' => $auxId,
                     'referencia_tipo' => 'Conteo', 'referencia_id' => $conteo->id],
                    [
                        'emisor_id' => $user->id,
                        'tipo'      => 'tarea',
                        'titulo'    => 'Asignado a Inventario',
                        'mensaje'   => "Fue asignado al conteo de inventario #{$conteo->id}.",
                        'modulo'    => 'Inventario',
                        'link_accion' => 'viewInventario',
                    ]
                );
            }

            return $this->ok($res, $conteo->load('auxiliares'), 'Auxiliares actualizados');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/niveles-reposicion ───────────────────────────────
    public function saveNivelReposicion(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];
        $required = ['producto_id', 'ubicacion_id', 'cantidad_minima'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        try {
            $nivel = NivelReposicion::updateOrCreate(
                [
                    'empresa_id'   => $user->empresa_id,
                    'sucursal_id'  => $user->sucursal_id,
                    'producto_id'  => $data['producto_id'],
                    'ubicacion_id' => $data['ubicacion_id'],
                ],
                [
                    'cantidad_minima' => (int)$data['cantidad_minima'],
                    'cantidad_ideal'  => isset($data['cantidad_ideal']) ? (int)$data['cantidad_ideal'] : (int)$data['cantidad_minima'] * 2,
                    'activo'          => 1,
                ]
            );

            return $this->ok($res, $nivel, 'Nivel de reposición guardado');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/ajuste  (alias for ajuste — kept for route compatibility)
    public function ajusteManual(Request $req, Response $res): Response
    {
        return $this->ajuste($req, $res);
    }

    // ══════════════════════════════════════════════════
    // Inventario General (merged from InventarioGeneralController)
    // ══════════════════════════════════════════════════

/**
     * GET /api/inv-general/eventos
     */
    public function getEventos(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $eventos = InvGeneralEvento::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->orderBy('id', 'desc')
            ->get();
        return $this->ok($res, $eventos);
    }

    /**
     * POST /api/inv-general/eventos
     */
    public function crearEvento(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($user->rol !== 'Admin' && $user->rol !== 'Supervisor') {
            return $this->error($res, 'Acceso denegado', 403);
        }

        $data = $r->getParsedBody();
        if (empty($data['nombre']) || empty($data['fecha_programada'])) {
            return $this->error($res, 'Nombre y Fecha son obligatorios', 400);
        }

        $e = new InvGeneralEvento();
        $e->empresa_id = $user->empresa_id;
        $e->sucursal_id = $user->sucursal_id;
        $e->nombre = $data['nombre'];
        $e->tipo = $data['tipo'] ?? 'Comparacion';
        $e->fecha_programada = $data['fecha_programada'];
        $e->notas = $data['notas'] ?? null;
        $e->creado_por = $user->id;
        $e->estado = 'Abierto';
        $e->save();

        return $this->ok($res, $e, 'Evento de Toma Física Creado');
    }

    /**
     * POST /api/inv-general/asignaciones
     */
    public function crearAsignacion(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();

        if (empty($data['evento_id']) || empty($data['personal_id'])) {
            return $this->error($res, 'Evento y Personal son requeridos', 400);
        }

        $a = new InvGeneralAsignacion();
        $a->evento_id = $data['evento_id'];
        $a->personal_id = $data['personal_id'];
        $a->rango_tipo = $data['rango_tipo'] ?? 'Libre';
        $a->rango_valor = $data['rango_valor'] ?? null;
        $a->asignado_por = $user->id;
        $a->estado = 'Pendiente';
        $a->save();

        // Optional: Trigger push notification task using NotificacionModel
        \App\Models\Notificacion::create([
            'empresa_id' => $user->empresa_id,
            'sucursal_id' => $user->sucursal_id,
            'emisor_id' => $user->id,
            'personal_id' => $data['personal_id'],
            'titulo' => 'Nueva Asignación de Inventario',
            'mensaje' => "Se te ha asignado zona: " . ($a->rango_valor ?? 'Libre'),
            'tipo' => 'tarea_manual',
            'link_accion' => '/inventario/conteo_nuevo',
        ]);

        return $this->ok($res, $a, 'Operador Asignado y Notificado');
    }

    /**
     * POST /api/inv-general/conteo
     * Lógica crítica: Barrido con bloqueo anti-colisión
     */
    public function registrarConteo(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();
        
        $eventoId = $data['evento_id'];
        $ubicacionId = $data['ubicacion_id'];
        $productoId = $data['producto_id'];
        $lote = $data['lote'] ?? null;
        $vencimiento = $data['fecha_vencimiento'] ?? null;
        $cantidad = (float)($data['cantidad'] ?? 0);
        $ciclo = (int)($data['ciclo'] ?? 1); // 1 = Primer conteo

        // 1. Validar si la ubicación ya fue contada por OTRO en el MISMO ciclo
        $otroConteo = InvGeneralConteo::where('evento_id', $eventoId)
            ->where('ubicacion_id', $ubicacionId)
            ->where('ciclo', $ciclo)
            ->where('personal_id', '!=', $user->id)
            ->first();

        if ($otroConteo) {
            $nombre = $otroConteo->personal->nombre ?? 'otro auxiliar';
            return $this->error($res, "La ubicación ya fue barrida en este ciclo por {$nombre}. Si hay diferencias, debe ser enviada a reconteo desde la Mesa de Control.", 423);
        }

        try {
            // Guardar el conteo
            $c = new InvGeneralConteo();
            $c->evento_id = $eventoId;
            $c->personal_id = $user->id;
            $c->ubicacion_id = $ubicacionId;
            $c->producto_id = $productoId;
            $c->lote = $lote;
            $c->fecha_vencimiento = $vencimiento;
            $c->cantidad = $cantidad;
            $c->ciclo = $ciclo;
            $c->save();

            // Sincronizar con Mesa de Control (Diferencias)
            $this->sincronizarMesaDiferencia($eventoId, $ubicacionId, $productoId, $lote, $vencimiento, $cantidad, $ciclo);

            return $this->ok($res, $c, 'Conteo registrado con éxito');

        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'conteo_unico_idx')) {
                return $this->error($res, 'Ya registraste esta ubicación/producto/lote en este ciclo. Si te equivocaste, pídele al supervisor anular la línea en la Mesa.', 400);
            }
            return $this->error($res, 'Error en BD: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper paramétrica para mesa de control
     */
    private function sincronizarMesaDiferencia($eventoId, $ubicacionId, $productoId, $lote, $vencimiento, $cantidad, $ciclo)
    {
        $dif = InvGeneralDiferencia::where('evento_id', $eventoId)
            ->where('ubicacion_id', $ubicacionId)
            ->where('producto_id', $productoId)
            ->where('lote', $lote)
            ->first();

        if (!$dif) {
            $dif = new InvGeneralDiferencia();
            $dif->evento_id = $eventoId;
            $dif->ubicacion_id = $ubicacionId;
            $dif->producto_id = $productoId;
            $dif->lote = $lote;
            $dif->vencimiento_esperado = $vencimiento;

            // Retrieve expected system stock
            $stockOriginal = Inventario::where('ubicacion_id', $ubicacionId)
                ->where('producto_id', $productoId)
                ->where('lote', $lote)
                ->sum('cantidad');
            
            $dif->cantidad_sistema = $stockOriginal ?? 0;
            $dif->estado = 'Pendiente';
        }

        if ($ciclo == 1) {
            $dif->conteo_1 = ($dif->conteo_1 ?? 0) + $cantidad;
        } elseif ($ciclo == 2) {
            $dif->conteo_2 = ($dif->conteo_2 ?? 0) + $cantidad;
        } elseif ($ciclo == 3) {
            $dif->conteo_3 = ($dif->conteo_3 ?? 0) + $cantidad;
        }

        // Auto-check if difference is 0 immediately sets state to "Aprobada" ?
        // Usually it's better to leave it to the Admin, but for speed, if Conteo = Sistema it's implicitly OK.
        $actual = $ciclo == 1 ? $dif->conteo_1 : ($ciclo == 2 ? $dif->conteo_2 : $dif->conteo_3);
        if ($actual == $dif->cantidad_sistema) {
            $dif->estado = 'Aprobada';
            $dif->cantidad_final_aprobada = $actual;
        } else {
            $dif->estado = 'RequiereRecorteo';
        }

        $dif->save();
    }

    /**
     * GET /api/inv-general/eventos/{id}/acta
     * Genera el Acta HTML para imprimir differences
     */
    public function getActaHtml(Request $r, Response $res, array $args): Response
    {
        $user = $r->getAttribute('user');
        $evento = InvGeneralEvento::where('empresa_id', $user->empresa_id)
            ->where('id', $args['id'])
            ->first();

        if (!$evento) {
            $res->getBody()->write("<h1>Evento no encontrado o acceso denegado.</h1>");
            return $res->withStatus(404)->withHeader('Content-Type', 'text/html');
        }

        $empresa = Empresa::find($user->empresa_id);
        
        // Obtener SOLO las diferencias donde cantidad_sistema != cantidad_final_aprobada 
        // y estado = Aprobada (o sea, ya se definió cuál es la cifra final física).
        $diferencias = InvGeneralDiferencia::with(['producto', 'ubicacion'])
            ->where('evento_id', $evento->id)
            ->whereRaw('cantidad_sistema != cantidad_final_aprobada')
            ->get();

        $fechaImpresion = date('d/m/Y H:i:s');
        $html = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <title>Acta de Inventario General #{$evento->id}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 20px; }
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
                .header h1 { margin: 0; font-size: 20px; text-transform: uppercase; }
                .header p { margin: 2px 0; font-size: 12px; }
                .meta-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
                .meta-table td { padding: 4px; border: 1px solid #ddd; }
                .meta-table th { padding: 4px; border: 1px solid #ddd; background: #f4f4f4; text-align: left; width: 15%; }
                
                .data-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
                .data-table th, .data-table td { border: 1px solid #000; padding: 6px; text-align: left; }
                .data-table th { background: #eee; text-align: center; font-weight: bold; }
                .data-table td.num { text-align: right; }
                .falta { color: #d32f2f; font-weight: bold; }
                .sobra { color: #388e3c; font-weight: bold; }
                
                .signatures { width: 100%; margin-top: 50px; display: table; }
                .sig-box { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; height: 80px; }
                .sig-line { width: 80%; border-top: 1px solid #000; margin: 0 auto; padding-top: 5px; font-weight: bold; text-transform: uppercase; }

                @media print {
                    @page { size: portrait; margin: 1cm; }
                    body { padding: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body onload='window.print()'>
            <div class='no-print' style='margin-bottom: 15px; text-align: right;'>
                <button onclick='window.print()' style='padding: 8px 16px; background: #000; color: #fff; border: none; cursor: pointer; font-size: 14px;'>🖨️ Imprimir a PDF</button>
            </div>

            <div class='header'>
                <h1>{$empresa->razon_social}</h1>
                <p>NIT: {$empresa->nit}</p>
                <h2>ACTA DE CIERRE: INVENTARIO FÍSICO GENERAL</h2>
            </div>

            <table class='meta-table'>
                <tr>
                    <th>Cod. Evento</th><td>#{$evento->id} - {$evento->nombre}</td>
                    <th>Fecha Inicio</th><td>{$evento->fecha_programada}</td>
                </tr>
                <tr>
                    <th>Estado</th><td>{$evento->estado}</td>
                    <th>Fecha Impresión</th><td>{$fechaImpresion}</td>
                </tr>
                <tr>
                    <th>Generado por</th><td colspan='3'>{$user->nombre} ({$user->rol})</td>
                </tr>
            </table>
            
            <p style='margin-bottom: 10px;'>A continuación, se listan única y exclusivamente los ítems que presentaron <b>Novedades (Faltantes y Sobrantes)</b> respecto a las existencias registradas en el sistema (Kárdex), los cuales han sido aprobados y serán ajustados formalmente.</p>

            <table class='data-table'>
                <thead>
                    <tr>
                        <th>Ubicación</th>
                        <th>Producto (Ref / Nombre)</th>
                        <th>Lote</th>
                        <th>Venc.</th>
                        <th>Stock Sistema</th>
                        <th>Stock Físico (Final)</th>
                        <th>Diferencia</th>
                    </tr>
                </thead>
                <tbody>";

        if ($diferencias->count() > 0) {
            foreach ($diferencias as $d) {
                $ubic = htmlspecialchars($d->ubicacion->codigo ?? "Indefinida");
                $prod = htmlspecialchars(($d->producto->codigo_interno ?? '') . ' - ' . ($d->producto->nombre ?? ''));
                $lote = htmlspecialchars($d->lote ?: '-');
                $sys = number_format($d->cantidad_sistema, 2);
                $fis = number_format($d->cantidad_final_aprobada, 2);
                $diffRaw = $d->cantidad_final_aprobada - $d->cantidad_sistema;
                
                $diffText = '';
                if ($diffRaw < 0) {
                    $diffText = "<span class='falta'>" . number_format($diffRaw, 2) . " (Faltante)</span>";
                } elseif ($diffRaw > 0) {
                    $diffText = "<span class='sobra'>+" . number_format($diffRaw, 2) . " (Sobrante)</span>";
                }

                $html .= "
                    <tr>
                        <td>{$ubic}</td>
                        <td>{$prod}</td>
                        <td style='text-align:center;'>{$lote}</td>
                        <td style='text-align:center;'>{$d->vencimiento_esperado}</td>
                        <td class='num'>{$sys}</td>
                        <td class='num'>{$fis}</td>
                        <td class='num'>{$diffText}</td>
                    </tr>
                ";
            }
        } else {
            $html .= "<tr><td colspan='7' style='text-align:center; padding: 20px;'>No se registraron diferencias vs el sistema. El inventario fue perfecto.</td></tr>";
        }

        $html .= "
                </tbody>
            </table>

            <div class='signatures'>
                <div class='sig-box'>
                    <div class='sig-line'>Firma Administrador / Gerente</div>
                </div>
                <div class='sig-box'>
                    <div class='sig-line'>Firma Auditor / Analista de Inventario</div>
                </div>
            </div>
            
            <div style='text-align:center; margin-top: 30px; font-size: 9px; color: #777;'>
                Documento generado por el sistema AntiGravity WMS - " . date("Y") . "
            </div>
        </body>
        </html>
        ";

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * GET /api/inventario/mapa-detallado
     * Retorna datos enriquecidos por ubicación para el Mapa 2D formato Tabla
     */
    public function getMapaDetallado(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        $prodId = !empty($params['producto_id']) ? (int)$params['producto_id'] : null;
        
        try {
            // 1. Obtener todas las ubicaciones activas
            $ubicaciones = Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('activo', 1)
                ->orderBy('secuencia_picking', 'asc')
                ->get();

            // 2. Obtener ocupación actual agrupada por ubicación
            $stockQuery = Capsule::table('inventarios')
                ->where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id);
            
            if ($prodId) {
                $stockQuery->where('producto_id', $prodId);
            }

            $stock = $stockQuery->select(
                    'ubicacion_id', 
                    Capsule::raw('SUM(cantidad) as total_unidades'),
                    Capsule::raw('MIN(fecha_vencimiento) as proximo_vencimiento')
                )
                ->groupBy('ubicacion_id')
                ->get()
                ->keyBy('ubicacion_id');

            // 2.1 Obtener detalle para calcular cajas (necesitamos el factor del producto)
            $detalleStock = Capsule::table('inventarios')
                ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
                ->where('inventarios.empresa_id', $user->empresa_id)
                ->where('inventarios.sucursal_id', $user->sucursal_id)
                ->select('inventarios.ubicacion_id', 'inventarios.cantidad', 'productos.unidades_caja')
                ->get();

            $cajasPorUbicacion = [];
            foreach ($detalleStock as $ds) {
                $factor = max(1, (int)$ds->unidades_caja);
                $cajas = (float)$ds->cantidad / $factor;
                $cajasPorUbicacion[$ds->ubicacion_id] = ($cajasPorUbicacion[$ds->ubicacion_id] ?? 0) + $cajas;
            }
            
            // Si filtramos por producto, solo queremos las ubicaciones que tienen ese producto
            if ($prodId) {
                $ubicIdsWithStock = $stock->keys()->toArray();
                $ubicaciones = $ubicaciones->filter(function($u) use ($ubicIdsWithStock) {
                    return in_array($u->id, $ubicIdsWithStock);
                });
            }

            // 3. Obtener último movimiento por ubicación (origen o destino)
            // Usamos un UNION o verificamos ambos campos. Para simplificar, vemos destino que es donde "llega" stock.
            $ultimosMovimientos = Capsule::table('movimiento_inventarios')
                ->where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->select('ubicacion_destino_id', Capsule::raw('MAX(created_at) as ultimo_mov'))
                ->groupBy('ubicacion_destino_id')
                ->get()
                ->keyBy('ubicacion_destino_id');

            // 4. Mapear resultados
            $data = $ubicaciones->map(function($u) use ($stock, $ultimosMovimientos, $cajasPorUbicacion) {
                $s = $stock[$u->id] ?? null;
                $totalStock = floatval($s->total_unidades ?? 0);
                $capacidad  = floatval($u->capacidad_maxima ?? 0);
                $pctOcupacion = $capacidad > 0 ? round(($totalStock / $capacidad) * 100, 2) : 0;
                
                $ultimoMov = $ultimosMovimientos[$u->id]->ultimo_mov ?? null;
                $diasSinMov = "N/A";
                if ($ultimoMov) {
                    $diasSinMov = (int)floor((time() - strtotime($ultimoMov)) / 86400);
                }

                return [
                    'id'                 => $u->id,
                    'ubicacion'          => $u->codigo,
                    'posicion'           => $u->codigo,
                    'tipo'               => $u->tipo_ubicacion ?? 'Almacenamiento',
                    'total_productos'    => $totalStock,
                    'total_cajas'        => round($cajasPorUbicacion[$u->id] ?? 0, 2),
                    'ocupacion_pct'      => $pctOcupacion,
                    'proximo_vencimiento'=> $s->proximo_vencimiento ?? null,
                    'dias_sin_mov'       => $diasSinMov,
                    'capacidad_maxima'   => $capacidad,
                ];
            })->values()->toArray();

            return $this->ok($res, $data);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }
}
