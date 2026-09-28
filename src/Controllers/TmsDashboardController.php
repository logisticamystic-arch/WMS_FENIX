<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\TmsClient;

/**
 * Dashboard de escritorio del TMS (entregas en punto de venta) — a pedido
 * explícito de Camilo (2026-09-28): KPIs, NS de referencias, tiempos de
 * demora y ranking de novedades por sucursal/referencia, con filtros
 * dinámicos. Todo se lee de `entregas_ruta` (una fila por pedido entregado,
 * ver TmsController::_procesarEntregaConfirmada) — no hay tabla propia
 * nueva, mismo criterio que ya usa DashboardTVController con sus otras
 * fuentes.
 */
class TmsDashboardController extends BaseController
{
    private function filtrosSql(array $p, int $empresaId, ?int $sucursalId): array
    {
        $where  = ['op.empresa_id = :emp'];
        $params = [':emp' => $empresaId];

        if ($sucursalId) {
            $where[] = 'op.sucursal_id = :suc';
            $params[':suc'] = $sucursalId;
        }
        $desde = $p['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
        $hasta = $p['fecha_hasta'] ?? date('Y-m-d');
        $where[] = 'op.fecha_movimiento BETWEEN :desde AND :hasta';
        $params[':desde'] = $desde;
        $params[':hasta'] = $hasta;

        if (!empty($p['sucursal_entrega'])) {
            $where[] = 'op.sucursal_entrega = :sucursal_entrega';
            $params[':sucursal_entrega'] = $p['sucursal_entrega'];
        }
        if (!empty($p['auxiliar'])) {
            $where[] = 'er.auxiliar_nombre_ruta ILIKE :auxiliar';
            $params[':auxiliar'] = '%' . $p['auxiliar'] . '%';
        }
        if (!empty($p['referencia'])) {
            $where[] = "EXISTS (SELECT 1 FROM picking_detalles pd JOIN productos pr ON pr.id = pd.producto_id
                         WHERE pd.orden_picking_id = op.id AND (pr.codigo_interno ILIKE :ref OR pr.nombre ILIKE :ref))";
            $params[':ref'] = '%' . $p['referencia'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    public function resumen(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();
        $p          = $request->getQueryParams();

        [$whereSql, $params] = $this->filtrosSql($p, $empresaId, $sucursalId);

        // CTE con las entregas ya filtradas — se reusa en todas las queries
        // de abajo (vía IN, no JOIN+EXISTS repetido) para no arriesgar un
        // alias "op"/"er" duplicado entre la query externa y una subquery,
        // que desconectaría el filtro silenciosamente y contaría de más.
        $cte = "WITH filtro AS (
                    SELECT er.id, er.orden_picking_id, er.tiene_novedad, er.total_novedades,
                           er.tiempo_demora_minutos, op.sucursal_entrega, op.fecha_movimiento
                    FROM entregas_ruta er
                    JOIN orden_pickings op ON op.id = er.orden_picking_id
                    WHERE {$whereSql}
                )";

        // ── KPIs generales ──────────────────────────────────────────────
        $kpis = $pdo->prepare("
            {$cte}
            SELECT COUNT(*) AS total_entregas,
                   COUNT(*) FILTER (WHERE tiene_novedad) AS con_novedad,
                   COUNT(*) FILTER (WHERE NOT tiene_novedad) AS sin_novedad,
                   COALESCE(SUM(total_novedades), 0) AS total_novedades,
                   ROUND(AVG(tiempo_demora_minutos)::numeric, 1) AS tiempo_promedio_min
            FROM filtro
        ");
        $kpis->execute($params);
        $kpis = $kpis->fetch(\PDO::FETCH_ASSOC) ?: [];

        // ── NS por referencias: aptas para entrega (pickeadas, no agotadas)
        //    vs entregadas sin novedad (sin devolución registrada) ────────
        $totalRefsAptas = $pdo->prepare("
            {$cte}
            SELECT COUNT(DISTINCT pd.producto_id)
            FROM picking_detalles pd
            WHERE pd.cantidad_pickeada > 0
              AND pd.orden_picking_id IN (SELECT orden_picking_id FROM filtro)
        ");
        $totalRefsAptas->execute($params);
        $totalRefsAptas = (int)$totalRefsAptas->fetchColumn();

        $refsConNovedad = $pdo->prepare("
            {$cte}
            SELECT COUNT(DISTINCT dd.producto_id)
            FROM devoluciones d
            JOIN devolucion_detalles dd ON dd.devolucion_id = d.id
            WHERE d.referencia_externa IN (SELECT orden_picking_id::text FROM filtro)
        ");
        $refsConNovedad->execute($params);
        $refsConNovedad = (int)$refsConNovedad->fetchColumn();

        $refsSinNovedad = max(0, $totalRefsAptas - $refsConNovedad);
        $nsPct = $totalRefsAptas > 0 ? round($refsSinNovedad / $totalRefsAptas * 100, 1) : null;

        // ── Tiempo de demora promedio por día ───────────────────────────
        $tiempos = $pdo->prepare("
            {$cte}
            SELECT fecha_movimiento AS fecha, ROUND(AVG(tiempo_demora_minutos)::numeric, 1) AS promedio
            FROM filtro WHERE tiempo_demora_minutos IS NOT NULL
            GROUP BY fecha_movimiento ORDER BY fecha_movimiento
        ");
        $tiempos->execute($params);
        $tiempos = $tiempos->fetchAll(\PDO::FETCH_ASSOC);

        // ── Sucursales con más novedades ─────────────────────────────────
        $sucursales = $pdo->prepare("
            {$cte}
            SELECT sucursal_entrega AS sucursal,
                   COUNT(*) FILTER (WHERE tiene_novedad) AS entregas_con_novedad,
                   COALESCE(SUM(total_novedades), 0) AS total_novedades
            FROM filtro
            GROUP BY sucursal_entrega
            HAVING COALESCE(SUM(total_novedades), 0) > 0
            ORDER BY total_novedades DESC LIMIT 10
        ");
        $sucursales->execute($params);
        $sucursales = $sucursales->fetchAll(\PDO::FETCH_ASSOC);

        // ── Referencias con más novedades ────────────────────────────────
        $referencias = $pdo->prepare("
            {$cte}
            SELECT pr.codigo_interno AS codigo, pr.nombre,
                   COUNT(*) AS veces, COALESCE(SUM(dd.cantidad), 0) AS unidades
            FROM devoluciones d
            JOIN devolucion_detalles dd ON dd.devolucion_id = d.id
            JOIN productos pr ON pr.id = dd.producto_id
            WHERE d.referencia_externa IN (SELECT orden_picking_id::text FROM filtro)
            GROUP BY pr.codigo_interno, pr.nombre
            ORDER BY veces DESC LIMIT 10
        ");
        $referencias->execute($params);
        $referencias = $referencias->fetchAll(\PDO::FETCH_ASSOC);

        // ── Opciones de filtro (rango de fechas del filtro, sin más
        //    restricciones, para que los combos no se auto-encojan) ──────
        $filtroParams = [':emp' => $empresaId, ':desde' => $params[':desde'], ':hasta' => $params[':hasta']];
        $filtroWhere  = 'op.empresa_id = :emp AND op.fecha_movimiento BETWEEN :desde AND :hasta';
        if ($sucursalId) { $filtroWhere .= ' AND op.sucursal_id = :suc'; $filtroParams[':suc'] = $sucursalId; }

        $sucursalesOpt = $pdo->prepare("
            SELECT DISTINCT op.sucursal_entrega FROM entregas_ruta er
            JOIN orden_pickings op ON op.id = er.orden_picking_id
            WHERE {$filtroWhere} AND op.sucursal_entrega IS NOT NULL ORDER BY 1
        ");
        $sucursalesOpt->execute($filtroParams);
        $sucursalesOpt = array_column($sucursalesOpt->fetchAll(\PDO::FETCH_ASSOC), 'sucursal_entrega');

        $auxiliaresOpt = $pdo->prepare("
            SELECT DISTINCT er.auxiliar_nombre_ruta FROM entregas_ruta er
            JOIN orden_pickings op ON op.id = er.orden_picking_id
            WHERE {$filtroWhere} AND er.auxiliar_nombre_ruta IS NOT NULL ORDER BY 1
        ");
        $auxiliaresOpt->execute($filtroParams);
        $auxiliaresOpt = array_column($auxiliaresOpt->fetchAll(\PDO::FETCH_ASSOC), 'auxiliar_nombre_ruta');

        return $this->json($response, ['error' => false, 'data' => [
            'kpis' => [
                'total_entregas'       => (int)($kpis['total_entregas'] ?? 0),
                'con_novedad'          => (int)($kpis['con_novedad'] ?? 0),
                'sin_novedad'          => (int)($kpis['sin_novedad'] ?? 0),
                'total_novedades'      => (int)($kpis['total_novedades'] ?? 0),
                'tiempo_promedio_min'  => $kpis['tiempo_promedio_min'] !== null ? (float)$kpis['tiempo_promedio_min'] : null,
                'ns_referencias_pct'   => $nsPct,
                'total_refs_aptas'     => $totalRefsAptas,
                'refs_sin_novedad'     => $refsSinNovedad,
            ],
            'tiempos_por_dia'    => $tiempos,
            'top_sucursales'     => $sucursales,
            'top_referencias'    => $referencias,
            'filtros' => [
                'sucursales' => $sucursalesOpt,
                'auxiliares' => $auxiliaresOpt,
            ],
        ]]);
    }

    // Mapa "en tiempo real": visitas aún no confirmadas (en curso, leídas al
    // vuelo del TMS) + últimas entregas YA confirmadas hoy (con su punto de
    // llegada/salida, ya persistido en el WMS) — ver comentario en TmsClient.
    public function mapa(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();

        $enCurso = TmsClient::visitasEnRuta();

        $stmt = $pdo->prepare("
            SELECT op.sucursal_entrega AS sucursal, er.auxiliar_nombre_ruta AS auxiliar_nombre,
                   er.hora_llegada, er.hora_salida, er.tracking_geo, er.tiene_novedad
            FROM entregas_ruta er
            JOIN orden_pickings op ON op.id = er.orden_picking_id
            WHERE op.empresa_id = :emp
              " . ($sucursalId ? 'AND op.sucursal_id = :suc' : '') . "
              AND op.fecha_movimiento = CURRENT_DATE
              AND er.tracking_geo IS NOT NULL
        ");
        $params = [':emp' => $empresaId];
        if ($sucursalId) $params[':suc'] = $sucursalId;
        $stmt->execute($params);

        $confirmadas = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $geo = json_decode($r['tracking_geo'], true) ?: [];
            $confirmadas[] = [
                'sucursal'        => $r['sucursal'],
                'auxiliar_nombre' => $r['auxiliar_nombre'],
                'estado'          => 'entregado',
                'tiene_novedad'   => (bool)$r['tiene_novedad'],
                'hora_llegada'    => $r['hora_llegada'],
                'hora_salida'     => $r['hora_salida'],
                'puntos'          => $geo,
            ];
        }

        return $this->json($response, ['error' => false, 'data' => [
            'en_curso'    => $enCurso,
            'confirmadas' => $confirmadas,
        ]]);
    }
}
