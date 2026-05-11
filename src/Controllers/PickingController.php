<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenPicking;
use App\Models\PickingDetalle;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\TareaReabastecimiento;
use App\Helpers\InventoryGuard;
use App\Helpers\FefoEngine;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * PickingController — FEFO estricto + auditoría completa.
 *
 * Flujo:
 *  1. crearBatch → genera OrdenPicking con detalles
 *  2. generateRoute → asigna ubicaciones FEFO a cada línea
 *  3. confirmLine → auxiliar confirma que tomó la cantidad
 *  4. completar → cierra la orden y descuenta inventario
 */
class PickingController extends BaseController
{
    // ── GET /api/picking ──────────────────────────────────────────────────────
    // Parámetros de filtro: estado, auxiliar_id, pasillo, marca_id, ubicacion,
    //                       planilla, sin_auxiliar, fecha_inicio, fecha_fin, limit,
    //                       solo_hoy, incluir_finalizados, sucursal_entrega, ruta, q
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $limit = min((int)($params['limit'] ?? 100), 500);

        $soloHoy           = !empty($params['solo_hoy']);
        $incluirFinalizados = !empty($params['incluir_finalizados']);

        $fechaDesdeFilter = $params['fecha_desde'] ?? null;
        $fechaHastaFilter = $params['fecha_hasta'] ?? null;

        $q = OrdenPicking::where('orden_pickings.empresa_id', $user->empresa_id)
            ->where('orden_pickings.sucursal_id', $user->sucursal_id)
            ->when($fechaDesdeFilter && $fechaHastaFilter, fn($q) =>
                $q->whereDate('orden_pickings.fecha_movimiento', '>=', $fechaDesdeFilter)
                  ->whereDate('orden_pickings.fecha_movimiento', '<=', $fechaHastaFilter))
            ->when(!$soloHoy && !($fechaDesdeFilter && $fechaHastaFilter),
                fn($q) => $q->whereBetween('orden_pickings.created_at', [$ini, $fin]))
            ->when($soloHoy && !($fechaDesdeFilter && $fechaHastaFilter),
                fn($q) => $q->whereDate('orden_pickings.fecha_movimiento', date('Y-m-d')))
            ->when($params['estado'] ?? null, function($q, $e) {
                if (strpos($e, ',') !== false) {
                    $q->whereIn('estado', explode(',', $e));
                } else {
                    $q->where('estado', $e);
                }
            })
            ->when($soloHoy && !($fechaDesdeFilter && $fechaHastaFilter) && !isset($params['estado']) && !$incluirFinalizados,
                fn($q) => $q->whereIn('orden_pickings.estado', ['Pendiente', 'EnProceso']))
            ->when($params['auxiliar_id']  ?? null, fn($q, $v) => $q->where('auxiliar_id', (int)$v))
            ->when($params['sin_auxiliar'] ?? null, fn($q)     => $q->whereNull('orden_pickings.auxiliar_id'))
            ->when($params['cliente']      ?? null, fn($q, $v) => $q->where('cliente', 'like', "%$v%"))
            ->when($params['sucursal_entrega'] ?? null, function($q, $v) {
                $q->where(fn($sq) =>
                    $sq->where('orden_pickings.sucursal_entrega', 'like', "%$v%")
                       ->orWhere(fn($sq2) =>
                           $sq2->whereNull('orden_pickings.sucursal_entrega')
                               ->where('orden_pickings.cliente', 'like', "%$v%")
                       )
                );
            })
            ->when($params['ruta'] ?? null, fn($q, $v) => $q->where('orden_pickings.ruta', 'like', "%$v%"))
            ->when($params['q'] ?? null, function($q, $v) {
                $q->where(fn($sq) => $sq
                    ->where('orden_pickings.numero_pedido', 'like', "%$v%")
                    ->orWhere('orden_pickings.cliente', 'like', "%$v%")
                    ->orWhere('orden_pickings.sucursal_entrega', 'like', "%$v%")
                    ->orWhere('orden_pickings.ruta', 'like', "%$v%")
                );
            });

        // Filtro específico para versión móvil: mostrar tareas del usuario conectado
        if (!empty($params['tiene_asignadas'])) {
            $q->where(fn($sq) => $sq
                ->where('orden_pickings.auxiliar_id', $user->id)
                ->orWhereHas('detalles', fn($dq) => $dq->where('auxiliar_id', $user->id))
            );
        }

        // Filtro por pasillo: requiere JOIN a detalles → ubicaciones
        if (!empty($params['pasillo'])) {
            $pasillo = $params['pasillo'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
                ->where(fn($sq) => $sq
                    ->where('ubicaciones.pasillo', $pasillo)
                    ->orWhere('ubicaciones.codigo', 'like', "$pasillo%")
                )
            );
        }

        // Filtro por marca
        if (!empty($params['marca_id'])) {
            $marcaId = (int)$params['marca_id'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->where('productos.marca_id', $marcaId)
            );
        }

        // Filtro por ubicación (código)
        if (!empty($params['ubicacion'])) {
            $ubic = $params['ubicacion'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones as ub2', 'picking_detalles.ubicacion_id', '=', 'ub2.id')
                ->where('ub2.codigo', 'like', "%$ubic%")
            );
        }

        // Filtro por número de planilla o ruta
        if (!empty($params['planilla'])) {
            $planilla = $params['planilla'];
            $q->where(fn($sq) => $sq
                ->where('orden_pickings.planilla_numero', $planilla)
                ->orWhere('orden_pickings.area_comercial', 'like', "%$planilla%")
                ->orWhere('orden_pickings.cliente', 'like', "%$planilla%")
            );
        }

        $ordenes = $q->with(['auxiliar:id,nombre', 'detalles.producto:id,nombre,codigo_interno,unidades_caja', 'detalles.auxiliar:id,nombre'])
            ->withCount([
                'detalles as seco_count'        => fn($q) => $q->where('ambiente', 'Seco'),
                'detalles as refrigerado_count' => fn($q) => $q->where('ambiente', 'Refrigerado'),
                'detalles as congelado_count'   => fn($q) => $q->where('ambiente', 'Congelado'),
                'detalles as total_count',
            ])
            ->orderByRaw('ISNULL(orden_pickings.sucursal_entrega) ASC, orden_pickings.sucursal_entrega ASC')
            ->orderBy('orden_pickings.prioridad')
            ->orderBy('orden_pickings.created_at', 'desc')
            ->limit($limit)
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# Orden', 'Cliente', 'Estado', 'Prioridad', 'Auxiliar', 'F.Requerida', 'Planilla'];
            $rows = $ordenes->map(fn($o) => [
                $o->numero_orden, $o->cliente ?? '—', $o->estado,
                $o->prioridad, $o->auxiliar->nombre ?? '—',
                $o->fecha_requerida ?? '—',
                $o->planilla_numero ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'picking_' . date('Y-m-d'));
        }

