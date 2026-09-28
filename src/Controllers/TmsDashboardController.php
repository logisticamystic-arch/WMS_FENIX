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
        // Default: día actual — a pedido explícito de Camilo (2026-09-29), antes
        // arrancaba en los últimos 30 días.
        $desde = $p['fecha_desde'] ?? date('Y-m-d');
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
                           er.tiempo_demora_minutos, er.auxiliar_nombre_ruta,
                           op.sucursal_entrega, op.fecha_movimiento
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

        // ── Nivel de Servicio por día — a pedido explícito de Camilo
        //    (2026-09-29), reemplaza el gráfico de "Referencias con más
        //    novedades" (esa info ahora vive en el detalle de novedades más
        //    abajo). Un día suele tener pocas líneas — se resuelve con dos
        //    queries chicas por día en vez de una sola muy correlacionada,
        //    más fácil de verificar correcta.
        $diasStmt = $pdo->prepare("{$cte} SELECT DISTINCT fecha_movimiento AS fecha FROM filtro ORDER BY fecha_movimiento");
        $diasStmt->execute($params);
        $dias = array_column($diasStmt->fetchAll(\PDO::FETCH_ASSOC), 'fecha');

        $nsPorDia = [];
        foreach ($dias as $fecha) {
            $paramsDia = $params + [':fecha_dia' => $fecha];

            $aptasDia = $pdo->prepare("
                {$cte}
                SELECT COUNT(DISTINCT pd.producto_id)
                FROM picking_detalles pd
                WHERE pd.cantidad_pickeada > 0
                  AND pd.orden_picking_id IN (SELECT orden_picking_id FROM filtro WHERE fecha_movimiento = :fecha_dia)
            ");
            $aptasDia->execute($paramsDia);
            $aptas = (int)$aptasDia->fetchColumn();

            $conNovedadDia = $pdo->prepare("
                {$cte}
                SELECT COUNT(DISTINCT dd.producto_id)
                FROM devoluciones d
                JOIN devolucion_detalles dd ON dd.devolucion_id = d.id
                WHERE d.referencia_externa IN (SELECT orden_picking_id::text FROM filtro WHERE fecha_movimiento = :fecha_dia)
            ");
            $conNovedadDia->execute($paramsDia);
            $conNov = (int)$conNovedadDia->fetchColumn();

            $sinNov = max(0, $aptas - $conNov);
            $nsPorDia[] = [
                'fecha'   => $fecha,
                'ns_pct'  => $aptas > 0 ? round($sinNov / $aptas * 100, 1) : null,
            ];
        }

        // ── Matriz de tiempos de demora por sucursal ─────────────────────
        $matrizDemora = $pdo->prepare("
            {$cte}
            SELECT sucursal_entrega AS sucursal, COUNT(*) AS entregas,
                   ROUND(AVG(tiempo_demora_minutos)::numeric, 1) AS promedio,
                   MIN(tiempo_demora_minutos) AS minimo, MAX(tiempo_demora_minutos) AS maximo
            FROM filtro WHERE tiempo_demora_minutos IS NOT NULL
            GROUP BY sucursal_entrega ORDER BY promedio DESC
        ");
        $matrizDemora->execute($params);
        $matrizDemora = $matrizDemora->fetchAll(\PDO::FETCH_ASSOC);

        // ── Detalle de novedades (una fila por referencia devuelta) ──────
        $detalleNovedades = $pdo->prepare("
            {$cte}
            SELECT d.consecutivo_devolucion AS consecutivo, d.fecha_movimiento AS fecha,
                   f.sucursal_entrega AS sucursal, f.auxiliar_nombre_ruta AS auxiliar,
                   pr.codigo_interno AS codigo, pr.nombre, cd.causal, dd.cantidad
            FROM devoluciones d
            JOIN devolucion_detalles dd ON dd.devolucion_id = d.id
            JOIN productos pr ON pr.id = dd.producto_id
            JOIN filtro f ON f.orden_picking_id::text = d.referencia_externa
            LEFT JOIN causales_devolucion cd ON cd.id = d.causal_devolucion_id
            ORDER BY d.fecha_movimiento DESC, d.id DESC LIMIT 200
        ");
        $detalleNovedades->execute($params);
        $detalleNovedades = $detalleNovedades->fetchAll(\PDO::FETCH_ASSOC);

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
            'ns_por_dia'         => $nsPorDia,
            'matriz_demora'      => $matrizDemora,
            'top_sucursales'     => $sucursales,
            'top_referencias'    => $referencias,
            'detalle_novedades'  => $detalleNovedades,
            'filtros' => [
                'sucursales' => $sucursalesOpt,
                'auxiliares' => $auxiliaresOpt,
            ],
        ]]);
    }

    // Mapa "en tiempo real": visitas aún no confirmadas (en curso, leídas al
    // vuelo del TMS, siempre "hoy" — no tiene sentido filtrar por fecha algo
    // que por definición está pasando ahora) + entregas YA confirmadas del
    // día filtrado (con su punto de llegada/salida, ya persistido en el WMS)
    // — ver comentario en TmsClient. A pedido explícito de Camilo
    // (2026-09-29): filtro de fecha (default hoy) + vehículo, y el
    // "recorrido" armado como una polylínea por auxiliar (los puntos
    // llegada/salida de cada parada, en orden cronológico).
    public function mapa(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $pdo        = Capsule::connection()->getPdo();
        $p          = $request->getQueryParams();

        $fecha    = $p['fecha'] ?? date('Y-m-d');
        $esHoy    = $fecha === date('Y-m-d');
        // "En curso" nunca se filtra por vehículo — el TMS no sabe qué
        // vehículo tiene cada auxiliar en vivo (solo vía planilla, no en
        // tiempo real) — mostrarlo igual mezclaría camiones distintos al
        // filtrado. A pedido explícito de Camilo (2026-09-29): "el filtro
        // solo debe mostrar el carro seleccionado".
        $enCurso  = ($esHoy && empty($p['vehiculo'])) ? TmsClient::visitasEnRuta() : [];

        $where  = ['op.empresa_id = :emp', 'op.fecha_movimiento = :fecha', 'er.tracking_geo IS NOT NULL'];
        $params = [':emp' => $empresaId, ':fecha' => $fecha];
        if ($sucursalId) { $where[] = 'op.sucursal_id = :suc'; $params[':suc'] = $sucursalId; }
        if (!empty($p['vehiculo'])) { $where[] = 'des.placa = :placa'; $params[':placa'] = $p['vehiculo']; }

        $stmt = $pdo->prepare("
            SELECT op.sucursal_entrega AS sucursal, er.auxiliar_nombre_ruta AS auxiliar_nombre,
                   er.hora_llegada, er.hora_salida, er.tracking_geo, er.tiene_novedad, des.placa
            FROM entregas_ruta er
            JOIN orden_pickings op ON op.id = er.orden_picking_id
            LEFT JOIN despachos des ON des.id = er.despacho_id
            WHERE " . implode(' AND ', $where) . "
        ");
        $stmt->execute($params);

        $confirmadas = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $geo = json_decode($r['tracking_geo'], true) ?: [];
            $confirmadas[] = [
                'sucursal'        => $r['sucursal'],
                'auxiliar_nombre' => $r['auxiliar_nombre'],
                'placa'           => $r['placa'],
                'estado'          => 'entregado',
                'tiene_novedad'   => (bool)$r['tiene_novedad'],
                'hora_llegada'    => $r['hora_llegada'],
                'hora_salida'     => $r['hora_salida'],
                'puntos'          => $geo,
            ];
        }

        // ── Recorrido: une los puntos de cada auxiliar en orden cronológico
        //    (llegada/salida de cada parada confirmada + el último punto en
        //    curso, si aplica) para poder dibujar la ruta recorrida hasta el
        //    momento, no solo marcadores sueltos.
        $rutas = [];
        foreach ($confirmadas as $c) {
            if (empty($c['auxiliar_nombre'])) continue;
            $aux = $c['auxiliar_nombre'];
            $rutas[$aux] = $rutas[$aux] ?? [];
            foreach (['llegada', 'salida'] as $evento) {
                $pt = $c['puntos'][$evento] ?? null;
                if (!$pt) continue;
                $hora = $evento === 'llegada' ? $c['hora_llegada'] : $c['hora_salida'];
                $rutas[$aux][] = ['lat' => $pt['lat'], 'lng' => $pt['lng'], 'hora' => $hora, 'sucursal' => $c['sucursal'], 'evento' => $evento];
            }
        }
        if ($esHoy) {
            foreach ($enCurso as $v) {
                if (empty($v['auxiliar_nombre'])) continue;
                $rutas[$v['auxiliar_nombre']] = $rutas[$v['auxiliar_nombre']] ?? [];
                // Recorrido completo (pings automáticos cada 5 min, ver
                // tracking.php evento 'live') — no solo el último punto, para
                // poder dibujar el camino recorrido en lo que va de la visita
                // en curso, no solo dónde está ahora.
                foreach (($v['recorrido'] ?? []) as $p) {
                    $rutas[$v['auxiliar_nombre']][] = [
                        'lat' => $p['lat'], 'lng' => $p['lng'],
                        'hora' => $p['ts'] ?? $v['hora_llegada'], 'sucursal' => $v['sucursal'], 'evento' => 'en_ruta',
                    ];
                }
                if (empty($v['recorrido']) && !empty($v['ultimo_punto'])) {
                    $rutas[$v['auxiliar_nombre']][] = [
                        'lat' => $v['ultimo_punto']['lat'], 'lng' => $v['ultimo_punto']['lng'],
                        'hora' => $v['hora_llegada'], 'sucursal' => $v['sucursal'], 'evento' => 'en_ruta',
                    ];
                }
            }
        }
        foreach ($rutas as $aux => $puntos) {
            usort($puntos, fn($a, $b) => strcmp($a['hora'] ?? '', $b['hora'] ?? ''));
            $rutas[$aux] = $puntos;
        }

        $vehiculosOpt = $pdo->prepare("
            SELECT DISTINCT des.placa FROM entregas_ruta er
            JOIN orden_pickings op ON op.id = er.orden_picking_id
            JOIN despachos des ON des.id = er.despacho_id
            WHERE op.empresa_id = :emp AND des.placa IS NOT NULL
            " . ($sucursalId ? 'AND op.sucursal_id = :suc' : '') . "
            ORDER BY 1
        ");
        $vehiculosParams = [':emp' => $empresaId];
        if ($sucursalId) $vehiculosParams[':suc'] = $sucursalId;
        $vehiculosOpt->execute($vehiculosParams);
        $vehiculosOpt = array_column($vehiculosOpt->fetchAll(\PDO::FETCH_ASSOC), 'placa');

        return $this->json($response, ['error' => false, 'data' => [
            'en_curso'    => $enCurso,
            'confirmadas' => $confirmadas,
            'rutas'       => $rutas,
            'filtros'     => ['fecha' => $fecha, 'vehiculos' => $vehiculosOpt],
        ]]);
    }

    /* ═══════════════════════════════════════════════════════════════════
       REABRIR PEDIDOS — a pedido explícito de Camilo (2026-09-29): sección
       para reabrir en el TMS un pedido que el auxiliar necesita rehacer.
       Los datos (planilla/líneas/novedades) viven en el TMS, no en el WMS —
       se leen bajo demanda vía TmsClient, igual que el mapa en vivo.
    ═══════════════════════════════════════════════════════════════════ */
    public function reabrirPedidosListar(Request $request, Response $response): Response
    {
        $p = $request->getQueryParams();
        $filtros = array_filter([
            'fecha_desde' => $p['fecha_desde'] ?? null,
            'fecha_hasta' => $p['fecha_hasta'] ?? null,
            'ruta'        => $p['ruta'] ?? null,
            'sucursal'    => $p['sucursal'] ?? null,
            'auxiliar'    => $p['auxiliar'] ?? null,
        ]);
        $data = TmsClient::consultarPedidos($filtros);
        return $this->json($response, ['error' => false, 'data' => $data]);
    }

    public function reabrirPedidoDetalle(Request $request, Response $response, array $args): Response
    {
        $ordenId = (int)($args['ordenId'] ?? 0);
        $data = TmsClient::detallePedido($ordenId);
        if (!$data) {
            return $this->json($response, ['error' => true, 'message' => 'No se pudo consultar el pedido en el TMS.'], 502);
        }
        return $this->json($response, ['error' => false, 'data' => $data]);
    }

    public function reabrirPedidoAccion(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $ordenId   = (int)($args['ordenId'] ?? 0);
        if (!$ordenId) return $this->json($response, ['error' => true, 'message' => 'orden_picking_id inválido'], 400);

        $orden = Capsule::table('orden_pickings')->where('id', $ordenId)->where('empresa_id', $empresaId)->first();
        if (!$orden) return $this->json($response, ['error' => true, 'message' => 'Pedido no encontrado'], 404);

        $ok = TmsClient::sincronizarPedido($ordenId, 'reabrir');
        if (!$ok) {
            return $this->json($response, ['error' => true, 'message' => 'El TMS no respondió. Intente de nuevo.'], 502);
        }

        // La entrega anterior se descarta: el auxiliar la va a rehacer. Sin
        // esto quedarían DOS filas de entregas_ruta para el mismo pedido y el
        // dashboard contaría la entrega dos veces.
        Capsule::table('entregas_ruta')->where('orden_picking_id', $ordenId)->delete();

        // Vuelve a "Despachado": deja de figurar como entregado mientras se
        // rehace, pero estado_despacho NUNCA queda en null — es justamente lo
        // que impide que el pedido regrese a Picking (regla de oro).
        if ($orden->estado_despacho === 'Entregado') {
            Capsule::table('orden_pickings')->where('id', $ordenId)->update(['estado_despacho' => 'Despachado']);
        }

        $this->audit($user, 'tms', 'reabrir_pedido', 'orden_pickings', $ordenId,
            ['estado_despacho' => $orden->estado_despacho], ['estado_despacho' => 'Despachado'],
            "Pedido #{$ordenId} reabierto en el TMS para rehacer la entrega");

        return $this->json($response, ['error' => false, 'message' => 'Pedido reabierto. El auxiliar ya puede volver a tomarlo en el TMS.']);
    }
}
