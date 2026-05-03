<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * RotacionController — Motor de análisis de rotación de productos (ABC-XYZ).
 *
 * Endpoints:
 *  GET  /api/rotacion                    → Dashboard principal (mv_rotacion_productos)
 *  GET  /api/rotacion/abc-xyz            → Clasificación ABC-XYZ vigente
 *  POST /api/rotacion/abc-xyz/ejecutar   → Ejecuta el motor de clasificación
 *  POST /api/rotacion/poblar-ventas      → Agrega ventas históricas a ventas_agregadas_ml
 *  POST /api/rotacion/refresh-mv         → Refresca vista materializada
 *  GET  /api/rotacion/riesgo             → Productos con mayor score de riesgo
 *  GET  /api/rotacion/cobertura-baja     → Productos con días de cobertura < umbral
 *  GET  /api/rotacion/ejecuciones        → Log de ejecuciones del motor ML
 *  GET  /api/rotacion/export             → Exportar CSV clasificación ABC-XYZ
 */
class RotacionController extends BaseController
{
    // ── GET /api/rotacion ─────────────────────────────────────────────────────
    // Dashboard principal: datos de la vista materializada
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        // Verificar si la vista materializada existe y tiene datos
        $mvExists = $this->mvExists();

        if (!$mvExists) {
            return $this->ok($res, [
                'resumen'   => null,
                'productos' => [],
                'mv_disponible' => false,
                'mensaje'   => 'Vista materializada no disponible. Ejecute POST /api/rotacion/abc-xyz/ejecutar primero.',
            ]);
        }