        return $this->ok($res, $ordenes);
    }

    // ── GET /api/picking/consolidados ─────────────────────────────────────────
    // Agrupa órdenes Pendientes/EnProceso por cliente para picking consolidado
    public function consolidados(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->with(['detalles.producto', 'detalles.auxiliar:id,nombre', 'auxiliar:id,nombre'])
            ->orderBy('prioridad')
            ->orderBy('created_at', 'desc')
            ->get();

        // Agrupar por cliente
        $grupos = [];
        foreach ($ordenes as $o) {
            $key = $o->cliente ?? 'Sin Cliente';
            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'cliente'          => $key,
                    'total_ordenes'    => 0,
                    'ordenes_pendientes' => 0,
                    'ordenes_en_proceso' => 0,
                    'prioridad_max'    => 9,
                    'productos_unicos' => [],
                    'ordenes'          => [],
                ];
            }
            $grupos[$key]['total_ordenes']++;
            if ($o->estado === 'Pendiente')  $grupos[$key]['ordenes_pendientes']++;
            if ($o->estado === 'EnProceso')  $grupos[$key]['ordenes_en_proceso']++;
            $grupos[$key]['prioridad_max'] = min($grupos[$key]['prioridad_max'], $o->prioridad ?? 9);
            foreach ($o->detalles as $d) {
                $grupos[$key]['productos_unicos'][$d->producto_id] = true;
            }
            $grupos[$key]['ordenes'][] = [
                'id'            => $o->id,
                'numero_orden'  => $o->numero_orden,
                'estado'        => $o->estado,
                'prioridad'     => $o->prioridad,
                'auxiliar'      => $o->auxiliar->nombre ?? null,
                'auxiliar_id'   => $o->auxiliar_id,
                'fecha_requerida' => $o->fecha_requerida,
                'total_lineas'  => count($o->detalles),
            ];
        }

        // Transformar para respuesta
        $result = array_values(array_map(function ($g) {
            $g['total_productos_unicos'] = count($g['productos_unicos']);
            unset($g['productos_unicos']);
            return $g;
        }, $grupos));

        // Ordenar por prioridad_max
        usort($result, fn($a, $b) => $a['prioridad_max'] <=> $b['prioridad_max']);

        return $this->ok($res, $result);
    }

    // ── POST /api/picking/asignar-ruta ───────────────────────────────────────
    // Assign a route name to all orders matching given IDs (planilla batch)
    public function asignarRuta(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $ordenIds = $data['orden_ids'] ?? [];
        $ruta     = trim($data['ruta'] ?? '');

        if (empty($ordenIds) || empty($ruta)) {
            return $this->error($res, 'Seleccione órdenes y proporcione un nombre de ruta');
        }

        $updated = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->whereIn('id', $ordenIds)
            ->update(['area_comercial' => $ruta]);

        return $this->ok($res, [
            'message'  => "Ruta '{$ruta}' asignada a {$updated} orden(es)",
            'updated'  => $updated,
        ]);
    }

    // ── POST /api/picking/asignar-multiple ────────────────────────────────────
    // Asigna auxiliar, genera rutas FEFO, RESERVA inventario (cantidad_reservada)
    // y registra faltantes automáticamente. El descuento real del stock solo
    // ocurre cuando el auxiliar confirma la línea (confirmLine).
    public function asignarMultiple(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $ordenIds    = array_map('intval', $data['orden_ids']    ?? []);
        // Soporta tanto personal_id (frontend) como auxiliar_id (legacy/api)
        $auxiliarId  = isset($data['personal_id']) ? (int)$data['personal_id'] : (isset($data['auxiliar_id']) ? (int)$data['auxiliar_id'] : null);
        $generarRuta = (bool)($data['generar_ruta'] ?? false);
        $separarConsolidado = (bool)($data['separar_consolidado'] ?? false);

        if (empty($ordenIds)) {
            return $this->error($res, 'Se requiere al menos una orden');
        }

        $ordenes = OrdenPicking::whereIn('id', $ordenIds)
            ->where('empresa_id', $user->empresa_id)
            ->get();

        $resultados = [
            'asignadas'        => 0,
            'rutas_generadas'  => 0,
            'inventario_reservado' => 0,
            'faltantes_detectados' => 0,
            'errores'          => [],
        ];

        $now = date('Y-m-d H:i:s');

        /** @var OrdenPicking $orden */
        foreach ($ordenes as $orden) {
            try {
                Capsule::transaction(function () use ($orden, $auxiliarId, $separarConsolidado, $user, $now, &$resultados, $generarRuta) {
                    // ── 1. Asignar auxiliar ──────────────────────────────────────
                    if ($auxiliarId !== null) {
                        $orden->auxiliar_id = $auxiliarId;
                        if ($separarConsolidado) {
                            $orden->tipo_picking = 'Consolidado Almacenamiento';
                        }
                        $orden->save();

                        // Sincronizar auxiliar en todas las líneas de la orden
                        Capsule::table('picking_detalles')
                            ->where('orden_picking_id', $orden->id)
                            ->update(['auxiliar_id' => $auxiliarId, 'updated_at' => $now]);

                        $resultados['asignadas']++;
                    }

                    // ── 2. Reservar inventario (compromiso atómico) ──────────────
                    // Cargamos todos los detalles pendientes de la orden
                    $detalles = PickingDetalle::where('orden_picking_id', $orden->id)
                        ->whereIn('estado', ['Pendiente', 'Creado'])
                        ->get();

                    $productoIds = $detalles->pluck('producto_id')->unique()->toArray();

                    // Pre-carga batch de inventario disponible con lock pesimista
                    $stockDisponible = Inventario::where('empresa_id', $user->empresa_id)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->whereIn('producto_id', $productoIds)
                        ->where('estado', 'Disponible')
                        ->whereRaw('(cantidad - cantidad_reservada) > 0')
                        ->lockForUpdate()
                        ->orderByRaw('fecha_vencimiento IS NULL ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->get();

                    $stockPorProducto = $stockDisponible->groupBy('producto_id');

                    foreach ($detalles as $linea) {
                        $cantidadNecesaria = $linea->cantidad_solicitada;
                        $stockProducto = $stockPorProducto->get($linea->producto_id, collect());

                        // Calcular disponible real (cantidad - reservada)
                        $totalDisponible = $stockProducto->sum(fn($inv) => max(0, $inv->cantidad - $inv->cantidad_reservada));

                        if ($totalDisponible >= $cantidadNecesaria) {
                            // ── Caso A: Hay stock suficiente → RESERVAR (no descontar) ──
                            $restante = $cantidadNecesaria;
                            foreach ($stockProducto as $inv) {
                                if ($restante <= 0) break;
                                $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                                if ($disp <= 0) continue;

                                $aReservar = min($disp, $restante);
                                $inv->cantidad_reservada += $aReservar;
                                $inv->save();
                                $restante -= $aReservar;

                                // Asignar la primera ubicación FEFO disponible a la línea
                                if (!$linea->ubicacion_id) {
                                    $linea->ubicacion_id      = $inv->ubicacion_id;
                                    $linea->lote              = $inv->lote;
                                    $linea->fecha_vencimiento = $inv->fecha_vencimiento;
                                }
                            }

                            $linea->estado = 'EnProceso';
                            $linea->save();
                            $resultados['inventario_reservado'] += $cantidadNecesaria;
                        } else {
                            // ── Caso B: Stock insuficiente → Reservar parcial + Faltante ──
                            $faltante = $cantidadNecesaria - $totalDisponible;

                            // Reservar lo que haya disponible
                            if ($totalDisponible > 0) {
                                $restante = $totalDisponible;
                                foreach ($stockProducto as $inv) {
                                    if ($restante <= 0) break;
                                    $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                                    if ($disp <= 0) continue;

                                    $aReservar = min($disp, $restante);
                                    $inv->cantidad_reservada += $aReservar;
                                    $inv->save();
                                    $restante -= $aReservar;

                                    if (!$linea->ubicacion_id) {
                                        $linea->ubicacion_id      = $inv->ubicacion_id;
                                        $linea->lote              = $inv->lote;
                                        $linea->fecha_vencimiento = $inv->fecha_vencimiento;
                                    }
                                }
                                $resultados['inventario_reservado'] += (int)$totalDisponible;
                            }

                            // Registrar faltante automáticamente
                            Capsule::table('picking_faltantes')->insert([
                                'empresa_id'          => $user->empresa_id,
                                'sucursal_id'         => $user->sucursal_id,
                                'orden_picking_id'    => $orden->id,
                                'producto_id'         => $linea->producto_id,
                                'planilla_lote'       => $orden->planilla_lote ?? $orden->planilla_numero,
                                'cantidad_solicitada' => $cantidadNecesaria,
                                'cantidad_faltante'   => $faltante,
                                'causa'               => 'Stock insuficiente al asignar planilla - Reserva automática',
                                'created_at'          => $now,
                                'updated_at'          => $now,
                            ]);

                            $linea->estado = 'Faltante';
                            $linea->save();
                            $resultados['faltantes_detectados']++;
                        }
                    }

                    // ── 3. Actualizar estado de la orden ─────────────────────────
                    $lineasEnProceso = PickingDetalle::where('orden_picking_id', $orden->id)
                        ->where('estado', 'EnProceso')->count();
                    $lineasFaltante  = PickingDetalle::where('orden_picking_id', $orden->id)
                        ->where('estado', 'Faltante')->count();

                    if ($lineasEnProceso > 0) {
                        $orden->estado = 'EnProceso';
                    } elseif ($lineasFaltante > 0) {
                        $orden->estado = 'Faltante';
                    }
                    $orden->save();

                    // Generar ruta FEFO adicional si se solicitó explícitamente
                    if ($generarRuta && $orden->estado === 'Pendiente') {
                        $this->_generarRutaFEFO($orden, $user);
                        $resultados['rutas_generadas']++;
                    }
                });
            } catch (\Exception $e) {
                $resultados['errores'][] = "Orden {$orden->numero_orden}: {$e->getMessage()}";
            }
        }

        // Notificar al auxiliar si hubo asignaciones
        if ($auxiliarId !== null && $resultados['asignadas'] > 0) {
            \App\Controllers\NotificacionesController::crear(
                $user->empresa_id,
                $auxiliarId,
                'Nuevas Órdenes de Picking',
                "Se le han asignado {$resultados['asignadas']} órdenes para alistamiento. " .
                "{$resultados['inventario_reservado']} unidades reservadas, " .
                "{$resultados['faltantes_detectados']} faltantes detectados.",
                'picking',
                $user->id,
                'Picking',
                null,
                'viewPicking',
                'Picking',
                true,
                $user->sucursal_id
            );
        }

        // @audit — Registro profesional de la asignación masiva
        $this->audit($user, 'picking', 'asignar_multiple', 'orden_pickings', null,
            null, $resultados,
            "Asignación masiva: {$resultados['asignadas']} órdenes, " .
            "{$resultados['inventario_reservado']} unidades reservadas, " .
            "{$resultados['faltantes_detectados']} faltantes registrados");

        $msg = "{$resultados['asignadas']} asignadas";
        if ($resultados['inventario_reservado'] > 0) $msg .= ", {$resultados['inventario_reservado']} unidades reservadas";
        if ($resultados['faltantes_detectados'] > 0) $msg .= ", {$resultados['faltantes_detectados']} faltantes";

        return $this->ok($res, $resultados, $msg);
    }

    // ── Método privado: lógica FEFO reutilizable ──────────────────────────────
    // OPTIMIZADO: pre-carga todo el inventario de los productos de la orden
    // en una sola query agrupada, evitando N+1 (una query por línea antes).
    private function _generarRutaFEFO(OrdenPicking $orden, $user): array
    {
        $alertas = [];
        $orden->load(['detalles.producto']);
        $now = date('Y-m-d H:i:s');
        $soloAlmacenamiento = ($orden->tipo_picking === 'Consolidado Almacenamiento');

        // ── PRE-CARGA BATCH DE INVENTARIOS (anti-N+1) ────────────────────────
        // Obtiene todo el inventario disponible para los productos de la orden
        // en una sola query con JOIN a ubicaciones, ordenado por FEFO.
        $productoIds = $orden->detalles->pluck('producto_id')->unique()->toArray();

        $todosLosStock = Inventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('producto_id', $productoIds)
            ->where('estado', 'Disponible')
            ->where('cantidad', '>', 0)
            ->with('ubicacion:id,tipo_ubicacion,codigo,zona')
            // FEFO estricto: NULL fechas al final, luego ascendente por fecha
            ->orderByRaw('fecha_vencimiento IS NULL ASC')
            ->orderBy('fecha_vencimiento', 'ASC')
            ->get();

        // Indexar por producto_id para acceso O(1) en el foreach
        $stockPorProducto = $todosLosStock->groupBy('producto_id');

        Capsule::transaction(function () use ($orden, $user, &$alertas, $now,
                                              $soloAlmacenamiento, $stockPorProducto) {
            foreach ($orden->detalles as $linea) {
                $qtyEnPicking = 0;
                $stockProducto = $stockPorProducto->get($linea->producto_id, collect());

                if (!$soloAlmacenamiento) {
                    // 1. Buscar en Zona de Picking (Prioridad 1) — desde cache batch
                    $pickingStock = $stockProducto->filter(
                        fn($i) => ($i->ubicacion->tipo_ubicacion ?? '') === 'Picking'
                    );

                    $qtyEnPicking = $pickingStock->sum('cantidad');

                    if ($qtyEnPicking >= $linea->cantidad_solicitada) {
                        // Caso A: Hay suficiente en Picking (FEFO ya ordenado en batch)
                        $first = $pickingStock->first();
                        $linea->ubicacion_id      = $first->ubicacion_id;
                        $linea->lote              = $first->lote;
                        $linea->fecha_vencimiento = $first->fecha_vencimiento;
                        $linea->estado            = 'EnProceso';
                        $linea->save();
                        continue;
                    }
                }

                // Caso B: Falta en Picking. Buscar en el resto de la bodega (Almacenamiento)
                if ($soloAlmacenamiento) {
                    $stockGlobal = $stockProducto->filter(
                        fn($i) => in_array($i->ubicacion->tipo_ubicacion ?? '',
                                           ['Almacenamiento', 'Rack', 'Estante'])
                    );
                } else {
                    $stockGlobal = $stockProducto->filter(
                        fn($i) => ($i->ubicacion->tipo_ubicacion ?? '') !== 'Picking'
                    );
                }

                // ── [resto del código sin cambios desde aquí] ─────────────────

                $totalDisponible = $qtyEnPicking + $stockGlobal->sum('cantidad');

                if ($totalDisponible < $linea->cantidad_solicitada) {
                    // Caso C: Faltante Global (Novedad)
                    $faltante = $linea->cantidad_solicitada - $totalDisponible;
                    
                    Capsule::table('picking_faltantes')->insert([
                        'empresa_id'          => $user->empresa_id,
                        'sucursal_id'         => $user->sucursal_id,
                        'orden_picking_id'    => $orden->id,
                        'producto_id'         => $linea->producto_id,
                        'planilla_lote'       => $orden->planilla_lote,
                        'cantidad_solicitada' => $linea->cantidad_solicitada,
                        'cantidad_faltante'   => $faltante,
                        'causa'               => 'Stock insuficiente en toda la bodega',
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);

                    $linea->estado = 'Faltante';
                    $linea->save();

                    $alertas[] = "Faltante global: {$linea->producto->nombre} ({$faltante} unds)";
                } else {
                    if ($soloAlmacenamiento) {
                        // Montacarguista recoge directamente de Almacenamiento (sin reabastecimiento)
                        $first = $stockGlobal->first();
                        $linea->ubicacion_id      = $first->ubicacion_id;
                        $linea->lote              = $first->lote;
                        $linea->fecha_vencimiento = $first->fecha_vencimiento;
                        $linea->estado            = 'EnProceso';
                        $linea->save();
                    } else {
                        // Caso D: Hay en la bodega pero no en Picking -> Reabastecimiento Automático
                        $qtyFaltanteEnPicking = $linea->cantidad_solicitada - $qtyEnPicking;
                        $source = $stockGlobal->first();

                        // Intentar encontrar una ubicación de picking destino para este producto
                        $destino = \App\Models\Ubicacion::where('sucursal_id', $user->sucursal_id)
                            ->where('tipo_ubicacion', 'Picking')
                            ->where('activo', 1)
                            ->first(); // TODO: Podría ser la ubicación habitual del producto

                        TareaReabastecimiento::create([
                            'empresa_id'           => $user->empresa_id,
                            'sucursal_id'          => $user->sucursal_id,
                            'orden_picking_id'     => $orden->id,
                            'producto_id'          => $linea->producto_id,
                            'ubicacion_origen_id'  => $source->ubicacion_id,
                            'ubicacion_destino_id' => $destino ? $destino->id : $source->ubicacion_id,
                            'cantidad'             => $qtyFaltanteEnPicking,
                            'estado'               => 'Pendiente',
                            'fecha_movimiento'     => date('Y-m-d'),
                        ]);

                        // Marcamos la línea como pendiente de reabastecimiento para que no se pierda
                        $linea->estado = 'PendienteReabastecimiento';
                        $linea->save();
                        
                        $alertas[] = "Reabastecimiento generado para {$linea->producto->nombre}";
                    }
                }
            }

            $asignadas = 0;
            foreach ($orden->detalles as $linea) {
                if (in_array($linea->estado, ['EnProceso', 'PendienteReabastecimiento'])) {
                    $asignadas++;
                }
            }

            if ($asignadas === 0) {
                // Si ninguna línea pudo ser servida, la orden NO pasa a EnProceso
                $orden->estado = 'Pendiente'; 
                $orden->save();
                throw new \Exception('No se encontró stock para ninguna de las líneas de esta orden. La ruta no puede ser generada.');
            }

            $orden->estado = 'EnProceso';
            $orden->save();
        });

        return $alertas;
    }

    // ── GET /api/picking/novedades-stock ──────────────────────────────────────
    // Retorna faltantes de stock registrados durante la generación de rutas FEFO.
    // Incluye stock actual en tiempo real para detectar productos reabastecidos.
    // Filtros: fecha_inicio, fecha_fin, numero_planilla, producto, limit, export=excel
    public function novedadesStockLegacy(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        // Subquery para stock actual en tiempo real
        $stockSubquery = Capsule::raw("(
            SELECT COALESCE(SUM(i.cantidad - i.cantidad_reservada), 0)
            FROM inventarios i
            WHERE i.producto_id = f.producto_id
              AND i.empresa_id = f.empresa_id
              AND i.sucursal_id = f.sucursal_id
              AND i.estado = 'Disponible'
              AND (i.cantidad - i.cantidad_reservada) > 0
        ) as stock_actual");

        $query = Capsule::table('picking_faltantes as f')
            ->join('productos as p',      'f.producto_id',       '=', 'p.id')
            ->leftJoin('orden_pickings as o', 'f.orden_picking_id', '=', 'o.id')
            ->leftJoin('personal as aux',    'o.auxiliar_id',       '=', 'aux.id')
            ->where('f.empresa_id',  $user->empresa_id)
            ->where('f.sucursal_id', $user->sucursal_id)
            ->whereBetween('f.created_at', [$ini . ' 00:00:00', $fin . ' 23:59:59'])
            ->when($params['numero_planilla'] ?? null, fn($q, $v) => $q->where('f.planilla_lote', $v))
            ->when($params['producto'] ?? null, function($q, $v) {
                $likeOp = (Capsule::connection()->getDriverName() === 'pgsql') ? 'ilike' : 'like';
                $q->where(fn($sub) => $sub
                    ->where('p.nombre', $likeOp, "%{$v}%")
                    ->orWhere('p.codigo_interno', $likeOp, "%{$v}%")
                );
            })
            ->select(
                'f.id',
                'f.created_at',
                'f.orden_picking_id',
                'f.producto_id',
                'f.planilla_lote as numero_planilla',
                'o.cliente',
                'o.numero_orden',
                'o.asesor_comercial as asesor',
                'aux.nombre as auxiliar',
                'p.codigo_interno as producto_codigo',
                'p.nombre as producto_nombre',
                'f.cantidad_solicitada',
                Capsule::raw('(f.cantidad_solicitada - f.cantidad_faltante) as stock_disponible'),
                'f.cantidad_faltante',
                $stockSubquery
            )
            ->orderBy('f.created_at', 'desc');

        // Total sin límite (para el frontend)
        $total = $query->count();

        // Aplicar límite si se solicita (por defecto sin límite para export)
        $limit = isset($params['limit']) ? (int)$params['limit'] : null;
        if ($limit && ($params['export'] ?? '') !== 'excel') {
            $query->limit($limit);
        }

        $rows = $query->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Fecha', 'Planilla', 'Auxiliar', 'Cliente', 'Asesor/Comercial',
                        'Código', 'Producto', 'Solicitado', 'Stock al Registrar', 'Faltante', 'Stock Actual'];
            $data = $rows->map(fn($row) => [
                substr($row->created_at ?? '', 0, 10),
                $row->numero_planilla ?? '—',
                $row->auxiliar        ?? '—',
                $row->cliente         ?? '—',
                $row->asesor          ?? '—',
                $row->producto_codigo ?? '—',
                $row->producto_nombre ?? '—',
                $row->cantidad_solicitada,
                $row->stock_disponible,
                $row->cantidad_faltante,
                $row->stock_actual ?? 0,
            ])->toArray();
            return $this->exportCsv($res, $headers, $data, 'faltantes_picking_' . date('Y-m-d'));
        }

        return $this->ok($res, ['rows' => $rows, 'total' => $total]);
    }

    public function novedadesStock(Request $r, Response $res): Response
    {
        return $this->novedadesStockLegacy($r, $res);
    }

    // ── POST /api/picking/backorder ──────────────────────────────────────────
    // Proceso de Backorder: re-asigna faltantes al picking cuando el inventario
    // ha sido reabastecido. Reserva atómicamente, reactiva líneas de picking
    // y elimina los registros de faltantes procesados.
    public function procesarBackorder(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $faltanteIds = array_map('intval', $data['faltante_ids'] ?? []);

        if (empty($faltanteIds)) {
            return $this->error($res, 'Seleccione al menos un faltante para procesar');
        }

        $now = date('Y-m-d H:i:s');
        $resultados = [
            'procesados'     => 0,
            'sin_stock'      => 0,
            'reservados'     => 0,
            'eliminados'     => 0,
            'errores'        => [],
            'detalle'        => [],
        ];

        // Cargar faltantes con validación de empresa
        $faltantes = Capsule::table('picking_faltantes')
            ->where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('id', $faltanteIds)
            ->get();

        if ($faltantes->isEmpty()) {
            return $this->error($res, 'No se encontraron faltantes válidos');
        }

        foreach ($faltantes as $falt) {
            try {
                Capsule::transaction(function () use ($falt, $user, $now, &$resultados) {
                    $cantidadNecesaria = (int)$falt->cantidad_faltante;

                    // ── 1. Verificar stock actual disponible (con lock pesimista) ──
                    $stockDisponible = Inventario::where('empresa_id', $user->empresa_id)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $falt->producto_id)
                        ->where('estado', 'Disponible')
                        ->whereRaw('(cantidad - cantidad_reservada) > 0')
                        ->lockForUpdate()
                        ->orderByRaw('fecha_vencimiento IS NULL ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->get();

                    $totalDisponible = $stockDisponible->sum(fn($inv) => max(0, $inv->cantidad - $inv->cantidad_reservada));

                    // Obtener nombre del producto para el detalle
                    $producto = \App\Models\Producto::find($falt->producto_id);
                    $nombreProducto = $producto->nombre ?? $producto->descripcion ?? "ID:{$falt->producto_id}";

                    if ($totalDisponible < $cantidadNecesaria) {
                        // Sin stock suficiente todavía
                        $resultados['sin_stock']++;
                        $resultados['detalle'][] = [
                            'faltante_id' => $falt->id,
                            'producto'    => $nombreProducto,
                            'necesario'   => $cantidadNecesaria,
                            'disponible'  => (int)$totalDisponible,
                            'estado'      => 'sin_stock',
                        ];
                        return;
                    }

                    // ── 2. Reservar inventario FEFO ──────────────────────────────
                    $restante = $cantidadNecesaria;
                    $ubicacionAsignada = null;
                    $loteAsignado = null;
                    $fechaVencAsignada = null;

                    foreach ($stockDisponible as $inv) {
                        if ($restante <= 0) break;
                        $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                        if ($disp <= 0) continue;

                        $aReservar = min($disp, $restante);
                        $inv->cantidad_reservada += $aReservar;
                        $inv->save();
                        $restante -= $aReservar;

                        // Tomar la primera ubicación FEFO
                        if (!$ubicacionAsignada) {
                            $ubicacionAsignada  = $inv->ubicacion_id;
                            $loteAsignado       = $inv->lote;
                            $fechaVencAsignada  = $inv->fecha_vencimiento;
                        }
                    }

                    $resultados['reservados'] += $cantidadNecesaria;

                    // ── 3. Reactivar la línea de picking ─────────────────────────
                    // Buscar la línea de detalle marcada como Faltante para este producto/orden
                    $lineaFaltante = PickingDetalle::where('orden_picking_id', $falt->orden_picking_id)
                        ->where('producto_id', $falt->producto_id)
                        ->where('estado', 'Faltante')
                        ->first();

                    if ($lineaFaltante) {
                        $lineaFaltante->estado            = 'EnProceso';
                        $lineaFaltante->ubicacion_id      = $ubicacionAsignada;
                        $lineaFaltante->lote              = $loteAsignado;
                        $lineaFaltante->fecha_vencimiento = $fechaVencAsignada;
                        $lineaFaltante->save();

                        // Actualizar la orden si estaba en Faltante
                        $orden = OrdenPicking::find($falt->orden_picking_id);
                        if ($orden && in_array($orden->estado, ['Faltante', 'Completada'])) {
                            $orden->estado = 'EnProceso';
                            $orden->save();
                        }
                    }

                    // ── 4. Eliminar el registro de faltante ─────────────────────
                    Capsule::table('picking_faltantes')->where('id', $falt->id)->delete();
                    $resultados['eliminados']++;

                    $resultados['procesados']++;
                    $resultados['detalle'][] = [
                        'faltante_id' => $falt->id,
                        'producto'    => $nombreProducto,
                        'necesario'   => $cantidadNecesaria,
                        'disponible'  => (int)$totalDisponible,
                        'estado'      => 'backorder_ok',
                    ];
                });
            } catch (\Exception $e) {
                $resultados['errores'][] = "Faltante #{$falt->id}: {$e->getMessage()}";
            }
        }

        // @audit — Registro profesional del backorder
        $this->audit($user, 'picking', 'backorder', 'picking_faltantes', null,
            ['faltante_ids' => $faltanteIds],
            $resultados,
            "Backorder: {$resultados['procesados']} procesados, " .
            "{$resultados['reservados']} unidades reservadas, " .
            "{$resultados['sin_stock']} sin stock aún");

        $msg = "{$resultados['procesados']} faltante(s) procesados";
        if ($resultados['sin_stock'] > 0) $msg .= ", {$resultados['sin_stock']} sin stock";
        if ($resultados['reservados'] > 0) $msg .= ", {$resultados['reservados']} unidades reservadas";

        return $this->ok($res, $resultados, $msg);
    }

    // ── GET /api/picking/{id} ─────────────────────────────────────────────────
    public function detalle(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->find($a['id']);

        if (!$orden) return $this->notFound($res);

        // ── CTE anti-N+1: carga detalles + stock disponible en una sola query ─
        // Sustituye el with(['detalles.producto','detalles.ubicacion']) que
        // generaba una query extra por cada línea para obtener el stock.
        if ($this->isPg()) {
            $detalles = Capsule::select("
                WITH stock_agg AS (
                    SELECT
                        producto_id,
                        SUM(cantidad) FILTER (WHERE estado = 'Disponible') AS stock_disponible,
                        MIN(fecha_vencimiento) FILTER (WHERE estado = 'Disponible'
                                                        AND fecha_vencimiento IS NOT NULL
                                                        AND fecha_vencimiento >= CURRENT_DATE) AS proximo_vencer,
                        SUM(cantidad) FILTER (WHERE estado = 'Disponible'
                                              AND fecha_vencimiento IS NOT NULL
                                              AND fecha_vencimiento < CURRENT_DATE) AS stock_vencido
                    FROM inventarios
                    WHERE empresa_id = ? AND sucursal_id = ? AND cantidad > 0
                    GROUP BY producto_id
                )
                SELECT
                    pd.*,
                    p.nombre            AS producto_nombre,
                    p.codigo_interno    AS producto_codigo,
                    p.unidades_caja     AS producto_unidades_caja,
                    ub.codigo           AS ubicacion_codigo,
                    ub.pasillo          AS ubicacion_pasillo,
                    ub.zona             AS ubicacion_zona,
                    COALESCE(sa.stock_disponible, 0) AS stock_disponible,
                    sa.proximo_vencer,
                    COALESCE(sa.stock_vencido, 0) AS stock_vencido
                FROM picking_detalles pd
                JOIN productos p    ON pd.producto_id  = p.id
                LEFT JOIN ubicaciones ub ON pd.ubicacion_id = ub.id
                LEFT JOIN stock_agg sa   ON sa.producto_id  = pd.producto_id
                WHERE pd.orden_picking_id = ?
                ORDER BY pd.id ASC
            ", [$user->empresa_id, $user->sucursal_id, $orden->id]);
        } else {
            // MySQL fallback: eager loading estándar
            $detalles = $orden->load(['detalles.producto', 'detalles.ubicacion'])->detalles;
        }

        return $this->ok($res, array_merge($orden->toArray(), ['detalles' => $detalles]));
    }

    // ── POST /api/picking ─────────────────────────────────────────────────────
    public function crearBatch(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if (empty($data['detalles']) || !is_array($data['detalles'])) {
            return $this->error($res, 'Se requiere al menos una línea de productos');
        }

        try {
            $orden = Capsule::transaction(function () use ($data, $user) {
                $orden = OrdenPicking::create([
                    'empresa_id'     => $user->empresa_id,
                    'sucursal_id'    => $user->sucursal_id,
                    'numero_orden'   => 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'cliente'        => $data['cliente'] ?? null,
                    'estado'         => 'Pendiente',
                    'prioridad'      => $data['prioridad'] ?? 5,
                    'auxiliar_id'    => $data['auxiliar_id'] ?? null,
                    'fecha_movimiento'=> date('Y-m-d'),
                    'hora_inicio'    => date('H:i:s'),
                    'fecha_requerida'=> $data['fecha_requerida'] ?? null,
                ]);

                foreach ($data['detalles'] as $det) {
                    PickingDetalle::create([
                        'orden_picking_id'  => $orden->id,
                        'producto_id'       => $det['producto_id'],
                        'ubicacion_id'      => null, // Se asignará en generateRoute (FEFO)
                        'cantidad_solicitada'=> $det['cantidad'],
                        'cantidad_pickeada' => 0,
                        'estado'            => 'Pendiente',
                    ]);
                }

                return $orden;
            });

            $this->audit($user, 'picking', 'crear', 'orden_pickings', $orden->id,
                null, $orden->toArray(), "Orden picking {$orden->numero_orden} creada");

            return $this->created($res, $orden->load('detalles'));
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/generar-ruta ─────────────────────────────
    public function generateRoute(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];

        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->with('detalles')
            ->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if ($orden->estado !== 'Pendiente') {
            return $this->error($res, "La orden ya está en estado {$orden->estado}");
        }

        // Asignar auxiliar si viene en el body
        if (!empty($data['auxiliar_id'])) {
            $auxId = (int)$data['auxiliar_id'];
            $orden->auxiliar_id = $auxId;
            $orden->save();

            // Notify the auxiliary
            \App\Controllers\NotificacionesController::crear(
                $user->empresa_id,
                $auxId,
                'Nueva Ruta de Picking',
                "Se le ha asignado la ruta para la orden {$orden->numero_orden}. Por favor inicie el picking.",
                'picking',
                $user->id,
                'Picking',
                $orden->id,
                'viewPicking',
                'Picking',
                true,
                $user->sucursal_id
            );
        }

        try {
            $alertas = $this->_generarRutaFEFO($orden, $user);

            $this->audit($user, 'picking', 'generar_ruta', 'orden_pickings', $orden->id,
                ['estado' => 'Pendiente'], ['estado' => 'EnProceso'],
                "Ruta FEFO generada para {$orden->numero_orden}");

            $lineasAsignadas = $orden->fresh()->detalles->where('estado', 'EnProceso')->count();

            return $this->ok($res, [
                'orden'            => $orden->load('detalles.ubicacion'),
                'lineas_asignadas' => $lineasAsignadas,
                'alertas_stock'    => $alertas,
            ], 'Ruta generada con FEFO');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/confirmar-linea ──────────────────────────
    public function confirmLine(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if (!in_array($orden->estado, ['EnProceso', 'Pendiente'])) {
            return $this->error($res, 'La orden no está en el estado correcto para picking');
        }

        // Si estaba en Pendiente, mover a EnProceso
        if ($orden->estado === 'Pendiente') {
            $orden->estado = 'EnProceso';
            $orden->save();
        }

        $linea = PickingDetalle::where('orden_picking_id', $orden->id)
            ->find($data['linea_id'] ?? 0);

        if (!$linea) return $this->notFound($res, 'Línea no encontrada');

        $cantidadTomada = (int)($data['cantidad_tomada'] ?? 0);
        if ($cantidadTomada <= 0) return $this->error($res, 'Cantidad inválida');

        // ── Guard de integridad pre-transacción ──────────────────────────────
        $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
        $check = $guard->canPick(
            $linea->producto_id,
            $cantidadTomada,
            $linea->lote,
            $linea->ubicacion_id
        );
        if (!$check['ok']) {
            return $this->error($res, $check['message'], 422);
        }
        if (!empty($check['fefo_warning'])) {
            // Registrar aviso FEFO sin bloquear la operación
            error_log('[FEFO-WARN] Orden ' . $orden->numero_orden . ': ' . $check['fefo_warning']);
        }

        try {
            Capsule::transaction(function () use ($linea, $orden, $user, $cantidadTomada) {
                // ── Fase 1: Liberar reserva (cantidad_reservada) ─────────────
                // La reserva se creó en asignarMultiple; ahora la liberamos
                // porque vamos a descontar el stock real.
                $invReservas = Inventario::where('empresa_id',  $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('estado',       'Disponible')
                    ->where('cantidad_reservada', '>', 0)
                    ->when($linea->ubicacion_id, fn($q) => $q->where('ubicacion_id', $linea->ubicacion_id))
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->lockForUpdate()
                    ->get();

                $reservaPorLiberar = $cantidadTomada;
                foreach ($invReservas as $invR) {
                    if ($reservaPorLiberar <= 0) break;
                    $aLiberar = min($invR->cantidad_reservada, $reservaPorLiberar);
                    $invR->cantidad_reservada -= $aLiberar;
                    $invR->save();
                    $reservaPorLiberar -= $aLiberar;
                }

                // ── Fase 2: Descontar inventario real ────────────────────────
                $inv = Inventario::where('empresa_id',  $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->lockForUpdate()
                    ->first();

                if (!$inv || $inv->cantidad < $cantidadTomada) {
                    throw new \Exception('Stock insuficiente para confirmar el picking');
                }

                $inv->cantidad -= $cantidadTomada;
                if ($inv->cantidad === 0) $inv->delete();
                else $inv->save();

                // Registrar movimiento con auditoría completa
                MovimientoInventario::create([
                    'empresa_id'           => $user->empresa_id,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $linea->producto_id,
                    'tipo_movimiento'      => 'SalidaPicking',
                    'cantidad'             => $cantidadTomada,
                    'ubicacion_origen_id'  => $linea->ubicacion_id,
                    'ubicacion_destino_id' => $linea->ubicacion_id,
                    'lote'                 => $linea->lote,
                    'fecha_vencimiento'    => $linea->fecha_vencimiento,
                    'auxiliar_id'          => $user->id,
                    'referencia_tipo'      => 'OrdenPicking',
                    'referencia_id'        => $orden->id,
                    'observaciones'        => "Picking orden {$orden->numero_orden} — Confirmación auxiliar",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                ]);

                $linea->cantidad_pickeada = $cantidadTomada;
                $linea->estado = $cantidadTomada >= $linea->cantidad_solicitada
                    ? 'Completado' : 'Faltante';
                $linea->save();

                // Verificar si todas las líneas están completas
                $pendientes = PickingDetalle::where('orden_picking_id', $orden->id)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->count();

                if ($pendientes === 0) {
                    $orden->estado   = 'Completada';
                    $orden->hora_fin = date('H:i:s');
                    $orden->save();
                }
            });

            $this->audit($user, 'picking', 'confirmar_linea', 'picking_detalles', $linea->id,
                null, ['cantidad_tomada' => $cantidadTomada],
                "Línea picking confirmada para {$orden->numero_orden}");

            return $this->ok($res, $linea, 'Línea confirmada');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{id}/completar ─────────────────────────────────────
    public function completar(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $orden->estado   = 'Completada';
        $orden->hora_fin = date('H:i:s');
        $orden->save();

        $this->audit($user, 'picking', 'completar', 'orden_pickings', $orden->id,
            null, ['estado' => 'Completada'], "Orden picking {$orden->numero_orden} completada");

        // If this order belongs to a planilla archivo, check if all orders are done
        if ($orden->archivo_id) {
            $totalOrdenes = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->where('archivo_id', $orden->archivo_id)->count();
            $completadas  = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->where('archivo_id', $orden->archivo_id)
                ->where('estado', 'Completada')->count();
            if ($totalOrdenes > 0 && $completadas >= $totalOrdenes) {
                \Illuminate\Database\Capsule\Manager::table('archivos_planilla')
                    ->where('id', $orden->archivo_id)
                    ->update(['estado' => 'Separado', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }

        return $this->ok($res, $orden, 'Orden de picking completada');
    }

    // ── POST /api/picking/assign ──────────────────────────────────────────────
    // Asignación granular por pasillo o categoría
    public function assignLines(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $now  = date('Y-m-d H:i:s');

        $ordenIds           = array_map('intval', $data['orden_ids'] ?? []);
        $auxiliarId         = (int)($data['auxiliar_id'] ?? 0);
        $pasillos           = (array)($data['pasillos'] ?? []);
        $categorias         = (array)($data['categorias'] ?? []);
        $separarConsolidado = (bool)($data['separar_consolidado'] ?? false);

        if (empty($ordenIds) || !$auxiliarId) {
            return $this->error($res, 'Ordenes y Auxiliar son requeridos');
        }

        try {
            $totalAsignadas = Capsule::transaction(function () use ($ordenIds, $auxiliarId, $pasillos, $categorias, $separarConsolidado, $now) {
                $q = PickingDetalle::whereIn('orden_picking_id', $ordenIds)
                    ->whereIn('estado', ['Pendiente', 'PendienteReabastecimiento', 'EnProceso'])
                    ->where(fn($sub) => $sub->whereNull('auxiliar_id')->orWhere('auxiliar_id', 0));

                if (!empty($pasillos)) {
                    $q->whereHas('ubicacion', fn($uq) => $uq->whereIn('pasillo', $pasillos));
                }

                if (!empty($categorias)) {
                    $q->whereHas('producto', fn($pq) => $pq->whereIn('categoria_id', $categorias));
                }

                $asignadas = $q->update([
                    'auxiliar_id' => $auxiliarId,
                    'estado'      => 'EnProceso',
                    'updated_at'  => $now
                ]);

                // Actualizar las órdenes involucradas
                $updateData = ['estado' => 'EnProceso'];
                if ($separarConsolidado) {
                    $updateData['tipo_picking'] = 'Consolidado Almacenamiento';
                }
                OrdenPicking::whereIn('id', $ordenIds)->update($updateData);

                return $asignadas;
            });

            return $this->ok($res, ['asignadas' => $totalAsignadas], "Se asignaron $totalAsignadas líneas al auxiliar.");
        } catch (\Exception $e) {
            return $this->error($res, 'Error en asignación: ' . $e->getMessage());
        }
    }

    // ── POST /api/picking/transfer ───────────────────────────────────────────
    // Herencia de tareas: de un auxiliar a otro
    public function transferTasks(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $fromId  = (int)($data['from_auxiliar_id'] ?? 0);
        $toId    = (int)($data['to_auxiliar_id'] ?? 0);
        $ordenId = (int)($data['orden_id'] ?? 0);

        if (!$fromId || !$toId) {
            return $this->error($res, 'Auxiliares origen y destino son requeridos');
        }

        try {
            $movidas = Capsule::transaction(function () use ($fromId, $toId, $ordenId) {
                $q = PickingDetalle::where('auxiliar_id', $fromId)
                    ->whereIn('estado', ['Pendiente', 'EnProceso', 'PendienteReabastecimiento']);
                
                if ($ordenId > 0) {
                    $q->where('orden_picking_id', $ordenId);
                }

                return $q->update(['auxiliar_id' => $toId]);
            });

            return $this->ok($res, ['movidas' => $movidas], "Se transfirieron $movidas tareas exitosamente.");
        } catch (\Exception $e) {
            return $this->error($res, 'Error en transferencia: ' . $e->getMessage());
        }
    }

    // ── GET /api/picking/{orden_id}/siguiente-linea ───────────────────────────
    // Flujo de separación guiado: devuelve la siguiente línea pendiente con toda
    // la información que el auxiliar necesita para separar (ubicación, lote, vencimiento).
    public function siguienteLinea(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['orden_id']);
        if (!$orden) return $this->notFound($res);

        // Siguiente línea no confirmada, ordenada por ubicacion_id (sigue la ruta FEFO)
        // REQUERIMIENTO: Solo mostrar líneas asignadas a este auxiliar (o sin asignar si la orden es suya)
        $lineaQuery = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->with(['producto', 'ubicacion'])
            ->orderBy('ubicacion_id');
        
        // Si el usuario no es admin/supervisor, forzar su propio auxiliar_id
        if ($user->rol === 'Auxiliar') {
            $lineaQuery->where('auxiliar_id', $user->id);
        }

        $linea = $lineaQuery->first();

        // Progreso
        $total       = PickingDetalle::where('orden_picking_id', $orden->id)->count();
        $confirmadas = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereNotIn('estado', ['Pendiente', 'EnProceso'])
            ->count();

        if (!$linea) {
            return $this->ok($res, [
                'completada' => true,
                'mensaje'    => 'Todas las líneas han sido confirmadas.',
                'progreso'   => ['confirmadas' => $confirmadas, 'total' => $total, 'pct' => 100],
            ]);
        }

        // EAN principal del producto
        $ean = \App\Models\ProductoEan::where('producto_id', $linea->producto_id)
            ->orderBy('es_principal', 'desc')->value('codigo_ean');

        return $this->ok($res, [
            'completada' => false,
            'linea' => [
                'id'                  => $linea->id,
                'producto_id'         => $linea->producto_id,
                'producto_nombre'     => $linea->producto->nombre          ?? '—',
                'producto_codigo'     => $linea->producto->codigo_interno  ?? '—',
                'producto_ean'        => $ean,
                'imagen_url'          => $linea->producto->imagen_url      ?? null,
                'ubicacion_id'        => $linea->ubicacion_id,
                'ubicacion_codigo'    => $linea->ubicacion->codigo         ?? '—',
                'pasillo'             => $linea->ubicacion->pasillo        ?? null,
                'nivel'               => $linea->ubicacion->nivel          ?? null,
                'cantidad_solicitada' => $linea->cantidad_solicitada,
                'cantidad_pickeada'   => $linea->cantidad_pickeada         ?? 0,
                'lote'                => $linea->lote,
                'fecha_vencimiento'   => $linea->fecha_vencimiento,
            ],
            'progreso' => [
                'confirmadas' => $confirmadas,
                'total'        => $total,
                'pct'          => $total > 0 ? (int)round($confirmadas / $total * 100) : 0,
            ],
            'orden' => [
                'id'           => $orden->id,
                'numero_orden' => $orden->numero_orden,
                'cliente'      => $orden->cliente,
            ],
            'empaque' => [
                'unidades_caja' => $linea->producto->unidades_caja ?: 1,
                'cajas'         => floor($linea->cantidad_solicitada / ($linea->producto->unidades_caja ?: 1)),
                'picos'         => $linea->cantidad_solicitada % ($linea->producto->unidades_caja ?: 1),
            ]
        ]);
    }

    // ── DELETE /api/picking/{id} — solo Admin ─────────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);
        if (in_array($orden->estado, ['Completada', 'Completado'])) {
            return $this->error($res, 'No se puede anular una orden ya completada');
        }

        $snapshot = $orden->toArray();

        try {
            Capsule::transaction(function () use ($orden, $user) {
                $detalles = PickingDetalle::where('orden_picking_id', $orden->id)->get();
                
                foreach ($detalles as $linea) {
                    if ($linea->estado === 'Anulado') continue;

                    // 1. Revertir inventario real pickeado
                    if ($linea->cantidad_pickeada > 0) {
                        $invReal = Inventario::where('empresa_id', $user->empresa_id)
                            ->where('sucursal_id', $user->sucursal_id)
                            ->where('producto_id', $linea->producto_id)
                            ->where('ubicacion_id', $linea->ubicacion_id)
                            ->where('estado', 'Disponible')
                            ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                            ->lockForUpdate()
                            ->first();
                            
                        if ($invReal) {
                            $invReal->cantidad += $linea->cantidad_pickeada;
                            $invReal->save();
                        } else {
                            Inventario::create([
                                'empresa_id' => $user->empresa_id,
                                'sucursal_id' => $user->sucursal_id,
                                'producto_id' => $linea->producto_id,
                                'ubicacion_id' => $linea->ubicacion_id,
                                'lote' => $linea->lote,
                                'fecha_vencimiento' => $linea->fecha_vencimiento ?? null,
                                'cantidad' => $linea->cantidad_pickeada,
                                'cantidad_reservada' => 0,
                                'estado' => 'Disponible',
                                'numero_pallet' => null,
                            ]);
                        }
                    }

                    // 2. Revertir reserva pendiente
                    $pendiente = $linea->cantidad_solicitada - $linea->cantidad_pickeada;
                    if ($pendiente > 0 && in_array($orden->estado, ['Asignado', 'EnProceso'])) {
                        $invs = Inventario::where('empresa_id', $user->empresa_id)
                            ->where('sucursal_id', $user->sucursal_id)
                            ->where('producto_id', $linea->producto_id)
                            ->where('cantidad_reservada', '>', 0)
                            ->when($linea->ubicacion_id, fn($q) => $q->where('ubicacion_id', $linea->ubicacion_id))
                            ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                            ->lockForUpdate()
                            ->get();
                        
                        foreach ($invs as $inv) {
                            if ($pendiente <= 0) break;
                            $aRevertir = min($inv->cantidad_reservada, $pendiente);
                            $inv->cantidad_reservada -= $aRevertir;
                            $inv->save();
                            $pendiente -= $aRevertir;
                        }
                    }
                    
                    $linea->estado = 'Anulado';
                    $linea->save();
                }

                if (in_array($orden->estado, ['EnProceso'])) {
                    $orden->estado = 'Anulado';
                    $orden->save();
                } else {
                    $orden->detalles()->delete();
                    $orden->delete();
                }
            });

            $this->audit($user, 'picking', 'eliminar', 'orden_pickings', $a['id'],
                $snapshot, null, "Orden {$snapshot['numero_orden']} " . (in_array($orden->estado, ['EnProceso']) ? "anulada" : "eliminada") . " por Admin");

            return $this->ok($res, null, 'Orden procesada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al anular orden: ' . $e->getMessage());
        }
    }

    // ── PUT /api/picking/{id} ─────────────────────────────────────────────────
    public function actualizar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $snapshot = $orden->toArray();

        if (isset($data['cliente'])) $orden->cliente = trim($data['cliente']);
        if (isset($data['prioridad'])) $orden->prioridad = (int)$data['prioridad'];
        if (isset($data['fecha_requerida'])) $orden->fecha_requerida = $data['fecha_requerida'];
        if (isset($data['area_comercial'])) $orden->area_comercial = trim($data['area_comercial']);
        if (isset($data['observaciones'])) $orden->observaciones = trim($data['observaciones']);

        $orden->save();
        $this->audit($user, 'picking', 'editar', 'orden_pickings', $orden->id, $snapshot, $orden->toArray(), 'Orden editada');

        return $this->ok($res, $orden, 'Orden actualizada');
    }

    // ── POST /api/picking/{id}/lineas ─────────────────────────────────────────
    public function agregarLinea(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);

        if (in_array($orden->estado, ['Completada', 'Completado', 'Anulado'])) {
            return $this->error($res, "No se pueden agregar líneas a una orden en estado {$orden->estado}");
        }

        $prodId = (int)($data['producto_id'] ?? 0);
        $cantidad = (float)($data['cantidad'] ?? 0);
        if (!$prodId || $cantidad <= 0) {
            return $this->error($res, 'Producto o cantidad inválida');
        }

        $prod = Producto::where('empresa_id', $user->empresa_id)->find($prodId);
        if (!$prod) return $this->error($res, 'Producto no encontrado');

        try {
            $linea = Capsule::transaction(function () use ($orden, $prod, $cantidad, $user) {
                $nl = PickingDetalle::create([
                    'orden_picking_id'   => $orden->id,
                    'producto_id'        => $prod->id,
                    'cantidad_solicitada'=> $cantidad,
                    'cantidad_pickeada'  => 0,
                    'estado'             => 'Pendiente',
                    'costo_unitario'     => $prod->peso_unitario ?? 0,
                ]);

                if (in_array($orden->estado, ['Asignado', 'EnProceso'])) {
                    $stockDisponible = Inventario::where('empresa_id', $user->empresa_id)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $prod->id)
                        ->where('estado', 'Disponible')
                        ->whereRaw('(cantidad - cantidad_reservada) > 0')
                        ->lockForUpdate()
                        ->orderByRaw('fecha_vencimiento IS NULL ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->get();

                    $restante = $cantidad;
                    foreach ($stockDisponible as $inv) {
                        if ($restante <= 0) break;
                        $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                        if ($disp <= 0) continue;

                        $aReservar = min($disp, $restante);
                        $inv->cantidad_reservada += $aReservar;
                        $inv->save();
                        $restante -= $aReservar;

                        if (!$nl->ubicacion_id) {
                            $nl->ubicacion_id = $inv->ubicacion_id;
                            $nl->lote = $inv->lote;
                            $nl->fecha_vencimiento = $inv->fecha_vencimiento;
                        }
                    }

                    if ($restante > 0) {
                        $nl->estado = 'Pendiente';
                    } else {
                        $nl->estado = 'EnProceso';
                    }
                    $nl->save();
                }

                if (!in_array($orden->estado, ['EnProceso', 'Asignado'])) {
                    $orden->estado = 'Pendiente';
                    $orden->save();
                }

                return $nl;
            });

            $this->audit($user, 'picking', 'agregar_linea', 'picking_detalles', $linea->id, null, $linea->toArray(), 'Línea agregada a orden ' . $orden->numero_orden);

            return $this->created($res, $linea, 'Línea agregada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al agregar línea: ' . $e->getMessage());
        }
    }

    // ── GET /api/picking/dashboard ────────────────────────────────────────────
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $empresaId = $user->empresa_id;

        // Base query para filtros del dashboard
        $baseQ = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->when($params['estado']  ?? null, fn($q, $e) => $q->where('estado', $e))
            ->when($params['auxiliar_id'] ?? null, fn($q, $a) => $q->where('auxiliar_id', $a))
            ->when($params['planilla'] ?? null, function($q, $p) {
                $q->where(fn($sq) => $sq
                    ->where('planilla_numero', $p)
                    ->orWhere('area_comercial', 'like', "%$p%")
                );
            });

        // KPIs Básicos
        $stats = [
            'total_ordenes'   => (clone $baseQ)->count(),
            'pendientes'      => (clone $baseQ)->where('estado', 'Pendiente')->count(),
            'en_proceso'      => (clone $baseQ)->where('estado', 'EnProceso')->count(),
            'completadas'     => (clone $baseQ)->where('estado', 'Completada')->count(),
        ];

        // Faltantes Críticos (Alertas)
        $stats['alertas_faltantes'] = PickingDetalle::whereHas('ordenPicking', function($q) use ($ini, $fin, $empresaId, $params, $user) {
            $q->where('empresa_id', $empresaId)
              ->where('sucursal_id', $user->sucursal_id)
              ->whereBetween('created_at', [$ini, $fin])
              ->when($params['planilla'] ?? null, function($sq, $p) {
                  $sq->where(fn($ssq) => $ssq->where('planilla_numero', $p)->orWhere('area_comercial', 'like', "%$p%"));
              });
        })
        ->where('estado', 'Faltante')
        ->with(['producto:id,nombre,codigo_interno', 'ordenPicking:id,planilla_numero'])
        ->get()
        ->map(fn($f) => [
            'id'       => $f->id,
            'producto' => $f->producto->nombre ?? '–',
            'ean'      => $f->producto->codigo_interno ?? '–',
            'solic'    => $f->cantidad_solicitada,
            'disp'     => $f->cantidad_pickeada,
            'dif'      => $f->cantidad_solicitada - $f->cantidad_pickeada,
            'planilla' => $f->ordenPicking->planilla_numero ?? '–'
        ]);

        // Ranking de Auxiliares (Pedidos, Líneas, Unidades)
        $stats['ranking_auxiliares'] = Capsule::table('personal as aux')
            ->join('picking_detalles as d', 'aux.id', '=', 'd.auxiliar_id')
            ->join('orden_pickings as o', 'd.orden_picking_id', '=', 'o.id')
            ->where('o.empresa_id', $empresaId)
            ->whereBetween('o.created_at', [$ini, $fin])
            ->select(
                'aux.nombre',
                Capsule::raw('COUNT(DISTINCT o.id) as pedidos'),
                Capsule::raw('COUNT(d.id) as lineas'),
                Capsule::raw('SUM(d.cantidad_pickeada) as unidades')
            )
            ->groupBy('aux.id', 'aux.nombre')
            ->orderByDesc('lineas')
            ->limit(10)
            ->get();

        // Series para gráfico de productividad
        $stats['series'] = [
            'diario'    => $this->_getSeriesProductividad($empresaId, 'diario'),
            'semanal'   => $this->_getSeriesProductividad($empresaId, 'semanal'),
        ];

        return $this->ok($res, $stats);
    }

    /**
     * Agregación de unidades pickeadas por periodo para reportes dinámicos.
     */
    private function _getSeriesProductividad($empresaId, $periodo)
    {
        $isPg = $this->isPg();
        $query = Capsule::table('picking_detalles as d')
            ->join('orden_pickings as o', 'd.orden_picking_id', '=', 'o.id')
            ->where('o.empresa_id', $empresaId)
            ->where('d.estado', 'Completado');

        switch ($periodo) {
            case 'diario':
                // Últimos 15 días
                $query->selectRaw('o.fecha_movimiento as label, SUM(d.cantidad_pickeada) as total')
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-15 days')))
                    ->groupBy('o.fecha_movimiento')
                    ->orderBy('o.fecha_movimiento');
                break;
            case 'semanal':
                // Últimas 8 semanas
                $weekSql = $isPg ? "EXTRACT(WEEK FROM COALESCE(o.fecha_movimiento, o.created_at))" : "WEEK(COALESCE(o.fecha_movimiento, o.created_at))";
                
                $query->selectRaw("CONCAT('Sem ', $weekSql) as label, SUM(d.cantidad_pickeada) as total")
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-60 days')))
                    ->whereNotNull('o.fecha_movimiento');
                
                if ($isPg) {
                    $query->groupBy(Capsule::raw($weekSql));
                } else {
                    $query->groupBy(Capsule::raw("CONCAT('Sem ', $weekSql)"));
                }
                
                $query->orderBy(Capsule::raw($weekSql));
                break;
            case 'mensual':
                // Últimos 6 meses
                $monthSql = $isPg ? "TO_CHAR(COALESCE(o.fecha_movimiento, o.created_at), 'YYYY-MM')" : "DATE_FORMAT(COALESCE(o.fecha_movimiento, o.created_at), '%Y-%m')";
                $query->selectRaw("$monthSql as label, SUM(d.cantidad_pickeada) as total")
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-6 months')))
                    ->groupBy(Capsule::raw($monthSql))
                    ->orderBy(Capsule::raw($monthSql));
                break;
            case 'trimestral':
                // Por trimestre (último año)
                $quarterSql = $isPg ? "EXTRACT(QUARTER FROM COALESCE(o.fecha_movimiento, o.created_at))" : "QUARTER(COALESCE(o.fecha_movimiento, o.created_at))";
                $yearSql    = $isPg ? "EXTRACT(YEAR FROM COALESCE(o.fecha_movimiento, o.created_at))" : "YEAR(COALESCE(o.fecha_movimiento, o.created_at))";
                
                $labelSql = "CONCAT('Trim ', $quarterSql, '-', $yearSql)";
                $query->selectRaw("$labelSql as label, SUM(d.cantidad_pickeada) as total")
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-1 year')));
                
                if ($isPg) {
                    $query->groupBy(Capsule::raw($yearSql), Capsule::raw($quarterSql));
                } else {
                    $query->groupBy(Capsule::raw($labelSql));
                }

                $query->orderBy(Capsule::raw($yearSql), Capsule::raw($quarterSql));
                break;
        }

        return $query->get();
    }

    // ── GET /api/picking/reabastecimientos ────────────────────────────────────
    public function reabastecimientosLegacy(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $tareas = TareaReabastecimiento::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->with(['ordenPicking:id,planilla'])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        return $this->ok($res, $tareas);
    }

    public function reabastecimientos(Request $r, Response $res): Response
    {
        return $this->reabastecimientosLegacy($r, $res);
    }

    // ── GET /api/picking/template ─────────────────────────────────────────────
    // Plantilla con los campos requeridos para importación masiva
    public function getTemplate(Request $r, Response $res): Response
    {
        $headers = [
            'Num Pedido', 'Sucursal Entrega', 'Documento', 'Direccion',
            'Planilla', 'Asesor', 'Referencia', 'Cantidad', 'Costo', 'Descuento'
        ];
        $sample1 = [
            'PED-00123', 'BODEGA NORTE', '70133976', 'CL 21 40 19',
            '22275', 'TMD703 - SOTO', '7702006207881', '12', '18117', '30'
        ];
        $sample2 = [
            'PED-00124', 'BODEGA SUR', '80045231', 'CR 5 12 34',
            '22276', 'TMD405 - LOPEZ', '7703001140022', '6', '23500', '15'
        ];

        $content = "\xEF\xBB\xBF"; // UTF-8 BOM
        $content .= "# Campos del sistema ─ Campos del archivo\r\n";
        $content .= "# Numero Factura ─ Num Pedido\r\n";
        $content .= "# Cliente ─ SUCURSAL ENTREGA\r\n";
        $content .= "# Documento ─ (null / opcional)\r\n";
        $content .= "# Direccion ─ (null / opcional)\r\n";
        $content .= "# Planilla ─ Num Pedido (auto)\r\n";
        $content .= "# Asesor ─ (null / opcional)\r\n";
        $content .= "# Producto ─ Referencia\r\n";
        $content .= "# Cantidad ─ UNID PEDIDO\r\n";
        $content .= "# Costo ─ (null / opcional)\r\n";
        $content .= "# Descuento ─ (null / opcional)\r\n";
        $content .= "#\r\n";
        $content .= implode(';', $headers) . "\r\n";
        $content .= implode(';', $sample1) . "\r\n";
        $content .= implode(';', $sample2) . "\r\n";

        if (ob_get_length()) ob_clean();
        $res->getBody()->write($content);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="plantilla_pedidos_picking.csv"');
    }

    // ── POST /api/picking/importar ────────────────────────────────────────────
    // Importación masiva desde archivo plano con mapeo inteligente de columnas.
    // Agrupa por sucursal_entrega → una Planilla por sucursal.
    // Anti-duplicado: bloquea reimportación de facturas ya existentes (por numero_pedido_ref).
    public function importarPedidos(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $files = $r->getUploadedFiles();
        $file  = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'Archivo no válido o no enviado');
        }

        $contents = $file->getStream()->getContents();
        if (!mb_detect_encoding($contents, 'UTF-8', true)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
        $allLines = array_values(array_filter($allLines, fn($l) => trim($l) !== ''));
        if (count($allLines) < 2) return $this->error($res, 'El archivo no contiene datos');

        $sep       = str_contains($allLines[0], ';') ? ';' : ',';
        $rawHdr    = str_getcsv($allLines[0], $sep);
        $headers   = array_map(fn($h) => strtolower(trim($h, " \t\r\n\xEF\xBB\xBF")), $rawHdr);
        $dataLines = array_slice($allLines, 1);

        $ALIASES = [
            'numero_factura'   => ['numero factura', 'num factura', 'factura', 'nro factura', 'num pedido', 'numero pedido', 'nro pedido', 'pedido'],
            'cliente'          => ['cliente', 'nombre cliente', 'razon social'],
            'sucursal_entrega' => ['sucursal entrega', 'sucursal_entrega', 'sucursal', 'punto entrega', 'destino', 'cliente entrega'],
            'documento'        => ['documento', 'nit', 'cedula', 'cc'],
            'direccion'        => ['direccion', 'dirección', 'dir'],
            'planilla'         => ['planilla', 'planilla numero', 'num planilla', 'num pedido', 'numero pedido', 'nro planilla'],
            'asesor'           => ['asesor', 'comercial', 'vendedor'],
            'producto'         => ['referencia', 'ref', 'barras', 'ean', 'codigo barras', 'producto', 'codigo producto', 'codigo'],
            'cantidad'         => ['cantidad', 'cant', 'qty', 'unidades', 'unid pedido', 'unid_pedido', 'unidades pedido'],
            'costo'            => ['costo', 'precio', 'valor', 'cost'],
            'descuento'        => ['descuento', 'desc', 'descto', 'dcto'],
        ];

        $colMap = [];
        foreach ($ALIASES as $field => $aliases) {
            foreach ($headers as $idx => $h) {
                $hl = strtolower(trim($h));
                foreach ($aliases as $alias) {
                    if ($hl === $alias || str_contains($hl, $alias)) {
                        $colMap[$field] = $idx;
                        break 2;
                    }
                }
            }
        }

        if (!isset($colMap['producto']) || !isset($colMap['cantidad'])) {
            return $this->error($res, 'No se pudieron detectar las columnas de Producto y Cantidad en el archivo. Verifique los encabezados.');
        }

        // ── Pre-audit totals + collect facturas for duplicate check ──────────
        $auditArchivo = [
            'lineas_archivo'   => count($dataLines),
            'clientes_archivo' => 0,
            'cantidad_archivo' => 0,
            'valor_archivo'    => 0,
        ];
        $clientesSet     = [];
        $porSucursalArch = [];
        $facturasSet     = [];
        foreach ($dataLines as $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($colMap as $field => $idx) {
                $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            if (empty(array_filter($row))) continue;
            if (!empty($row['cliente'])) $clientesSet[$row['cliente']] = true;
            $cant  = max(1, (int)($row['cantidad'] ?? 1));
            $costo = (float) str_replace(',', '.', str_replace('.', '', $row['costo'] ?? '0'));
            $auditArchivo['cantidad_archivo'] += $cant;
            $auditArchivo['valor_archivo']    += $cant * $costo;
            $suc = trim($row['sucursal_entrega'] ?? $row['cliente'] ?? '') ?: '(Sin sucursal)';
            $porSucursalArch[$suc] = ($porSucursalArch[$suc] ?? 0) + 1;
            $nf = trim($row['numero_factura'] ?? $row['planilla'] ?? '');
            if ($nf) $facturasSet[$nf] = true;
        }
        $auditArchivo['clientes_archivo'] = count($clientesSet);

        // ── Check which facturas already exist in picking_detalles ───────────
        $facturasExistentes = [];
        $facturasEnArchivo  = array_keys($facturasSet);
        if (!empty($facturasEnArchivo)) {
            try {
                $existing = Capsule::table('picking_detalles')
                    ->join('orden_pickings', 'orden_pickings.id', '=', 'picking_detalles.orden_picking_id')
                    ->where('orden_pickings.empresa_id', $user->empresa_id)
                    ->where('orden_pickings.sucursal_id', $user->sucursal_id)
                    ->whereIn('picking_detalles.numero_pedido_ref', $facturasEnArchivo)
                    ->pluck('picking_detalles.numero_pedido_ref')
                    ->unique()
                    ->toArray();
                $facturasExistentes = array_flip($existing);
            } catch (\Throwable $ignored) {
                // Column not yet in DB — skip duplicate check gracefully
            }
        }

        $summary = [
            'total'                    => 0,
            'total_lineas'             => 0,
            'importadas'               => 0,
            'errores'                  => [],
            'duplicados'               => [],
            'lineas_excluidas'         => [],
            'productos_no_encontrados' => 0,
            'campos_detectados'        => array_keys($colMap),
            'cantidad_sistema'         => 0,
            'valor_sistema'            => 0,
            'clientes_sistema'         => [],
            'por_sucursal_sistema'     => [],
        ];

        // ── Group lines by sucursal_entrega → one OrdenPicking per sucursal ──
        $grupos = [];
        foreach ($dataLines as $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($colMap as $field => $idx) {
                $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            if (empty(array_filter($row))) continue;
            $groupKey = trim($row['sucursal_entrega'] ?? '') ?: trim($row['cliente'] ?? '') ?: '(Sin identificar)';
            $grupos[$groupKey][] = $row;
            $summary['total']++;
        }

        if (empty($grupos)) {
            return $this->error($res, 'No se encontraron filas de datos en el archivo');
        }

        // ── Sequential planilla number ────────────────────────────────────────
        $maxSeq = (int) OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('numero_orden', 'like', 'Planilla %')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(numero_orden, 10) AS UNSIGNED)), 0) as max_seq")
            ->value('max_seq');
        $nextSeq = $maxSeq + 1;

        $cleanNumber = function($val) {
            if (empty($val)) return 0.0;
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
            return (float) $val;
        };

        // ── Process each sucursal group ───────────────────────────────────────
        foreach ($grupos as $sucursal => $filas) {
            // Separate duplicate lines from clean lines; aggregate by factura
            $filasLimpias   = [];
            $dupsPorFactura = [];
            foreach ($filas as $fila) {
                $nf = trim($fila['numero_factura'] ?? $fila['planilla'] ?? '');
                if ($nf && isset($facturasExistentes[$nf])) {
                    if (!isset($dupsPorFactura[$nf])) {
                        $dupsPorFactura[$nf] = ['numero_factura' => $nf, 'sucursal' => $sucursal, 'lineas' => 0];
                    }
                    $dupsPorFactura[$nf]['lineas']++;
                } else {
                    $filasLimpias[] = $fila;
                }
            }
            foreach ($dupsPorFactura as $dup) {
                $summary['duplicados'][] = $dup;
            }

            if (empty($filasLimpias)) continue;

            try {
                Capsule::transaction(function () use (
                    $sucursal, $filasLimpias, $user, &$summary, &$nextSeq, $cleanNumber
                ) {
                    $fila0 = $filasLimpias[0];

                    $orden = OrdenPicking::create([
                        'empresa_id'        => $user->empresa_id,
                        'sucursal_id'       => $user->sucursal_id,
                        'numero_orden'      => 'Planilla ' . $nextSeq,
                        'numero_factura'    => null,
                        'planilla_numero'   => $fila0['planilla'] ?? null,
                        'planilla_lote'     => null,
                        'cliente'           => trim($fila0['cliente'] ?? ''),
                        'sucursal_entrega'  => trim($fila0['sucursal_entrega'] ?? '') ?: $sucursal,
                        'direccion_cliente' => trim($fila0['direccion'] ?? ''),
                        'asesor_comercial'  => trim($fila0['asesor'] ?? ''),
                        'estado'            => 'Pendiente',
                        'fecha_movimiento'  => date('Y-m-d'),
                        'hora_inicio'       => date('H:i:s'),
                        'prioridad'         => 5,
                        'auxiliar_id'       => null,
                    ]);
                    $nextSeq++;

                    $lineasCreadas = 0;
                    foreach ($filasLimpias as $fila) {
                        $ean  = trim($fila['producto'] ?? '');
                        $prod = null;

                        if ($ean) {
                            $eanRec = \App\Models\ProductoEan::where('codigo_ean', $ean)->first();
                            if ($eanRec) {
                                $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                                    ->find($eanRec->producto_id);
                            }
                            if (!$prod) {
                                $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                                    ->where('codigo_interno', $ean)->first();
                            }
                            if (!$prod && strlen($ean) > 6) {
                                $eanRec = \App\Models\ProductoEan::where('codigo_ean', 'like', '%' . substr($ean, -10))->first();
                                if ($eanRec) {
                                    $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                                        ->find($eanRec->producto_id);
                                }
                            }
                        }

                        if (!$prod) {
                            $summary['productos_no_encontrados']++;
                            $nfRef = trim($fila['numero_factura'] ?? $fila['planilla'] ?? '');
                            $summary['lineas_excluidas'][] = [
                                'tipo'           => 'producto_no_encontrado',
                                'ean'            => $ean,
                                'numero_factura' => $nfRef,
                                'sucursal'       => $sucursal,
                                'razon'          => "Producto '{$ean}' no encontrado en catálogo",
                            ];
                            continue;
                        }

                        $cantidad  = max(1, (int)($fila['cantidad'] ?? 1));
                        $costo     = $cleanNumber($fila['costo'] ?? '0');
                        $descuento = (float)($fila['descuento'] ?? 0);
                        $nfRef     = trim($fila['numero_factura'] ?? $fila['planilla'] ?? '');

                        PickingDetalle::create([
                            'orden_picking_id'    => $orden->id,
                            'producto_id'         => $prod->id,
                            'cantidad_solicitada' => $cantidad,
                            'cantidad_pickeada'   => 0,
                            'costo_unitario'      => $costo,
                            'descuento_porc'      => $descuento,
                            'estado'              => 'Pendiente',
                            'ambiente'            => $this->_clasificarAmbiente('', $prod->categoria ?? ''),
                            'numero_pedido_ref'   => $nfRef ?: null,
                        ]);

                        $lineasCreadas++;
                        $summary['cantidad_sistema'] += $cantidad;
                        $summary['valor_sistema']    += $cantidad * $costo;
                        if (!empty(trim($fila0['cliente'] ?? ''))) {
                            $summary['clientes_sistema'][trim($fila0['cliente'])] = true;
                        }
                    }

                    $summary['total_lineas'] += $lineasCreadas;
                    if ($lineasCreadas > 0) {
                        $summary['por_sucursal_sistema'][$sucursal] =
                            ($summary['por_sucursal_sistema'][$sucursal] ?? 0) + $lineasCreadas;
                    }

                    if ($lineasCreadas === 0) {
                        $orden->delete();
                        $nextSeq--;
                        throw new \Exception('Ningún producto fue encontrado en el catálogo');
                    }
                });
                $summary['importadas']++;
            } catch (\Exception $e) {
                $summary['errores'][] = "Sucursal '{$sucursal}': " . $e->getMessage();
            }
        }

        $msg = "Importación completada: {$summary['importadas']} planilla(s) de picking creada(s)";
        if (!empty($summary['duplicados'])) {
            $msg .= '. ' . count($summary['duplicados']) . ' pedido(s) bloqueado(s) por duplicado';
        }
        if ($summary['productos_no_encontrados'] > 0) {
            $msg .= ". {$summary['productos_no_encontrados']} producto(s) no encontrado(s)";
        }
        if (!empty($summary['errores'])) {
            $msg .= '. Errores: ' . count($summary['errores']);
        }

        $audit = [
            'archivo' => $auditArchivo,
            'sistema' => [
                'lineas_sistema'   => $summary['total_lineas'],
                'clientes_sistema' => count($summary['clientes_sistema']),
                'cantidad_sistema' => $summary['cantidad_sistema'],
                'valor_sistema'    => round($summary['valor_sistema'], 2),
            ],
        ];
        $audit['diferencias'] = [
            'lineas'   => $auditArchivo['lineas_archivo'] - $summary['total_lineas'],
            'clientes' => $auditArchivo['clientes_archivo'] - count($summary['clientes_sistema']),
            'cantidad' => $auditArchivo['cantidad_archivo'] - $summary['cantidad_sistema'],
            'valor'    => round($auditArchivo['valor_archivo'] - $summary['valor_sistema'], 2),
        ];
        $audit['por_sucursal'] = [
            'archivo' => $porSucursalArch,
            'sistema' => $summary['por_sucursal_sistema'],
        ];
        unset($summary['clientes_sistema'], $summary['por_sucursal_sistema']);

        $response = $res;
        $response->getBody()->write(json_encode([
            'error'      => false,
            'importadas' => $summary['importadas'],
            'message'    => $msg,
            'data'       => $summary,
            'audit'      => $audit,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── POST /api/picking/{id}/marcar-faltante ───────────────────────────────
    public function marcarFaltante(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $lineaId = (int)($data['linea_id'] ?? 0);
        $obs     = trim($data['observacion'] ?? '');

        $linea = PickingDetalle::where('orden_picking_id', $orden->id)->find($lineaId);
        if (!$linea) return $this->notFound($res, 'Línea no encontrada');

        $linea->estado      = 'Faltante';
        $linea->observacion = $obs ?: 'Sin stock disponible';
        $linea->save();

        // Si todas las líneas están resueltas, cerrar la orden
        $abiertas = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->count();
        if ($abiertas === 0) {
            $orden->estado   = 'Completada';
            $orden->hora_fin = date('H:i:s');
            $orden->save();
        }

        $this->audit($user, 'picking', 'marcar_faltante', 'picking_detalles', $linea->id,
            null, ['observacion' => $obs], "Línea marcada como faltante en orden {$orden->numero_orden}");

        return $this->ok($res, $linea, 'Línea marcada como faltante');
    }

    // ── POST /api/picking/reabast/{id}/completar ─────────────────────────────
    public function completarReabastLegacy(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $tarea = TareaReabastecimiento::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($a['id']);
        if (!$tarea) return $this->notFound($res);

        if ($tarea->estado === 'Completada') {
            return $this->error($res, 'La tarea ya fue completada');
        }

        try {
            Capsule::transaction(function () use ($tarea, $user) {
                // Move stock from source to destination
                if ($tarea->ubicacion_origen_id && $tarea->ubicacion_destino_id && $tarea->producto_id) {
                    $origen = Inventario::where('empresa_id',  $user->empresa_id)
                        ->where('sucursal_id',  $user->sucursal_id)
                        ->where('producto_id',  $tarea->producto_id)
                        ->where('ubicacion_id', $tarea->ubicacion_origen_id)
                        ->where('estado', 'Disponible')
                        ->first();

                    if ($origen && $origen->cantidad >= $tarea->cantidad) {
                        $origen->cantidad -= $tarea->cantidad;
                        if ($origen->cantidad === 0) $origen->delete();
                        else $origen->save();

                        $destino = Inventario::firstOrNew([
                            'empresa_id'   => $user->empresa_id,
                            'sucursal_id'  => $user->sucursal_id,
                            'producto_id'  => $tarea->producto_id,
                            'ubicacion_id' => $tarea->ubicacion_destino_id,
                            'estado'       => 'Disponible',
                        ]);
                        $destino->cantidad = ($destino->cantidad ?? 0) + $tarea->cantidad;
                        $destino->save();

                        MovimientoInventario::create([
                            'empresa_id'           => $user->empresa_id,
                            'sucursal_id'          => $user->sucursal_id,
                            'producto_id'          => $tarea->producto_id,
                            'tipo_movimiento'      => 'Traslado',
                            'cantidad'             => $tarea->cantidad,
                            'ubicacion_origen_id'  => $tarea->ubicacion_origen_id,
                            'ubicacion_destino_id' => $tarea->ubicacion_destino_id,
                            'auxiliar_id'          => $user->id,
                            'referencia_tipo'      => 'Reabastecimiento',
                            'referencia_id'        => $tarea->id,
                            'observaciones'        => 'Reabastecimiento completado',
                            'fecha_movimiento'     => date('Y-m-d'),
                            'hora_inicio'          => date('H:i:s'),
                        ]);
                    }
                }

                $tarea->estado   = 'Completada';
                $tarea->auxiliar_id = $user->id;
                $tarea->save();
            });

            $this->audit($user, 'picking', 'completar_reabast', 'tareas_reabastecimiento', $tarea->id,
                null, [], "Reabastecimiento #{$tarea->id} completado");

            return $this->ok($res, $tarea, 'Reabastecimiento completado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }
    // ── PICKING PROFESIONAL (Basado en Planillas) ───────────────────────────

    public function misPlanillas(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        
        $planillas = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->where(function($q) use ($user) {
                $q->where('auxiliar_id', $user->id)
                  ->orWhereHas('detalles', fn($dq) => $dq->where('auxiliar_id', $user->id));
            })
            ->select('planilla_numero')
            ->selectRaw('MAX(area_comercial) as ruta')
            ->selectRaw('MAX(hora_inicio) as hora_inicio')
            ->groupBy('planilla_numero')
            ->orderBy('planilla_numero', 'desc')
            ->get();

        // Calcular métricas para cada planilla
        foreach ($planillas as $p) {
            $detalles = PickingDetalle::whereHas('ordenPicking', fn($q) => $q->where('planilla_numero', $p->planilla_numero))
                ->join('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->where('picking_detalles.auxiliar_id', $user->id)
                ->whereIn('picking_detalles.estado', ['Pendiente', 'EnProceso', 'Completado', 'Faltante'])
                ->select('picking_detalles.*', 'productos.unidades_caja')
                ->get();
            
            $p->total_lineas   = $detalles->count();
            $p->total_unidades = (int)$detalles->sum('cantidad_solicitada');
            
            // Cálculo de cajas totales
            $cajasTotal = 0;
            foreach ($detalles as $d) {
                $factor = (int)($d->unidades_caja ?: 1);
                $cajasTotal += $d->cantidad_solicitada / $factor;
            }
            $p->total_cajas = round($cajasTotal, 1);
            $p->empezada    = !empty($p->hora_inicio) && $p->hora_inicio !== '00:00:00';
        }

        return $this->ok($res, $planillas);
    }

    public function planillaDetalles(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $numero = $a['numero'] ?? null;
        $estado = $r->getQueryParams()['estado'] ?? 'Pendiente,EnProceso';
        $estados = explode(',', $estado);

        if (!$numero) return $this->error($res, 'Número de planilla requerido.', 400);

        try {
            // Consolidar por Producto + Ubicación
            $detalles = PickingDetalle::join('orden_pickings', 'picking_detalles.orden_picking_id', '=', 'orden_pickings.id')
                ->leftJoin('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->leftJoin('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
                ->where('orden_pickings.empresa_id', $user->empresa_id)
                ->where('orden_pickings.planilla_numero', $numero)
                ->whereIn('picking_detalles.estado', $estados)
                ->select(
                    'productos.nombre as producto_nombre',
                    'productos.codigo_interno as producto_codigo',
                    'productos.unidades_caja',
                    'ubicaciones.codigo as ubicacion_codigo',
                    'ubicaciones.id as ubicacion_id',
                    'picking_detalles.lote',
                    'picking_detalles.fecha_vencimiento',
                    Capsule::raw('SUM(picking_detalles.cantidad_total) as cantidad_total'),
                    Capsule::raw('SUM(picking_detalles.cantidad_pickeada) as cantidad_pick'),
                    Capsule::raw($this->isPg() ? 'STRING_AGG(picking_detalles.id::text, \',\') as ids' : 'GROUP_CONCAT(picking_detalles.id) as ids')
                )
                ->groupBy(
                    'productos.id', 
                    'productos.nombre', 
                    'productos.codigo_interno', 
                    'productos.unidades_caja', 
                    'ubicaciones.id', 
                    'ubicaciones.codigo',
                    'picking_detalles.lote', 
                    'picking_detalles.fecha_vencimiento'
                )
                ->orderBy(Capsule::raw('SUM(picking_detalles.cantidad_pickeada)'), 'asc')
                ->orderBy('ubicaciones.codigo')
                ->get();

            // Formatear para el Wizard Móvil (Cajas / Picos)
            $detalles->transform(function($it) {
                $factor = (int)($it->unidades_caja ?: 1);
                $it->cajas = floor($it->cantidad_total / $factor);
                $it->picos = $it->cantidad_total % $factor;
                return $it;
            });

            return $this->ok($res, $detalles);
        } catch (\Exception $e) {
            error_log('PickingController::planillaDetalles error: ' . $e->getMessage());
            return $this->error($res, 'Error al cargar detalles de planilla.', 500);
        }
    }

    /**
     * POST /api/picking/planilla/{numero}/iniciar
     * Marca la hora de inicio de la planilla para el auxiliar.
     */
    public function iniciarPlanilla(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $numero = $a['numero'] ?? null;

        if (!$numero) {
            return $this->error($res, 'Número de planilla requerido.', 400);
        }

        try {
            $updated = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('planilla_numero', $numero)
                ->where('auxiliar_id', $user->id)
                ->whereNull('hora_inicio')
                ->update(['hora_inicio' => date('H:i:s'), 'estado' => 'EnProceso']);

            if ($updated === 0) {
                // Already started or not assigned
                $exists = OrdenPicking::where('empresa_id', $user->empresa_id)
                    ->where('planilla_numero', $numero)->exists();
                if (!$exists) {
                    return $this->notFound($res, 'Planilla no encontrada.');
                }
            }

            return $this->ok($res, ['planilla_numero' => $numero, 'hora_inicio' => date('H:i:s')],
                'Planilla iniciada');
        } catch (\Exception $e) {
            error_log('PickingController::iniciarPlanilla error: ' . $e->getMessage());
            return $this->error($res, 'Error al iniciar planilla.', 500);
        }
    }

    /**
     * POST /api/picking/confirmar-consolidado
     * Confirma que un consolidado ha sido verificado y cerrado.
     */
    public function confirmarConsolidado(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $body = $r->getParsedBody() ?? [];
        $id   = $body['orden_id'] ?? null;

        if (!$id) {
            return $this->error($res, 'orden_id requerido.', 400);
        }

        try {
            $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->findOrFail($id);

            if ($orden->estado !== 'Completado') {
                return $this->error($res, 'La orden no está completada.', 422);
            }

            $orden->estado = 'Consolidado';
            $orden->save();

            $this->audit($user, 'picking', 'confirmar_consolidado', 'ordenes_picking', $orden->id,
                null, ['estado' => 'Completado'], "Consolidado confirmado #{$orden->id}");

            return $this->ok($res, $orden, 'Consolidado confirmado');
        } catch (\Exception $e) {
            error_log('PickingController::confirmarConsolidado error: ' . $e->getMessage());
            return $this->error($res, 'Error al confirmar consolidado.', 500);
        }
    }

    /**
     * POST /api/picking/asignar-consolidado
     * Asigna un auxiliar a un grupo de órdenes (consolidado).
     */
    public function asignarConsolidado(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $body = $r->getParsedBody() ?? [];
        $ids       = $body['orden_ids']   ?? [];
        $auxiliarId = (int)($body['auxiliar_id'] ?? 0);

        if (empty($ids) || !$auxiliarId) {
            return $this->error($res, 'orden_ids y auxiliar_id son requeridos.', 400);
        }

        try {
            $updated = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->whereIn('id', $ids)
                ->update(['auxiliar_id' => $auxiliarId]);

            $this->audit($user, 'picking', 'asignar_consolidado', 'ordenes_picking', null,
                null, [], "Consolidado asignado a auxiliar #{$auxiliarId} — {$updated} órdenes");

            return $this->ok($res, ['updated' => $updated], 'Consolidado asignado');
        } catch (\Exception $e) {
            error_log('PickingController::asignarConsolidado error: ' . $e->getMessage());
            return $this->error($res, 'Error al asignar consolidado.', 500);
        }
    }

    /**
     * GET /api/picking/reporte
     * Retorna resumen operativo del picking con filtros de fecha, ruta y sucursal_entrega.
     */
    public function reporte(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $fechaDesde = $params['fecha_desde'] ?? null;
        $fechaHasta = $params['fecha_hasta'] ?? null;

        if (!$fechaDesde || !$fechaHasta) {
            return $this->ok($res, [
                'ordenes'     => [],
                'resumen'     => ['total'=>0,'completadas'=>0,'faltantes'=>0,'duracion_prom_min'=>0],
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ]);
        }

        try {
            $q = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereBetween('fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->when($params['ruta'] ?? null,
                    fn($q, $v) => $q->where('ruta', 'like', "%$v%"))
                ->when($params['sucursal_entrega'] ?? null,
                    fn($q, $v) => $q->where('sucursal_entrega', 'like', "%$v%"))
                ->withCount([
                    'detalles as completadas_count' => fn($q) => $q->where('estado', 'Completado'),
                    'detalles as faltantes_count'   => fn($q) => $q->where('estado', 'Faltante'),
                    'detalles as total_lineas_count',
                ])
                ->with([
                    'detalles.auxiliar:id,nombre',
                    'auxiliar:id,nombre',
                ])
                ->orderBy('fecha_movimiento', 'DESC')
                ->orderBy('created_at', 'DESC');

            $ordenes = $q->get();

            $rows = $ordenes->map(function($o) {
                $auxNombres = $o->detalles->pluck('auxiliar.nombre')
                    ->filter()->unique()->values()->join(', ');
                if (!$auxNombres && $o->auxiliar) $auxNombres = $o->auxiliar->nombre;

                $durMin = null;
                if ($o->hora_inicio && $o->hora_fin) {
                    $ini = strtotime($o->fecha_movimiento . ' ' . $o->hora_inicio);
                    $fin = strtotime($o->fecha_movimiento . ' ' . $o->hora_fin);
                    if ($fin > $ini) $durMin = round(($fin - $ini) / 60);
                }

                $total = $o->total_lineas_count ?: 0;
                $comp  = $o->completadas_count  ?: 0;
                return [
                    'id'               => $o->id,
                    'fecha'            => $o->fecha_movimiento,
                    'numero_orden'     => $o->numero_orden,
                    'numero_pedido'    => $o->numero_pedido,
                    'cliente'          => $o->cliente,
                    'sucursal_entrega' => $o->sucursal_entrega,
                    'ruta'             => $o->ruta,
                    'estado'           => $o->estado,
                    'total_lineas'     => $total,
                    'completadas'      => $comp,
                    'faltantes'        => $o->faltantes_count ?: 0,
                    'pct_cumplimiento' => $total > 0 ? round($comp / $total * 100, 1) : 0,
                    'auxiliares'       => $auxNombres ?: '—',
                    'hora_inicio'      => $o->hora_inicio,
                    'hora_fin'         => $o->hora_fin,
                    'duracion_min'     => $durMin,
                ];
            });

            $duraciones  = $rows->pluck('duracion_min')->filter();
            $durPromedio = $duraciones->isNotEmpty() ? round($duraciones->avg()) : 0;

            return $this->ok($res, [
                'ordenes'     => $rows->values(),
                'resumen'     => [
                    'total'             => $rows->count(),
                    'completadas'       => $rows->where('estado','Completada')->count(),
                    'faltantes'         => $rows->sum('faltantes'),
                    'duracion_prom_min' => $durPromedio,
                ],
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ]);
        } catch (\Exception $e) {
            error_log('reporte error: ' . $e->getMessage());
            return $this->error($res, 'Error generando reporte.', 500);
        }
    }

    private function _clasificarAmbiente(string $zona, string $categoria): string
    {
        $z = strtolower($zona);
        $c = strtolower($categoria);
        if (str_contains($z, 'congel') || str_contains($c, 'congel')) return 'Congelado';
        if (str_contains($z, 'refrig') || str_contains($z, 'frio') || str_contains($z, 'frío') ||
            str_contains($z, 'lácteo') || str_contains($z, 'lacteo') ||
            str_contains($c, 'refrig') || str_contains($c, 'frio') || str_contains($c, 'lácteo') ||
            str_contains($c, 'lacteo')) return 'Refrigerado';
        return 'Seco';
    }

    private function _reservarInventarioBatch(array $ordenIds, object $user): void
    {
        $now      = date('Y-m-d H:i:s');
        $detalles = PickingDetalle::whereIn('orden_picking_id', $ordenIds)
            ->where('estado', 'EnProceso')
            ->whereNotNull('auxiliar_id')
            ->get();

        if ($detalles->isEmpty()) return;

        $productoIds     = $detalles->pluck('producto_id')->unique()->toArray();
        $stockDisponible = Inventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('producto_id', $productoIds)
            ->where('estado', 'Disponible')
            ->whereRaw('(cantidad - cantidad_reservada) > 0')
            ->lockForUpdate()
            ->orderByRaw('fecha_vencimiento IS NULL ASC')
            ->orderBy('fecha_vencimiento', 'ASC')
            ->get();

        $stockPorProducto = $stockDisponible->groupBy('producto_id');
        foreach ($detalles as $linea) {
            $restante = (float)$linea->cantidad_solicitada;
            foreach ($stockPorProducto->get($linea->producto_id, collect()) as $inv) {
                if ($restante <= 0) break;
                $disponible = max(0, $inv->cantidad - $inv->cantidad_reservada);
                if ($disponible <= 0) continue;
                $reservar                = min($disponible, $restante);
                $inv->cantidad_reservada += $reservar;
                $inv->save();
                $restante -= $reservar;
            }
        }
    }

    public function asignarPorAmbiente(Request $r, Response $res): Response
    {
        $user     = $r->getAttribute('user');
        $data     = $r->getParsedBody() ?? [];
        $ordenIds = array_map('intval', $data['orden_ids'] ?? []);
        $modo     = $data['modo'] ?? 'ambiente';
        $config   = $data['config'] ?? [];
        $ruta     = trim($data['ruta'] ?? '');

        if (empty($ordenIds))           return $this->error($res, 'Se requieren orden_ids');
        if (!in_array($modo, ['ambiente','pasillo']))
                                        return $this->error($res, 'Modo inválido: use "ambiente" o "pasillo"');

        try {
            $resultado = Capsule::transaction(function () use ($ordenIds, $modo, $config, $ruta, $user) {
                $now = date('Y-m-d H:i:s');

                // 1+2. Cargar TODAS las líneas con lock pesimista y detectar colisiones en PHP
                $todasLineas = Capsule::table('picking_detalles as pd')
                    ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
                    ->leftJoin('ubicaciones as u', 'pd.ubicacion_id', '=', 'u.id')
                    ->leftJoin('productos as pr', 'pd.producto_id', '=', 'pr.id')
                    ->where('op.empresa_id', $user->empresa_id)
                    ->where('op.sucursal_id', $user->sucursal_id)
                    ->whereIn('pd.orden_picking_id', $ordenIds)
                    ->select(['pd.id','pd.orden_picking_id','pd.auxiliar_id','pd.estado','u.zona','u.pasillo','pr.categoria'])
                    ->lockForUpdate()
                    ->get();

                $colisionIds = $todasLineas->filter(fn($l) => $l->auxiliar_id !== null)
                    ->pluck('orden_picking_id')->unique()->values();
                if ($colisionIds->isNotEmpty()) {
                    throw new \RuntimeException(json_encode([
                        'tipo'      => 'colision',
                        'orden_ids' => $colisionIds->toArray(),
                    ]));
                }

                $lineas = $todasLineas->filter(fn($l) => $l->estado === 'Pendiente' && $l->auxiliar_id === null);

                // 3. Clasificar cada línea por ambiente
                foreach ($lineas as $linea) {
                    $linea->amb = $this->_clasificarAmbiente($linea->zona ?? '', $linea->categoria ?? '');
                }

                // 4. Determinar auxiliar por línea
                $porAuxiliar  = [];  // [auxId => [lineaId,...]]
                $porAmbiente  = ['Seco' => 0, 'Refrigerado' => 0, 'Congelado' => 0];
                $sinAuxiliar  = 0;

                foreach ($lineas as $linea) {
                    if ($modo === 'ambiente') {
                        $auxId = $config[$linea->amb]['auxiliar_id'] ?? null;
                    } else {
                        $auxId = null;
                        foreach (($config['rangos'] ?? []) as $rng) {
                            if (($linea->pasillo ?? '') >= ($rng['pasillo_desde'] ?? '') &&
                                ($linea->pasillo ?? '') <= ($rng['pasillo_hasta'] ?? '')) {
                                $auxId = $rng['auxiliar_id'] ?? null;
                                break;
                            }
                        }
                    }
                    if ($auxId) {
                        $porAuxiliar[$auxId][] = $linea->id;
                        $porAmbiente[$linea->amb] = ($porAmbiente[$linea->amb] ?? 0) + 1;
                    } else {
                        // Sin auxiliar: actualizar solo el campo ambiente
                        Capsule::table('picking_detalles')
                            ->where('id', $linea->id)
                            ->update(['ambiente' => $linea->amb, 'updated_at' => $now]);
                        $sinAuxiliar++;
                    }
                }

                // 5. UPDATE picking_detalles por auxiliar
                $totalAsignadas = 0;
                $ambPorId = collect($lineas)->pluck('amb', 'id')->toArray();
                foreach ($porAuxiliar as $auxId => $ids) {
                    foreach ($ids as $lineaId) {
                        Capsule::table('picking_detalles')
                            ->where('id', $lineaId)
                            ->update([
                                'auxiliar_id' => $auxId,
                                'ambiente'    => $ambPorId[$lineaId] ?? 'Seco',
                                'estado'      => 'EnProceso',
                                'updated_at'  => $now,
                            ]);
                    }
                    $totalAsignadas += count($ids);
                }

                // 6. Actualizar orden_pickings: estado + ruta + orden_logico
                foreach ($ordenIds as $i => $ordId) {
                    $upd = ['estado' => 'EnProceso', 'updated_at' => $now, 'orden_logico' => $i + 1];
                    if ($ruta) $upd['ruta'] = $ruta;
                    Capsule::table('orden_pickings')->where('id', $ordId)->update($upd);
                }

                // 7. Reservar inventario
                $this->_reservarInventarioBatch($ordenIds, $user);

                // 8. Log de auditoría
                Capsule::table('picking_asignaciones_log')->insert([
                    'empresa_id'   => $user->empresa_id,
                    'sucursal_id'  => $user->sucursal_id,
                    'ordenes_json' => json_encode($ordenIds),
                    'modo'         => $modo,
                    'config_json'  => json_encode($config),
                    'lineas_total' => $totalAsignadas,
                    'ruta'         => $ruta ?: null,
                    'asignado_por' => $user->id,
                    'created_at'   => $now,
                ]);

                return [
                    'asignadas'    => $totalAsignadas,
                    'por_ambiente' => $porAmbiente,
                    'sin_auxiliar' => $sinAuxiliar,
                    'ordenes'      => count($ordenIds),
                ];
            });

            return $this->ok($res, $resultado, 'Asignación completada');

        } catch (\RuntimeException $e) {
            $decoded = json_decode($e->getMessage(), true);
            if (($decoded['tipo'] ?? '') === 'colision') {
                $payload = json_encode(['error' => 'Algunos pedidos ya tienen líneas asignadas.', 'orden_ids_en_conflicto' => $decoded['orden_ids']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $res->getBody()->write($payload);
                return $res->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            return $this->error($res, $e->getMessage(), 500);
        } catch (\Exception $e) {
            error_log('asignarPorAmbiente error: ' . $e->getMessage());
            return $this->error($res, 'Error en asignación: ' . $e->getMessage(), 500);
        }
    }

    public function asignarRutaOrden(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $ruta  = trim($data['ruta'] ?? '');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$orden) return $this->notFound($res);
        $orden->ruta = $ruta ?: null;
        $orden->save();
        return $this->ok($res, ['id' => $orden->id, 'ruta' => $orden->ruta], 'Ruta actualizada');
    }

    // ── CERTIFICACIÓN POR SUCURSAL ───────────────────────────────────────────
    
    public function certPendientes(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        
        $sucursales = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('estado', 'Completada')
            ->where('estado_certificacion', 'Pendiente')
            ->select('sucursal_entrega', Capsule::raw('COUNT(*) as total_pedidos'), Capsule::raw('SUM( (SELECT COUNT(*) FROM picking_detalles WHERE orden_picking_id = orden_pickings.id) ) as total_lineas'))
            ->groupBy('sucursal_entrega')
            ->get();
            
        return $this->ok($res, $sucursales);
    }

    public function certDetalle(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $sucursal = urldecode($a['sucursal']);
        
        $detalles = PickingDetalle::whereHas('ordenPicking', function($q) use ($user, $sucursal) {
                $q->where('empresa_id', $user->empresa_id)
                  ->where('sucursal_id', $user->sucursal_id)
                  ->where('sucursal_entrega', $sucursal)
                  ->where('estado', 'Completada')
                  ->where('estado_certificacion', 'Pendiente');
            })
            ->with(['producto:id,nombre,codigo_interno,codigo_barras', 'ordenPicking:id,numero_orden,cliente'])
            ->get();
            
        // Consolidation by product
        $consolidado = [];
        foreach ($detalles as $d) {
            $pid = $d->producto_id;
            if (!isset($consolidado[$pid])) {
                $consolidado[$pid] = [
                    'producto_id' => $pid,
                    'nombre'      => $d->producto->nombre ?? 'Desconocido',
                    'codigo'      => $d->producto->codigo_interno ?? $d->producto->codigo_barras ?? '-',
                    'ean'         => $d->producto->codigo_barras ?? '-',
                    'cantidad_pickeada' => 0,
                    'cantidad_certificada' => 0,
                    'detalles_ids' => []
                ];
            }
            $consolidado[$pid]['cantidad_pickeada']    += (float)$d->cantidad_pickeada;
            $consolidado[$pid]['cantidad_certificada'] += (float)$d->cantidad_certificada;
            $consolidado[$pid]['detalles_ids'][]       = $d->id;
        }
        
        return $this->ok($res, array_values($consolidado));
    }

    public function certConfirmar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();
        
        $productoId = $data['producto_id'];
        $sucursal   = $data['sucursal_entrega'];
        $cantidad   = (float)$data['cantidad'];
        
        $detalles = PickingDetalle::where('producto_id', $productoId)
            ->whereHas('ordenPicking', function($q) use ($user, $sucursal) {
                $q->where('empresa_id', $user->empresa_id)
                  ->where('sucursal_id', $user->sucursal_id)
                  ->where('sucursal_entrega', $sucursal)
                  ->where('estado', 'Completada')
                  ->where('estado_certificacion', 'Pendiente');
            })
            ->orderBy('id', 'asc')
            ->get();
            
        if ($detalles->isEmpty()) return $this->error($res, 'No se encontraron líneas pendientes para certificar');

        Capsule::transaction(function() use ($detalles, $cantidad) {
            $restante = $cantidad;
            foreach ($detalles as $d) {
                $capacidad = (float)$d->cantidad_pickeada;
                $tomar = min($restante, $capacidad);
                $d->cantidad_certificada = $tomar;
                $d->estado_certificacion = ($tomar >= $capacidad) ? 'Certificado' : 'Diferencia';
                $d->save();
                $restante -= $tomar;
            }
        });
        
        return $this->ok($res, null, 'Certificación de producto registrada');
    }

    public function certFinalizar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();
        $sucursal = $data['sucursal_entrega'];
        
        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado', 'Completada')
            ->where('estado_certificacion', 'Pendiente')
            ->get();
            
        if ($ordenes->isEmpty()) return $this->error($res, 'No hay órdenes pendientes para finalizar');

        Capsule::transaction(function() use ($ordenes, $user) {
            foreach ($ordenes as $o) {
                $o->estado_certificacion = 'Certificada';
                $o->fecha_certificacion  = date('Y-m-d H:i:s');
                $o->certificador_id      = $user->id;
                $o->save();
                
                // Audit differences as novedades
                foreach ($o->detalles as $d) {
                    $diff = (float)$d->cantidad_pickeada - (float)$d->cantidad_certificada;
                    if ($diff != 0) {
                        $this->audit($user, 'picking', 'novedad_certificacion', 'picking_detalles', $d->id,
                            ['pick' => $d->cantidad_pickeada], ['cert' => $d->cantidad_certificada],
                            "Diferencia en certificación: Pedido {$o->numero_orden}, Producto ID {$d->producto_id}. Faltan " . abs($diff));
                    }
                }
            }
        });
        
        return $this->ok($res, null, 'Certificación de sucursal finalizada correctamente');
    }

    public function imprimirCertificado(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $sucursal = urldecode($a['sucursal']);
        
        // 1. Get info to print
        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado_certificacion', 'Certificada')
            ->get();

        if ($ordenes->isEmpty()) return $this->error($res, 'No se encontraron órdenes certificadas para esta sucursal');

        $totalLineas = PickingDetalle::whereIn('orden_picking_id', $ordenes->pluck('id'))->count();
        
        // 2. Get printers assigned to the 'certificacion' module
        $pRotulos = \App\Models\Impresora::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('modulos', 'LIKE', '%certificacion%')
            ->where('tipo', 'Rotulos')
            ->where('activo', true)
            ->first();
            
        $pDespacho = \App\Models\Impresora::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('modulos', 'LIKE', '%certificacion%')
            ->where('tipo', 'Despacho')
            ->where('activo', true)
            ->first();

        $results = [];

        // 3. Print Label (ZPL)
        if ($pRotulos) {
            $zpl = \App\Helpers\PrintHelper::generateZPL($sucursal, [
                'pedidos' => $ordenes->count(),
                'lineas'  => $totalLineas
            ]);
            $results['label'] = \App\Helpers\PrintHelper::sendToPrinter($pRotulos->ip, $pRotulos->puerto, $zpl);
        } else {
            $results['label'] = ['error' => true, 'message' => 'No hay impresora de rótulos configurada'];
        }

        // 4. Print Document (Mockup or ESC/POS if applicable)
        if ($pDespacho) {
            $text = "--- DOCUMENTO DE DESPACHO ---\n";
            $text .= "Sucursal: $sucursal\n";
            $text .= "Fecha: " . date('Y-m-d H:i') . "\n";
            $text .= "-----------------------------\n";
            foreach($ordenes as $o) {
                $text .= "Orden: {$o->numero_orden} - {$o->cliente}\n";
            }
            $text .= "-----------------------------\n";
            $text .= "Total Pedidos: " . $ordenes->count() . "\n";
            $text .= "Total Lineas: $totalLineas\n";
            $text .= "\n\n\n\n"; // Space for cutter
            
            $results['document'] = \App\Helpers\PrintHelper::sendToPrinter($pDespacho->ip, $pDespacho->puerto, $text);
        } else {
            $results['document'] = ['error' => true, 'message' => 'No hay impresora de despacho configurada'];
        }

        return $this->ok($res, $results, 'Proceso de impresión completado');
    }
}
