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
    //                       planilla, sin_auxiliar, fecha_inicio, fecha_fin, limit
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $limit = min((int)($params['limit'] ?? 100), 500);

        $q = OrdenPicking::where('orden_pickings.empresa_id', $user->empresa_id)
            ->where('orden_pickings.sucursal_id', $user->sucursal_id)
            ->whereBetween('orden_pickings.created_at', [$ini, $fin])
            ->when($params['estado'] ?? null, function($q, $e) {
                if (strpos($e, ',') !== false) {
                    $q->whereIn('estado', explode(',', $e));
                } else {
                    $q->where('estado', $e);
                }
            })
            ->when($params['auxiliar_id']  ?? null, fn($q, $v) => $q->where('auxiliar_id', (int)$v))
            ->when($params['sin_auxiliar'] ?? null, fn($q)     => $q->whereNull('auxiliar_id'))
            ->when($params['cliente']      ?? null, fn($q, $v) => $q->where('cliente', 'like', "%$v%"));

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
    // Asigna auxiliar y/o genera rutas para múltiples órdenes en un solo POST
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

        $resultados = ['asignadas' => 0, 'rutas_generadas' => 0, 'errores' => []];

        /** @var OrdenPicking $orden */
        foreach ($ordenes as $orden) {
            try {
                // Asignar auxiliar si se indicó
                if ($auxiliarId !== null) {
                    $orden->auxiliar_id = $auxiliarId;
                    if ($separarConsolidado) {
                        $orden->tipo_picking = 'Consolidado Almacenamiento';
                    }
                    $orden->save();

                    // Sincronizar auxiliar en todas las líneas de la orden
                    Capsule::table('picking_detalles')
                        ->where('orden_picking_id', $orden->id)
                        ->update(['auxiliar_id' => $auxiliarId, 'updated_at' => date('Y-m-d H:i:s')]);

                    $resultados['asignadas']++;
                }

                // Generar ruta FEFO si se solicitó y la orden está Pendiente
                if ($generarRuta && $orden->estado === 'Pendiente') {
                    $this->_generarRutaFEFO($orden, $user);
                    $resultados['rutas_generadas']++;
                }
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
                "Se le han asignado {$resultados['asignadas']} órdenes para alistamiento.",
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

        $this->audit($user, 'picking', 'asignar_multiple', 'orden_pickings', null,
            null, $resultados, "Asignación masiva: {$resultados['asignadas']} órdenes");

        return $this->ok($res, $resultados,
            "{$resultados['asignadas']} asignadas, {$resultados['rutas_generadas']} rutas generadas");
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
    // Filtros: fecha_inicio, fecha_fin, numero_planilla, producto, limit, export=excel
    public function novedadesStockLegacy(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

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
                'f.planilla_lote as numero_planilla',
                'o.cliente',
                'o.asesor_comercial as asesor',
                'aux.nombre as auxiliar',
                'p.codigo_interno as producto_codigo',
                'p.nombre as producto_nombre',
                'f.cantidad_solicitada',
                Capsule::raw('(f.cantidad_solicitada - f.cantidad_faltante) as stock_disponible'),
                'f.cantidad_faltante'
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
                        'Código', 'Producto', 'Solicitado', 'Stock Disponible', 'Faltante'];
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
            ])->toArray();
            return $this->exportCsv($res, $headers, $data, 'faltantes_picking_' . date('Y-m-d'));
        }

        return $this->ok($res, ['rows' => $rows, 'total' => $total]);
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
                // Descontar inventario — re-verificamos con bloqueo pesimista
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

                // Registrar movimiento
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
                    'observaciones'        => "Picking orden {$orden->numero_orden}",
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
        if ($orden->estado === 'Completada') {
            return $this->error($res, 'No se puede eliminar una orden completada');
        }

        $snapshot = $orden->toArray();
        $orden->detalles()->delete();
        $orden->delete();

        $this->audit($user, 'picking', 'eliminar', 'orden_pickings', $a['id'],
            $snapshot, null, "Orden {$snapshot['numero_orden']} eliminada por Admin");

        return $this->ok($res, null, 'Orden eliminada');
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

    // ── GET /api/picking/template ─────────────────────────────────────────────
    // Plantilla con los campos requeridos para importación masiva
    public function getTemplate(Request $r, Response $res): Response
    {
        $headers = [
            'Numero factura', 'Cliente', 'Documento', 'Direccion',
            'Planilla', 'Asesor', 'Producto', 'Cantidad', 'Costo', 'Descuento'
        ];
        $sample = [
            'FACO259312', 'NESTOR HERNANDEZ', '70133976', 'CL 21 40 19',
            '22275', 'TMD703 - CCO-YASMIN ALEXANDRA SOTO', '7702006207881', '1', '18117', '30'
        ];

        $content = "\xEF\xBB\xBF"; // UTF-8 BOM
        $content .= implode(';', $headers) . "\r\n";
        $content .= implode(';', $sample) . "\r\n";

        if (ob_get_length()) ob_clean();
        $res->getBody()->write($content);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="plantilla_pedidos_picking.csv"');
    }

    // ── POST /api/picking/importar ────────────────────────────────────────────
    // Importación masiva desde archivo plano con mapeo inteligente de columnas:
    // Num Factura, Cliente, Documento, Dirección, Planilla, Asesor,
    // Producto (EAN/BARRAS), Cantidad, Costo, Descuento
    public function importarPedidos(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $files = $r->getUploadedFiles();
        $file  = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'Archivo no válido o no enviado');
        }

        $contents = $file->getStream()->getContents();
        // Detect and convert encoding
        if (!mb_detect_encoding($contents, 'UTF-8', true)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        // Split lines + strip BOM
        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
        $allLines = array_values(array_filter($allLines, fn($l) => trim($l) !== ''));
        if (count($allLines) < 2) return $this->error($res, 'El archivo no contiene datos');

        // Detect delimiter from header line
        $sep     = str_contains($allLines[0], ';') ? ';' : ',';
        $rawHdr  = str_getcsv($allLines[0], $sep);
        $headers = array_map(fn($h) => strtolower(trim($h, " \t\r\n\xEF\xBB\xBF")), $rawHdr);
        $dataLines = array_slice($allLines, 1);

        // ── Auto-detect column indices using aliases ────────────────────────
        $ALIASES = [
            'numero_factura' => ['numero factura', 'num factura', 'factura', 'nro factura'],
            'cliente'        => ['cliente', 'nombre cliente', 'razon social'],
            'documento'      => ['documento', 'nit', 'cedula', 'cc'],
            'direccion'      => ['direccion', 'dirección', 'dir'],
            'planilla'       => ['planilla', 'planilla numero', 'num planilla'],
            'asesor'         => ['asesor', 'comercial', 'vendedor'],
            'producto'       => ['barras', 'ean', 'codigo barras', 'producto', 'codigo producto'],
            'cantidad'       => ['cantidad', 'cant', 'qty', 'unidades'],
            'costo'          => ['costo', 'precio', 'valor', 'cost'],
            'descuento'      => ['descuento', 'desc', 'descto', 'dcto'],
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

        // Validate minimum required fields
        if (!isset($colMap['producto']) || !isset($colMap['cantidad'])) {
            return $this->error($res, 'No se pudieron detectar las columnas de Producto y Cantidad en el archivo. Verifique los encabezados.');
        }

        // ── Pre-compute file-level audit totals ──────────────────────────
        $auditArchivo = [
            'lineas_archivo'    => count($dataLines),
            'clientes_archivo'  => 0,
            'cantidad_archivo'  => 0,
            'valor_archivo'     => 0,
        ];
        $clientesSet = [];
        foreach ($dataLines as $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($colMap as $field => $idx) {
                $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            if (empty(array_filter($row))) continue;
            if (!empty($row['cliente'])) $clientesSet[$row['cliente']] = true;
            $cant = max(1, (int)($row['cantidad'] ?? 1));
            $costo = (float) str_replace(',', '.', str_replace('.', '', $row['costo'] ?? '0'));
            $auditArchivo['cantidad_archivo'] += $cant;
            $auditArchivo['valor_archivo']    += $cant * $costo;
        }
        $auditArchivo['clientes_archivo'] = count($clientesSet);

        $summary = [
            'total'           => 0,
            'total_lineas'    => 0,
            'importadas'      => 0,
            'errores'         => [],
            'productos_no_encontrados' => 0,
            'campos_detectados' => array_keys($colMap),
            'cantidad_sistema'  => 0,
            'valor_sistema'     => 0,
            'clientes_sistema'  => [],
        ];

        // ── Group lines by Numero Factura → one OrdenPicking per factura ────
        $grupos = [];
        foreach ($dataLines as $lineNum => $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($colMap as $field => $idx) {
                $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            if (empty(array_filter($row))) continue;

            // Group key: Numero factura, fallback to planilla, fallback to auto-generated
            $groupKey = $row['numero_factura'] ?? $row['planilla'] ?? ('IMP-' . date('Ymd') . '-' . ($lineNum + 1));
            $grupos[$groupKey][] = $row;
            $summary['total']++;
        }

        if (empty($grupos)) {
            return $this->error($res, 'No se encontraron filas de datos en el archivo');
        }

        // ── Process each group (factura) → create one OrdenPicking ──────────
        foreach ($grupos as $factura => $filas) {
            try {
                Capsule::transaction(function () use ($factura, $filas, $user, &$summary) {
                    $fila0 = $filas[0];

                    // Clean up cost values ("18,117.00" → 18117.00)
                    $cleanNumber = function($val) {
                        if (empty($val)) return 0;
                        // Remove thousands separator dots, convert comma decimal to dot
                        $val = str_replace('.', '', $val);  // Remove dots (thousands)
                        $val = str_replace(',', '.', $val);  // Convert comma to dot (decimal)
                        return (float) $val;
                    };

                    $orden = OrdenPicking::create([
                        'empresa_id'       => $user->empresa_id,
                        'sucursal_id'      => $user->sucursal_id,
                        'numero_orden'     => 'PICK-' . date('Ymd') . '-' . strtoupper(substr(md5($factura . microtime()), 0, 5)),
                        'numero_factura'   => $factura ?: null,
                        'planilla_numero'  => $fila0['planilla'] ?? null,
                        'planilla_lote'    => $fila0['planilla'] ?? null,
                        'cliente'          => trim($fila0['cliente'] ?? ''),
                        'direccion_cliente'=> trim($fila0['direccion'] ?? ''),
                        'asesor_comercial' => trim($fila0['asesor'] ?? ''),
                        'estado'           => 'Pendiente',
                        'fecha_movimiento' => date('Y-m-d'),
                        'hora_inicio'      => date('H:i:s'),
                        'prioridad'        => 5,
                        'auxiliar_id'      => null,
                    ]);

                    $lineasCreadas = 0;
                    foreach ($filas as $fila) {
                        // Resolver producto por código de barras (EAN)
                        $prod = null;
                        $ean  = trim($fila['producto'] ?? '');

                        if ($ean) {
                            // 1. Search by exact EAN in ProductoEan table
                            $eanRec = \App\Models\ProductoEan::where('codigo_ean', $ean)->first();
                            if ($eanRec) {
                                $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                                    ->find($eanRec->producto_id);
                            }

                            // 2. Fallback: search by codigo_interno
                            if (!$prod) {
                                $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                                    ->where('codigo_interno', $ean)->first();
                            }

                            // 3. Fallback: partial EAN match (trimming leading zeros/characters)
                            if (!$prod && strlen($ean) > 6) {
                                $eanRec = \App\Models\ProductoEan::where('codigo_ean', 'like', "%" . substr($ean, -10))->first();
                                if ($eanRec) {
                                    $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                                        ->find($eanRec->producto_id);
                                }
                            }
                        }

                        if (!$prod) {
                            $summary['productos_no_encontrados']++;
                            continue; // Skip unresolvable products
                        }

                        $cantidad  = max(1, (int)($fila['cantidad'] ?? 1));
                        $costo     = $cleanNumber($fila['costo'] ?? '0');
                        $descuento = (float)($fila['descuento'] ?? 0);

                        PickingDetalle::create([
                            'orden_picking_id'   => $orden->id,
                            'producto_id'        => $prod->id,
                            'cantidad_solicitada'=> $cantidad,
                            'cantidad_pickeada'  => 0,
                            'costo_unitario'     => $costo,
                            'descuento_porc'     => $descuento,
                            'estado'             => 'Pendiente',
                        ]);

                        $lineasCreadas++;
                        $summary['cantidad_sistema'] += $cantidad;
                        $summary['valor_sistema']    += $cantidad * $costo;
                        if (!empty(trim($fila0['cliente'] ?? ''))) {
                            $summary['clientes_sistema'][trim($fila0['cliente'])] = true;
                        }
                    }

                    $summary['total_lineas'] += $lineasCreadas;

                    // If no lines were created, delete the empty order
                    if ($lineasCreadas === 0) {
                        $orden->delete();
                        throw new \Exception('Ningún producto fue encontrado en el catálogo');
                    }
                });
                $summary['importadas']++;
            } catch (\Exception $e) {
                $summary['errores'][] = "Factura {$factura}: " . $e->getMessage();
            }
        }

        $msg = "Importación completada: {$summary['importadas']} orden(es) de picking creada(s)";
        if ($summary['productos_no_encontrados'] > 0) {
            $msg .= ". {$summary['productos_no_encontrados']} producto(s) no encontrado(s)";
        }
        if (!empty($summary['errores'])) {
            $msg .= ". Errores: " . count($summary['errores']);
        }

        // Build audit comparison
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
            'lineas'    => $auditArchivo['lineas_archivo'] - $summary['total_lineas'],
            'clientes'  => $auditArchivo['clientes_archivo'] - count($summary['clientes_sistema']),
            'cantidad'  => $auditArchivo['cantidad_archivo'] - $summary['cantidad_sistema'],
            'valor'     => round($auditArchivo['valor_archivo'] - $summary['valor_sistema'], 2),
        ];
        unset($summary['clientes_sistema']); // don't send raw set

        $response = $res;
        $response->getBody()->write(json_encode([
            'error'     => false,
            'importadas'=> $summary['importadas'],
            'message'   => $msg,
            'data'      => $summary,
            'audit'     => $audit,
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
     * Retorna una tabla HTML con el resumen operativo del picking.
     */
    public function reporte(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $fechaDesde = $params['fecha_desde'] ?? date('Y-m-01');
        $fechaHasta = $params['fecha_hasta'] ?? date('Y-m-d');

        try {
            $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereBetween('created_at', [$fechaDesde . ' 00:00:00', $fechaHasta . ' 23:59:59'])
                ->orderBy('created_at', 'DESC')
                ->get();

            $resumen = [
                'total'       => $ordenes->count(),
                'completadas' => $ordenes->where('estado', 'Completado')->count(),
                'pendientes'  => $ordenes->whereIn('estado', ['Pendiente', 'En Proceso'])->count(),
                'canceladas'  => $ordenes->where('estado', 'Cancelado')->count(),
            ];

            $this->audit($user, 'picking', 'reporte', 'ordenes_picking', null, null, [],
                "Reporte picking {$fechaDesde} → {$fechaHasta}");

            return $this->ok($res, [
                'resumen'       => $resumen,
                'ordenes'       => $ordenes->values(),
                'fecha_desde'   => $fechaDesde,
                'fecha_hasta'   => $fechaHasta,
            ]);
        } catch (\Exception $e) {
            error_log('PickingController::reporte error: ' . $e->getMessage());
            return $this->error($res, 'Error generando reporte.', 500);
        }
    }
}