        $q = Capsule::table('mv_rotacion_productos')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id);

        // Filtros
        if (!empty($params['segmento'])) {
            $q->where('segmento', strtoupper($params['segmento']));
        }
        if (!empty($params['clase_abc'])) {
            $q->where('clase_abc', strtoupper($params['clase_abc']));
        }
        if (!empty($params['clase_xyz'])) {
            $q->where('clase_xyz', strtoupper($params['clase_xyz']));
        }
        if (!empty($params['alerta'])) {
            $q->where('alerta_quiebre', true);
        }
        if (!empty($params['zona'])) {
            $q->where('zona_recomendada', $params['zona']);
        }
        if (!empty($params['q'])) {
            $search = '%' . $params['q'] . '%';
            $q->where(function ($sub) use ($search) {
                $sub->where('producto_nombre', 'ilike', $search)
                    ->orWhere('codigo', 'ilike', $search);
            });
        }

        $limit  = min((int)($params['limit'] ?? 50), 200);
        $offset = (int)($params['offset'] ?? 0);

        $total    = (clone $q)->count();
        $productos = $q->orderBy('score_riesgo', 'desc')
                       ->orderBy('total_valor', 'desc')
                       ->limit($limit)
                       ->offset($offset)
                       ->get();

        // Resumen general
        $resumen = Capsule::table('mv_rotacion_productos')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->selectRaw("
                COUNT(*) AS total_productos,
                COUNT(*) FILTER (WHERE clase_abc = 'A') AS clase_a,
                COUNT(*) FILTER (WHERE clase_abc = 'B') AS clase_b,
                COUNT(*) FILTER (WHERE clase_abc = 'C') AS clase_c,
                COUNT(*) FILTER (WHERE alerta_quiebre = TRUE) AS con_alerta_quiebre,
                COUNT(*) FILTER (WHERE score_riesgo >= 70) AS riesgo_alto,
                COUNT(*) FILTER (WHERE dias_cobertura IS NOT NULL AND dias_cobertura < 7) AS cobertura_critica,
                ROUND(SUM(total_valor)::numeric, 2) AS valor_total_ventas,
                MAX(calculado_at) AS ultima_actualizacion
            ")
            ->first();

        return $this->ok($res, [
            'resumen'        => $resumen,
            'productos'      => $productos,
            'total'          => $total,
            'limit'          => $limit,
            'offset'         => $offset,
            'mv_disponible'  => true,
        ]);
    }

    // ── GET /api/rotacion/abc-xyz ─────────────────────────────────────────────
    // Clasificación ABC-XYZ vigente paginada (directo de tabla, sin mv)
    public function abcXyz(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $q = Capsule::table('clasificaciones_abc_xyz as c')
            ->join('productos as p', 'c.producto_id', '=', 'p.id')
            ->where('c.empresa_id',  $user->empresa_id)
            ->where('c.sucursal_id', $user->sucursal_id)
            ->where('c.vigente', true)
            ->select(
                'c.*',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo'
            );

        if (!empty($params['segmento'])) {
            $q->whereRaw('(c.clase_abc || c.clase_xyz) = ?', [strtoupper($params['segmento'])]);
        }
        if (!empty($params['clase_abc'])) {
            $q->where('c.clase_abc', strtoupper($params['clase_abc']));
        }

        $limit  = min((int)($params['limit'] ?? 100), 500);
        $offset = (int)($params['offset'] ?? 0);
        $total  = (clone $q)->count();

        $items = $q->orderBy('c.total_valor', 'desc')
                   ->limit($limit)
                   ->offset($offset)
                   ->get();

        // Distribución de segmentos
        $distribucion = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('vigente', true)
            ->selectRaw("
                (clase_abc || clase_xyz) AS segmento,
                COUNT(*) AS cantidad,
                ROUND(SUM(total_valor)::numeric, 2) AS valor_total
            ")
            ->groupByRaw("clase_abc || clase_xyz")
            ->orderByRaw("clase_abc || clase_xyz")
            ->get();

        return $this->ok($res, [
            'clasificacion' => $items,
            'distribucion'  => $distribucion,
            'total'         => $total,
            'limit'         => $limit,
            'offset'        => $offset,
        ]);
    }

    // ── POST /api/rotacion/abc-xyz/ejecutar ───────────────────────────────────
    // Dispara el motor de clasificación ABC-XYZ via función PostgreSQL
    public function ejecutarAbcXyz(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $body  = (array)($r->getParsedBody() ?? []);
        $meses = (int)($body['meses'] ?? 12);

        if ($meses < 1 || $meses > 36) {
            return $this->error($res, 'El parámetro meses debe estar entre 1 y 36');
        }

        if (!$this->isPg()) {
            return $this->error($res, 'Esta función requiere PostgreSQL 16', 503);
        }

        $result = Capsule::selectOne(
            'SELECT ejecutar_abc_xyz(?, ?, ?) AS procesados',
            [$user->empresa_id, $user->sucursal_id, $meses]
        );

        $procesados = $result ? $result->procesados : 0;

        // Refrescar vista materializada inmediatamente
        try {
            Capsule::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rotacion_productos');
        } catch (\Exception $e) {
            // Si la MV aún no tiene índice único (primera ejecución), refresh sin CONCURRENTLY
            Capsule::statement('REFRESH MATERIALIZED VIEW mv_rotacion_productos');
        }

        $this->audit($user, 'rotacion', 'abc_xyz', 'clasificaciones_abc_xyz', null,
            null, ['procesados' => $procesados, 'meses' => $meses],
            "ABC-XYZ ejecutado: {$procesados} productos clasificados");

        return $this->ok($res, [
            'productos_clasificados' => $procesados,
            'meses_analizados'       => $meses,
            'mv_refrescada'          => true,
        ], 'Clasificación ABC-XYZ completada');
    }

    // ── POST /api/rotacion/poblar-ventas ─────────────────────────────────────
    // Agrega ventas históricas desde ordenes/orden_detalles a ventas_agregadas_ml
    public function poblarVentas(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $body  = (array)($r->getParsedBody() ?? []);
        $desde = $body['desde'] ?? date('Y-m-d', strtotime('-24 months'));
        $hasta = $body['hasta'] ?? date('Y-m-d');

        if (!$this->isPg()) {
            return $this->error($res, 'Esta función requiere PostgreSQL 16', 503);
        }

        $result = Capsule::selectOne(
            'SELECT poblar_ventas_ml(?, ?, ?, ?) AS insertados',
            [$user->empresa_id, $user->sucursal_id, $desde, $hasta]
        );

        $insertados = $result ? $result->insertados : 0;

        $this->audit($user, 'rotacion', 'poblar_ventas', 'ventas_agregadas_ml', null,
            null, ['insertados' => $insertados, 'desde' => $desde, 'hasta' => $hasta],
            "Ventas ML pobladas: {$insertados} registros mensuales");

        return $this->ok($res, [
            'registros_procesados' => $insertados,
            'periodo_desde'        => $desde,
            'periodo_hasta'        => $hasta,
        ], 'Ventas históricas pobladas correctamente');
    }

    // ── POST /api/rotacion/refresh-mv ────────────────────────────────────────
    // Refresca manualmente la vista materializada
    public function refreshMv(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        if (!$this->isPg()) {
            return $this->error($res, 'Vista materializada solo disponible en PostgreSQL 16', 503);
        }

        $start = microtime(true);
        try {
            Capsule::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rotacion_productos');
        } catch (\Exception $e) {
            Capsule::statement('REFRESH MATERIALIZED VIEW mv_rotacion_productos');
        }
        $ms = round((microtime(true) - $start) * 1000);

        return $this->ok($res, ['duracion_ms' => $ms], 'Vista materializada actualizada');
    }

    // ── GET /api/rotacion/riesgo ──────────────────────────────────────────────
    // Top productos por score de riesgo (para panel de alertas prioritarias)
    public function riesgo(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 20), 100);

        if (!$this->mvExists()) {
            return $this->ok($res, ['productos' => [], 'mv_disponible' => false]);
        }

        $productos = Capsule::table('mv_rotacion_productos')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('score_riesgo', '>=', (int)($params['min_score'] ?? 40))
            ->orderBy('score_riesgo', 'desc')
            ->orderBy('total_valor', 'desc')
            ->limit($limit)
            ->get();

        return $this->ok($res, ['productos' => $productos, 'mv_disponible' => true]);
    }

    // ── GET /api/rotacion/cobertura-baja ─────────────────────────────────────
    // Productos con días de cobertura por debajo del umbral
    public function coberturasBajas(Request $r, Response $res): Response
    {
        $user     = $r->getAttribute('user');
        $params   = $r->getQueryParams();
        $umbral   = (int)($params['dias'] ?? 7);

        if (!$this->mvExists()) {
            return $this->ok($res, ['productos' => [], 'mv_disponible' => false]);
        }

        $productos = Capsule::table('mv_rotacion_productos')
            ->where('empresa_id',   $user->empresa_id)
            ->where('sucursal_id',  $user->sucursal_id)
            ->whereNotNull('dias_cobertura')
            ->where('dias_cobertura', '<', $umbral)
            ->orderBy('dias_cobertura', 'asc')
            ->orderBy('clase_abc', 'asc')
            ->get();

        return $this->ok($res, [
            'productos'      => $productos,
            'umbral_dias'    => $umbral,
            'mv_disponible'  => true,
        ]);
    }

    // ── GET /api/rotacion/ejecuciones ────────────────────────────────────────
    // Log de ejecuciones del motor ML
    public function ejecuciones(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 20), 100);

        $ejecuciones = Capsule::table('ejecuciones_ml')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->when(!empty($params['tipo']), fn($q) => $q->where('tipo', $params['tipo']))
            ->orderBy('inicio_at', 'desc')
            ->limit($limit)
            ->get();

        return $this->ok($res, ['ejecuciones' => $ejecuciones]);
    }

    // ── GET /api/rotacion/export ──────────────────────────────────────────────
    // Exportar clasificación ABC-XYZ a CSV
    public function export(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $items = Capsule::table('clasificaciones_abc_xyz as c')
            ->join('productos as p', 'c.producto_id', '=', 'p.id')
            ->where('c.empresa_id',  $user->empresa_id)
            ->where('c.sucursal_id', $user->sucursal_id)
            ->where('c.vigente', true)
            ->select(
                'p.codigo_interno as codigo',
                'p.nombre as producto',
                'c.clase_abc',
                'c.clase_xyz',
                Capsule::raw("(c.clase_abc || c.clase_xyz) AS segmento"),
                'c.total_valor',
                'c.pct_valor_acum',
                'c.demanda_media',
                'c.coef_variacion',
                'c.meses_activos',
                'c.rotacion_anual',
                'c.dias_inventario',
                'c.zona_recomendada',
                'c.accion_sugerida'
            )
            ->orderBy('c.total_valor', 'desc')
            ->get();

        $headers = [
            'Código', 'Producto', 'Clase ABC', 'Clase XYZ', 'Segmento',
            'Valor Total Ventas', '% Acumulado', 'Demanda Media/Mes',
            'Coef. Variación', 'Meses Activos', 'Rotación Anual',
            'Días Inventario', 'Zona Recomendada', 'Acción Sugerida',
        ];

        $rows = $items->map(fn($i) => [
            $i->codigo, $i->producto,
            $i->clase_abc, $i->clase_xyz, $i->segmento,
            number_format($i->total_valor, 2),
            number_format($i->pct_valor_acum, 3) . '%',
            number_format($i->demanda_media, 2),
            number_format($i->coef_variacion, 4),
            $i->meses_activos,
            $i->rotacion_anual !== null ? number_format($i->rotacion_anual, 2) : '—',
            $i->dias_inventario !== null ? number_format($i->dias_inventario, 1) : '—',
            $i->zona_recomendada ?? '—',
            $i->accion_sugerida ?? '—',
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'rotacion_abc_xyz_' . date('Y-m-d'));
    }

    // ── Privado ───────────────────────────────────────────────────────────────

    /** Verifica si la vista materializada existe y tiene filas. */
    private function mvExists(): bool
    {
        try {
            $count = Capsule::table('mv_rotacion_productos')->count();
            return $count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

}
