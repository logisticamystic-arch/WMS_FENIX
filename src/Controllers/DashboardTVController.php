<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

class DashboardTVController extends BaseController
{
    public function getDashboardTV(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();

        // PHP está en America/Bogota (configurado en index.php)
        $today      = date('Y-m-d');
        $fecha      = $request->getQueryParams()['fecha'] ?? $today;
        $esHoy      = ($fecha === $today);
        $fechaStart = $fecha . ' 00:00:00';
        $fechaEnd   = $fecha . ' 23:59:59';
        // Compatibilidad con queries que aún usan $todayStart/$todayEnd
        $todayStart = $fechaStart;
        $todayEnd   = $fechaEnd;

        // ── 1. Recepciones del día (o fecha solicitada): CON y SIN ODC ──────
        // Usa fecha_movimiento (campo date, siempre en zona Colombia) — nunca created_at.
        // LEFT JOIN a citas para obtener proveedor en recepciones sin ODC.
        $recepciones = [];
        try {
            $stmtRec = $pdo->prepare("
                SELECT r.numero_recepcion,
                       r.estado,
                       r.created_at,
                       COALESCE(prov_odc.razon_social, ci.proveedor, 'Sin ODC / Directo') AS proveedor_nombre,
                       COALESCE(oc.numero_odc, '—')    AS numero_odc,
                       pr.nombre                        AS producto_nombre,
                       pr.codigo_interno,
                       rd.cantidad_recibida,
                       rd.estado_mercancia,
                       pe.nombre                        AS auxiliar_nombre
                FROM recepcion_detalles rd
                JOIN  recepciones        r       ON r.id       = rd.recepcion_id
                JOIN  productos          pr      ON pr.id      = rd.producto_id
                LEFT JOIN ordenes_compra oc      ON oc.id      = r.odc_id
                LEFT JOIN proveedores    prov_odc ON prov_odc.id = oc.proveedor_id
                LEFT JOIN citas          ci      ON ci.id      = r.cita_id
                LEFT JOIN personal       pe      ON pe.id      = r.auxiliar_id
                WHERE r.empresa_id  = :emp
                  AND r.sucursal_id = :suc
                  AND r.fecha_movimiento = :fecha
                ORDER BY r.created_at DESC, rd.id ASC
                LIMIT 60
            ");
            $stmtRec->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha]);
            $recepciones = $stmtRec->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:recepciones — ' . $e->getMessage());
        }

