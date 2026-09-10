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

        // ── 3. Agotados: Día anterior y actual (rango de 2 días) ──────────────
        $agotados = [];
        try {
            $fechaAyer = date('Y-m-d', strtotime('-1 day', strtotime($fecha)));
            $dateCol   = $this->isPg() ? "pf.created_at::date" : "DATE(pf.created_at)";

            $stmtAgo = $pdo->prepare("
                SELECT pr.nombre                                             AS descripcion,
                       pr.codigo_interno,
                       0                                                     AS stock_actual,
                       COUNT(pf.id)                                          AS lineas_pendientes,
                       COALESCE(SUM(pf.cantidad_solicitada), 0)             AS cantidad_solicitada,
                       COALESCE(SUM(pf.cantidad_solicitada - pf.cantidad_faltante), 0) AS cantidad_pickeada,
                       COALESCE(SUM(pf.cantidad_faltante), 0)                AS demanda_pendiente,
                       COALESCE(pf.causa, 'Agotado')                        AS motivo,
                       MIN(pf.created_at)                                    AS ultimo_ingreso,
                       COALESCE(op.cliente, op.sucursal_entrega, '—')       AS sucursal
                FROM picking_faltantes pf
                JOIN productos pr ON pr.id = pf.producto_id
                JOIN orden_pickings op ON op.id = pf.orden_picking_id
                LEFT JOIN picking_detalles pd_res ON (
                    pd_res.orden_picking_id = pf.orden_picking_id
                    AND pd_res.producto_id = pf.producto_id
                    AND pd_res.estado IN ('Completada', 'Completado')
                    AND pd_res.cantidad_pickeada > 0
                )
                WHERE pf.empresa_id = :emp
                  AND pf.sucursal_id = :suc
                  AND {$dateCol} BETWEEN :fecha_ayer AND :fecha
                  AND pd_res.id IS NULL
                GROUP BY pr.nombre, pr.codigo_interno, pf.causa, op.cliente, op.sucursal_entrega
                ORDER BY demanda_pendiente DESC
                LIMIT 50
            ");
            $stmtAgo->execute([
                ':emp'        => $empresaId,
                ':suc'        => $sucursalId,
                ':fecha_ayer' => $fechaAyer,
                ':fecha'      => $fecha,
            ]);
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
     * A pedido explícito de Camilo (2026-09-05): el Nivel de Servicio es el
     * PROMEDIO de dos métricas independientes, cada una excluyendo faltantes
     * causados por error de digitación del pedido (no son una falla de bodega):
     *   NS1 (Referencias) = referencias solicitadas SIN AGOTADO real / total referencias solicitadas
     *   NS2 (Unidades)    = unidades solicitadas SIN AGOTADO real / total unidades solicitadas
     * Antes este endpoint calculaba un único % en base a `picking_faltantes` con
     * causal `afecta_nivel_servicio = true` — pero NINGÚN causal tenía ese flag
     * en true (ni siquiera 'AGOTADO'), así que "faltantes" siempre daba 0 y el
     * nivel de servicio siempre mostraba 100%. Se reemplaza por la misma lógica
     * ya validada en nivelServicio() (TV), factorizada en _nsCalcularRango().
     */
    public function getNivelServicio(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();

        $params = $request->getQueryParams();
        $tipo   = in_array($params['tipo'] ?? '', ['dia', 'mes', 'sucursal'], true)
                    ? $params['tipo']
                    : 'dia';

        $diasRaw = isset($params['dias']) ? (int)$params['dias'] : 30;
        $dias    = max(1, min(90, $diasRaw));

        try {
            $labels        = [];
            $seriePctRefs  = [];
            $seriePctUnid  = [];
            $serieNs       = [];

            if ($tipo === 'dia') {
                for ($i = $dias - 1; $i >= 0; $i--) {
                    $fecha = date('Y-m-d', strtotime("-{$i} days"));
                    $r     = $this->_nsCalcularRango($pdo, $empresaId, $sucursalId, $fecha, $fecha);
                    $labels[]       = $fecha;
                    $seriePctRefs[] = $r['pct_refs'];
                    $seriePctUnid[] = $r['pct_unidades'];
                    $serieNs[]      = $r['promedio'];
                }
            } elseif ($tipo === 'mes') {
                $meses = max(1, (int)ceil($dias / 30));
                for ($i = $meses - 1; $i >= 0; $i--) {
                    $desde = date('Y-m-01', strtotime("-{$i} months"));
                    $hasta = date('Y-m-t', strtotime("-{$i} months"));
                    $r     = $this->_nsCalcularRango($pdo, $empresaId, $sucursalId, $desde, $hasta);
                    $labels[]       = date('Y-m', strtotime($desde));
                    $seriePctRefs[] = $r['pct_refs'];
                    $seriePctUnid[] = $r['pct_unidades'];
                    $serieNs[]      = $r['promedio'];
                }
            } else {
                // sucursal (cliente/sucursal_entrega) — últimos 30 días fijo
                $desde = date('Y-m-d', strtotime('-30 days'));
                $hasta = date('Y-m-d');
                $stmt  = $pdo->prepare("
                    SELECT DISTINCT COALESCE(op.sucursal_entrega, 'Sin sucursal') AS sucursal
                    FROM orden_pickings op
                    WHERE op.empresa_id = :emp AND op.sucursal_id = :suc
                      AND op.fecha_movimiento::date BETWEEN :desde AND :hasta
                    ORDER BY 1
                ");
                $stmt->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':desde' => $desde, ':hasta' => $hasta]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $sucEntrega) {
                    $r = $this->_nsCalcularRango($pdo, $empresaId, $sucursalId, $desde, $hasta, $sucEntrega);
                    $labels[]       = $sucEntrega;
                    $seriePctRefs[] = $r['pct_refs'];
                    $seriePctUnid[] = $r['pct_unidades'];
                    $serieNs[]      = $r['promedio'];
                }
            }

            // Resumen: se recalcula sobre TODO el rango combinado (no promedio de
            // promedios diarios) para no distorsionar el número con días de bajo volumen.
            if ($tipo === 'sucursal') {
                $resumen = $this->_nsCalcularRango($pdo, $empresaId, $sucursalId, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
            } else {
                $hasta = $tipo === 'mes' ? date('Y-m-t') : date('Y-m-d');
                $desde = $tipo === 'mes'
                    ? date('Y-m-01', strtotime('-' . (max(1, (int)ceil($dias / 30)) - 1) . ' months'))
                    : date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
                $resumen = $this->_nsCalcularRango($pdo, $empresaId, $sucursalId, $desde, $hasta);
            }

            return $this->ok($response, [
                'tipo'   => $tipo,
                'labels' => $labels,
                'series' => [
                    ['label' => 'NS Referencias %',    'data' => $seriePctRefs],
                    ['label' => 'NS Unidades %',        'data' => $seriePctUnid],
                    ['label' => 'Nivel de Servicio %', 'data' => $serieNs],
                ],
                'resumen' => [
                    'total_solicitado'    => $resumen['solicitado'],
                    'total_separado'      => $resumen['separado'],
                    'pct_referencias'     => $resumen['pct_refs'],
                    'pct_unidades'        => $resumen['pct_unidades'],
                    'nivel_servicio'      => $resumen['promedio'],
                ],
            ]);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'DashboardTV:nivelServicio — ' . $e->getMessage());
            return $this->error($response, 'Error al calcular nivel de servicio: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Calcula el Nivel de Servicio real para un rango de fechas [desde, hasta]
     * (inclusive, ambos incluidos — un solo día si desde === hasta), opcionalmente
     * filtrado a una sucursal_entrega (cliente) específica.
     *
     * NS1 (Referencias): de las referencias solicitadas con cantidad > 0 tras
     * descontar error de digitación, cuántas fueron despachadas por completo.
     * NS2 (Unidades): unidades despachadas efectivas / unidades solicitadas
     * válidas (descontando digitación; el despacho efectivo se topa al 100%
     * por línea para que un sobre-despacho no infle el % por encima de 100).
     * 'promedio' = (NS1 + NS2) / 2 — el "Nivel de Servicio" que se muestra.
     */
    private function _nsCalcularRango(\PDO $pdo, int $empresaId, int $sucursalId, string $desde, string $hasta, ?string $sucursalEntrega = null): array
    {
        $filtroEntrega = $sucursalEntrega !== null ? "AND COALESCE(op.sucursal_entrega, 'Sin sucursal') = :suc_entrega" : '';

        // 1. Faltantes por error de digitación del pedido — no son falla de bodega.
        $sqlDigit = "
            SELECT pf.orden_picking_id, pf.producto_id,
                   COALESCE(SUM(pf.cantidad_faltante), 0) AS faltante_digitacion
            FROM picking_faltantes pf
            LEFT JOIN causales_novedad cn ON cn.id = pf.causal_id
            JOIN orden_pickings op ON op.id = pf.orden_picking_id
            WHERE pf.empresa_id  = :emp
              AND pf.sucursal_id = :suc
              AND op.fecha_movimiento::date BETWEEN :desde AND :hasta
              {$filtroEntrega}
              AND (cn.nombre ILIKE '%DIGITACION%' OR pf.causa ILIKE '%DIGITACION%')
            GROUP BY pf.orden_picking_id, pf.producto_id
        ";
        $params = [':emp' => $empresaId, ':suc' => $sucursalId, ':desde' => $desde, ':hasta' => $hasta];
        if ($sucursalEntrega !== null) $params[':suc_entrega'] = $sucursalEntrega;
        $stmtDigit = $pdo->prepare($sqlDigit);
        $stmtDigit->execute($params);
        $digitMap = [];
        $digitTotal = 0;
        foreach ($stmtDigit->fetchAll(\PDO::FETCH_ASSOC) as $d) {
            $key = $d['orden_picking_id'] . '_' . $d['producto_id'];
            $digitMap[$key] = (float)$d['faltante_digitacion'];
            $digitTotal += (float)$d['faltante_digitacion'];
        }

        // 2. Solicitado vs pickeado por línea, en el rango.
        $sqlGen = "
            SELECT pd.orden_picking_id, pd.producto_id,
                   COALESCE(SUM(pd.cantidad_solicitada), 0) AS total_solicitado,
                   COALESCE(SUM(pd.cantidad_pickeada),   0) AS total_separado
            FROM picking_detalles pd
            JOIN orden_pickings op ON op.id = pd.orden_picking_id
            WHERE op.empresa_id  = :emp
              AND op.sucursal_id = :suc
              AND op.estado NOT IN ('Anulado')
              AND op.fecha_movimiento::date BETWEEN :desde AND :hasta
              {$filtroEntrega}
            GROUP BY pd.orden_picking_id, pd.producto_id
        ";
        $stmtGen = $pdo->prepare($sqlGen);
        $stmtGen->execute($params);

        $solValido   = 0;
        $sepEfectivo = 0;
        $refSolMap   = [];
        $refSepMap   = [];

        foreach ($stmtGen->fetchAll(\PDO::FETCH_ASSOC) as $g) {
            $key    = $g['orden_picking_id'] . '_' . $g['producto_id'];
            $prodId = $g['producto_id'];
            $sol    = (float)$g['total_solicitado'];
            $sep    = (float)$g['total_separado'];
            $digit  = $digitMap[$key] ?? 0;

            $solValida   = max(0, $sol - $digit);
            $sepEfectiva = min($solValida, max(0, $sep));

            $solValido   += $solValida;
            $sepEfectivo += $sepEfectiva;

            if (!isset($refSolMap[$prodId])) { $refSolMap[$prodId] = 0; $refSepMap[$prodId] = 0; }
            $refSolMap[$prodId] += $solValida;
            $refSepMap[$prodId] += $sepEfectiva;
        }

        $pctUnidades = $solValido > 0
            ? min(100.0, max(0.0, round(($sepEfectivo / $solValido) * 100, 2)))
            : 100.0;

        $totalRefs     = 0;
        $refsCompletas = 0;
        foreach ($refSolMap as $prodId => $solVal) {
            if ($solVal > 0) {
                $totalRefs++;
                if (($refSepMap[$prodId] ?? 0) >= $solVal) $refsCompletas++;
            }
        }
        $pctRefs = $totalRefs > 0
            ? min(100.0, max(0.0, round(($refsCompletas / $totalRefs) * 100, 2)))
            : 100.0;

        return [
            'solicitado'          => $solValido,
            'separado'            => $sepEfectivo,
            'digitacion_excluido' => $digitTotal,
            'pct_unidades'        => $pctUnidades,
            'total_refs'          => $totalRefs,
            'refs_completas'      => $refsCompletas,
            'pct_refs'            => $pctRefs,
            'promedio'            => round(($pctUnidades + $pctRefs) / 2, 2),
        ];
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
            // General: Unidades (NS2) + Referencias (NS1) del día, excluyendo
            // faltantes por error de digitación — ver _nsCalcularRango().
            $gen = $this->_nsCalcularRango($pdo, $empresaId, $sucursalId, $fecha, $fecha);
            $solGenValido    = $gen['solicitado'];
            $sepGenEfectivo  = $gen['separado'];
            $digitGenTotal   = $gen['digitacion_excluido'];
            $pctGen          = $gen['pct_unidades'];
            $totalRefs       = $gen['total_refs'];
            $refsCompletas   = $gen['refs_completas'];
            $pctRefs         = $gen['pct_refs'];

            // ── Por sucursal (fecha dada) — por referencia/SKU válidos ───────────
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

            // ── Agotados del período: referencias con faltantes registrados (excluye digitación) ──
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
                    LEFT JOIN causales_novedad cn ON cn.id = pf.causal_id
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
                      AND (cn.id IS NULL OR cn.nombre NOT ILIKE '%DIGITACION%')
                      AND (pf.causa IS NULL OR pf.causa NOT ILIKE '%DIGITACION%')
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
                    'solicitado'         => $solGenValido,
                    'separado'           => $sepGenEfectivo,
                    'digitacion_excluido'=> $digitGenTotal,
                    'pct'                => $pctGen,
                    'pct_formula'        => 'min(100.0, (separado_efectivo / solicitado_valido) * 100)',
                    'total_refs'         => $totalRefs,
                    'refs_completas'     => $refsCompletas,
                    'pct_refs'           => $pctRefs,
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

    /**
     * GET /api/tv/picking-ranking
     *
     * Devuelve ranking de auxiliares (por referencias y por unidades) y completadas hoy.
     */
    public function getPickingRanking(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();

        $params = $request->getQueryParams();
        $fecha  = !empty($params['fecha']) ? $params['fecha'] : date('Y-m-d');
        $start  = $fecha . ' 00:00:00';
        $end    = $fecha . ' 23:59:59';
        $dateCol = $this->isPg() ? "op.fecha_movimiento::date" : "DATE(op.fecha_movimiento)";

        try {
            // 1. Totales de progreso global del día (solicitado vs pickeado vs pendiente)
            $stmtTotals = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT pd.producto_id) as total_refs_solicitadas,
                    COUNT(DISTINCT CASE WHEN pd.estado IN ('Completado', 'Completada') THEN pd.producto_id END) as total_refs_completadas,
                    COALESCE(SUM(pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1)), 0) as total_unidades_solicitadas,
                    COALESCE(SUM(pd.cantidad_pickeada), 0) as total_unidades_completadas
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                LEFT JOIN productos p ON p.id = pd.producto_id
                WHERE op.empresa_id = :emp
                  AND op.sucursal_id = :suc
                  AND ({$dateCol} = :fecha OR pd.created_at BETWEEN :start AND :end)
            ");
            $stmtTotals->execute([
                ':emp'   => $empresaId,
                ':suc'   => $sucursalId,
                ':fecha' => $fecha,
                ':start' => $start,
                ':end'   => $end,
            ]);
            $totalsRaw = $stmtTotals->fetch(\PDO::FETCH_ASSOC) ?: [];

            $refSol  = (int)($totalsRaw['total_refs_solicitadas'] ?? 0);
            $refComp = (int)($totalsRaw['total_refs_completadas'] ?? 0);
            $refPend = max(0, $refSol - $refComp);
            $refPct  = $refSol > 0 ? round($refComp / $refSol * 100, 1) : 0;

            $undSol  = (float)($totalsRaw['total_unidades_solicitadas'] ?? 0);
            $undComp = (float)($totalsRaw['total_unidades_completadas'] ?? 0);
            $undPend = max(0, $undSol - $undComp);
            $undPct  = $undSol > 0 ? round($undComp / $undSol * 100, 1) : 0;

            $totalesProgreso = [
                'refs_solicitadas'     => $refSol,
                'refs_completadas'     => $refComp,
                'refs_pendientes'      => $refPend,
                'refs_pct'             => $refPct,
                'unidades_solicitadas' => $undSol,
                'unidades_completadas' => $undComp,
                'unidades_pendientes'  => $undPend,
                'unidades_pct'         => $undPct,
            ];

            // 2. Desglose multi-métrica por Auxiliar
            $stmtAux = $pdo->prepare("
                SELECT
                    COALESCE(pe.id, 0) as auxiliar_id,
                    COALESCE(pe.nombre, 'Sin asignar') AS nombre,
                    COUNT(DISTINCT CASE WHEN pd.cantidad_pickeada > 0 THEN pd.producto_id END) AS referencias,
                    COALESCE(SUM(pd.cantidad_pickeada), 0) AS unidades,
                    COUNT(DISTINCT CASE WHEN pd.estado = 'Faltante' OR pf.id IS NOT NULL THEN pd.producto_id END) AS agotados,
                    COALESCE(SUM(CASE WHEN pd.estado = 'Faltante' OR pf.id IS NOT NULL THEN pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1) ELSE 0 END), 0) AS unidades_agotadas,
                    ROUND(AVG(
                        CASE 
                            WHEN op.hora_inicio IS NOT NULL AND op.hora_fin IS NOT NULL AND op.hora_fin >= op.hora_inicio 
                            THEN EXTRACT(EPOCH FROM (op.hora_fin::time - op.hora_inicio::time))/60 
                            ELSE NULL 
                        END
                    )::numeric, 1) as tiempo_promedio_min
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                LEFT JOIN productos p ON p.id = pd.producto_id
                LEFT JOIN personal pe ON pe.id = COALESCE(pd.auxiliar_id, op.auxiliar_id)
                LEFT JOIN picking_faltantes pf ON pf.orden_picking_id = op.id AND pf.producto_id = pd.producto_id
                WHERE op.empresa_id = :emp
                  AND op.sucursal_id = :suc
                  AND ({$dateCol} = :fecha OR pd.created_at BETWEEN :start AND :end)
                GROUP BY pe.id, pe.nombre
                HAVING COUNT(pd.id) > 0
            ");
            $stmtAux->execute([
                ':emp'   => $empresaId,
                ':suc'   => $sucursalId,
                ':fecha' => $fecha,
                ':start' => $start,
                ':end'   => $end,
            ]);
            $auxiliares = $stmtAux->fetchAll(\PDO::FETCH_ASSOC);

            // Rankings ordenados
            $rankingRefs = $auxiliares;
            usort($rankingRefs, fn($a, $b) => $b['referencias'] <=> $a['referencias']);

            $rankingUnits = $auxiliares;
            usort($rankingUnits, fn($a, $b) => $b['unidades'] <=> $a['unidades']);

            $rankingTiempo = array_values(array_filter($auxiliares, fn($a) => !is_null($a['tiempo_promedio_min']) && $a['tiempo_promedio_min'] > 0));
            usort($rankingTiempo, fn($a, $b) => $a['tiempo_promedio_min'] <=> $b['tiempo_promedio_min']);

            $rankingAgotados = $auxiliares;
            usort($rankingAgotados, fn($a, $b) => $b['agotados'] <=> $a['agotados']);

            // 3. Completadas hoy
            $stmtComp = $pdo->prepare("
                SELECT COUNT(id)
                FROM orden_pickings op
                WHERE op.empresa_id = :emp
                  AND op.sucursal_id = :suc
                  AND {$dateCol} = :fecha
                  AND op.estado IN ('Completada', 'Completado', 'Cerrada')
            ");
            $stmtComp->execute([
                ':emp'   => $empresaId,
                ':suc'   => $sucursalId,
                ':fecha' => $fecha,
            ]);
            $completadasHoy = (int)$stmtComp->fetchColumn();

            return $this->ok($response, [
                'totales_progreso' => $totalesProgreso,
                'ranking_refs'     => array_slice($rankingRefs, 0, 10),
                'ranking_units'    => array_slice($rankingUnits, 0, 10),
                'ranking_tiempo'   => array_slice($rankingTiempo, 0, 10),
                'ranking_agotados' => array_slice($rankingAgotados, 0, 10),
                'auxiliares'       => $auxiliares,
                'completadas_hoy'  => $completadasHoy,
            ]);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'TV:getPickingRanking — ' . $e->getMessage());
            return $this->error($response, 'Error al obtener ranking de picking: ' . $e->getMessage(), 500);
        }
    }
}
