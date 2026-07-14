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
    // ── Helper: calcular cajas y saldos desde und_total ─────────────────────
    private function calcularCajasSaldos(float $cantidad, int $upc): array
    {
        $upc = max(1, $upc);
        $cantCajas = (int)floor($cantidad / $upc);
        $saldos    = fmod($cantidad, (float)$upc);
        return [$cantCajas, round($saldos, 4)];
    }

    // ── GET /api/inventario/stock ────────────────────────────────────────────
    public function getStock(Request $req, Response $res, array $args): Response
    {
        $user   = $req->getAttribute('user');
        if ($deny = $this->requireSelectedTenantForSuperAdmin($user, $req, $res, true)) {
            return $deny;
        }

        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        $params = $req->getQueryParams();

        // ── Límite defensivo: nunca devolver un dump completo sin filtro ──────
        // Con 25 usuarios simultáneos sin límite podría saturar memoria PHP.
        $limit = min((int)($params['limit'] ?? 500), 2000);

        $q = Inventario::where('inventarios.empresa_id', $empresaId)
            ->where('inventarios.sucursal_id', $sucursalId)
            ->join('productos',   'inventarios.producto_id',  '=', 'productos.id')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->select(
                'productos.nombre as producto_nombre',
                'productos.codigo_interno',
                'productos.unidades_caja',
                'ubicaciones.codigo as ubicacion_codigo',
                'inventarios.numero_pallet',
                'inventarios.*',
                'inventarios.cantidad_cajas',
                'inventarios.saldos'
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
            // Enriquecer con campos und/total para el frontend
            $upc = max(1, (int)($item->unidades_caja ?? 1));
            $item->und_total = $item->cantidad;
            $item->und_total_label = $item->cantidad . ' UND/TOTAL';
            // Si los campos no existen aún en BD, derivarlos on-the-fly
            if (is_null($item->cantidad_cajas) && is_null($item->saldos)) {
                [$item->cantidad_cajas, $item->saldos] = $this->calcularCajasSaldos((float)$item->cantidad, $upc);
            }
            return $item;
        });

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Producto', 'Código', 'Ubicación', 'Lote', 'F.Vencimiento', 'Cajas', 'Sueltos', 'UND/TOTAL', 'Estado', 'Días p/Vencer'];
            $rows = $stock->map(fn($i) => [
                $i->producto_nombre,
                $i->codigo_interno,
                $i->ubicacion_codigo,
                $i->lote ?? '—',
                $i->fecha_vencimiento ?? '—',
                $i->cantidad_cajas ?? 0,
                $i->saldos ?? 0,
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
        if ($deny = $this->requireSelectedTenantForSuperAdmin($user, $req, $res, true)) {
            return $deny;
        }

        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        $data = $req->getParsedBody() ?? [];

        $required = ['producto_id', 'ubicacion_origen_id', 'ubicacion_destino_id', 'cantidad'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        $cantidad = (float)$data['cantidad'];
        if ($cantidad <= 0) return $this->error($res, 'La cantidad debe ser mayor a 0');

        // ── Guard de integridad: verificar stock en origen antes de abrir la TX ─
        $loteGuard = trim((string)($data['lote'] ?? '')) ?: null;
        $guard = new InventoryGuard($empresaId, $sucursalId, $user->id);
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
            Capsule::transaction(function () use ($data, $user, $cantidad, $empresaId, $sucursalId) {
                $lote  = trim((string)($data['lote'] ?? ''));
                $fvenc = trim((string)($data['fecha_vencimiento'] ?? ''));
                if ($lote === '') {
                    $lote = null;
                }
                if ($fvenc === '') {
                    $fvenc = null;
                }

                // Verificar stock origen. Permitir inventario en patio o disponible para ubicar.
                $origenQuery = Inventario::where('empresa_id',    $empresaId)
                    ->where('sucursal_id',   $sucursalId)
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

                // Descontar origen y recalcular cajas/saldos
                $origen->cantidad -= $cantidad;
                if ((float)$origen->cantidad <= 0 && (float)($origen->cantidad_reservada ?? 0) <= 0) {
                    $origen->delete();
                } else {
                    $productoOrigen = \App\Models\Producto::select('unidades_caja')->find($data['producto_id']);
                    $upcOrigen = max(1, (int)(($productoOrigen->unidades_caja ?? null) ?: 1));
                    [$origen->cantidad_cajas, $origen->saldos] = $this->calcularCajasSaldos((float)$origen->cantidad, $upcOrigen);
                    $origen->save();
                }

                // Acumular en destino
                if ($lote !== null) {
                    $destino = Inventario::firstOrCreate(
                        [
                            'empresa_id'   => $empresaId,
                            'sucursal_id'  => $sucursalId,
                            'producto_id'  => $data['producto_id'],
                            'ubicacion_id' => $data['ubicacion_destino_id'],
                            'lote'         => $lote,
                            'estado'       => 'Disponible',
                            'numero_pallet' => $data['numero_pallet'] ?? null,
                        ],
                        [
                            'cantidad'           => 0,
                            'cantidad_reservada' => 0,
                            'cantidad_cajas'     => 0,
                            'saldos'             => 0,
                            'fecha_vencimiento'  => $fvenc,
                        ]
                    );
                } else {
                    $destino = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $data['producto_id'])
                        ->where('ubicacion_id', $data['ubicacion_destino_id'])
                        ->where(function ($sub) {
                            $sub->whereNull('lote')->orWhere('lote', 'N/A');
                        })
                        ->where('estado', 'Disponible')
                        ->lockForUpdate()
                        ->first();

                    if (!$destino) {
                        $destino = new Inventario();
                        $destino->empresa_id = $empresaId;
                        $destino->sucursal_id = $sucursalId;
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

                // Garantizar cantidad_reservada no nula (registros legacy sin ese campo)
                if (is_null($destino->cantidad_reservada)) {
                    $destino->cantidad_reservada = 0;
                }

                $destino->cantidad += $cantidad;
                if ($fvenc) {
                    $destino->fecha_vencimiento = $fvenc;
                }
                // Recalcular cajas/saldos destino
                $productoDestino = \App\Models\Producto::select('unidades_caja')->find($data['producto_id']);
                $upcDestino = max(1, (int)(($productoDestino->unidades_caja ?? null) ?: 1));
                [$destino->cantidad_cajas, $destino->saldos] = $this->calcularCajasSaldos((float)$destino->cantidad, $upcDestino);
                $destino->save();

                // Calcular cajas/saldos del movimiento para kardex
                $productoMov = \App\Models\Producto::select('unidades_caja')->find($data['producto_id']);
                $upcMov = max(1, (int)(($productoMov->unidades_caja ?? null) ?: 1));
                [$cajasMov, $saldosMov] = $this->calcularCajasSaldos($cantidad, $upcMov);

                // Movimiento trazable
                MovimientoInventario::create([
                    'empresa_id'          => $empresaId,
                    'sucursal_id'         => $sucursalId,
                    'producto_id'         => $data['producto_id'],
                    'tipo_movimiento'     => 'Traslado',
                    'cantidad'            => $cantidad,
                    'cantidad_cajas'      => $cajasMov,
                    'saldos'              => $saldosMov,
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
            Capsule::transaction(function () use ($data, $user, $req) {
                $inv = Inventario::where('empresa_id',   $this->getEffectiveEmpresaId($user, $req))
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $data['producto_id'])
                    ->where('ubicacion_id', $data['ubicacion_id'])
                    ->where('estado', 'Disponible')
                    ->when($data['lote'] ?? null, fn($q) => $q->where('lote', $data['lote']))
                    ->lockForUpdate()
                    ->first();

                $fvencParsed = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);
                
                // Validación R09 para ajustes positivos o creación
                if ($data['cantidad_nueva'] > 0) {
                    $guard = new InventoryGuard($this->getEffectiveEmpresaId($user, $req), $user->sucursal_id, $user->id);
                    $checkDate = $guard->checkExpirationMandatory($data['producto_id'], $fvencParsed);
                    if (!$checkDate['ok']) {
                        throw new \Exception($checkDate['message']);
                    }
                }

                $cantidadAnterior = $inv ? $inv->cantidad : 0;
                $cantidadNueva    = (float)$data['cantidad_nueva'];

                if ($cantidadNueva < 0) {
                    throw new \Exception('El sistema no permite dejar el stock en negativo.');
                }

                $diferencia = $cantidadNueva - $cantidadAnterior;

                // Resolver upc del producto para cajas/saldos
                $productoAjuste = \App\Models\Producto::select('unidades_caja')->find($data['producto_id']);
                $upcAjuste = max(1, (int)(($productoAjuste->unidades_caja ?? null) ?: 1));

                // Leer cajas/saldos del payload o calcularlos
                if (isset($data['cantidad_cajas']) || isset($data['saldos'])) {
                    $cantCajasAjuste = (int)($data['cantidad_cajas'] ?? (int)floor($cantidadNueva / $upcAjuste));
                    $saldosAjuste    = round((float)($data['saldos'] ?? fmod($cantidadNueva, (float)$upcAjuste)), 4);
                    // Validar consistencia: cajas*upc+saldos debe igualar cantidad_nueva
                    $recalculada = ($cantCajasAjuste * $upcAjuste) + $saldosAjuste;
                    if (abs($recalculada - $cantidadNueva) > 0.001) {
                        throw new \Exception(
                            "Inconsistencia: cantidad_cajas ({$cantCajasAjuste}) × upc ({$upcAjuste}) + saldos ({$saldosAjuste}) = {$recalculada} ≠ cantidad_nueva ({$cantidadNueva})"
                        );
                    }
                } else {
                    [$cantCajasAjuste, $saldosAjuste] = $this->calcularCajasSaldos($cantidadNueva, $upcAjuste);
                }

                if (!$inv) {
                    if ($cantidadNueva > 0) {
                        Inventario::create([
                            'empresa_id'         => $this->getEffectiveEmpresaId($user, $req),
                            'sucursal_id'        => $user->sucursal_id,
                            'producto_id'        => $data['producto_id'],
                            'ubicacion_id'       => $data['ubicacion_id'],
                            'lote'               => $data['lote'] ?? null,
                            'fecha_vencimiento'  => $fvencParsed,
                            'cantidad'           => $cantidadNueva,
                            'cantidad_cajas'     => $cantCajasAjuste,
                            'saldos'             => $saldosAjuste,
                            'cantidad_reservada' => 0,
                            'estado'             => 'Disponible',
                        ]);
                    }
                } else {
                    $inv->cantidad       = $cantidadNueva;
                    $inv->cantidad_cajas = $cantCajasAjuste;
                    $inv->saldos         = $saldosAjuste;
                    if ($cantidadNueva === 0.0 && (float)($inv->cantidad_reservada ?? 0) <= 0) {
                        $inv->delete();
                    } else {
                        $inv->save();
                    }
                }

                $tipo = $diferencia >= 0 ? 'AjustePositivo' : 'AjusteNegativo';
                // FIX: usar getEffectiveEmpresaId y sucursal_id directamente (evitar $empresaId/$sucursalId indefinidos en closure)
                MovimientoInventario::create([
                    'empresa_id'           => $this->getEffectiveEmpresaId($user, $req),
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $data['producto_id'],
                    'tipo_movimiento'      => $tipo,
                    'cantidad'             => abs($diferencia),
                    'cantidad_cajas'       => $cantCajasAjuste,
                    'saldos'               => $saldosAjuste,
                    'ubicacion_origen_id'  => $data['ubicacion_id'],
                    'ubicacion_destino_id' => $data['ubicacion_id'],
                    'lote'                 => $data['lote'] ?? null,
                    'fecha_vencimiento'    => $fvencParsed,
                    'auxiliar_id'          => $user->id,
                    'referencia_tipo'      => 'ajuste',
                    'observaciones'        => $data['motivo'],
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
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

            $q = MovimientoInventario::where('movimiento_inventarios.empresa_id', $this->getEffectiveEmpresaId($user, $req))
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
                    'empresa_id'      => $this->getEffectiveEmpresaId($user, $req),
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
                            'empresa_id' => $this->getEffectiveEmpresaId($user, $req),
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
                    $q = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('estado', 'Disponible');
                    
                    if (!empty($data['filtro_pasillo'])) {
                        $q->whereHas('ubicacion', fn($uq) => $uq->where('pasillo', $data['filtro_pasillo']));
                    }
                    if (!empty($data['filtro_ubicaciones']) && is_array($data['filtro_ubicaciones'])) {
                        $q->whereIn('ubicacion_id', $data['filtro_ubicaciones']);
                    }

                    $items = $q->with('producto:id,unidades_caja')->get();
                    foreach ($items as $item) {
                        $upcSnap = max(1, (int)(($item->producto->unidades_caja ?? null) ?: 1));
                        [$cajasSnap, $saldosSnap] = $this->calcularCajasSaldos((float)$item->cantidad, $upcSnap);
                        ConteoDetalle::create([
                            'conteo_id'                => $conteo->id,
                            'ronda'                    => 1,
                            'ubicacion_id'             => $item->ubicacion_id,
                            'producto_id'              => $item->producto_id,
                            'lote'                     => $item->lote,
                            'cantidad_sistema'         => $item->cantidad,
                            'cantidad_sistema_snapshot'=> $item->cantidad,
                            'estado'                   => 'Pendiente',
                            // Snapshot de la descomposición al momento del conteo
                            'cantidad_cajas_sistema'   => $cajasSnap,
                            'saldos_sistema'           => $saldosSnap,
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
        $conteo = ConteoInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);

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
            $ubic = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
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
            'empresa_id'  => $this->getEffectiveEmpresaId($user, $req),
            'sucursal_id' => $user->sucursal_id,
            'producto_id' => $productoId,
            'ubicacion_id'=> $ubicacionId,
            'estado'      => 'Disponible'
        ])->sum('cantidad');

        // Calcular descomposición del sistema para comparación en frontend
        $productoLinea = \App\Models\Producto::select('unidades_caja')->find($productoId);
        $upcLinea = max(1, (int)(($productoLinea->unidades_caja ?? null) ?: 1));
        [$cajasSistema, $saldosSistema] = $this->calcularCajasSaldos($cantSistema, $upcLinea);

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

            return $this->ok($res, [
                'linea'              => $linea,
                'und_total_sistema'  => $cantSistema,
                'cajas_sistema'      => $cajasSistema,
                'saldos_sistema'     => $saldosSistema,
                'upc'                => $upcLinea,
                'und_total_label'    => "{$cajasSistema} cajas x {$upcLinea} u/e + {$saldosSistema} sueltos = {$cantSistema} UND/TOTAL",
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    public function finalizarRonda(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $conteo = ConteoInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
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
            $q = ConteoInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
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
            $niveles = NivelReposicion::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
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
        $eId  = $this->getEffectiveEmpresaId($user, $req);
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
            'conteos_cero'         => Capsule::table('sesion_lineas as sl')
                                        ->join('sesiones_inventario as si', 'si.id', '=', 'sl.sesion_id')
                                        ->where('si.empresa_id', $eId)
                                        ->where('si.sucursal_id', $sId)
                                        ->whereIn('si.estado', ['EnCurso', 'PendienteAjuste'])
                                        ->where('sl.estado', 'Activo')
                                        ->where('sl.cantidad_contada', '<=', 0)
                                        ->count(),
            'ubicaciones_vacias'   => Capsule::table('ubicaciones as u')
                                        ->where('u.empresa_id', $eId)
                                        ->where('u.sucursal_id', $sId)
                                        ->where('u.activo', 1)
                                        ->whereNotExists(function ($q) use ($eId, $sId) {
                                            $q->select(Capsule::raw(1))
                                              ->from('inventarios as i')
                                              ->whereColumn('i.ubicacion_id', 'u.id')
                                              ->where('i.empresa_id', $eId)
                                              ->where('i.sucursal_id', $sId)
                                              ->where('i.cantidad', '>', 0);
                                        })
                                        ->count(),
        ];

        return $this->ok($res, $data);
    }

    // ── GET /api/inventario/ubicaciones-en-cero ──────────────────────────────
    // Retorna sesion_lineas con cantidad_contada=0 en sesiones activas (para dashboard)
    public function getUbicacionesEnCero(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $eId  = $this->getEffectiveEmpresaId($user, $req);
        $sId  = $user->sucursal_id;

        $lineas = Capsule::table('sesion_lineas as sl')
            ->join('sesiones_inventario as si', 'si.id', '=', 'sl.sesion_id')
            ->join('productos as p',           'p.id',  '=', 'sl.producto_id')
            ->join('ubicaciones as u',         'u.id',  '=', 'sl.ubicacion_id')
            ->leftJoin('personal as aux',      'aux.id','=', 'sl.auxiliar_id')
            ->where('si.empresa_id', $eId)
            ->where('si.sucursal_id', $sId)
            ->whereIn('si.estado', ['EnCurso', 'PendienteAjuste'])
            ->where('sl.estado', 'Activo')
            ->where('sl.cantidad_contada', '<=', 0)
            ->whereRaw('(sl.ajustado IS NOT TRUE)')
            ->select(
                'sl.id as linea_id',
                'sl.sesion_id',
                'si.nombre as sesion_nombre',
                'si.tipo as sesion_tipo',
                'si.estado as sesion_estado',
                'sl.producto_id',
                'p.codigo_interno as producto_codigo',
                'p.nombre as producto_nombre',
                'sl.ubicacion_id',
                'u.codigo as ubicacion_codigo',
                'u.zona as ubicacion_zona',
                'sl.auxiliar_id',
                'aux.nombre as auxiliar_nombre',
                'sl.cantidad_sistema',
                'sl.cantidad_contada',
                'sl.diferencia',
                'sl.hora_conteo',
                'sl.ronda'
            )
            ->orderBy('sl.hora_conteo', 'desc')
            ->limit(300)
            ->get();

        return $this->ok($res, $lineas);
    }

    // ── GET /api/inventario/conteo/{id}/dashboard ─────────────────────────────
    public function getDashboardData(Request $req, Response $res, array $a): Response
    {
        $user   = $req->getAttribute('user');
        $conteo = ConteoInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
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

        $conteo = ConteoInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
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

                    // Resolver upc para cajas/saldos
                    $productoFin = \App\Models\Producto::select('unidades_caja')->find($det->producto_id);
                    $upcFin = max(1, (int)(($productoFin->unidades_caja ?? null) ?: 1));
                    [$cajasFin, $saldosFin] = $this->calcularCajasSaldos((float)$det->cantidad_fisica, $upcFin);

                    // Actualizar inventario
                    $inv = Inventario::where('empresa_id',   $this->getEffectiveEmpresaId($user, $req))
                        ->where('sucursal_id',  $user->sucursal_id)
                        ->where('producto_id',  $det->producto_id)
                        ->where('ubicacion_id', $det->ubicacion_id)
                        ->where('estado', 'Disponible')
                        ->when($det->lote, fn($q) => $q->where('lote', $det->lote))
                        ->lockForUpdate()
                        ->first();

                    // Capturar fecha_vencimiento antes de posible delete del $inv
                    $fvConteo = $det->fv_leida ?? ($inv->fecha_vencimiento ?? null);

                    if ($inv) {
                        $inv->cantidad       = $det->cantidad_fisica;
                        $inv->cantidad_cajas = $cajasFin;
                        $inv->saldos         = $saldosFin;
                        if ($inv->cantidad <= 0) $inv->delete();
                        else $inv->save();
                    } elseif ($det->cantidad_fisica > 0) {
                        Inventario::create([
                            'empresa_id'         => $this->getEffectiveEmpresaId($user, $req),
                            'sucursal_id'        => $user->sucursal_id,
                            'producto_id'        => $det->producto_id,
                            'ubicacion_id'       => $det->ubicacion_id,
                            'lote'               => $det->lote,
                            'fecha_vencimiento'  => $det->fv_leida ?? null,
                            'cantidad'           => $det->cantidad_fisica,
                            'cantidad_cajas'     => $cajasFin,
                            'saldos'             => $saldosFin,
                            'cantidad_reservada' => 0,
                            'estado'             => 'Disponible',
                        ]);
                    }

                    MovimientoInventario::create([
                        'empresa_id'           => $this->getEffectiveEmpresaId($user, $req),
                        'sucursal_id'          => $user->sucursal_id,
                        'producto_id'          => $det->producto_id,
                        'tipo_movimiento'      => $tipo,
                        'cantidad'             => abs($diferencia),
                        'cantidad_cajas'       => $cajasFin,
                        'saldos'               => $saldosFin,
                        'ubicacion_origen_id'  => $det->ubicacion_id,
                        'ubicacion_destino_id' => $det->ubicacion_id,
                        'lote'                 => $det->lote,
                        'fecha_vencimiento'    => $fvConteo,
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

        $conteo = ConteoInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
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
                    ['empresa_id' => $this->getEffectiveEmpresaId($user, $req), 'personal_id' => $auxId,
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
                    'empresa_id'   => $this->getEffectiveEmpresaId($user, $req),
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
        $eventos = InvGeneralEvento::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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
        $e->empresa_id = $this->getEffectiveEmpresaId($user, $r);
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
            'empresa_id' => $this->getEffectiveEmpresaId($user, $r),
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
            $this->sincronizarMesaDiferencia($eventoId, $ubicacionId, $productoId, $lote, $vencimiento, $cantidad, $ciclo, $this->getEffectiveEmpresaId($user, $r));

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
    private function sincronizarMesaDiferencia($eventoId, $ubicacionId, $productoId, $lote, $vencimiento, $cantidad, $ciclo, int $empresaId = 0)
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

            // Stock del sistema scoped por empresa para evitar cross-tenant
            $invQuery = Inventario::where('ubicacion_id', $ubicacionId)
                ->where('producto_id', $productoId)
                ->where('lote', $lote);
            if ($empresaId) $invQuery->where('empresa_id', $empresaId);
            $stockOriginal = $invQuery->sum('cantidad');
            
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
        $evento = InvGeneralEvento::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('id', $args['id'])
            ->first();

        if (!$evento) {
            $res->getBody()->write("<h1>Evento no encontrado o acceso denegado.</h1>");
            return $res->withStatus(404)->withHeader('Content-Type', 'text/html');
        }

        $empresa = Empresa::find($this->getEffectiveEmpresaId($user, $r));
        
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
        if ($deny = $this->requireSelectedTenantForSuperAdmin($user, $req, $res, true)) {
            return $deny;
        }

        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        $params = $req->getQueryParams();
        $prodId = !empty($params['producto_id']) ? (int)$params['producto_id'] : null;
        
        try {
            // 1. Obtener todas las ubicaciones activas
            $ubicaciones = Ubicacion::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('activo', 1)
                ->orderBy('codigo', 'asc')
                ->get();

            // 2. Obtener ocupación actual agrupada por ubicación
            $stockQuery = Capsule::table('inventarios')
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId);
            
            if ($prodId) {
                $stockQuery->where('producto_id', $prodId);
            }

            $stock = $stockQuery->select(
                    'ubicacion_id',
                    Capsule::raw('SUM(cantidad) as total_unidades'),
                    Capsule::raw('SUM(COALESCE(cantidad_reservada, 0)) as total_reservado'),
                    Capsule::raw('COUNT(DISTINCT producto_id) as total_refs'),
                    Capsule::raw('SUM(COALESCE(cantidad_cajas, 0)) as total_cajas_db'),
                    Capsule::raw('SUM(COALESCE(saldos, 0)) as total_sueltos_db'),
                    Capsule::raw('MIN(fecha_vencimiento) as proximo_vencimiento')
                )
                ->groupBy('ubicacion_id')
                ->get()
                ->keyBy('ubicacion_id');

            // 2.1 Fallback: calcular cajas desde cantidad/upc solo cuando cantidad_cajas es NULL en BD
            $detalleStock = Capsule::table('inventarios')
                ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
                ->where('inventarios.empresa_id', $empresaId)
                ->where('inventarios.sucursal_id', $sucursalId)
                ->whereNull('inventarios.cantidad_cajas')
                ->select('inventarios.ubicacion_id', 'inventarios.cantidad', 'productos.unidades_caja')
                ->get();

            $cajasLegacyPorUbicacion = [];
            foreach ($detalleStock as $ds) {
                $factor = max(1, (int)$ds->unidades_caja);
                $cajas = (float)$ds->cantidad / $factor;
                $cajasLegacyPorUbicacion[$ds->ubicacion_id] = ($cajasLegacyPorUbicacion[$ds->ubicacion_id] ?? 0) + $cajas;
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
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->select('ubicacion_destino_id', Capsule::raw('MAX(created_at) as ultimo_mov'))
                ->groupBy('ubicacion_destino_id')
                ->get()
                ->keyBy('ubicacion_destino_id');

            // 4. Mapear resultados
            $data = $ubicaciones->map(function($u) use ($stock, $ultimosMovimientos, $cajasLegacyPorUbicacion) {
                $s = $stock[$u->id] ?? null;
                $totalStock  = floatval($s->total_unidades ?? 0);
                $capacidad   = floatval($u->capacidad_maxima ?? 0);
                $pctOcupacion = $capacidad > 0 ? round(($totalStock / $capacidad) * 100, 2) : 0;

                // Usar campos reales de BD; si son 0 (registros legacy sin migrar), usar cálculo derivado
                $totalCajasDb   = floatval($s->total_cajas_db ?? 0);
                $totalSueltosDb = floatval($s->total_sueltos_db ?? 0);
                $totalCajas = $totalCajasDb > 0
                    ? round($totalCajasDb, 2)
                    : round($cajasLegacyPorUbicacion[$u->id] ?? 0, 2);

                $ultimoMov = $ultimosMovimientos[$u->id]->ultimo_mov ?? null;
                $diasSinMov = "N/A";
                if ($ultimoMov) {
                    $diasSinMov = (int)floor((time() - strtotime($ultimoMov)) / 86400);
                }

                return [
                    'id'                 => $u->id,
                    'ubicacion'          => $u->codigo,
                    'posicion'           => $u->codigo,
                    'zona'               => $u->zona    ?? null,
                    'pasillo'            => $u->pasillo ?? null,
                    'nivel'              => $u->nivel   ?? null,
                    'estado_ubi'         => $u->estado  ?? 'Libre',
                    'tipo'               => $u->tipo_ubicacion ?? 'Almacenamiento',
                    'total_productos'    => $totalStock,
                    'und_total'          => $totalStock,
                    'und_total_label'    => $totalStock . ' UND/TOTAL',
                    'total_cajas'        => $totalCajas,
                    'total_sueltos'      => round($totalSueltosDb, 4),
                    'total_reservado'    => floatval($s->total_reservado ?? 0),
                    'total_refs'         => intval($s->total_refs ?? 0),
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

    public function cargueInicial(Request $req, Response $res): Response
    {
        try {
            $user  = $req->getAttribute('user');
            $roles = ['Admin', 'Supervisor', 'SuperAdmin'];
            if (!in_array($user->rol ?? '', $roles, true)) {
                return $this->error($res, 'No autorizado', 403);
            }

            $empId = $this->getEffectiveEmpresaId($user, $req);
            $sucId = $user->sucursal_id ?? null;

            $body = $req->getParsedBody();
            if (empty($body)) {
                return $this->error($res, 'Body vacío o inválido', 400);
            }

            // Acepta un objeto único o un array de objetos
            $lineas = isset($body[0]) ? $body : [$body];

            $guard     = new InventoryGuard($empId, $sucId, $user->id);
            $procesadas = 0;

            foreach ($lineas as $linea) {
                $productoId  = $linea['producto_id']  ?? null;
                $ubicacionId = $linea['ubicacion_id'] ?? null;

                if (empty($productoId) || empty($ubicacionId)) {
                    return $this->error($res, 'producto_id y ubicacion_id son requeridos', 400);
                }

                $cantCajas = max(0, (int)($linea['cantidad_cajas'] ?? 0));
                $saldos    = max(0.0, (float)($linea['saldos'] ?? 0));
                $lote      = $linea['lote'] ?? null;

                $producto = Producto::where('empresa_id', $empId)->find($productoId);
                if (!$producto) {
                    return $this->error($res, "Producto ID {$productoId} no encontrado", 404);
                }

                $fvencParsed = $this->estandarizarFecha($linea['fecha_vencimiento'] ?? null);

                $expirationCheck = $guard->checkExpirationMandatory($productoId, $fvencParsed);
                if (!$expirationCheck['ok']) {
                    return $this->error($res, "Producto '{$producto->nombre}': " . $expirationCheck['message'], 400);
                }

                $upc      = max(1, (int)($producto->unidades_caja ?? 1));
                $undTotal = ($cantCajas * $upc) + $saldos;

                if ($undTotal <= 0) {
                    return $this->error($res, "Producto '{$producto->nombre}': la cantidad total debe ser mayor a 0", 400);
                }

                // UPSERT en inventarios
                $inventario = Inventario::where('empresa_id',   $empId)
                    ->where('sucursal_id',  $sucId)
                    ->where('producto_id',  $productoId)
                    ->where('ubicacion_id', $ubicacionId)
                    ->where('estado',       'Disponible')
                    ->when($lote !== null, fn($q) => $q->where('lote', $lote))
                    ->first();

                $cantAnterior = 0.0;

                if ($inventario) {
                    $cantAnterior                    = (float)($inventario->cantidad ?? 0);
                    $inventario->cantidad            = $undTotal;
                    $inventario->cantidad_cajas      = $cantCajas;
                    $inventario->saldos              = $saldos;
                    $inventario->fecha_vencimiento   = $fvencParsed;
                    $inventario->save();
                } else {
                    $inventario = Inventario::create([
                        'empresa_id'         => $empId,
                        'sucursal_id'        => $sucId,
                        'producto_id'        => $productoId,
                        'ubicacion_id'       => $ubicacionId,
                        'lote'               => $lote,
                        'fecha_vencimiento'  => $fvencParsed,
                        'cantidad'           => $undTotal,
                        'cantidad_cajas'     => $cantCajas,
                        'saldos'             => $saldos,
                        'cantidad_reservada' => 0,
                        'estado'             => 'Disponible',
                    ]);
                }

                MovimientoInventario::create([
                    'empresa_id'           => $empId,
                    'sucursal_id'          => $sucId,
                    'producto_id'          => $productoId,
                    'ubicacion_destino_id' => $ubicacionId,
                    'tipo_movimiento'      => 'InvInicial',
                    'cantidad'             => $undTotal,
                    'lote'                 => $lote,
                    'fecha_vencimiento'    => $fvencParsed,
                    'referencia_tipo'      => 'cargue_inicial',
                    'auxiliar_id'          => $user->id,
                    'observaciones'        => "Cargue inicial: {$cantCajas} cajas + {$saldos} sueltos = {$undTotal} UND/TOTAL",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                ]);

                $procesadas++;
            }

            $this->audit(
                $user,
                'Inventario',
                'CargueInicial',
                'inventarios',
                null,
                null,
                ['procesadas' => $procesadas],
                "Cargue inicial: {$procesadas} línea(s) procesada(s)"
            );

            return $this->ok($res, ['procesadas' => $procesadas], "Cargue inicial completado: {$procesadas} línea(s) procesada(s)");
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ── POST /api/inventario/cargue-inicial/linea ────────────────────────────
    // Guarda una línea en estado Pendiente (cualquier usuario autorizado)
    public function agregarLineaCargue(Request $req, Response $res): Response
    {
        $user  = $req->getAttribute('user');
        $empId = $this->getEffectiveEmpresaId($user, $req);
        $sucId = $user->sucursal_id;
        $data  = (array)($req->getParsedBody() ?? []);

        $productoId  = (int)($data['producto_id']  ?? 0);
        $ubicCodigo  = trim($data['ubicacion_codigo'] ?? '');
        $cantCajas   = max(0, (int)($data['cantidad_cajas'] ?? 0));
        $saldos      = max(0.0, (float)($data['saldos'] ?? 0));
        $lote        = $data['lote'] ?? null;
        $fvenc       = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);

        if (!$productoId) return $this->error($res, 'producto_id requerido');
        if (!$ubicCodigo) return $this->error($res, 'ubicacion_codigo requerida');

        $producto = Producto::where('empresa_id', $empId)->find($productoId);
        if (!$producto) return $this->error($res, "Producto #{$productoId} no encontrado", 404);

        // Validar fecha vencimiento si el producto lo requiere
        if ($producto->control_vencimientos && empty($fvenc)) {
            return $this->error($res, "El producto \"{$producto->nombre}\" requiere fecha de vencimiento");
        }

        $upc      = max(1, (int)($producto->unidades_caja ?? 1));
        $undTotal = ($cantCajas * $upc) + $saldos;
        $esVaciado = ($undTotal <= 0); // und_total=0 → vaciado de ubicación

        // Resolver ubicacion_id desde el código — acepta con/sin ambiente y sin guiones
        $codNorm = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($ubicCodigo));
        $ubicacion = Capsule::table('ubicaciones')
            ->where('empresa_id', $empId)
            ->where(function($q) use ($sucId) { $q->where('sucursal_id', $sucId)->orWhereNull('sucursal_id'); })
            ->where(function($q) use ($ubicCodigo, $codNorm) {
                $q->where('codigo', $ubicCodigo)
                  ->orWhere('codigo', 'ilike', $ubicCodigo)
                  ->orWhereRaw("REPLACE(REPLACE(UPPER(codigo),'-',''),'/','') = ?", [$codNorm]);
            })
            ->orderByRaw("CASE WHEN codigo = ? THEN 0 WHEN REPLACE(REPLACE(UPPER(codigo),'-',''),'/','') = ? THEN 1 ELSE 2 END", [$ubicCodigo, $codNorm])
            ->first(['id', 'codigo']);

        $ahora = date('Y-m-d H:i:s');
        $id = Capsule::table('cargue_inicial_lineas')->insertGetId([
            'empresa_id'       => $empId,
            'sucursal_id'      => $sucId,
            'producto_id'      => $productoId,
            'ubicacion_id'     => $ubicacion?->id,
            'ubicacion_codigo' => $ubicacion?->codigo ?? $ubicCodigo,
            'lote'             => $lote ?: null,
            'fecha_vencimiento'=> $fvenc,
            'cantidad_cajas'   => $cantCajas,
            'saldos'           => $saldos,
            'und_total'        => $undTotal,
            'estado'           => 'Pendiente',
            'creado_por'       => $user->id,
            'created_at'       => $ahora,
            'updated_at'       => $ahora,
        ]);

        $msg = $esVaciado ? 'Línea de vaciado agregada a pendientes' : 'Línea agregada a pendientes';
        return $this->ok($res, [
            'id'               => $id,
            'producto'         => $producto->nombre,
            'ubicacion_codigo' => $ubicacion?->codigo ?? $ubicCodigo,
            'und_total'        => $undTotal,
            'es_vaciado'       => $esVaciado,
            'advertencia'      => $ubicacion ? null : "Ubicación \"{$ubicCodigo}\" no encontrada en el sistema — verifique el código",
        ], $msg);
    }

    // ── GET /api/inventario/cargue-inicial/pendientes ────────────────────────
    public function getLineasPendientes(Request $req, Response $res): Response
    {
        $user  = $req->getAttribute('user');
        $empId = $this->getEffectiveEmpresaId($user, $req);
        $sucId = $user->sucursal_id;
        $params = $req->getQueryParams();
        $estado = $params['estado'] ?? 'Pendiente';

        $soloMio = !empty($params['mio']) && $params['mio'] == '1';

        $q = Capsule::table('cargue_inicial_lineas as c')
            ->join('productos as p', 'p.id', '=', 'c.producto_id')
            ->leftJoin('ubicaciones as u', 'u.id', '=', 'c.ubicacion_id')
            ->leftJoin('users as usr', 'usr.id', '=', 'c.creado_por')
            ->where('c.empresa_id', $empId)
            ->where('c.sucursal_id', $sucId)
            ->where('c.estado', $estado)
            ->select(
                'c.id', 'c.producto_id', 'c.ubicacion_id', 'c.ubicacion_codigo',
                'c.lote', 'c.fecha_vencimiento', 'c.cantidad_cajas', 'c.saldos',
                'c.und_total', 'c.estado', 'c.creado_por', 'c.created_at',
                'p.nombre as producto', 'p.codigo_interno as codigo', 'p.unidades_caja',
                'u.codigo as ubicacion_validada',
                Capsule::raw("COALESCE(usr.nombre, 'Usuario #' || c.creado_por::text) as nombre_usuario")
            );

        if ($soloMio) {
            $q->where('c.creado_por', $user->id);
        }

        $lineas = $q->orderBy('c.created_at', 'desc')->get();

        return $this->ok($res, ['lineas' => $lineas, 'total' => $lineas->count()]);
    }

    // ── POST /api/inventario/cargue-inicial/{id}/aprobar ─────────────────────
    // Solo Admin/Supervisor — crea el inventario real y el movimiento InvInicial
    public function aprobarLineaCargue(Request $req, Response $res, array $args): Response
    {
        $user  = $req->getAttribute('user');
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin'], true)) {
            return $this->error($res, 'Se requiere rol Admin o Supervisor', 403);
        }
        $empId = $this->getEffectiveEmpresaId($user, $req);
        $sucId = $user->sucursal_id;
        $id    = (int)($args['id'] ?? 0);

        $linea = Capsule::table('cargue_inicial_lineas')
            ->where('id', $id)->where('empresa_id', $empId)->where('estado', 'Pendiente')->first();
        if (!$linea) return $this->error($res, 'Línea no encontrada o ya procesada', 404);

        if (!$linea->ubicacion_id) {
            return $this->error($res, "La ubicación \"{$linea->ubicacion_codigo}\" no está registrada en el sistema. Créela primero en Maestros.");
        }

        try {
            $ahora     = date('Y-m-d H:i:s');
            $undTotal  = (float)$linea->und_total;
            $cantCajas = (int)$linea->cantidad_cajas;
            $saldos    = (float)$linea->saldos;
            $esVaciado = ($undTotal <= 0);

            if ($esVaciado) {
                // Vaciado: anular todo el inventario del producto en esa ubicación
                $registros = Inventario::where('empresa_id',  $empId)
                    ->where('sucursal_id',  $sucId)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->get();

                foreach ($registros as $inv) {
                    $cantPrev = (float)$inv->cantidad;
                    if ($cantPrev <= 0) continue;
                    $inv->cantidad = 0; $inv->cantidad_cajas = 0; $inv->saldos = 0;
                    $inv->updated_at = $ahora; $inv->save();

                    MovimientoInventario::create([
                        'empresa_id'          => $empId,
                        'sucursal_id'         => $sucId,
                        'producto_id'         => $linea->producto_id,
                        'ubicacion_origen_id' => $linea->ubicacion_id,
                        'tipo_movimiento'     => 'AjusteNegativo',
                        'cantidad'            => $cantPrev,
                        'lote'                => $inv->lote,
                        'fecha_vencimiento'   => $inv->fecha_vencimiento,
                        'referencia_tipo'     => 'vaciado_ubicacion',
                        'auxiliar_id'         => $user->id,
                        'observaciones'       => "Vaciado de ubicación (cargue inicial): -{$cantPrev} und",
                        'fecha_movimiento'    => date('Y-m-d'),
                        'hora_inicio'         => date('H:i:s'),
                    ]);
                }
            } else {
                // Flujo normal: UPSERT en inventarios
                $inv = Inventario::where('empresa_id',  $empId)
                    ->where('sucursal_id',  $sucId)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->first();

                if ($inv) {
                    $inv->cantidad = $undTotal; $inv->cantidad_cajas = $cantCajas;
                    $inv->saldos = $saldos; $inv->fecha_vencimiento = $linea->fecha_vencimiento;
                    $inv->updated_at = $ahora; $inv->save();
                } else {
                    Inventario::create([
                        'empresa_id'         => $empId, 'sucursal_id'        => $sucId,
                        'producto_id'        => $linea->producto_id, 'ubicacion_id' => $linea->ubicacion_id,
                        'lote'               => $linea->lote, 'fecha_vencimiento' => $linea->fecha_vencimiento,
                        'cantidad'           => $undTotal, 'cantidad_cajas'     => $cantCajas,
                        'saldos'             => $saldos, 'cantidad_reservada'  => 0, 'estado' => 'Disponible',
                    ]);
                }

                MovimientoInventario::create([
                    'empresa_id'           => $empId, 'sucursal_id'         => $sucId,
                    'producto_id'          => $linea->producto_id, 'ubicacion_destino_id' => $linea->ubicacion_id,
                    'tipo_movimiento'      => 'InvInicial', 'cantidad'             => $undTotal,
                    'lote'                 => $linea->lote, 'fecha_vencimiento'    => $linea->fecha_vencimiento,
                    'referencia_tipo'      => 'cargue_inicial', 'auxiliar_id'          => $user->id,
                    'observaciones'        => "Inv inicial aprobado: {$cantCajas} cajas + {$saldos} sueltos = {$undTotal} UND/TOTAL",
                    'fecha_movimiento'     => date('Y-m-d'), 'hora_inicio'          => date('H:i:s'),
                ]);
            }

            // Marcar línea como Aprobada
            Capsule::table('cargue_inicial_lineas')->where('id', $id)->update([
                'estado'      => 'Aprobado',
                'aprobado_por'=> $user->id,
                'aprobado_at' => $ahora,
                'updated_at'  => $ahora,
            ]);

            $msg = $esVaciado ? 'Ubicación vaciada y ajuste registrado' : 'Línea aprobada e inventario actualizado';
            return $this->ok($res, ['id' => $id, 'es_vaciado' => $esVaciado], $msg);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ── POST /api/inventario/cargue-inicial/aprobar-todo ─────────────────────
    public function aprobarTodoCargue(Request $req, Response $res): Response
    {
        $user  = $req->getAttribute('user');
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin'], true)) {
            return $this->error($res, 'Se requiere rol Admin o Supervisor', 403);
        }
        $empId = $this->getEffectiveEmpresaId($user, $req);
        $sucId = $user->sucursal_id;

        $lineas = Capsule::table('cargue_inicial_lineas')
            ->where('empresa_id', $empId)->where('sucursal_id', $sucId)
            ->where('estado', 'Pendiente')->get();

        if ($lineas->isEmpty()) return $this->error($res, 'No hay líneas pendientes');

        $aprobadas = 0; $errores = [];
        foreach ($lineas as $linea) {
            // Crear request fake para reutilizar aprobarLineaCargue
            if (!$linea->ubicacion_id) {
                $errores[] = "Línea #{$linea->id} ({$linea->ubicacion_codigo}): ubicación sin ID registrado";
                continue;
            }
            try {
                $ahora     = date('Y-m-d H:i:s');
                $undTotal  = (float)$linea->und_total;
                $cantCajas = (int)$linea->cantidad_cajas;
                $saldos    = (float)$linea->saldos;
                $esVaciado = ($undTotal <= 0);

                if ($esVaciado) {
                    $registros = Inventario::where('empresa_id', $empId)->where('sucursal_id', $sucId)
                        ->where('producto_id', $linea->producto_id)->where('ubicacion_id', $linea->ubicacion_id)
                        ->where('estado', 'Disponible')
                        ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))->get();
                    foreach ($registros as $inv) {
                        $cantPrev = (float)$inv->cantidad;
                        if ($cantPrev <= 0) continue;
                        $inv->cantidad = 0; $inv->cantidad_cajas = 0; $inv->saldos = 0;
                        $inv->updated_at = $ahora; $inv->save();
                        MovimientoInventario::create([
                            'empresa_id' => $empId, 'sucursal_id' => $sucId,
                            'producto_id' => $linea->producto_id, 'ubicacion_origen_id' => $linea->ubicacion_id,
                            'tipo_movimiento' => 'AjusteNegativo', 'cantidad' => $cantPrev,
                            'lote' => $inv->lote, 'fecha_vencimiento' => $inv->fecha_vencimiento,
                            'referencia_tipo' => 'vaciado_ubicacion', 'auxiliar_id' => $user->id,
                            'observaciones' => "Vaciado de ubicación (cargue inicial): -{$cantPrev} und",
                            'fecha_movimiento' => date('Y-m-d'), 'hora_inicio' => date('H:i:s'),
                        ]);
                    }
                } else {
                $inv = Inventario::where('empresa_id',  $empId)
                    ->where('sucursal_id',  $sucId)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->first();

                if ($inv) {
                    $inv->cantidad = $undTotal; $inv->cantidad_cajas = $cantCajas;
                    $inv->saldos = $saldos; $inv->fecha_vencimiento = $linea->fecha_vencimiento;
                    $inv->updated_at = $ahora; $inv->save();
                } else {
                    Inventario::create([
                        'empresa_id' => $empId, 'sucursal_id' => $sucId,
                        'producto_id' => $linea->producto_id, 'ubicacion_id' => $linea->ubicacion_id,
                        'lote' => $linea->lote, 'fecha_vencimiento' => $linea->fecha_vencimiento,
                        'cantidad' => $undTotal, 'cantidad_cajas' => $cantCajas,
                        'saldos' => $saldos, 'cantidad_reservada' => 0, 'estado' => 'Disponible',
                    ]);
                }
                    MovimientoInventario::create([
                        'empresa_id' => $empId, 'sucursal_id' => $sucId,
                        'producto_id' => $linea->producto_id, 'ubicacion_destino_id' => $linea->ubicacion_id,
                        'tipo_movimiento' => 'InvInicial', 'cantidad' => $undTotal,
                        'lote' => $linea->lote, 'fecha_vencimiento' => $linea->fecha_vencimiento,
                        'referencia_tipo' => 'cargue_inicial', 'auxiliar_id' => $user->id,
                        'observaciones' => "Inv inicial: {$cantCajas} cajas + {$saldos} sueltos = {$undTotal} UND/TOTAL",
                        'fecha_movimiento' => date('Y-m-d'), 'hora_inicio' => date('H:i:s'),
                    ]);
                } // fin else no-vaciado
                Capsule::table('cargue_inicial_lineas')->where('id', $linea->id)->update([
                    'estado' => 'Aprobado', 'aprobado_por' => $user->id, 'aprobado_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $aprobadas++;
            } catch (\Throwable $e) {
                $errores[] = "Línea #{$linea->id}: " . $e->getMessage();
            }
        }

        $this->audit($user, 'Inventario', 'AprobarTodoCargue', 'cargue_inicial_lineas', null,
            null, ['aprobadas' => $aprobadas, 'errores' => count($errores)],
            "Aprobación masiva cargue inicial: {$aprobadas} aprobadas");

        return $this->ok($res, ['aprobadas' => $aprobadas, 'errores' => $errores],
            "{$aprobadas} líneas aprobadas" . (count($errores) ? ' con ' . count($errores) . ' error(es)' : ''));
    }

    // ── POST /api/inventario/vaciar-ubicacion ────────────────────────────────
    // Zeroes all inventory in a location and registers AjusteNegativo movements
    public function vaciarUbicacion(Request $req, Response $res): Response
    {
        $user  = $req->getAttribute('user');
        $empId = $this->getEffectiveEmpresaId($user, $req);
        $sucId = $this->getEffectiveSucursalId($user, $req);
        $data  = (array)($req->getParsedBody() ?? []);

        // Resolver ubicación por ID o código
        $ubicacionId = (int)($data['ubicacion_id'] ?? 0);
        if (!$ubicacionId && !empty($data['ubicacion_codigo'])) {
            $u = Capsule::table('ubicaciones')
                ->where('empresa_id', $empId)
                ->where(function($q) use ($sucId) { $q->where('sucursal_id', $sucId)->orWhereNull('sucursal_id'); })
                ->where('codigo', 'ilike', trim($data['ubicacion_codigo']))
                ->first(['id', 'codigo']);
            $ubicacionId = $u?->id ?? 0;
        }
        if (!$ubicacionId) return $this->error($res, 'Ubicación no encontrada', 404);

        $ahora     = date('Y-m-d H:i:s');
        $registros = Inventario::where('empresa_id',  $empId)
            ->where('sucursal_id',  $sucId)
            ->where('ubicacion_id', $ubicacionId)
            ->where('estado',       'Disponible')
            ->where('cantidad',     '>', 0)
            ->get();

        if ($registros->isEmpty()) {
            return $this->ok($res, ['ajustados' => 0], 'La ubicación ya está vacía — no había inventario');
        }

        $ajustados = 0;
        foreach ($registros as $inv) {
            $cantPrev = (float)$inv->cantidad;
            $inv->cantidad = 0; $inv->cantidad_cajas = 0; $inv->saldos = 0;
            $inv->updated_at = $ahora; $inv->save();

            MovimientoInventario::create([
                'empresa_id'          => $empId,
                'sucursal_id'         => $sucId,
                'producto_id'         => $inv->producto_id,
                'ubicacion_origen_id' => $ubicacionId,
                'tipo_movimiento'     => 'AjusteNegativo',
                'cantidad'            => $cantPrev,
                'lote'                => $inv->lote,
                'fecha_vencimiento'   => $inv->fecha_vencimiento,
                'referencia_tipo'     => 'vaciado_ubicacion',
                'auxiliar_id'         => $user->id,
                'observaciones'       => "Ubicación vaciada manualmente: -{$cantPrev} und",
                'fecha_movimiento'    => date('Y-m-d'),
                'hora_inicio'         => date('H:i:s'),
            ]);
            $ajustados++;
        }

        $this->audit($user, 'Inventario', 'VaciarUbicacion', 'inventarios', $ubicacionId,
            null, ['ajustados' => $ajustados],
            "Vaciado de ubicación ID={$ubicacionId}: {$ajustados} registro(s) en cero");

        return $this->ok($res, ['ajustados' => $ajustados, 'ubicacion_id' => $ubicacionId],
            "Ubicación vaciada: {$ajustados} registro(s) ajustado(s) a cero");
    }

    // ── DELETE /api/inventario/cargue-inicial/{id} ───────────────────────────
    public function eliminarLineaCargue(Request $req, Response $res, array $args): Response
    {
        $user  = $req->getAttribute('user');
        $empId = $this->getEffectiveEmpresaId($user, $req);
        $id    = (int)($args['id'] ?? 0);

        $linea = Capsule::table('cargue_inicial_lineas')
            ->where('id', $id)->where('empresa_id', $empId)->where('estado', 'Pendiente')->first();
        if (!$linea) return $this->error($res, 'Línea no encontrada o ya procesada', 404);

        // Solo el creador o admin puede eliminar
        $isAdmin = in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin'], true);
        if (!$isAdmin && $linea->creado_por != $user->id) {
            return $this->error($res, 'Solo el creador o un Admin puede eliminar esta línea', 403);
        }

        Capsule::table('cargue_inicial_lineas')->where('id', $id)->delete();
        return $this->ok($res, null, 'Línea eliminada');
    }

    // ── GET /api/inventario/cargue-inicial ───────────────────────────────────
    // Devuelve el kardex filtrado por tipo InvInicial (historial de cargues iniciales)
    public function getCargueInicialKardex(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $empId  = $this->getEffectiveEmpresaId($user, $req);
        $sucId  = $user->sucursal_id;
        $params = $req->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 200), 500);

        try {
            $q = Capsule::table('movimiento_inventarios as m')
                ->join('productos as p', 'p.id', '=', 'm.producto_id')
                ->leftJoin('ubicaciones as u', 'u.id', '=', 'm.ubicacion_destino_id')
                ->where('m.empresa_id',      $empId)
                ->where('m.sucursal_id',     $sucId)
                ->where('m.tipo_movimiento', 'InvInicial')
                ->select(
                    'm.id', 'm.producto_id', 'm.ubicacion_destino_id as ubicacion_id',
                    'm.cantidad', 'm.lote', 'm.fecha_vencimiento',
                    'm.observaciones', 'm.fecha_movimiento as fecha',
                    'p.nombre as producto', 'p.codigo_interno as codigo',
                    'u.codigo as ubicacion'
                )
                ->orderBy('m.fecha_movimiento', 'desc')
                ->orderBy('m.id', 'desc')
                ->limit($limit)
                ->offset((int)($params['offset'] ?? 0));

            $total  = (clone $q)->count();
            $lineas = $q->get();

            return $this->ok($res, [
                'lineas' => $lineas,
                'total'  => $total,
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ── POST /api/inventario/reconciliar ────────────────────────────────────
    /**
     * Reconciliación de integridad del inventario. Solo Admin.
     *
     * Acción 1: Elimina registros muertos (cantidad <= 0 Y cantidad_reservada <= 0).
     *           Estos son restos de picking que no se borraron correctamente.
     * Acción 2: Corrige cantidad_reservada > cantidad (reserva mayor que el stock
     *           físico existente) → ajusta cantidad_reservada = cantidad.
     * Acción 3: Registra un log de auditoría con los conteos afectados.
     */
    public function reconciliar(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($user->rol !== 'Admin') {
            return $this->error($res, 'Solo el administrador puede ejecutar la reconciliación.', 403);
        }

        $empresaId  = $this->getEffectiveEmpresaId($user, $req);
        $sucursalId = $user->sucursal_id;

        try {
            $resultado = Capsule::transaction(function () use ($empresaId, $sucursalId) {
                // ── Acción 1: Eliminar registros muertos ──────────────────────
                // Un registro muerto tiene cantidad <= 0 Y cantidad_reservada <= 0.
                // No aporta stock real y contamina las vistas de inventario.
                $muertos = Capsule::table('inventarios')
                    ->where('empresa_id',  $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->whereRaw('COALESCE(cantidad, 0) <= 0')
                    ->whereRaw('COALESCE(cantidad_reservada, 0) <= 0')
                    ->get(['id', 'producto_id', 'ubicacion_id', 'lote', 'cantidad', 'cantidad_reservada']);

                $idsMuertos = $muertos->pluck('id')->all();
                $eliminados = 0;
                if (!empty($idsMuertos)) {
                    $eliminados = Capsule::table('inventarios')
                        ->whereIn('id', $idsMuertos)
                        ->delete();
                }

                // ── Acción 2: Corregir cantidad_reservada > cantidad ──────────
                // Esto ocurre cuando un picking libera stock pero no libera la
                // reserva correctamente (race condition o bug previo).
                $corruptos = Capsule::table('inventarios')
                    ->where('empresa_id',  $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->whereRaw('COALESCE(cantidad_reservada, 0) > COALESCE(cantidad, 0)')
                    ->lockForUpdate()
                    ->get(['id', 'producto_id', 'ubicacion_id', 'lote', 'cantidad', 'cantidad_reservada']);

                $corregidos = 0;
                foreach ($corruptos as $row) {
                    Capsule::table('inventarios')
                        ->where('id', $row->id)
                        ->update([
                            'cantidad_reservada' => max(0, (float)$row->cantidad),
                            'updated_at'         => date('Y-m-d H:i:s'),
                        ]);
                    $corregidos++;
                }

                return [
                    'eliminados'  => $eliminados,
                    'corregidos'  => $corregidos,
                    'detalle_muertos' => $muertos->map(fn($r) => [
                        'id'          => $r->id,
                        'producto_id' => $r->producto_id,
                        'ubicacion_id'=> $r->ubicacion_id,
                        'lote'        => $r->lote,
                        'cantidad'    => $r->cantidad,
                        'reservada'   => $r->cantidad_reservada,
                    ])->values()->all(),
                    'detalle_corregidos' => collect($corruptos)->map(fn($r) => [
                        'id'                     => $r->id,
                        'producto_id'            => $r->producto_id,
                        'ubicacion_id'           => $r->ubicacion_id,
                        'lote'                   => $r->lote,
                        'cantidad'               => $r->cantidad,
                        'cantidad_reservada_vieja' => $r->cantidad_reservada,
                        'cantidad_reservada_nueva' => max(0, (float)$r->cantidad),
                    ])->values()->all(),
                ];
            });

            // ── Log de auditoría ──────────────────────────────────────────────
            $msg = "Reconciliación inventario — eliminados: {$resultado['eliminados']}, corregidos: {$resultado['corregidos']}";
            error_log("[RECONCILIACION] empresa={$empresaId} sucursal={$sucursalId} usuario={$user->id} — {$msg}");
            $this->audit($user, 'inventario', 'reconciliar', 'inventarios', null, null, [
                'eliminados' => $resultado['eliminados'],
                'corregidos' => $resultado['corregidos'],
            ], $msg);

            return $this->ok($res, $resultado, $msg);
        } catch (\Throwable $e) {
            error_log('[RECONCILIACION ERROR] ' . $e->getMessage());
            return $this->error($res, 'Error en reconciliación: ' . $e->getMessage(), 500);
        }
    }
}