        // ── 2. Misceláneos del día (o fecha solicitada) ───────────────────────
        // created_at es el único campo de fecha; se usa rango para evitar problemas de TZ.
        $miscelaneos = [];
        try {
            $stmtMisc = $pdo->prepare("
                SELECT m.id, m.numero_recepcion, m.proveedor, m.articulo,
                       m.cantidad, m.estado, m.created_at, m.cliente_nombre,
                       (SELECT mf.url FROM miscelaneo_fotos mf
                        WHERE mf.miscelaneo_id = m.id ORDER BY mf.id ASC LIMIT 1) AS foto_url
                FROM miscelaneos m
                WHERE m.empresa_id  = :emp
                  AND m.sucursal_id = :suc
                  AND m.created_at BETWEEN :start AND :end
                ORDER BY m.created_at DESC
                LIMIT 30
            ");
            $stmtMisc->execute([
                ':emp'   => $empresaId,
                ':suc'   => $sucursalId,
                ':start' => $fechaStart,
                ':end'   => $fechaEnd,
            ]);
            $miscelaneos = $stmtMisc->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:miscelaneos — ' . $e->getMessage());
        }

        // ── 3. Agotados: hoy → picking activo sin stock; histórico → faltantes ─
        $agotados = [];
        try {
            if ($esHoy) {
                // Query operacional: líneas de picking activas sin stock disponible
                $stmtAgo = $pdo->prepare("
                    SELECT pr.id,
                           pr.nombre AS descripcion,
                           pr.codigo_interno,
                           COALESCE(SUM(inv.cantidad), 0)                                     AS stock_actual,
                           COUNT(pd.id)                                                        AS lineas_pendientes,
                           COALESCE(SUM(pd.cantidad_solicitada - COALESCE(pd.cantidad_pickeada,0)), 0) AS demanda_pendiente,
                           MAX(inv.created_at)                                                 AS ultimo_ingreso,
                           NULL                                                                AS sucursal
                    FROM picking_detalles pd
                    JOIN  orden_pickings op ON op.id  = pd.orden_picking_id
                    JOIN  productos      pr ON pr.id  = pd.producto_id
                    LEFT JOIN inventarios inv ON inv.producto_id = pr.id
                         AND inv.sucursal_id  = :suc2
                         AND inv.estado       = 'Disponible'
                    WHERE op.empresa_id  = :emp
                      AND op.sucursal_id = :suc
                      AND pd.estado      IN ('EnProceso', 'Parcial', 'Asignado')
                      AND op.estado      IN ('Asignado', 'EnProceso')
                    GROUP BY pr.id, pr.nombre, pr.codigo_interno
                    HAVING COALESCE(SUM(inv.cantidad), 0) <= 0
                    ORDER BY demanda_pendiente DESC
                    LIMIT 10
                ");
                $stmtAgo->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':suc2' => $sucursalId]);
            } else {
                // Query histórica: faltantes registrados en la fecha solicitada
                $stmtAgo = $pdo->prepare("
                    SELECT pr.nombre AS descripcion,
                           pr.codigo_interno,
                           0 AS stock_actual,
                           COUNT(pf.id) AS lineas_pendientes,
                           SUM(pf.cantidad_faltante) AS demanda_pendiente,
                           MIN(pf.created_at) AS ultimo_ingreso,
                           op.sucursal_entrega AS sucursal
                    FROM picking_faltantes pf
                    JOIN productos pr ON pr.id = pf.producto_id
                    JOIN orden_pickings op ON op.id = pf.orden_picking_id
                    -- Excluir faltantes cuyo producto ya fue pickeado exitosamente después
                    LEFT JOIN picking_detalles pd_res ON (
                        pd_res.orden_picking_id = pf.orden_picking_id
                        AND pd_res.producto_id = pf.producto_id
                        AND pd_res.estado IN ('Completada', 'Completado')
                        AND pd_res.cantidad_pickeada > 0
                    )
                    WHERE pf.empresa_id = :emp
                      AND pf.sucursal_id = :suc
                      AND pf.created_at::date = :fecha
                      AND pd_res.id IS NULL
                    GROUP BY pr.nombre, pr.codigo_interno, op.sucursal_entrega
                    ORDER BY demanda_pendiente DESC
                    LIMIT 20
                ");
                $stmtAgo->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha]);
            }
            $agotados = $stmtAgo->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:agotados — ' . $e->getMessage());
        }

        // ── 4. Próximos a vencer (≤ 30 días) ─────────────────────────────────
        $proximos_vencer = [];
        try {
            $stmtVen = $pdo->prepare("
                SELECT inv.id,
                       pr.nombre AS descripcion,
                       pr.codigo_interno,
                       inv.lote,
                       inv.fecha_vencimiento,
                       inv.cantidad,
                       COALESCE(u.codigo, '—')                                                AS ubicacion,
                       EXTRACT(DAY FROM (inv.fecha_vencimiento::timestamp - NOW()))::int       AS dias_restantes
                FROM inventarios inv
                JOIN  productos   pr ON pr.id = inv.producto_id
                LEFT JOIN ubicaciones u  ON u.id = inv.ubicacion_id
                WHERE inv.empresa_id  = :emp
                  AND inv.sucursal_id = :suc
                  AND inv.estado       = 'Disponible'
                  AND inv.fecha_vencimiento IS NOT NULL
                  AND inv.fecha_vencimiento >= CURRENT_DATE
                  AND inv.fecha_vencimiento <= CURRENT_DATE + INTERVAL '30 days'
                  AND inv.cantidad > 0
                  AND (inv.cantidad - COALESCE(inv.cantidad_reservada, 0)) > 0
                ORDER BY inv.fecha_vencimiento ASC
                LIMIT 20
            ");
            $stmtVen->execute([':emp' => $empresaId, ':suc' => $sucursalId]);
            $proximos_vencer = $stmtVen->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:proximos_vencer — ' . $e->getMessage());
        }

        // ── 5. Stock crítico — Agente ML Operacional ──────────────────────
        // Solo muestra referencias RELEVANTES para la operación:
        //   • Stock < 10 cajas Y tiene demanda activa de picking (líneas pendientes)
        //   • Stock = 0 Y ha tenido movimiento de picking en los últimos 30 días
        // Referencias sin movimiento de picking NO se muestran (no son operacionalmente relevantes).
        $stock_critico = [];
        try {
            $stmtCrit = $pdo->prepare("
                WITH stock_por_producto AS (
                    SELECT pr.id,
                           pr.nombre       AS descripcion,
                           pr.codigo_interno,
                           COALESCE(SUM(inv.cantidad), 0) AS stock_actual
                    FROM productos pr
                    LEFT JOIN inventarios inv ON inv.producto_id = pr.id
                         AND inv.sucursal_id = :suc
                         AND inv.estado      = 'Disponible'
                    WHERE pr.empresa_id = :emp
                      AND pr.activo     = 1
                    GROUP BY pr.id, pr.nombre, pr.codigo_interno
                    HAVING COALESCE(SUM(inv.cantidad), 0) < 10
                ),
                demanda_activa AS (
                    -- Referencias con líneas de picking pendientes ahora mismo
                    SELECT DISTINCT pd.producto_id
                    FROM picking_detalles pd
                    JOIN orden_pickings op ON op.id = pd.orden_picking_id
                    WHERE op.empresa_id  = :emp2
                      AND op.sucursal_id = :suc2
                      AND pd.estado IN ('EnProceso','Parcial','Asignado')
                      AND op.estado  IN ('Asignado','EnProceso')
                ),
                movimiento_reciente AS (
                    -- Referencias que han tenido picking en los últimos 30 días
                    SELECT DISTINCT pd.producto_id
                    FROM picking_detalles pd
                    JOIN orden_pickings op ON op.id = pd.orden_picking_id
                    WHERE op.empresa_id  = :emp3
                      AND op.sucursal_id = :suc3
                      AND op.created_at >= CURRENT_DATE - INTERVAL '30 days'
                )
                SELECT sp.id,
                       sp.descripcion,
                       sp.codigo_interno,
                       sp.stock_actual,
                       (da.producto_id IS NOT NULL) AS tiene_demanda,
                       (mr.producto_id IS NOT NULL) AS tiene_movimiento
                FROM stock_por_producto sp
                LEFT JOIN demanda_activa     da ON da.producto_id = sp.id
                LEFT JOIN movimiento_reciente mr ON mr.producto_id = sp.id
                WHERE (da.producto_id IS NOT NULL OR mr.producto_id IS NOT NULL)
                ORDER BY sp.stock_actual ASC,
                         (da.producto_id IS NOT NULL) DESC,
                         sp.descripcion ASC
                LIMIT 50
            ");
            $stmtCrit->execute([
                ':emp'  => $empresaId, ':emp2' => $empresaId, ':emp3' => $empresaId,
                ':suc'  => $sucursalId, ':suc2' => $sucursalId, ':suc3' => $sucursalId,
            ]);
            $stock_critico = $stmtCrit->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:stock_critico — ' . $e->getMessage());
        }

        // ── KPIs ──────────────────────────────────────────────────────────────
        // recepciones es ahora línea-a-línea; contar docs únicos para el badge
        $totalRecDoc  = count(array_unique(array_column($recepciones, 'numero_recepcion')));
        $unidadesRec  = (float) array_sum(array_column($recepciones, 'cantidad_recibida'));
        $unidadesMisc = (float) array_sum(array_map(fn($m) => (float)($m['cantidad'] ?? 0), $miscelaneos));

        return $this->ok($response, [
            'recepciones'     => $recepciones,
            'miscelaneos'     => $miscelaneos,
            'agotados'        => $agotados,
            'proximos_vencer' => $proximos_vencer,
            'stock_critico'   => $stock_critico,
            'kpis' => [
                'total_ingresos'           => $totalRecDoc + count($miscelaneos),
                'total_unidades_ingresadas' => $unidadesRec + $unidadesMisc,
                'agotados_count'           => count($agotados),
                'proximos_vencer_count'    => count($proximos_vencer),
                'stock_critico_count'      => count($stock_critico),
            ],
        ]);
    }

    /**
     * GET /api/dashboard/nivel-servicio
     *
     * Query params:
     *   tipo  : 'dia' | 'mes' | 'sucursal'  (default: 'dia')
     *   dias  : int 1-90                     (default: 30, aplica a tipo=dia y tipo=mes)
     *
     * Fórmula:
     *   nivel_servicio = ((total_solicitado - faltantes_afecta_ns) / total_solicitado) * 100
     *
     * faltantes_afecta_ns: registros en picking_faltantes cuyo causal_id apunta a
     * causales_novedad con afecta_nivel_servicio = true.
     */
    public function getNivelServicio(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $pdo       = Capsule::connection()->getPdo();

        $params = $request->getQueryParams();
        $tipo   = in_array($params['tipo'] ?? '', ['dia', 'mes', 'sucursal'], true)
                    ? $params['tipo']
                    : 'dia';

        $diasRaw = isset($params['dias']) ? (int)$params['dias'] : 30;
        $dias    = max(1, min(90, $diasRaw));

        try {
            if ($tipo === 'dia') {
                $rows = $this->_nsQueryDia($pdo, $empresaId, $dias);
                $labels = array_column($rows, 'periodo');
            } elseif ($tipo === 'mes') {
                $rows = $this->_nsQueryMes($pdo, $empresaId, $dias);
                $labels = array_column($rows, 'periodo');
            } else {
                // sucursal — últimos 30 días fijo
                $rows = $this->_nsQuerySucursal($pdo, $empresaId);
                $labels = array_column($rows, 'periodo');
            }

            $totalSolicitado = [];
            $faltantesNs     = [];
            $nivelServicio   = [];
            $sumTotal        = 0;
            $sumFaltantes    = 0;

            foreach ($rows as $row) {
                $total    = (float)($row['total_solicitado'] ?? 0);
                $faltante = (float)($row['faltantes_ns']    ?? 0);
                $ns       = $total > 0
                    ? round((($total - $faltante) / $total) * 100, 2)
                    : 100.00;

                $totalSolicitado[] = $total;
                $faltantesNs[]     = $faltante;
                $nivelServicio[]   = $ns;
                $sumTotal         += $total;
                $sumFaltantes     += $faltante;
            }

            $nsResumen = $sumTotal > 0
                ? round((($sumTotal - $sumFaltantes) / $sumTotal) * 100, 2)
                : 100.00;

            return $this->ok($response, [
                'tipo'   => $tipo,
                'labels' => $labels,
                'series' => [
                    ['label' => 'Total Solicitado',   'data' => $totalSolicitado],
                    ['label' => 'Faltantes NS',        'data' => $faltantesNs],
                    ['label' => 'Nivel de Servicio %', 'data' => $nivelServicio],
                ],
                'resumen' => [
                    'total_solicitado'  => $sumTotal,
                    'total_faltantes_ns' => $sumFaltantes,
                    'nivel_servicio'    => $nsResumen,
                ],
            ]);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'DashboardTV:nivelServicio — ' . $e->getMessage());
            return $this->error($response, 'Error al calcular nivel de servicio: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/tv/nivel-servicio
     *
     * Formato de respuesta compatible con el TV Dashboard.
     * Query params: fecha (default: hoy)
     *
     * Responde:
     * {
     *   general: { solicitado, separado, pct },
     *   por_sucursal: [ { sucursal, total_refs, refs_completas, pct_refs } ],           // fecha dada, por SKU
     *   por_dia:      [ { fecha, total_refs, refs_completas, pct_refs } ],              // mes activo, por SKU
     *   por_referencia: [ { nombre, solicitado, separado, pct } ],                     // top 10 peores (unidades)
     *   por_mes:      [ { mes, mes_label, total_refs, refs_completas, pct_refs } ],     // últimos 3 meses, por SKU
     * }
     */
    public function nivelServicio(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();

        $params = $request->getQueryParams();
        $fecha  = !empty($params['fecha']) ? $params['fecha'] : date('Y-m-d');

        try {
            // ── General: solicitado vs pickeado para la fecha dada ────────────────
            $stmtGen = $pdo->prepare("
                SELECT
                    COALESCE(SUM(pd.cantidad_solicitada), 0)  AS total_solicitado,
                    COALESCE(SUM(pd.cantidad_pickeada),   0)  AS total_separado,
                    COUNT(*) AS total_lineas,
                    COUNT(CASE WHEN pd.cantidad_pickeada >= pd.cantidad_solicitada AND pd.cantidad_solicitada > 0 THEN 1 END) AS lineas_completas,
                    COUNT(DISTINCT pd.producto_id) AS total_refs,
                    COUNT(DISTINCT CASE WHEN pd.cantidad_pickeada >= pd.cantidad_solicitada THEN pd.producto_id END) AS refs_completas
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                WHERE op.empresa_id  = :emp
                  AND op.sucursal_id = :suc
                  AND op.estado NOT IN ('Anulado')
                  AND op.fecha_movimiento::date = :fecha
            ");
            $stmtGen->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha]);
            $gen = $stmtGen->fetch(\PDO::FETCH_ASSOC) ?: [];
            $solGen        = (float)($gen['total_solicitado'] ?? 0);
            $sepGen        = (float)($gen['total_separado'] ?? 0);
            $pctGen        = $solGen > 0 ? round($sepGen / $solGen * 100, 1) : 0;
            $totalRefs     = (int)($gen['total_refs'] ?? 0);
            $refsCompletas = (int)($gen['refs_completas'] ?? 0);
            $pctRefs       = $totalRefs > 0 ? round($refsCompletas / $totalRefs * 100, 1) : 0;

            // ── Por sucursal (fecha dada) — por referencia/SKU ───────────────────
            $stmtSuc = $pdo->prepare("
                SELECT
                    COALESCE(op.sucursal_entrega, 'Sin sucursal') AS sucursal,
                    COUNT(DISTINCT pd.producto_id) AS total_refs,
                    COUNT(DISTINCT CASE
                        WHEN pd.cantidad_pickeada >= pd.cantidad_solicitada
                         AND pd.cantidad_solicitada > 0
                        THEN pd.producto_id
                    END) AS refs_completas
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                WHERE op.empresa_id  = :emp
                  AND op.sucursal_id = :suc
                  AND op.estado NOT IN ('Anulado')
                  AND op.fecha_movimiento::date = :fecha
                  AND pd.estado NOT IN ('Pendiente', 'EnProceso')
                GROUP BY op.sucursal_entrega
                ORDER BY refs_completas DESC
            ");
            $stmtSuc->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha]);
            $rawSuc = $stmtSuc->fetchAll(\PDO::FETCH_ASSOC);
            $porSucursal = array_map(function($r) {
                return [
                    'sucursal'       => $r['sucursal'],
                    'total_refs'     => (int)$r['total_refs'],
                    'refs_completas' => (int)$r['refs_completas'],
                    'pct_refs'       => $r['total_refs'] > 0
                        ? round($r['refs_completas'] / $r['total_refs'] * 100, 1)
                        : null,
                ];
            }, $rawSuc);

            // ── Por día del mes activo — por referencia (SKU) ────────────────────
            $mes = substr($fecha, 0, 7); // 'YYYY-MM'
            $stmtDia = $pdo->prepare("
                SELECT
                    op.fecha_movimiento::date AS fecha,
                    COUNT(DISTINCT pd.producto_id) AS total_refs,
                    COUNT(DISTINCT CASE
                        WHEN pd.cantidad_pickeada >= pd.cantidad_solicitada
                         AND pd.cantidad_solicitada > 0
                        THEN pd.producto_id
                    END) AS refs_completas
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                WHERE op.empresa_id = :emp
                  AND op.sucursal_id = :suc
                  AND op.estado NOT IN ('Anulado')
                  AND TO_CHAR(op.fecha_movimiento, 'YYYY-MM') = :mes
                  AND pd.estado NOT IN ('Pendiente', 'EnProceso')
                GROUP BY op.fecha_movimiento::date
                ORDER BY fecha
            ");
            $stmtDia->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':mes' => $mes]);
            $rawDia = $stmtDia->fetchAll(\PDO::FETCH_ASSOC);

            $porDia = array_map(function($r) {
                $pct = $r['total_refs'] > 0
                    ? round($r['refs_completas'] / $r['total_refs'] * 100, 1)
                    : null;
                return [
                    'fecha'          => $r['fecha'],
                    'total_refs'     => (int)$r['total_refs'],
                    'refs_completas' => (int)$r['refs_completas'],
                    'pct_refs'       => $pct,
                ];
            }, $rawDia);

            // ── Por referencia: top 10 peores (últimos 30 días) ──────────────────
            $stmtRef = $pdo->prepare("
                SELECT
                    pr.nombre                                            AS nombre,
                    COALESCE(SUM(pd.cantidad_solicitada), 0)             AS solicitado,
                    COALESCE(SUM(pd.cantidad_pickeada),   0)             AS separado
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                JOIN productos pr ON pr.id = pd.producto_id
                WHERE op.empresa_id  = :emp
                  AND op.sucursal_id = :suc
                  AND op.estado_certificacion IN ('Certificada','Pendiente')
                  AND op.estado IN ('Completada','EnProceso')
                  AND op.fecha_movimiento::date >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY pr.id, pr.nombre
                HAVING COALESCE(SUM(pd.cantidad_solicitada), 0) > 0
                ORDER BY (COALESCE(SUM(pd.cantidad_pickeada), 0) / NULLIF(SUM(pd.cantidad_solicitada), 0)) ASC
                LIMIT 10
            ");
            $stmtRef->execute([':emp' => $empresaId, ':suc' => $sucursalId]);
            $porReferencia = array_map(function($r) {
                $sol = (float)$r['solicitado'];
                $sep = (float)$r['separado'];
                return [
                    'nombre'    => $r['nombre'],
                    'solicitado'=> $sol,
                    'separado'  => $sep,
                    'pct'       => $sol > 0 ? round($sep / $sol * 100, 1) : 0,
                ];
            }, $stmtRef->fetchAll(\PDO::FETCH_ASSOC));

            // ── Por mes (últimos 3 meses) — por referencia/SKU ───────────────────
            $stmtMes = $pdo->prepare("
                SELECT
                    TO_CHAR(op.fecha_movimiento, 'YYYY-MM') AS mes,
                    TO_CHAR(op.fecha_movimiento, 'Mon')     AS mes_label,
                    COUNT(DISTINCT pd.producto_id) AS total_refs,
                    COUNT(DISTINCT CASE
                        WHEN pd.cantidad_pickeada >= pd.cantidad_solicitada
                         AND pd.cantidad_solicitada > 0
                        THEN pd.producto_id
                    END) AS refs_completas
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                WHERE op.empresa_id  = :emp
                  AND op.sucursal_id = :suc
                  AND op.estado NOT IN ('Anulado')
                  AND op.fecha_movimiento >= (CURRENT_DATE - INTERVAL '3 months')
                  AND pd.estado NOT IN ('Pendiente', 'EnProceso')
                GROUP BY TO_CHAR(op.fecha_movimiento, 'YYYY-MM'), TO_CHAR(op.fecha_movimiento, 'Mon')
                ORDER BY mes
            ");
            $stmtMes->execute([':emp' => $empresaId, ':suc' => $sucursalId]);
            $rawMes = $stmtMes->fetchAll(\PDO::FETCH_ASSOC);
            $porMes = array_map(function($r) {
                return [
                    'mes'            => $r['mes'],
                    'mes_label'      => $r['mes_label'],
                    'total_refs'     => (int)$r['total_refs'],
                    'refs_completas' => (int)$r['refs_completas'],
                    'pct_refs'       => $r['total_refs'] > 0
                        ? round($r['refs_completas'] / $r['total_refs'] * 100, 1)
                        : null,
                ];
            }, $rawMes);

            // ── Agotados del período: referencias con faltantes registrados ─────────
            $agotados = [];
            try {
                $stmtAgo = $pdo->prepare("
                    SELECT
                        pr.nombre,
                        pr.codigo_interno,
                        COALESCE(SUM(pf.cantidad_solicitada), 0) AS solicitado,
                        COALESCE(SUM(pf.cantidad_solicitada - pf.cantidad_faltante), 0) AS separado
                    FROM picking_faltantes pf
                    JOIN productos pr ON pr.id = pf.producto_id
                    JOIN orden_pickings op_ago ON op_ago.id = pf.orden_picking_id
                    -- Excluir faltantes cuyo producto ya fue pickeado exitosamente después
                    LEFT JOIN picking_detalles pd_res ON (
                        pd_res.orden_picking_id = pf.orden_picking_id
                        AND pd_res.producto_id = pf.producto_id
                        AND pd_res.estado IN ('Completada', 'Completado')
                        AND pd_res.cantidad_pickeada > 0
                    )
                    WHERE pf.empresa_id  = :emp
                      AND pf.sucursal_id = :suc
                      AND op_ago.fecha_movimiento::date = :fecha
                      AND pd_res.id IS NULL
                    GROUP BY pr.id, pr.nombre, pr.codigo_interno
                    ORDER BY solicitado DESC
                ");
                $stmtAgo->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha]);
                $agotados = $stmtAgo->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $eAgo) {
                wmsLog('ERROR', 'TV:nivelServicio:agotados — ' . $eAgo->getMessage());
            }

            return $this->ok($response, [
                'general'        => [
                    'solicitado'     => $solGen,
                    'separado'       => $sepGen,
                    'pct'            => $pctGen,
                    'total_refs'     => $totalRefs,
                    'refs_completas' => $refsCompletas,
                    'pct_refs'       => $pctRefs,
                ],
                'por_sucursal'   => $porSucursal,
                'por_dia'        => $porDia,
                'por_referencia' => $porReferencia,
                'por_mes'        => $porMes,
                'agotados'       => $agotados,
            ]);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:nivelServicio — ' . $e->getMessage());
            return $this->error($response, 'Error al calcular nivel de servicio TV: ' . $e->getMessage(), 500);
        }
    }

    // ── Helpers privados de nivel de servicio ────────────────────────────────

    private function _nsQueryDia(\PDO $pdo, int $empresaId, int $dias): array
    {
        // Construir el rango de fechas directamente (no se puede usar parámetro PDO dentro de INTERVAL literal)
        $fechaDesde = date('Y-m-d', strtotime("-{$dias} days"));

        $sql = "
            WITH fechas AS (
                SELECT generate_series(
                    :fecha_desde::date,
                    CURRENT_DATE,
                    INTERVAL '1 day'
                )::date AS fecha
            ),
            solicitado AS (
                SELECT op.fecha::date                           AS fecha,
                       COALESCE(SUM(pd.cantidad_solicitada), 0) AS total
                FROM orden_pickings op
                JOIN picking_detalles pd ON pd.orden_picking_id = op.id
                WHERE op.empresa_id = :emp
                  AND op.fecha::date >= :fecha_desde_b::date
                GROUP BY op.fecha::date
            ),
            faltantes AS (
                SELECT pf.created_at::date                      AS fecha,
                       COALESCE(SUM(pf.cantidad_faltante), 0)   AS total
                FROM picking_faltantes pf
                JOIN causales_novedad cn ON cn.id = pf.causal_id
                                        AND cn.afecta_nivel_servicio = TRUE
                                        AND cn.empresa_id = :emp2
                WHERE pf.empresa_id = :emp3
                  AND pf.created_at::date >= :fecha_desde_c::date
                GROUP BY pf.created_at::date
            )
            SELECT f.fecha::text                              AS periodo,
                   COALESCE(s.total, 0)                      AS total_solicitado,
                   COALESCE(fa.total, 0)                     AS faltantes_ns
            FROM fechas f
            LEFT JOIN solicitado s  ON s.fecha  = f.fecha
            LEFT JOIN faltantes  fa ON fa.fecha = f.fecha
            ORDER BY f.fecha ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':emp'           => $empresaId,
            ':emp2'          => $empresaId,
            ':emp3'          => $empresaId,
            ':fecha_desde'   => $fechaDesde,
            ':fecha_desde_b' => $fechaDesde,
            ':fecha_desde_c' => $fechaDesde,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function _nsQueryMes(\PDO $pdo, int $empresaId, int $dias): array
    {
        $sql = "
            WITH solicitado AS (
                SELECT TO_CHAR(op.fecha::date, 'YYYY-MM')          AS mes,
                       COALESCE(SUM(pd.cantidad_solicitada), 0)    AS total
                FROM orden_pickings op
                JOIN picking_detalles pd ON pd.orden_picking_id = op.id
                WHERE op.empresa_id = :emp
                  AND op.fecha::date >= CURRENT_DATE - (:dias_a * INTERVAL '1 day')
                GROUP BY mes
            ),
            faltantes AS (
                SELECT TO_CHAR(pf.created_at::date, 'YYYY-MM')    AS mes,
                       COALESCE(SUM(pf.cantidad_faltante), 0)      AS total
                FROM picking_faltantes pf
                JOIN causales_novedad cn ON cn.id = pf.causal_id
                                        AND cn.afecta_nivel_servicio = TRUE
                                        AND cn.empresa_id = :emp2
                WHERE pf.empresa_id = :emp3
                  AND pf.created_at::date >= CURRENT_DATE - (:dias_b * INTERVAL '1 day')
                GROUP BY mes
            ),
            meses AS (
                SELECT DISTINCT mes FROM solicitado
                UNION
                SELECT DISTINCT mes FROM faltantes
            )
            SELECT m.mes                         AS periodo,
                   COALESCE(s.total, 0)          AS total_solicitado,
                   COALESCE(fa.total, 0)         AS faltantes_ns
            FROM meses m
            LEFT JOIN solicitado s  ON s.mes  = m.mes
            LEFT JOIN faltantes  fa ON fa.mes = m.mes
            ORDER BY m.mes ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':emp'    => $empresaId,
            ':emp2'   => $empresaId,
            ':emp3'   => $empresaId,
            ':dias_a' => $dias,
            ':dias_b' => $dias,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * GET /api/tv/ingresos-chart?mes=YYYY-MM
     *
     * Devuelve cajas recibidas por día y proveedor para el mes indicado
     * (recepciones en estado 'Cerrada').
     *
     * Respuesta:
     * {
     *   "labels": ["2026-07-01", "2026-07-02", ...],
     *   "series": [
     *     { "proveedor": "Proveedor A", "data": [10, 5, 0, ...] },
     *     { "proveedor": "Proveedor B", "data": [0, 3, 8, ...] }
     *   ]
     * }
     */
    public function ingresosChart(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();

        $params = $request->getQueryParams();
        $mes    = !empty($params['mes']) ? $params['mes'] : date('Y-m');

        // Validar formato YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            return $this->error($response, 'Parámetro mes inválido. Use formato YYYY-MM.', 400);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    DATE_TRUNC('day', r.fecha_recepcion)::date AS dia,
                    COALESCE(p.razon_social, p.nombre, 'Sin proveedor') AS proveedor,
                    SUM(rd.cantidad_recibida) AS cajas
                FROM recepciones r
                JOIN recepcion_detalles rd ON rd.recepcion_id = r.id
                LEFT JOIN proveedores p ON p.id = r.proveedor_id
                WHERE r.empresa_id  = :emp
                  AND r.sucursal_id = :suc
                  AND TO_CHAR(r.fecha_recepcion, 'YYYY-MM') = :mes
                  AND r.estado = 'Cerrada'
                GROUP BY dia, proveedor
                ORDER BY dia, proveedor
            ");
            $stmt->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':mes' => $mes]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Construir labels (días únicos del mes con datos) y pivotar series
            $diasSet      = [];
            $proveedorSet = [];
            $dataMap      = [];  // ['proveedor']['dia'] = cajas

            foreach ($rows as $row) {
                $dia  = $row['dia'];
                $prov = $row['proveedor'];
                $cajas = (float)$row['cajas'];

                $diasSet[$dia]      = true;
                $proveedorSet[$prov] = true;
                $dataMap[$prov][$dia] = $cajas;
            }

            // Si no hay datos, generar labels vacíos del mes para no romper el chart
            if (empty($diasSet)) {
                return $this->ok($response, ['labels' => [], 'series' => []]);
            }

            $labels = array_keys($diasSet);
            sort($labels);

            $series = [];
            foreach (array_keys($proveedorSet) as $prov) {
                $data = [];
                foreach ($labels as $dia) {
                    $data[] = $dataMap[$prov][$dia] ?? 0;
                }
                $series[] = ['proveedor' => $prov, 'data' => $data];
            }

            // Ordenar series por volumen total descendente
            usort($series, fn($a, $b) => array_sum($b['data']) <=> array_sum($a['data']));

            return $this->ok($response, ['labels' => $labels, 'series' => $series]);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:ingresosChart — ' . $e->getMessage());
            return $this->error($response, 'Error al obtener ingresos chart: ' . $e->getMessage(), 500);
        }
    }

    private function _nsQuerySucursal(\PDO $pdo, int $empresaId): array
    {
        $sql = "
            WITH solicitado AS (
                SELECT COALESCE(op.sucursal_entrega, 'Sin sucursal')  AS sucursal,
                       COALESCE(SUM(pd.cantidad_solicitada), 0)        AS total
                FROM orden_pickings op
                JOIN picking_detalles pd ON pd.orden_picking_id = op.id
                WHERE op.empresa_id = :emp
                  AND op.fecha::date >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY sucursal
            ),
            faltantes AS (
                SELECT COALESCE(op2.sucursal_entrega, 'Sin sucursal') AS sucursal,
                       COALESCE(SUM(pf.cantidad_faltante), 0)          AS total
                FROM picking_faltantes pf
                JOIN orden_pickings op2 ON op2.id = pf.orden_picking_id
                JOIN causales_novedad cn ON cn.id = pf.causal_id
                                        AND cn.afecta_nivel_servicio = TRUE
                                        AND cn.empresa_id = :emp2
                WHERE pf.empresa_id = :emp3
                  AND pf.created_at::date >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY sucursal
            ),
            sucursales AS (
                SELECT DISTINCT sucursal FROM solicitado
                UNION
                SELECT DISTINCT sucursal FROM faltantes
            )
            SELECT su.sucursal               AS periodo,
                   COALESCE(s.total,  0)     AS total_solicitado,
                   COALESCE(fa.total, 0)     AS faltantes_ns
            FROM sucursales su
            LEFT JOIN solicitado s  ON s.sucursal  = su.sucursal
            LEFT JOIN faltantes  fa ON fa.sucursal = su.sucursal
            ORDER BY su.sucursal ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':emp'  => $empresaId,
            ':emp2' => $empresaId,
            ':emp3' => $empresaId,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
