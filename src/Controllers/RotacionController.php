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
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
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
            $q->where(fn($sub) =>
                $sub->where('producto_nombre', 'like', $search)
                    ->orWhere('codigo', 'like', $search)
            );
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
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->selectRaw("
                COUNT(*) AS total_productos,
                COUNT(CASE WHEN clase_abc = 'A' THEN 1 END) AS clase_a,
                COUNT(CASE WHEN clase_abc = 'B' THEN 1 END) AS clase_b,
                COUNT(CASE WHEN clase_abc = 'C' THEN 1 END) AS clase_c,
                COUNT(CASE WHEN alerta_quiebre = TRUE THEN 1 END) AS con_alerta_quiebre,
                COUNT(CASE WHEN score_riesgo >= 70 THEN 1 END) AS riesgo_alto,
                COUNT(CASE WHEN dias_cobertura IS NOT NULL AND dias_cobertura < 7 THEN 1 END) AS cobertura_critica,
                ROUND(SUM(total_valor), 2) AS valor_total_ventas,
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
            ->where('c.empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('c.sucursal_id', $user->sucursal_id)
            ->where('c.vigente', true)
            ->select(
                'c.*',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo'
            );

        if (!empty($params['segmento'])) {
            $q->whereRaw('CONCAT(c.clase_abc, c.clase_xyz) = ?', [strtoupper($params['segmento'])]);
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
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('vigente', true)
            ->selectRaw("
                CONCAT(clase_abc, clase_xyz) AS segmento,
                COUNT(*) AS cantidad,
                ROUND(SUM(total_valor), 2) AS valor_total
            ")
            ->groupByRaw("CONCAT(clase_abc, clase_xyz)")
            ->orderByRaw("CONCAT(clase_abc, clase_xyz)")
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
    public function ejecutarAbcXyz(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $body  = (array)($r->getParsedBody() ?? []);
        $meses = (int)($body['meses'] ?? 12);

        if ($meses < 1 || $meses > 36) {
            return $this->error($res, 'El parámetro meses debe estar entre 1 y 36');
        }

        if ($this->isPg()) {
            $result = Capsule::selectOne(
                'SELECT ejecutar_abc_xyz(?, ?, ?) AS procesados',
                [$this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $meses]
            );
            $procesados = $result ? $result->procesados : 0;
            try {
                Capsule::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rotacion_productos');
            } catch (\Exception $e) {
                Capsule::statement('REFRESH MATERIALIZED VIEW mv_rotacion_productos');
            }
        } else {
            // Motor ABC-XYZ nativo PHP para MySQL (negativo = clasificación provisional sin ventas)
            $raw        = $this->_ejecutarAbcXyzMysql($this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $meses);
            $provisional = $raw < 0;
            $procesados  = abs($raw);
        }

        $this->audit($user, 'rotacion', 'abc_xyz', 'clasificaciones_abc_xyz', null,
            null, ['procesados' => $procesados, 'meses' => $meses, 'provisional' => $provisional ?? false],
            "ABC-XYZ ejecutado: {$procesados} productos clasificados" . (($provisional ?? false) ? ' (provisional CZ)' : ''));

        $msg = ($provisional ?? false)
            ? "Clasificación provisional CZ aplicada a {$procesados} productos. No se encontraron salidas en los últimos {$meses} meses. Re-ejecute cuando haya movimientos de venta."
            : 'Clasificación ABC-XYZ completada';

        return $this->ok($res, [
            'productos_clasificados' => $procesados,
            'meses_analizados'       => $meses,
            'mv_refrescada'          => $this->isPg(),
            'provisional'            => $provisional ?? false,
        ], $msg);
    }

    private function _ejecutarAbcXyzMysql(int $empId, int $sucId, int $meses): int
    {
        $desde = date('Y-m-01', strtotime("-{$meses} months"));
        $ahora = date('Y-m-d H:i:s');

        // 1. Ventas por producto/mes desde movimiento_inventarios
        $ventas = Capsule::table('movimiento_inventarios as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.empresa_id',  $empId)
            ->where('m.sucursal_id', $sucId)
            ->whereIn('m.tipo_movimiento', ['Salida', 'Picking', 'Despacho'])
            ->where('m.fecha_movimiento', '>=', $desde)
            ->where('m.cantidad', '>', 0)
            ->selectRaw("
                m.producto_id,
                p.nombre AS nombre,
                p.codigo_interno AS codigo,
                DATE_FORMAT(m.fecha_movimiento, '%Y-%m-01') AS mes,
                SUM(ABS(m.cantidad)) AS unidades
            ")
            ->groupByRaw("m.producto_id, p.nombre, p.codigo_interno, DATE_FORMAT(m.fecha_movimiento, '%Y-%m-01')")
            ->get();

        if ($ventas->isEmpty()) {
            // Sin movimientos de salida: clasificar como CZ provisional (retorna negativo como señal)
            return -$this->_clasificarInventarioSinVentas($empId, $sucId, $desde, $ahora);
        }

        // 2. Precio/costo promedio por producto
        $precios = Capsule::table('inventarios')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('costo_unitario', '>', 0)
            ->selectRaw('producto_id, AVG(costo_unitario) AS precio')
            ->groupBy('producto_id')
            ->pluck('precio', 'producto_id');

        // 3. Construir serie mensual por producto
        $mesesList = [];
        for ($i = $meses; $i >= 1; $i--) {
            $mesesList[] = date('Y-m-01', strtotime("-{$i} months"));
        }

        $prods = [];
        foreach ($ventas as $v) {
            $pid = $v->producto_id;
            if (!isset($prods[$pid])) {
                $prods[$pid] = ['nombre' => $v->nombre, 'codigo' => $v->codigo, 'serie' => [], 'total_uds' => 0];
            }
            $prods[$pid]['serie'][$v->mes] = (float)$v->unidades;
            $prods[$pid]['total_uds'] += (float)$v->unidades;
        }

        // 4. Calcular valor, XYZ y estadísticas por producto
        foreach ($prods as $pid => &$p) {
            $precio = (float)($precios[$pid] ?? 0);
            $serie  = array_map(fn($m) => $p['serie'][$m] ?? 0.0, $mesesList);
            $mesesActivos = count(array_filter($serie));
            $media = array_sum($serie) / max($meses, 1);

            // Coeficiente de variación
            if ($mesesActivos >= 2 && $media > 0) {
                $varianza  = array_sum(array_map(fn($x) => pow($x - $media, 2), $serie)) / count($serie);
                $cv        = sqrt($varianza) / $media;
            } elseif ($mesesActivos === 1) {
                $cv = 1.5;
            } else {
                $cv = 2.0;
            }

            $valorTotal = $precio > 0 ? $p['total_uds'] * $precio : $p['total_uds'];

            $p['valor_total']    = $valorTotal;
            $p['demanda_media']  = $media;
            $p['coef_variacion'] = round($cv, 4);
            $p['meses_activos']  = $mesesActivos;
            $p['clase_xyz']      = $cv < 0.5 ? 'X' : ($cv < 1.0 ? 'Y' : 'Z');
        }
        unset($p);

        // 5. Clasificación ABC (Pareto por valor)
        uasort($prods, fn($a, $b) => $b['valor_total'] <=> $a['valor_total']);
        $totalValor = array_sum(array_column($prods, 'valor_total'));
        $acum = 0;
        foreach ($prods as $pid => &$p) {
            $acum  += $p['valor_total'];
            $pct    = $totalValor > 0 ? ($acum / $totalValor * 100) : 100.0;
            $p['pct_valor_acum'] = round($pct, 3);
            $p['clase_abc']      = $pct <= 80 ? 'A' : ($pct <= 95 ? 'B' : 'C');
        }
        unset($p);

        // 6. Stock actual para rotación/cobertura
        $stocks = Capsule::table('inventarios')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('cantidad', '>', 0)
            ->selectRaw('producto_id, SUM(cantidad) AS stock')
            ->groupBy('producto_id')
            ->pluck('stock', 'producto_id');

        // 7. Guardar (invalidar previas, insertar nuevas)
        Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->update(['vigente' => false, 'updated_at' => $ahora]);

        $procesados = 0;
        foreach ($prods as $pid => $p) {
            $stock   = (float)($stocks[$pid] ?? 0);
            $rotAnual = ($p['demanda_media'] > 0 && $stock > 0)
                ? round(($p['demanda_media'] * 12) / $stock, 2) : null;
            $diasInv  = ($p['demanda_media'] > 0 && $stock > 0)
                ? round(($stock / $p['demanda_media']) * 30, 1) : null;

            $seg  = $p['clase_abc'] . $p['clase_xyz'];
            $zona = match(true) {
                $p['clase_abc'] === 'A' && $p['clase_xyz'] === 'X' => 'Zona A - Alta Prioridad',
                $p['clase_abc'] === 'A' && $p['clase_xyz'] === 'Y' => 'Zona A - Monitoreo Activo',
                $p['clase_abc'] === 'A' && $p['clase_xyz'] === 'Z' => 'Zona A - Control Especial',
                $p['clase_abc'] === 'B'                            => 'Zona B - Revisión Periódica',
                default                                            => 'Zona C - Control Mínimo',
            };
            $accion = match(true) {
                $seg === 'AX' => 'Mantener stock óptimo; punto de reorden automático',
                $seg === 'AY' => 'Revisar semanalmente; ajustar stock de seguridad',
                $seg === 'AZ' => 'Revisión diaria; considerar hacer-a-pedido',
                str_starts_with($seg, 'B') => 'Revisar quincenalmente; política de mínimos/máximos',
                default => 'Revisar mensualmente; reducir stock a mínimo operativo',
            };

            Capsule::table('clasificaciones_abc_xyz')->insert([
                'empresa_id'       => $empId,
                'sucursal_id'      => $sucId,
                'producto_id'      => $pid,
                'clase_abc'        => $p['clase_abc'],
                'clase_xyz'        => $p['clase_xyz'],
                'total_valor'      => round($p['valor_total'], 2),
                'pct_valor_acum'   => $p['pct_valor_acum'],
                'demanda_media'    => round($p['demanda_media'], 4),
                'coef_variacion'   => $p['coef_variacion'],
                'meses_activos'    => $p['meses_activos'],
                'rotacion_anual'   => $rotAnual,
                'dias_inventario'  => $diasInv,
                'zona_recomendada' => $zona,
                'accion_sugerida'  => $accion,
                'vigente'          => true,
                'periodo_inicio'   => $desde,
                'periodo_fin'      => date('Y-m-d'),
                'calculado_at'     => $ahora,
                'created_at'       => $ahora,
                'updated_at'       => $ahora,
            ]);
            $procesados++;
        }

        return $procesados;
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
            [$this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $desde, $hasta]
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
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
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
            ->where('empresa_id',   $this->getEffectiveEmpresaId($user, $r))
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
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
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
            ->where('c.empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('c.sucursal_id', $user->sucursal_id)
            ->where('c.vigente', true)
            ->select(
                'p.codigo_interno as codigo',
                'p.nombre as producto',
                'c.clase_abc',
                'c.clase_xyz',
                Capsule::raw("CONCAT(c.clase_abc, c.clase_xyz) AS segmento"),
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

    /**
     * Clasificación provisional CZ para productos en inventario sin historial de ventas.
     * Permite que el motor de slotting funcione incluso antes de tener movimientos de salida.
     */
    private function _clasificarInventarioSinVentas(int $empId, int $sucId, string $desde, string $ahora): int
    {
        $inventario = Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.empresa_id',  $empId)
            ->where('i.sucursal_id', $sucId)
            ->where('i.cantidad', '>', 0)
            ->selectRaw('i.producto_id, p.nombre, p.codigo_interno AS codigo, SUM(i.cantidad) AS stock')
            ->groupByRaw('i.producto_id, p.nombre, p.codigo_interno')
            ->get();

        if ($inventario->isEmpty()) return 0;

        Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->update(['vigente' => false, 'updated_at' => $ahora]);

        $procesados = 0;
        foreach ($inventario as $inv) {
            Capsule::table('clasificaciones_abc_xyz')->insert([
                'empresa_id'       => $empId,
                'sucursal_id'      => $sucId,
                'producto_id'      => $inv->producto_id,
                'clase_abc'        => 'C',
                'clase_xyz'        => 'Z',
                'total_valor'      => 0,
                'pct_valor_acum'   => 100.0,
                'demanda_media'    => 0,
                'coef_variacion'   => 2.0,
                'meses_activos'    => 0,
                'rotacion_anual'   => null,
                'dias_inventario'  => null,
                'zona_recomendada' => 'Zona C - Control Mínimo',
                'accion_sugerida'  => 'Sin historial de ventas — clasificación provisional CZ. Re-ejecutar tras registrar salidas.',
                'vigente'          => true,
                'periodo_inicio'   => $desde,
                'periodo_fin'      => date('Y-m-d'),
                'calculado_at'     => $ahora,
                'created_at'       => $ahora,
                'updated_at'       => $ahora,
            ]);
            $procesados++;
        }

        return $procesados;
    }

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
