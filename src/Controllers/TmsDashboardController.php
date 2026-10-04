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
        //
        // CORREGIDO 2026-09-28 (Camilo: "se acaba de realizar la entrega y no
        // aparece en el TMS de escritorio"): se filtraba por
        // orden_pickings.fecha_movimiento (cuándo se PICKEÓ el pedido), no por
        // cuándo se ENTREGÓ realmente. Un pedido reabierto y reentregado un
        // día distinto al que se certificó (exactamente este caso) quedaba
        // invisible en "hoy" aunque la entrega hubiera pasado hoy. Ahora se
        // filtra por la fecha real de la entrega (hora_salida de
        // entregas_ruta, con hora_llegada y fecha_movimiento como respaldo
        // para filas viejas/incompletas).
        $desde = $p['fecha_desde'] ?? date('Y-m-d');
        $hasta = $p['fecha_hasta'] ?? date('Y-m-d');
        $where[] = 'COALESCE(er.hora_salida::date, er.hora_llegada::date, op.fecha_movimiento) BETWEEN :desde AND :hasta';
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
                           er.hora_llegada, er.hora_salida, er.tracking_geo,
                           op.sucursal_entrega, op.planilla_numero,
                           COALESCE(er.hora_salida::date, er.hora_llegada::date, op.fecha_movimiento) AS fecha_entrega
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

        // Entregas por planilla consolidada + tiempo real por GPS (ver
        // _entregasPorPlanilla). Reemplaza el conteo por pedido y los tiempos
        // de la app en los indicadores.
        $filasStmt = $pdo->prepare("{$cte} SELECT * FROM filtro");
        $filasStmt->execute($params);
        $entregas = $this->_entregasPorPlanilla($filasStmt->fetchAll(\PDO::FETCH_ASSOC), $empresaId, $params[':desde'], $params[':hasta']);
        $conTiempo = array_values(array_filter($entregas, fn($e) => $e['tiempo_min'] !== null));
        $conGps = array_filter($entregas, fn($e) => $e['tiempo_gps_min'] !== null);
        $prom = fn($l) => $l ? round(array_sum(array_column($l, 'tiempo_min')) / count($l), 1) : null;

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

        // ── Tiempo en sitio promedio por día (por planilla, GPS > app) ───
        $porDia = [];
        foreach ($conTiempo as $e) $porDia[$e['fecha']][] = $e;
        ksort($porDia);
        $tiempos = [];
        foreach ($porDia as $fecha => $l) $tiempos[] = ['fecha' => $fecha, 'promedio' => $prom($l)];

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
        $diasStmt = $pdo->prepare("{$cte} SELECT DISTINCT fecha_entrega AS fecha FROM filtro ORDER BY fecha_entrega");
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
                  AND pd.orden_picking_id IN (SELECT orden_picking_id FROM filtro WHERE fecha_entrega = :fecha_dia)
            ");
            $aptasDia->execute($paramsDia);
            $aptas = (int)$aptasDia->fetchColumn();

            $conNovedadDia = $pdo->prepare("
                {$cte}
                SELECT COUNT(DISTINCT dd.producto_id)
                FROM devoluciones d
                JOIN devolucion_detalles dd ON dd.devolucion_id = d.id
                WHERE d.referencia_externa IN (SELECT orden_picking_id::text FROM filtro WHERE fecha_entrega = :fecha_dia)
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
        $porSuc = [];
        foreach ($conTiempo as $e) $porSuc[$e['sucursal']][] = $e;
        $matrizDemora = [];
        foreach ($porSuc as $suc => $l) {
            $ts = array_column($l, 'tiempo_min');
            $matrizDemora[] = ['sucursal' => $suc, 'entregas' => count($l), 'promedio' => $prom($l), 'minimo' => min($ts), 'maximo' => max($ts),
                               'con_gps' => count(array_filter($l, fn($e) => $e['fuente_tiempo'] === 'GPS'))];
        }
        usort($matrizDemora, fn($a, $b) => $b['promedio'] <=> $a['promedio']);

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
        $filtroWhere  = 'op.empresa_id = :emp AND COALESCE(er.hora_salida::date, er.hora_llegada::date, op.fecha_movimiento) BETWEEN :desde AND :hasta';
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
            'entregas_detalle' => $entregas,
            'kpis' => [
                // Entregas = planillas consolidadas; pedidos aparte.
                'total_entregas'       => count($entregas),
                'total_pedidos'        => (int)($kpis['total_entregas'] ?? 0),
                'entregas_con_novedad' => count(array_filter($entregas, fn($e) => $e['tiene_novedad'])),
                'entregas_sin_novedad' => count(array_filter($entregas, fn($e) => !$e['tiene_novedad'])),
                'tiempo_promedio_gps_min' => $prom(array_values($conGps)),
                'entregas_con_gps'     => count($conGps),
                'con_novedad'          => (int)($kpis['con_novedad'] ?? 0),
                'sin_novedad'          => (int)($kpis['sin_novedad'] ?? 0),
                'total_novedades'      => (int)($kpis['total_novedades'] ?? 0),
                'tiempo_promedio_min'  => $prom($conTiempo),
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
        $tms      = TmsClient::enRuta($fecha);
        $enCurso  = ($esHoy && empty($p['vehiculo'])) ? $tms['visitas'] : [];

        // Placa de cada auxiliar ese día (por su Planilla de Cargue) — permite
        // filtrar también el recorrido GPS por vehículo, cosa que las visitas
        // en curso no pueden (no traen el auxiliar del WMS).
        $stPl = $pdo->prepare("SELECT auxiliar_id, MAX(placa) AS placa FROM despachos
                               WHERE empresa_id = :emp AND fecha_movimiento = :f AND auxiliar_id IS NOT NULL
                               GROUP BY auxiliar_id");
        $stPl->execute([':emp' => $empresaId, ':f' => $fecha]);
        $placaPorPersonal = array_column($stPl->fetchAll(\PDO::FETCH_ASSOC), 'placa', 'auxiliar_id');

        // Filtra por la fecha REAL de la entrega (hora_salida/llegada), no por
        // cuándo se pickeó el pedido — mismo fix que resumen() (2026-09-28).
        $where  = ['op.empresa_id = :emp', 'COALESCE(er.hora_salida::date, er.hora_llegada::date, op.fecha_movimiento) = :fecha', 'er.tracking_geo IS NOT NULL'];
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
        // Recorrido continuo (GPS cada 5 min, tracking_auxiliar del TMS) —
        // 2026-10-01. Es el trayecto real, incluido el tramo entre paradas;
        // solo se registra desde que la planilla se despacha hasta que el
        // vehículo vuelve a la bodega (ver TMS tracking/ping.php).
        // Placa: la del despacho de cada punto (exacta); si el punto no trae
        // despacho, la de la planilla del auxiliar ese día.
        $despIds = [];
        foreach ($tms['tracks'] as $t) foreach ($t['puntos'] as $pt) if (!empty($pt['despacho_id'])) $despIds[$pt['despacho_id']] = true;
        foreach ($tms['rutas'] as $rt) $despIds[$rt['despacho_id']] = true;
        $placaPorDespacho = $despIds ? Capsule::table('despachos')->where('empresa_id', $empresaId)
            ->whereIn('id', array_keys($despIds))->pluck('placa', 'id')->all() : [];

        // Estado de la ruta por auxiliar (la más reciente): en ruta / regresó.
        $rutaPorPersonal = [];
        foreach ($tms['rutas'] as $rt) {
            if (!array_key_exists($rt['despacho_id'], $placaPorDespacho)) continue; // otra empresa
            $rutaPorPersonal[$rt['personal_id']] = $rt;
        }

        $vivos = [];
        $conPuntos = [];
        foreach ($tms['tracks'] as $t) {
            $ult = end($t['puntos']);
            $placa = !empty($ult['despacho_id']) ? ($placaPorDespacho[$ult['despacho_id']] ?? null)
                   : ($t['personal_id'] ? ($placaPorPersonal[$t['personal_id']] ?? null) : null);
            if (!empty($p['vehiculo']) && $placa !== $p['vehiculo']) continue;
            $rt = $t['personal_id'] ? ($rutaPorPersonal[$t['personal_id']] ?? null) : null;
            // Sin ruta activa ni regreso a bodega registrado = no está en
            // monitoreo (sin planilla despachada): no se muestra — 2026-10-01,
            // decisión de Camilo. Los "sin señal" de una ruta activa SÍ se ven.
            if (!$rt || ($rt['finalizada_at'] !== null && $rt['motivo_fin'] !== 'llegada_bodega')) continue;
            $aux = $t['auxiliar_nombre'];
            $rutas[$aux] = $rutas[$aux] ?? [];
            foreach ($t['puntos'] as $pt) {
                $rutas[$aux][] = ['lat' => $pt['lat'], 'lng' => $pt['lng'], 'hora' => $pt['ts'], 'sucursal' => null, 'evento' => 'gps'];
            }
            $conPuntos[$t['personal_id']] = true;
            $vivos[] = [
                'auxiliar_nombre'   => $aux,
                'placa'             => $placa,
                'ultimo_punto'      => ['lat' => $ult['lat'], 'lng' => $ult['lng']],
                'ultima_hora'       => $ult['ts'],
                'minutos_sin_senal' => (int)floor((time() - strtotime($ult['ts'])) / 60),
                'total_puntos'      => count($t['puntos']),
                'ruta_activa'       => $rt ? $rt['finalizada_at'] === null : false,
                'numero_despacho'   => $rt['numero_despacho'] ?? null,
                'regreso_bodega_at' => ($rt && $rt['motivo_fin'] === 'llegada_bodega') ? $rt['finalizada_at'] : null,
                'salio_bodega_at'   => $rt['salio_bodega_at'] ?? null,
            ];
        }
        // Planillas despachadas cuyo auxiliar aún no reporta ninguna posición
        // (app cerrada / sin permiso de ubicación): se listan igual para que
        // se note que falta la señal.
        $sinSenal = [];
        foreach ($rutaPorPersonal as $pid => $rt) {
            if ($rt['finalizada_at'] !== null || isset($conPuntos[$pid])) continue;
            $placa = $placaPorDespacho[$rt['despacho_id']] ?? $rt['placa'];
            if (!empty($p['vehiculo']) && $placa !== $p['vehiculo']) continue;
            $nombreAux = $rt['auxiliar_nombre'] ?: (Capsule::table('personal')->where('id', $pid)->value('nombre') ?: ('#' . $pid));
            $sinSenal[] = ['auxiliar_nombre' => $nombreAux, 'placa' => $placa,
                           'numero_despacho' => $rt['numero_despacho'], 'iniciada_at' => $rt['iniciada_at']];
        }

        if ($esHoy) {
            foreach ($enCurso as $v) {
                if (empty($v['auxiliar_nombre'])) continue;
                $rutas[$v['auxiliar_nombre']] = $rutas[$v['auxiliar_nombre']] ?? [];
                // Recorrido completo (pings automáticos cada 5 min, ver
                // tracking.php evento 'live') — no solo el último punto, para
                // poder dibujar el camino recorrido en lo que va de la visita
                // en curso, no solo dónde está ahora.
                foreach (($v['recorrido'] ?? []) as $pt) {
                    $rutas[$v['auxiliar_nombre']][] = [
                        'lat' => $pt['lat'], 'lng' => $pt['lng'],
                        'hora' => $pt['ts'] ?? $v['hora_llegada'], 'sucursal' => $v['sucursal'], 'evento' => 'en_ruta',
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
        $vehiculosOpt = array_values(array_unique(array_filter(array_merge($vehiculosOpt, array_values($placaPorPersonal), array_values($placaPorDespacho)))));
        sort($vehiculosOpt);

        // Paradas > 15 min del día de los vehículos visibles (mismo cálculo que
        // el reporte "Tiempos de paro", parámetros por defecto).
        $paradasMapa = [];
        $visibles = array_flip(array_column($vivos, 'auxiliar_nombre'));
        if ($visibles && ($dp = TmsClient::paradas($fecha, $fecha))) {
            foreach ($this->_detectarParadas($dp, $empresaId, $this->_cfgParadas([])) as $pa) {
                if (isset($visibles[$pa['auxiliar']]) && $pa['tipo'] !== 'Bodega') $paradasMapa[] = $pa;
            }
        }

        return $this->json($response, ['error' => false, 'data' => [
            'en_curso'    => $enCurso,
            'paradas'     => $paradasMapa,
            'vivos'       => $vivos,
            'sin_senal'   => $sinSenal,
            'bodega'      => $tms['bodega'],
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

        if (!$this->_limpiarPedido($user, $orden)) {
            return $this->json($response, ['error' => true, 'message' => 'El TMS no respondió. Intente de nuevo.'], 502);
        }

        return $this->json($response, ['error' => false, 'message' => 'Pedido reabierto. El auxiliar ya puede volver a tomarlo en el TMS.']);
    }

    // Reabrir/limpiar la entrega de un pedido en el TMS para que el auxiliar
    // la rehaga — compartido por "Reabrir" y por "Limpiar registros" de
    // Gestión de Pedidos.
    private function _limpiarPedido($user, $orden): bool
    {
        $ordenId = (int)$orden->id;
        // 'limpiar' = reinicio completo en el TMS (no 'reabrir', que conservaba
        // la visita a medio hacer) — corregido 2026-10-01.
        if (!TmsClient::sincronizarPedido($ordenId, 'limpiar')) return false;

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
        return true;
    }

    /* ═══════════════════════════════════════════════════════════════════
       GESTIÓN DE PEDIDOS — a pedido de Camilo (2026-10-01): activar /
       desactivar / limpiar registros / transferir a otro auxiliar los
       pedidos de las planillas cargadas, con filtros por auxiliar,
       sucursal, rango de fecha y planilla. Los pedidos viven en el TMS; los
       nombres de auxiliar y la placa se completan aquí desde el WMS.
    ═══════════════════════════════════════════════════════════════════ */
    public function gestionPedidosListar(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $p = $request->getQueryParams();

        $data = TmsClient::consultarPedidos(array_filter([
            'fecha_desde'          => $p['fecha_desde'] ?? null,
            'fecha_hasta'          => $p['fecha_hasta'] ?? null,
            'sucursal'             => $p['sucursal'] ?? null,
            'planilla'             => $p['planilla'] ?? null,
            'auxiliar_personal_id' => $p['auxiliar_id'] ?? null,
            'activo'               => $p['activo'] ?? null,
        ], fn($v) => $v !== null && $v !== ''));

        // El TMS es compartido entre empresas: solo se muestran los pedidos
        // de la empresa activa.
        $pedidos = $data['pedidos'] ?? [];
        $ids = array_column($pedidos, 'orden_picking_id');
        $propios = $ids ? Capsule::table('orden_pickings')->where('empresa_id', $empresaId)->whereIn('id', $ids)
            ->pluck('id')->flip()->all() : [];
        $pedidos = array_values(array_filter($pedidos, fn($x) => isset($propios[$x['orden_picking_id']])));

        $auxiliares = Capsule::table('personal')->where('empresa_id', $empresaId)->where('activo', true)
            ->where('rol', 'Auxiliar')->orderBy('nombre')->get(['id', 'nombre', 'documento']);
        $idsNombre = array_values(array_filter(array_merge(
            array_column($pedidos, 'auxiliar_personal_id'), $data['filtros']['auxiliares'] ?? []
        )));
        $nombres = $idsNombre ? Capsule::table('personal')->whereIn('id', $idsNombre)->pluck('nombre', 'id')->all() : [];
        $despIds = array_values(array_filter(array_unique(array_column($pedidos, 'despacho_id'))));
        $placas = $despIds ? Capsule::table('despachos')->whereIn('id', $despIds)->pluck('placa', 'id')->all() : [];

        foreach ($pedidos as &$x) {
            $x['auxiliar_asignado'] = $x['auxiliar_personal_id'] ? ($nombres[$x['auxiliar_personal_id']] ?? ('#' . $x['auxiliar_personal_id'])) : null;
            $x['placa'] = $x['despacho_id'] ? ($placas[$x['despacho_id']] ?? null) : null;
        }
        unset($x);

        $filtroAux = array_map(fn($id) => ['id' => $id, 'nombre' => $nombres[$id] ?? ('#' . $id)], $data['filtros']['auxiliares'] ?? []);
        usort($filtroAux, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));

        return $this->json($response, ['error' => false, 'data' => [
            'pedidos'    => $pedidos,
            'auxiliares' => $auxiliares,
            'filtros'    => [
                'sucursales' => $data['filtros']['sucursales'] ?? [],
                'planillas'  => $data['filtros']['planillas'] ?? [],
                'auxiliares' => $filtroAux,
            ],
        ]]);
    }

    public function gestionPedidosAccion(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $b = (array)($request->getParsedBody() ?? []);
        $accion = $b['accion'] ?? '';
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['orden_ids'] ?? [])))));

        if (!in_array($accion, ['activar', 'desactivar', 'limpiar', 'transferir'], true) || !$ids) {
            return $this->json($response, ['error' => true, 'message' => 'Acción u órdenes inválidas'], 400);
        }

        $destino = null;
        if ($accion === 'transferir') {
            $destino = Capsule::table('personal')->where('empresa_id', $empresaId)->where('activo', true)
                ->where('id', (int)($b['auxiliar_id'] ?? 0))->first(['id', 'nombre']);
            if (!$destino) return $this->json($response, ['error' => true, 'message' => 'Seleccione un auxiliar destino válido'], 400);
        }

        $ordenes = Capsule::table('orden_pickings')->where('empresa_id', $empresaId)->whereIn('id', $ids)->get();
        $ok = 0;
        $fallidos = [];
        foreach ($ordenes as $o) {
            if ($accion === 'limpiar') {
                $hecho = $this->_limpiarPedido($user, $o);
            } elseif ($accion === 'transferir') {
                $hecho = TmsClient::sincronizarPedido((int)$o->id, 'transferir', ['auxiliar_personal_id' => (int)$destino->id]);
                if ($hecho) {
                    Capsule::table('orden_pickings')->where('id', $o->id)->update(['tms_auxiliar_id' => $destino->id]);
                    $this->audit($user, 'tms', 'transferir_pedido', 'orden_pickings', (int)$o->id,
                        ['tms_auxiliar_id' => $o->tms_auxiliar_id], ['tms_auxiliar_id' => $destino->id],
                        "Pedido #{$o->id} transferido en el TMS a {$destino->nombre}");
                }
            } else {
                $hecho = TmsClient::sincronizarPedido((int)$o->id, $accion);
                if ($hecho) {
                    $this->audit($user, 'tms', $accion . '_pedido', 'orden_pickings', (int)$o->id, null, null,
                        "Pedido #{$o->id} " . ($accion === 'activar' ? 'activado' : 'desactivado') . ' en el TMS');
                }
            }
            if ($hecho) $ok++;
            else $fallidos[] = (int)$o->id;
        }

        // Pedido transferido de una planilla ya en ruta: el auxiliar destino
        // también debe quedar con el GPS activo hasta volver a la bodega.
        if ($accion === 'transferir') {
            $desp = Capsule::table('despachos')->whereIn('id', $ordenes->pluck('despacho_id')->filter()->unique()->values()->all())
                ->where('estado', 'Despachado')->pluck('id');
            foreach ($desp as $dId) {
                try { TmsClient::rutaDespacho((int)$dId, 'iniciar'); } catch (\Throwable $e) {}
            }
        }

        $total = count($ordenes);
        return $this->json($response, [
            'error'   => $ok === 0 && $total > 0,
            'message' => $fallidos ? "{$ok} de {$total} pedidos actualizados — el TMS no respondió para " . count($fallidos) . '.'
                                   : "{$ok} pedido(s) actualizados.",
            'data'    => ['ok' => $ok, 'fallidos' => $fallidos],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
       TIEMPOS DE PARO — a pedido de Camilo (2026-10-01), mismo criterio que
       los reportes de paradas de plataformas de rastreo (Satrack, Drivin,
       OnTrack): una PARADA es una secuencia de posiciones dentro de un radio
       (radio_m) que dura al menos umbral_min. Se clasifica por geocerca:
         - Bodega (BODEGA_* del TMS)
         - En cliente: cruza con la visita que el auxiliar registró en el TMS
           a esa hora, o cae en la geocerca (lat/lng del maestro de clientes)
         - No programada: ninguna de las anteriores
       Con GPS cada 5 min la duración es la MÍNIMA comprobada (primer a último
       punto dentro del radio) — precisión ±5 min.
    ═══════════════════════════════════════════════════════════════════ */
    private function _distM(float $a, float $b, float $c, float $d): float
    {
        $dLat = deg2rad($c - $a); $dLng = deg2rad($d - $b);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dLng / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($h)));
    }

    /**
     * Detecta y clasifica paradas. $cfg: umbral_min, radio_m, radio_cliente_m.
     * $d: respuesta de TMS paradas.php. Devuelve filas listas para el reporte/mapa.
     */
    private function _detectarParadas(array $d, int $empresaId, array $cfg): array
    {
        $umbral = $cfg['umbral_min'] * 60;
        $radio = $cfg['radio_m'];

        // Geocercas de clientes con coordenadas en el maestro.
        $clientes = Capsule::table('clientes')->where('empresa_id', $empresaId)
            ->whereNotNull('latitud')->whereNotNull('longitud')
            ->get(['id', 'razon_social', 'direccion', 'latitud', 'longitud']);
        $porNombre = Capsule::table('clientes')->where('empresa_id', $empresaId)
            ->get(['id', 'razon_social', 'direccion', 'latitud', 'longitud'])
            ->keyBy(fn($c) => mb_strtoupper(trim($c->razon_social)));

        $despIds = [];
        foreach ($d['tracks'] ?? [] as $t) foreach ($t['puntos'] as $p) if ($p['despacho_id']) $despIds[$p['despacho_id']] = true;
        $despachos = $despIds ? Capsule::table('despachos')->where('empresa_id', $empresaId)
            ->whereIn('id', array_keys($despIds))->get(['id', 'placa', 'numero_despacho'])->keyBy('id') : collect();

        $bod = $d['bodega'] ?? null;
        $out = [];
        foreach ($d['tracks'] ?? [] as $t) {
            $pts = $t['puntos'];
            $n = count($pts);
            // El auxiliar debe pertenecer a esta empresa (TMS compartido): sus
            // puntos traen despachos de la empresa.
            $i = 0;
            while ($i < $n) {
                $lat = $pts[$i]['lat']; $lng = $pts[$i]['lng']; $k = 1; $j = $i + 1; $gap = false;
                while ($j < $n && $this->_distM($lat, $lng, $pts[$j]['lat'], $pts[$j]['lng']) <= $radio) {
                    if (strtotime($pts[$j]['ts']) - strtotime($pts[$j - 1]['ts']) > 12 * 60) $gap = true;
                    // centroide incremental (evita que la parada "camine")
                    $lat = ($lat * $k + $pts[$j]['lat']) / ($k + 1);
                    $lng = ($lng * $k + $pts[$j]['lng']) / ($k + 1);
                    $k++; $j++;
                }
                $ini = $pts[$i]['ts']; $fin = $pts[$j - 1]['ts'];
                $dur = strtotime($fin) - strtotime($ini);
                $desp = $pts[$i]['despacho_id'] ? ($despachos[$pts[$i]['despacho_id']] ?? null) : null;
                if ($dur >= $umbral && ($desp || !$pts[$i]['despacho_id'])) {
                    $tipo = 'No programada'; $lugar = null; $clienteId = null; $direccion = null; $visita = null;
                    if ($bod && $this->_distM($lat, $lng, $bod['lat'], $bod['lng']) <= $bod['radio_m']) {
                        $tipo = 'Bodega'; $lugar = 'Bodega';
                    } else {
                        foreach ($d['visitas'] ?? [] as $v) {
                            if ($v['personal_id'] !== $t['personal_id']) continue;
                            // Sin "llegada" marcada (pasaba antes de la llegada
                            // automática) se usa la salida: el auxiliar estuvo en la
                            // sucursal justo antes de confirmar.
                            $vFin = $v['hora_salida'] ? strtotime($v['hora_salida']) : time();
                            $vIni = $v['hora_llegada'] ? strtotime($v['hora_llegada']) : $vFin - 3600;
                            if (!($vIni <= strtotime($fin) + 300 && $vFin >= strtotime($ini) - 300)) continue;
                            // BUG CORREGIDO 2026-10-02: además de la hora, la parada debe
                            // estar cerca de donde se registró la visita (llegada o, si no
                            // marcó llegada, donde confirmó). Solo por hora, la parada de
                            // CLAP BURGER LAURELES (10:02-10:30) se cruzó con la visita de
                            // CHICKEN SAN DIEGO (confirmada 11:32, a ~3 km) y "Fijar
                            // ubicación" le guardó a San Diego la coordenada de Laureles.
                            $vg = $v['geo_llegada'] ?? $v['geo_salida'] ?? null;
                            if ($vg && $this->_distM($lat, $lng, (float)$vg['lat'], (float)$vg['lng']) > max(300, $cfg['radio_cliente_m'])) continue;
                            $visita = $v; break;
                        }
                        if ($visita) {
                            $tipo = 'En cliente'; $lugar = $visita['sucursal'];
                            $c = $porNombre[mb_strtoupper(trim($visita['sucursal']))] ?? null;
                            if ($c) { $clienteId = $c->id; $direccion = $c->direccion ?: null; }
                        } else {
                            $mejor = null; $mejorD = PHP_FLOAT_MAX;
                            foreach ($clientes as $c) {
                                $dc = $this->_distM($lat, $lng, (float)$c->latitud, (float)$c->longitud);
                                if ($dc < $mejorD) { $mejorD = $dc; $mejor = $c; }
                            }
                            if ($mejor && $mejorD <= $cfg['radio_cliente_m']) {
                                $tipo = 'En cliente'; $lugar = $mejor->razon_social; $clienteId = $mejor->id; $direccion = $mejor->direccion ?: null;
                            }
                        }
                    }
                    $cli = $clienteId ? $porNombre->firstWhere('id', $clienteId) : null;
                    $out[] = [
                        'auxiliar'        => $t['auxiliar_nombre'],
                        'personal_id'     => $t['personal_id'],
                        'placa'           => $desp->placa ?? null,
                        'numero_despacho' => $desp->numero_despacho ?? null,
                        'inicio'          => $ini,
                        'fin'             => $fin,
                        'duracion_min'    => (int)round($dur / 60),
                        'tipo'            => $tipo,
                        'lugar'           => $lugar,
                        'direccion'       => $direccion,
                        'lat'             => round($lat, 6),
                        'lng'             => round($lng, 6),
                        'cliente_id'      => $clienteId,
                        'cliente_sin_ubicacion' => $cli && (!$cli->latitud || !$cli->longitud),
                        'visita_registrada_min' => $visita['demora_min'] ?? null,
                        'tramo_sin_senal' => $gap,
                        'en_curso'        => $j === $n,
                    ];
                }
                $i = $j;
            }
        }
        usort($out, fn($a, $b) => strcmp($b['inicio'], $a['inicio']));
        return $out;
    }

    private function _cfgParadas(array $p): array
    {
        return [
            'umbral_min'      => max(1, min(240, (int)($p['umbral_min'] ?? 15))),
            'radio_m'         => max(30, min(1000, (int)($p['radio_m'] ?? 150))),
            'radio_cliente_m' => max(30, min(2000, (int)($p['radio_cliente_m'] ?? 150))),
        ];
    }

    public function paradas(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $p = $request->getQueryParams();
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = preg_match($re, $p['fecha_desde'] ?? '') ? $p['fecha_desde'] : date('Y-m-d');
        $hasta = preg_match($re, $p['fecha_hasta'] ?? '') ? $p['fecha_hasta'] : $desde;
        $cfg = $this->_cfgParadas($p);

        $d = TmsClient::paradas($desde, $hasta);
        if ($d === null) return $this->json($response, ['error' => true, 'message' => 'El TMS no respondió.'], 502);
        $filas = $this->_detectarParadas($d, $empresaId, $cfg);

        $opVeh = array_values(array_unique(array_filter(array_column($filas, 'placa')))); sort($opVeh);
        $opAux = array_values(array_unique(array_filter(array_column($filas, 'auxiliar')))); sort($opAux);
        if (!empty($p['vehiculo'])) $filas = array_values(array_filter($filas, fn($f) => $f['placa'] === $p['vehiculo']));
        if (!empty($p['auxiliar'])) $filas = array_values(array_filter($filas, fn($f) => $f['auxiliar'] === $p['auxiliar']));
        if (!empty($p['tipo']))     $filas = array_values(array_filter($filas, fn($f) => $f['tipo'] === $p['tipo']));

        if (($p['export'] ?? '') === 'excel') {
            $headers = ['Inicio', 'Fin', 'Duración (min)', 'Tipo', 'Lugar', 'Dirección', 'Latitud', 'Longitud',
                        'Vehículo', 'Planilla', 'Auxiliar', 'Tiempo registrado en visita (min)', 'Observación'];
            $rows = array_map(fn($f) => [
                $f['inicio'], $f['fin'], $f['duracion_min'], $f['tipo'], $f['lugar'] ?? '', $f['direccion'] ?? '',
                $f['lat'], $f['lng'], $f['placa'] ?? '', $f['numero_despacho'] ?? '', $f['auxiliar'],
                $f['visita_registrada_min'] ?? '', trim(($f['en_curso'] ? 'En curso. ' : '') . ($f['tramo_sin_senal'] ? 'Incluye tramo sin señal GPS.' : '')),
            ], $filas);
            return $this->exportCsv($response, $headers, $rows, "tiempos_paro_{$desde}_{$hasta}");
        }

        $tot = array_sum(array_column($filas, 'duracion_min'));
        $porTipo = [];
        foreach ($filas as $f) { $porTipo[$f['tipo']] = ($porTipo[$f['tipo']] ?? 0) + $f['duracion_min']; }
        $enCliente = array_values(array_filter($filas, fn($f) => $f['tipo'] === 'En cliente'));

        return $this->json($response, ['error' => false, 'data' => [
            'paradas' => $filas,
            'kpis'    => [
                'total_paradas'       => count($filas),
                'minutos_detenido'    => $tot,
                'no_programadas'      => count(array_filter($filas, fn($f) => $f['tipo'] === 'No programada')),
                'minutos_por_tipo'    => $porTipo,
                'promedio_en_cliente' => $enCliente ? (int)round(array_sum(array_column($enCliente, 'duracion_min')) / count($enCliente)) : 0,
            ],
            'filtros' => ['vehiculos' => $opVeh, 'auxiliares' => $opAux],
            'cfg'     => $cfg,
        ]]);
    }

    // Guarda la ubicación detectada de una parada como coordenada del cliente
    // en el maestro (los clientes no tienen dirección/coordenadas cargadas
    // todavía) — así la siguiente parada allí cae en su geocerca.
    public function paradaUbicarCliente(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $b = (array)($request->getParsedBody() ?? []);
        $lat = (float)($b['lat'] ?? 0); $lng = (float)($b['lng'] ?? 0);
        if (!$lat || !$lng || abs($lat) > 90 || abs($lng) > 180) return $this->json($response, ['error' => true, 'message' => 'Coordenadas inválidas'], 400);
        $c = Capsule::table('clientes')->where('empresa_id', $empresaId)->where('id', (int)($b['cliente_id'] ?? 0))->first();
        if (!$c) return $this->json($response, ['error' => true, 'message' => 'Cliente no encontrado'], 404);
        Capsule::table('clientes')->where('id', $c->id)->update(['latitud' => $lat, 'longitud' => $lng, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit($user, 'tms', 'ubicar_cliente', 'clientes', (int)$c->id,
            ['latitud' => $c->latitud, 'longitud' => $c->longitud], ['latitud' => $lat, 'longitud' => $lng],
            "Ubicación de {$c->razon_social} tomada de una parada GPS del TMS");
        return $this->json($response, ['error' => false, 'message' => "Ubicación guardada para {$c->razon_social}"]);
    }

    /**
     * Entregas por PLANILLA consolidada (2026-10-02, a pedido de Camilo: "la
     * entrega se debe registrar por la planilla consolidada y no por pedido —
     * fueron 14 pedidos pero son 7 planillas"). entregas_ruta trae una fila
     * por pedido; aquí se agrupan por día + auxiliar + planilla.
     *
     * Tiempo en sitio REAL por GPS: los tiempos de la app no son confiables
     * (el auxiliar confirma en lote: dos sucursales distintas con 1 minuto de
     * diferencia). Se toma el tramo de posiciones GPS del vehículo dentro de
     * 150 m de la sucursal más cercano a la hora de confirmación: primera y
     * última posición = llegada y salida reales. Sin GPS suficiente se usa el
     * tiempo registrado en la app, marcado como tal.
     */
    private function _entregasPorPlanilla(array $filas, int $empresaId, string $desde, string $hasta): array
    {
        $grupos = [];
        foreach ($filas as $f) {
            $k = $f['fecha_entrega'] . '|' . ($f['auxiliar_nombre_ruta'] ?? '') . '|' . ($f['planilla_numero'] ?: $f['sucursal_entrega']);
            if (!isset($grupos[$k])) {
                $grupos[$k] = ['fecha' => $f['fecha_entrega'], 'auxiliar' => $f['auxiliar_nombre_ruta'], 'planilla' => $f['planilla_numero'],
                    'sucursal' => $f['sucursal_entrega'], 'pedidos' => 0, 'tiene_novedad' => false, 'total_novedades' => 0,
                    'llegada_app' => null, 'salida_app' => null, 'geo' => null];
            }
            $g = &$grupos[$k];
            $g['pedidos']++;
            $g['tiene_novedad'] = $g['tiene_novedad'] || (bool)$f['tiene_novedad'];
            $g['total_novedades'] += (int)$f['total_novedades'];
            if ($f['hora_llegada'] && (!$g['llegada_app'] || $f['hora_llegada'] < $g['llegada_app'])) $g['llegada_app'] = $f['hora_llegada'];
            if ($f['hora_salida'] && (!$g['salida_app'] || $f['hora_salida'] > $g['salida_app'])) $g['salida_app'] = $f['hora_salida'];
            if (!$g['geo'] && $f['tracking_geo']) $g['geo'] = json_decode($f['tracking_geo'], true);
            unset($g);
        }

        // Ubicación de cada sucursal: maestro de clientes; si no, GPS de llegada.
        $coords = Capsule::table('clientes')->where('empresa_id', $empresaId)->whereNotNull('latitud')
            ->get(['razon_social', 'latitud', 'longitud'])
            ->keyBy(fn($c) => mb_strtoupper(trim($c->razon_social)));

        $tms = TmsClient::paradas($desde, $hasta);
        $puntosPorAux = [];
        foreach ($tms['tracks'] ?? [] as $t) $puntosPorAux[mb_strtoupper(trim($t['auxiliar_nombre']))] = $t['puntos'];

        $radio = 150;
        $out = [];
        foreach ($grupos as $g) {
            $c = $coords[mb_strtoupper(trim($g['sucursal']))] ?? null;
            $site = $c ? ['lat' => (float)$c->latitud, 'lng' => (float)$c->longitud] : ($g['geo']['llegada'] ?? null);
            $gps = null;
            $pts = $puntosPorAux[mb_strtoupper(trim((string)$g['auxiliar']))] ?? [];
            $ref = $g['salida_app'] ? strtotime($g['salida_app']) : null;
            if ($site && $pts && $ref) {
                // Tramos consecutivos de posiciones dentro del radio.
                $tramos = []; $act = null; $tramosPts = []; $actPts = [];
                foreach ($pts as $p) {
                    if (substr($p['ts'], 0, 10) !== $g['fecha']) continue;
                    $dentro = $this->_distM($p['lat'], $p['lng'], $site['lat'], $site['lng']) <= $radio;
                    if ($dentro) { $act ??= []; $act[] = strtotime($p['ts']); $actPts[] = $p; }
                    elseif ($act) { $tramos[] = $act; $tramosPts[] = $actPts; $act = null; $actPts = []; }
                }
                if ($act) { $tramos[] = $act; $tramosPts[] = $actPts; }
                // El tramo más cercano a la confirmación (hasta 3 h antes / 1 h después).
                $mejor = null; $mejorD = PHP_INT_MAX;
                $mejorPts = [];
                foreach ($tramos as $ti => $tr) {
                    $d = ($ref < $tr[0]) ? $tr[0] - $ref : (($ref > end($tr)) ? $ref - end($tr) : 0);
                    if ($d < $mejorD && $d <= 3 * 3600) { $mejorD = $d; $mejor = $tr; $mejorPts = $tramosPts[$ti]; }
                }
                if ($mejor) {
                    $gps = ['lat' => array_sum(array_column($mejorPts, 'lat')) / count($mejorPts), 'lng' => array_sum(array_column($mejorPts, 'lng')) / count($mejorPts),
                            'llegada' => date('Y-m-d H:i:s', $mejor[0]), 'salida' => date('Y-m-d H:i:s', end($mejor)),
                            'puntos' => count($mejor), 'min' => count($mejor) > 1 ? (int)round((end($mejor) - $mejor[0]) / 60) : null];
                }
            }
            $appMin = ($g['llegada_app'] && $g['salida_app']) ? (int)round((strtotime($g['salida_app']) - strtotime($g['llegada_app'])) / 60) : null;
            $out[] = [
                'fecha' => $g['fecha'], 'auxiliar' => $g['auxiliar'], 'planilla' => $g['planilla'], 'sucursal' => $g['sucursal'],
                'pedidos' => $g['pedidos'], 'tiene_novedad' => $g['tiene_novedad'], 'total_novedades' => $g['total_novedades'],
                'llegada_app' => $g['llegada_app'], 'salida_app' => $g['salida_app'], 'tiempo_app_min' => $appMin,
                'llegada_gps' => $gps['llegada'] ?? null, 'salida_gps' => $gps['salida'] ?? null, 'puntos_gps' => $gps['puntos'] ?? 0,
                'tiempo_gps_min' => $gps['min'] ?? null,
                // Punto para asignar la ubicación de la sucursal: tramo GPS en el
                // sitio > llegada registrada > punto donde confirmó (menos fiable).
                'coord' => isset($gps['lat']) ? ['lat' => round($gps['lat'], 6), 'lng' => round($gps['lng'], 6), 'fuente' => 'GPS en el sitio']
                    : (isset($g['geo']['llegada']) ? ['lat' => (float)$g['geo']['llegada']['lat'], 'lng' => (float)$g['geo']['llegada']['lng'], 'fuente' => 'Llegada registrada']
                    : (isset($g['geo']['salida']) ? ['lat' => (float)$g['geo']['salida']['lat'], 'lng' => (float)$g['geo']['salida']['lng'], 'fuente' => 'Donde confirmó (verificar)'] : null)),
                'tiene_ubicacion' => (bool)$c,
                // Tiempo que se usa en indicadores: GPS si hay; si no, el de la app.
                'tiempo_min'    => $gps['min'] ?? $appMin,
                'fuente_tiempo' => isset($gps['min']) ? 'GPS' : ($appMin !== null ? 'App' : null),
            ];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['salida_app'], (string)$a['salida_app']));
        return $out;
    }

    /* ═══════════════════════════════════════════════════════════════════
       MANTENIMIENTO DEL TRACKING GPS (2026-10-03, a pedido de Camilo:
       "borrar información del tracking en cierto período para no llenar la
       base de datos"). Los datos viven en el TMS; aquí se consultan y se
       ordena la depuración. Configurar/depurar: solo Admin (borra datos) y
       queda en auditoría.
    ═══════════════════════════════════════════════════════════════════ */
    public function trackingMantenimiento(Request $request, Response $response): Response
    {
        $d = TmsClient::mantenimientoTracking();
        if (!$d) return $this->json($response, ['error' => true, 'message' => 'El TMS no respondió.'], 502);
        return $this->json($response, ['error' => false, 'data' => $d['data'] ?? []]);
    }

    public function trackingMantenimientoAccion(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $response)) return $deny;
        $b = (array)($request->getParsedBody() ?? []);
        $accion = $b['accion'] ?? '';
        if (!in_array($accion, ['configurar', 'depurar'], true)) {
            return $this->json($response, ['error' => true, 'message' => 'Acción inválida'], 400);
        }
        $payload = ['accion' => $accion];
        if ($accion === 'configurar') $payload['retencion_dias'] = (int)($b['retencion_dias'] ?? -1);
        if ($accion === 'depurar') { $payload['hasta'] = (string)($b['hasta'] ?? ''); $payload['usuario'] = $user->nombre ?? null; }

        $r = TmsClient::mantenimientoTracking($payload);
        if (!$r) return $this->json($response, ['error' => true, 'message' => 'El TMS no respondió.'], 502);
        if (!empty($r['error'])) return $this->json($response, ['error' => true, 'message' => $r['message'] ?? 'Error'], 422);

        $this->audit($user, 'tms', $accion === 'depurar' ? 'depurar_tracking' : 'configurar_retencion_tracking', null, null, null, $payload,
            $r['message'] ?? $accion);
        return $this->json($response, ['error' => false, 'message' => $r['message'] ?? 'Listo', 'data' => $r['data'] ?? null]);
    }
}
