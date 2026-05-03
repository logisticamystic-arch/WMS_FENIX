<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * ForecastController — Motor de predicción de demanda.
 *
 * Gestiona las predicciones generadas por el microservicio Python (Holt-Winters/Prophet).
 * El microservicio escribe directamente en la tabla forecast_demanda;
 * este controlador expone los resultados al frontend y recibe nuevas predicciones vía API.
 *
 * Endpoints:
 *  GET  /api/forecast                       → Dashboard de predicciones vigentes
 *  GET  /api/forecast/alertas               → Productos con alerta de quiebre de stock
 *  GET  /api/forecast/{producto_id}         → Predicciones de un producto específico
 *  POST /api/forecast/ingest                → Recibe predicciones del microservicio ML
 *  POST /api/forecast/calcular-interno      → Motor Holt-Winters nativo PHP (sin Python)
 *  GET  /api/forecast/precision             → Métricas MAPE/RMSE por modelo
 *  GET  /api/forecast/export                → Exportar CSV de alertas
 */
class ForecastController extends BaseController
{
    // ── GET /api/forecast ─────────────────────────────────────────────────────
    // Dashboard: predicciones vigentes con score de riesgo
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 50), 200);

        // Usar vista materializada si está disponible
        try {
            $usaMv = Capsule::table('mv_rotacion_productos')
                ->where('empresa_id', $user->empresa_id)
                ->exists();
        } catch (\Exception $e) {
            $usaMv = false;
        }

        if ($usaMv) {
            $q = Capsule::table('mv_rotacion_productos')
                ->where('empresa_id',  $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereNotNull('forecast_30d');

            if (!empty($params['solo_alertas'])) {
                $q->where('alerta_quiebre', true);
            }
            if (!empty($params['clase_abc'])) {
                $q->where('clase_abc', strtoupper($params['clase_abc']));
            }

            $total = (clone $q)->count();
            $items = $q->orderBy('score_riesgo', 'desc')
                       ->limit($limit)
                       ->offset((int)($params['offset'] ?? 0))
                       ->get([
                           'producto_id', 'producto_nombre', 'codigo',
                           'clase_abc', 'clase_xyz', 'segmento',
                           'stock_actual', 'forecast_30d', 'alerta_quiebre',
                           'dias_hasta_quiebre', 'stock_seguridad_sug',
                           'punto_reorden_sug', 'score_riesgo', 'dias_cobertura',
                       ]);

            return $this->ok($res, [
                'predicciones' => $items,
                'total'        => $total,
                'fuente'       => 'mv_rotacion_productos',
            ]);
        }

        // Fallback: consulta directa a forecast_demanda
        $q = Capsule::table('forecast_demanda as f')
            ->join('productos as p', 'f.producto_id', '=', 'p.id')
            ->where('f.empresa_id',  $user->empresa_id)
            ->where('f.sucursal_id', $user->sucursal_id)
            ->where('f.es_vigente',  true)
            ->where('f.horizonte_dias', (int)($params['horizonte'] ?? 30));

        if (!empty($params['solo_alertas'])) {
            $q->where('f.alerta_quiebre', true);
        }

        $total = (clone $q)->count();
        $items = $q->select(
                    'f.*',
                    'p.nombre as producto_nombre',
                    'p.codigo_interno as codigo'
                )
                ->orderByRaw('f.alerta_quiebre DESC, f.dias_hasta_quiebre ASC NULLS LAST')
                ->limit($limit)
                ->offset((int)($params['offset'] ?? 0))
                ->get();

        return $this->ok($res, [
            'predicciones' => $items,
            'total'        => $total,
            'fuente'       => 'forecast_demanda',
        ]);
    }

    // ── GET /api/forecast/alertas ─────────────────────────────────────────────
    // Solo productos con alerta de quiebre inminente, ordenados por urgencia
    public function alertas(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $diasUmbral = (int)($params['dias'] ?? 14);

        $alertas = Capsule::table('forecast_demanda as f')
            ->join('productos as p', 'f.producto_id', '=', 'p.id')
            ->leftJoin('clasificaciones_abc_xyz as c',
                fn($j) => $j->on('c.producto_id', '=', 'f.producto_id')
                             ->where('c.empresa_id',  '=', 'f.empresa_id') // nota: raw comparison
                             ->where('c.vigente', true))
            ->where('f.empresa_id',  $user->empresa_id)
            ->where('f.sucursal_id', $user->sucursal_id)
            ->where('f.es_vigente',  true)
            ->where('f.alerta_quiebre', true)
            ->where(fn($q) =>
                $q->whereNull('f.dias_hasta_quiebre')
                  ->orWhere('f.dias_hasta_quiebre', '<=', $diasUmbral)
            )
            ->select(
                'f.producto_id', 'p.nombre as producto', 'p.codigo_interno as codigo',
                'f.horizonte_dias', 'f.demanda_pred', 'f.dias_hasta_quiebre',
                'f.stock_seguridad_sug', 'f.punto_reorden_sug',
                'f.banda_inf_80', 'f.banda_sup_80',
                'f.modelo_usado', 'f.score_confianza',
                Capsule::raw("(c.clase_abc || c.clase_xyz) AS segmento"),
                'c.total_valor'
            )
            ->orderByRaw('f.dias_hasta_quiebre ASC NULLS LAST')
            ->orderByRaw("c.clase_abc ASC NULLS LAST")
            ->get();

        // Resumen por nivel de urgencia
        $criticos  = $alertas->filter(fn($a) => ($a->dias_hasta_quiebre ?? 999) <= 3)->count();
        $urgentes  = $alertas->filter(fn($a) => ($a->dias_hasta_quiebre ?? 999) <= 7 && ($a->dias_hasta_quiebre ?? 999) > 3)->count();
        $proximos  = $alertas->filter(fn($a) => ($a->dias_hasta_quiebre ?? 999) > 7)->count();

        return $this->ok($res, [
            'alertas'  => $alertas,
            'resumen'  => [
                'criticos'       => $criticos,   // quiebre en ≤ 3 días
                'urgentes'       => $urgentes,   // quiebre en 4-7 días
                'proximos'       => $proximos,   // quiebre en > 7 días
                'total'          => $alertas->count(),
            ],
            'umbral_dias' => $diasUmbral,
        ]);
    }

    // ── GET /api/forecast/{producto_id} ───────────────────────────────────────
    // Historial de predicciones de un producto: todos los horizontes
    public function producto(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');

        $producto = Capsule::table('productos')
            ->where('id', $a['producto_id'])
            ->where('empresa_id', $user->empresa_id)
            ->first(['id', 'nombre', 'codigo_interno']);

        if (!$producto) return $this->notFound($res);

        // Predicciones vigentes por horizonte
        $predicciones = Capsule::table('forecast_demanda')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('producto_id', $a['producto_id'])
            ->where('es_vigente',  true)
            ->orderBy('horizonte_dias', 'asc')
            ->get();

        // Historial de ventas reales de ventas_agregadas_ml (últimos 12 meses)
        $historial = Capsule::table('ventas_agregadas_ml')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('producto_id', $a['producto_id'])
            ->where('periodo',     '>=', date('Y-m-d', strtotime('-12 months')))
            ->orderBy('periodo', 'asc')
            ->get(['periodo', 'unidades_vendidas', 'valor_vendido', 'stock_medio']);

        // Clasificación ABC-XYZ actual
        $clasificacion = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('producto_id', $a['producto_id'])
            ->where('vigente', true)
            ->first();

        // Stock actual
        $stockActual = Capsule::table('inventarios')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('producto_id', $a['producto_id'])
            ->where('estado', 'Disponible')
            ->sum('cantidad');

        return $this->ok($res, [
            'producto'      => $producto,
            'stock_actual'  => $stockActual,
            'clasificacion' => $clasificacion,
            'predicciones'  => $predicciones,
            'historial'     => $historial,
        ]);
    }

    // ── POST /api/forecast/ingest ─────────────────────────────────────────────
    // Recibe predicciones del microservicio Python ML
    // Esperado: { "producto_id": X, "horizonte_dias": 30, "demanda_pred": 150.5, ... }
    public function ingest(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        // Puede recibir un array de predicciones o una sola
        $items = isset($body[0]) ? $body : [$body];

        $insertados = 0;
        $ahora      = date('Y-m-d H:i:s');

        foreach ($items as $item) {
            $reqs = ['producto_id', 'horizonte_dias', 'demanda_pred', 'fecha_prediccion'];
            foreach ($reqs as $campo) {
                if (!isset($item[$campo])) continue 2;
            }

            // Marcar predicciones anteriores del mismo producto/horizonte como no vigentes
            Capsule::table('forecast_demanda')
                ->where('empresa_id',    $user->empresa_id)
                ->where('sucursal_id',   $user->sucursal_id)
                ->where('producto_id',   (int)$item['producto_id'])
                ->where('horizonte_dias',(int)$item['horizonte_dias'])
                ->where('es_vigente',    true)
                ->update(['es_vigente' => false]);

            // Calcular alerta de quiebre
            $stockActual = Capsule::table('inventarios')
                ->where('empresa_id',  $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', (int)$item['producto_id'])
                ->where('estado', 'Disponible')
                ->sum('cantidad');

            $demandaDia    = (float)$item['demanda_pred'] / 30;
            $diasCobertura = $demandaDia > 0 ? round($stockActual / $demandaDia) : null;
            $alertaQuiebre = $diasCobertura !== null && $diasCobertura < (int)$item['horizonte_dias'];

            // Stock de seguridad = 1.65 * std (nivel de servicio 95%)
            $std = (float)($item['demanda_std'] ?? 0);
            $leadTimeDias = 7; // días de lead time estándar
            $stockSegSug  = $std > 0 ? round(1.65 * $std * sqrt($leadTimeDias), 1) : null;
            $puntoReorden = $demandaDia > 0 ? round($demandaDia * $leadTimeDias + ($stockSegSug ?? 0), 1) : null;

            Capsule::table('forecast_demanda')->insert([
                'empresa_id'         => $user->empresa_id,
                'sucursal_id'        => $user->sucursal_id,
                'producto_id'        => (int)$item['producto_id'],
                'fecha_prediccion'   => $item['fecha_prediccion'],
                'horizonte_dias'     => (int)$item['horizonte_dias'],
                'demanda_pred'       => (float)$item['demanda_pred'],
                'banda_inf_80'       => $item['banda_inf_80'] ?? null,
                'banda_sup_80'       => $item['banda_sup_80'] ?? null,
                'banda_inf_95'       => $item['banda_inf_95'] ?? null,
                'banda_sup_95'       => $item['banda_sup_95'] ?? null,
                'modelo_usado'       => $item['modelo_usado'] ?? 'externo',
                'mape'               => $item['mape']         ?? null,
                'rmse'               => $item['rmse']         ?? null,
                'score_confianza'    => $item['score_confianza'] ?? null,
                'alerta_quiebre'     => $alertaQuiebre,
                'dias_hasta_quiebre' => $alertaQuiebre ? $diasCobertura : null,
                'stock_seguridad_sug'=> $stockSegSug,
                'punto_reorden_sug'  => $puntoReorden,
                'generado_at'        => $ahora,
                'es_vigente'         => true,
            ]);

            $insertados++;
        }

        // Refrescar vista materializada si corresponde
        if ($insertados > 0 && $this->isPg()) {
            try {
                Capsule::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rotacion_productos');
            } catch (\Exception $e) { /* silencioso */ }
        }

        $this->audit($user, 'forecast', 'ingest', 'forecast_demanda', null,
            null, ['insertados' => $insertados],
            "Forecast ingest: {$insertados} predicciones recibidas");

        return $this->ok($res, ['insertados' => $insertados], 'Predicciones procesadas');
    }

    // ── POST /api/forecast/calcular-interno ───────────────────────────────────
    // Motor Holt-Winters implementado en PHP puro (sin dependencia de Python)
    // Para uso cuando no hay microservicio ML disponible
    public function calcularInterno(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $body     = (array)($r->getParsedBody() ?? []);
        $horizonte = (int)($body['horizonte_dias'] ?? 30);
        $soloClaseA = (bool)($body['solo_clase_a'] ?? true);

        // Obtener productos a procesar (por defecto solo clase A)
        $q = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('vigente', true);

        if ($soloClaseA) {
            $q->where('clase_abc', 'A');
        }

        $productos = $q->pluck('producto_id')->toArray();

        if (empty($productos)) {
            return $this->error($res, 'No hay clasificación ABC-XYZ vigente. Ejecute primero el motor ABC-XYZ.');
        }

        $procesados = 0;
        $ahora      = date('Y-m-d H:i:s');
        $fechaPred  = date('Y-m-d', strtotime("+{$horizonte} days"));

        foreach ($productos as $productoId) {
            // Obtener serie temporal (últimos 12 meses de ventas mensuales)
            $serie = Capsule::table('ventas_agregadas_ml')
                ->where('empresa_id',  $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $productoId)
                ->where('periodo',     '>=', date('Y-m-01', strtotime('-12 months')))
                ->orderBy('periodo', 'asc')
                ->pluck('unidades_vendidas')
                ->map(fn($v) => (float)$v)
                ->toArray();

            if (count($serie) < 3) continue; // necesita mínimo 3 puntos

            // Holt-Winters Aditivo Simplificado (sin estacionalidad si < 12 puntos)
            [$pred, $std] = $this->_holtWinters($serie, $horizonte);

            // Marcar predicciones anteriores como no vigentes
            Capsule::table('forecast_demanda')
                ->where('empresa_id',    $user->empresa_id)
                ->where('sucursal_id',   $user->sucursal_id)
                ->where('producto_id',   $productoId)
                ->where('horizonte_dias', $horizonte)
                ->where('es_vigente',     true)
                ->update(['es_vigente' => false]);

            // Stock actual
            $stock = Capsule::table('inventarios')
                ->where('empresa_id',  $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $productoId)
                ->where('estado', 'Disponible')
                ->sum('cantidad');

            $demandaDia   = $pred / 30;
            $diasCob      = $demandaDia > 0 ? round($stock / $demandaDia) : null;
            $alerta       = $diasCob !== null && $diasCob < $horizonte;
            $stockSeg     = $std > 0 ? round(1.65 * $std * sqrt(7), 1) : null;
            $puntoReorden = $demandaDia > 0 ? round($demandaDia * 7 + ($stockSeg ?? 0), 1) : null;

            Capsule::table('forecast_demanda')->insert([
                'empresa_id'          => $user->empresa_id,
                'sucursal_id'         => $user->sucursal_id,
                'producto_id'         => $productoId,
                'fecha_prediccion'    => $fechaPred,
                'horizonte_dias'      => $horizonte,
                'demanda_pred'        => round($pred, 2),
                'banda_inf_80'        => round(max(0, $pred - 1.28 * $std), 2),
                'banda_sup_80'        => round($pred + 1.28 * $std, 2),
                'banda_inf_95'        => round(max(0, $pred - 1.96 * $std), 2),
                'banda_sup_95'        => round($pred + 1.96 * $std, 2),
                'modelo_usado'        => 'holt_winters_php',
                'alerta_quiebre'      => $alerta,
                'dias_hasta_quiebre'  => $alerta ? $diasCob : null,
                'stock_seguridad_sug' => $stockSeg,
                'punto_reorden_sug'   => $puntoReorden,
                'generado_at'         => $ahora,
                'es_vigente'          => true,
            ]);

            $procesados++;
        }

        // Refrescar vista materializada
        if ($procesados > 0 && $this->isPg()) {
            try {
                Capsule::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rotacion_productos');
            } catch (\Exception $e) { /* silencioso */ }
        }

        $this->audit($user, 'forecast', 'calcular_interno', 'forecast_demanda', null,
            null, ['procesados' => $procesados, 'horizonte' => $horizonte],
            "Holt-Winters PHP: {$procesados} predicciones calculadas");

        return $this->ok($res, [
            'productos_procesados' => $procesados,
            'horizonte_dias'       => $horizonte,
            'modelo'               => 'holt_winters_php',
        ], 'Predicciones calculadas');
    }

    // ── GET /api/forecast/precision ───────────────────────────────────────────
    // Métricas de calidad de los modelos de predicción
    public function precision(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $metricas = Capsule::table('forecast_demanda')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('es_vigente',  true)
            ->whereNotNull('mape')
            ->selectRaw("
                modelo_usado,
                horizonte_dias,
                COUNT(*) AS num_predicciones,
                ROUND(AVG(mape)::numeric, 3)           AS mape_promedio,
                ROUND(AVG(rmse)::numeric, 3)           AS rmse_promedio,
                ROUND(AVG(score_confianza)::numeric, 3) AS confianza_promedio
            ")
            ->groupBy('modelo_usado', 'horizonte_dias')
            ->orderBy('mape_promedio', 'asc')
            ->get();

        return $this->ok($res, ['metricas' => $metricas]);
    }

    // ── GET /api/forecast/export ──────────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $items = Capsule::table('forecast_demanda as f')
            ->join('productos as p', 'f.producto_id', '=', 'p.id')
            ->where('f.empresa_id',  $user->empresa_id)
            ->where('f.sucursal_id', $user->sucursal_id)
            ->where('f.es_vigente',  true)
            ->orderByRaw('f.alerta_quiebre DESC, f.dias_hasta_quiebre ASC NULLS LAST')
            ->select(
                'p.codigo_interno as codigo', 'p.nombre as producto',
                'f.horizonte_dias', 'f.fecha_prediccion', 'f.demanda_pred',
                'f.banda_inf_80', 'f.banda_sup_80', 'f.modelo_usado',
                'f.alerta_quiebre', 'f.dias_hasta_quiebre',
                'f.stock_seguridad_sug', 'f.punto_reorden_sug', 'f.score_confianza'
            )
            ->get();

        $headers = [
            'Código', 'Producto', 'Horizonte (días)', 'Fecha Predicción',
            'Demanda Pred.', 'Banda Inf 80%', 'Banda Sup 80%', 'Modelo',
            'Alerta Quiebre', 'Días hasta quiebre', 'Stock Seguridad Sug.', 'Punto Reorden Sug.', 'Confianza',
        ];

        $rows = $items->map(fn($i) => [
            $i->codigo, $i->producto,
            $i->horizonte_dias, $i->fecha_prediccion,
            number_format($i->demanda_pred, 2),
            $i->banda_inf_80 !== null ? number_format($i->banda_inf_80, 2) : '—',
            $i->banda_sup_80 !== null ? number_format($i->banda_sup_80, 2) : '—',
            $i->modelo_usado,
            $i->alerta_quiebre ? 'SÍ' : 'No',
            $i->dias_hasta_quiebre ?? '—',
            $i->stock_seguridad_sug !== null ? number_format($i->stock_seguridad_sug, 1) : '—',
            $i->punto_reorden_sug   !== null ? number_format($i->punto_reorden_sug, 1)   : '—',
            $i->score_confianza !== null ? number_format($i->score_confianza, 3) : '—',
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'forecast_' . date('Y-m-d'));
    }

    // ── Motor Holt-Winters simplificado (Double Exponential Smoothing) ────────
    // Retorna [prediccion_mensual, desviacion_estandar]
    private function _holtWinters(array $serie, int $horizonteDias): array
    {
        $n = count($serie);
        if ($n < 2) return [$serie[0] ?? 0, 0];

        // Parámetros de suavizado (alpha=nivel, beta=tendencia)
        $alpha = 0.3;
        $beta  = 0.1;

        // Inicialización
        $nivel     = $serie[0];
        $tendencia = ($serie[1] - $serie[0]);
        $errores   = [];

        // Ajuste del modelo
        for ($i = 1; $i < $n; $i++) {
            $nivelPrev     = $nivel;
            $nivel         = $alpha * $serie[$i] + (1 - $alpha) * ($nivelPrev + $tendencia);
            $tendencia     = $beta  * ($nivel - $nivelPrev) + (1 - $beta) * $tendencia;
            $prediccionStep= $nivelPrev + $tendencia;
            $errores[]     = $serie[$i] - $prediccionStep;
        }

        // Proyectar h pasos (h = horizonte en meses)
        $h      = max(1, round($horizonteDias / 30));
        $pred   = $nivel + $h * $tendencia;
        $pred   = max(0, $pred); // no puede ser negativo

        // Desviación estándar de los errores de ajuste
        $std = count($errores) > 1
            ? sqrt(array_sum(array_map(fn($e) => $e * $e, $errores)) / count($errores))
            : ($pred * 0.2); // fallback: 20% de la predicción

        return [$pred, $std];
    }

}
