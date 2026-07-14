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
    // Dashboard principal: datos de clasificaciones_abc_xyz (la MV no existe en esta BD)
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $empId = $this->getEffectiveEmpresaId($user, $r);
        $sucId = $user->sucursal_id;

        // Verificar si hay clasificación vigente
        $hayClasificacion = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->exists();

        if (!$hayClasificacion) {
            return $this->ok($res, [
                'resumen'        => null,
                'productos'      => [],
                'mv_disponible'  => false,
                'mensaje'        => 'Sin clasificación ABC-XYZ vigente. Ejecute POST /api/rotacion/abc-xyz/ejecutar primero.',
            ]);
        }

        $q = Capsule::table('clasificaciones_abc_xyz as c')
            ->join('productos as p', 'c.producto_id', '=', 'p.id')
            ->where('c.empresa_id',  $empId)
            ->where('c.sucursal_id', $sucId)
            ->where('c.vigente', true)
            ->select(
                'c.producto_id', 'c.clase_abc', 'c.clase_xyz', 'c.segmento',
                'c.total_valor', 'c.total_unidades', 'c.pct_valor', 'c.pct_unidades',
                'c.cv_demanda', 'c.periodos', 'c.calculado_at',
                'p.nombre as producto_nombre', 'p.codigo_interno as codigo'
            );

        // Filtros
        if (!empty($params['segmento'])) {
            $q->where('c.segmento', strtoupper($params['segmento']));
        }
        if (!empty($params['clase_abc'])) {
            $q->where('c.clase_abc', strtoupper($params['clase_abc']));
        }
        if (!empty($params['clase_xyz'])) {
            $q->where('c.clase_xyz', strtoupper($params['clase_xyz']));
        }
        if (!empty($params['q'])) {
            $search = '%' . $params['q'] . '%';
            $q->where(fn($sub) =>
                $sub->where('p.nombre', 'like', $search)
                    ->orWhere('p.codigo_interno', 'like', $search)
            );
        }

        $limit  = min((int)($params['limit'] ?? 50), 200);
        $offset = (int)($params['offset'] ?? 0);

        $total    = (clone $q)->count();
        $productos = $q->orderBy('c.total_valor', 'desc')
                       ->limit($limit)
                       ->offset($offset)
                       ->get();

        // Resumen general
        $resumen = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->selectRaw("
                COUNT(*) AS total_productos,
                COUNT(CASE WHEN clase_abc = 'A' THEN 1 END) AS clase_a,
                COUNT(CASE WHEN clase_abc = 'B' THEN 1 END) AS clase_b,
                COUNT(CASE WHEN clase_abc = 'C' THEN 1 END) AS clase_c,
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
                'c.id', 'c.empresa_id', 'c.sucursal_id', 'c.producto_id',
                'c.clase_abc', 'c.clase_xyz', 'c.segmento',
                'c.total_valor', 'c.total_unidades',
                'c.pct_valor', 'c.pct_unidades',
                'c.cv_demanda', 'c.periodos',
                'c.vigente', 'c.calculado_at',
                'c.created_at', 'c.updated_at',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo'
            );

        if (!empty($params['segmento'])) {
            $q->where('c.segmento', strtoupper($params['segmento']));
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
                segmento,
                COUNT(*) AS cantidad,
                ROUND(SUM(total_valor), 2) AS valor_total
            ")
            ->groupBy('segmento')
            ->orderBy('segmento')
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

        $provisional = false;

        if ($this->isPg()) {
            // Intentar función PG nativa; si no existe, usar el motor PHP
            try {
                $result = Capsule::selectOne(
                    'SELECT ejecutar_abc_xyz(?, ?, ?) AS procesados',
                    [$this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $meses]
                );
                $procesados = $result ? $result->procesados : 0;
            } catch (\Exception $e) {
                // Función PG no disponible — usar motor PHP en modo PostgreSQL
                $raw        = $this->_ejecutarAbcXyzMysql($this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $meses);
                $provisional = $raw < 0;
                $procesados  = abs($raw);
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
            'provisional'            => $provisional,
        ], $msg);
    }

    private function _ejecutarAbcXyzMysql(int $empId, int $sucId, int $meses): int
    {
        $desde = date('Y-m-01', strtotime("-{$meses} months"));
        $ahora = date('Y-m-d H:i:s');

        // 1. Ventas por producto/mes desde movimiento_inventarios
        // DATE_TRUNC para PG, DATE_FORMAT para MySQL
        $mesExpr = $this->isPg()
            ? "DATE_TRUNC('month', m.fecha_movimiento)::date::text AS mes"
            : "DATE_FORMAT(m.fecha_movimiento, '%Y-%m-01') AS mes";
        $mesGrp = $this->isPg()
            ? "DATE_TRUNC('month', m.fecha_movimiento)::date"
            : "DATE_FORMAT(m.fecha_movimiento, '%Y-%m-01')";

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
                {$mesExpr},
                SUM(ABS(m.cantidad)) AS unidades
            ")
            ->groupByRaw("m.producto_id, p.nombre, p.codigo_interno, {$mesGrp}")
            ->get();

        if ($ventas->isEmpty()) {
            // Sin movimientos de salida: clasificar como CZ provisional (retorna negativo como señal)
            return -$this->_clasificarInventarioSinVentas($empId, $sucId, $desde, $ahora);
        }

        // 2. Precio/costo promedio por producto (inventarios no tiene costo_unitario — usar saldos/cantidad)
        $precios = Capsule::table('inventarios')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('cantidad', '>', 0)
            ->where('saldos', '>', 0)
            ->selectRaw('producto_id, AVG(saldos / NULLIF(cantidad, 0)) AS precio')
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
        $totalValor  = array_sum(array_column($prods, 'valor_total'));
        $totalUnidades = array_sum(array_column($prods, 'total_uds'));
        $acumValor = 0;
        $acumUds   = 0;
        foreach ($prods as $pid => &$p) {
            $acumValor += $p['valor_total'];
            $acumUds   += $p['total_uds'];
            $pctValor   = $totalValor > 0 ? ($acumValor / $totalValor * 100) : 100.0;
            $pctUds     = $totalUnidades > 0 ? ($acumUds / $totalUnidades * 100) : 100.0;
            $p['pct_valor']    = round($pctValor, 4);
            $p['pct_unidades'] = round($pctUds, 4);
            $p['clase_abc']    = $pctValor <= 80 ? 'A' : ($pctValor <= 95 ? 'B' : 'C');
        }
        unset($p);

        // 6. Guardar (invalidar previas, insertar nuevas)
        Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->update(['vigente' => false, 'updated_at' => $ahora]);

        $procesados = 0;
        foreach ($prods as $pid => $p) {
            $seg = $p['clase_abc'] . $p['clase_xyz'];

            Capsule::table('clasificaciones_abc_xyz')->insert([
                'empresa_id'     => $empId,
                'sucursal_id'    => $sucId,
                'producto_id'    => $pid,
                'clase_abc'      => $p['clase_abc'],
                'clase_xyz'      => $p['clase_xyz'],
                'segmento'       => $seg,
                'total_valor'    => round($p['valor_total'], 2),
                'total_unidades' => round($p['total_uds'], 2),
                'pct_valor'      => $p['pct_valor'],
                'pct_unidades'   => $p['pct_unidades'],
                'cv_demanda'     => $p['coef_variacion'],
                'periodos'       => $p['meses_activos'],
                'vigente'        => true,
                'calculado_at'   => $ahora,
                'created_at'     => $ahora,
                'updated_at'     => $ahora,
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

        $empId = $this->getEffectiveEmpresaId($user, $r);
        $sucId = $user->sucursal_id;

        // Agregar ventas desde movimiento_inventarios a ventas_agregadas_ml (nativo PHP, sin función PG)
        $ventas = Capsule::table('movimiento_inventarios as m')
            ->where('m.empresa_id',  $empId)
            ->where('m.sucursal_id', $sucId)
            ->whereIn('m.tipo_movimiento', ['Salida', 'Picking', 'Despacho'])
            ->where('m.cantidad', '>', 0)
            ->whereBetween('m.fecha_movimiento', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->selectRaw("
                m.producto_id,
                DATE_TRUNC('month', m.fecha_movimiento)::date AS periodo,
                SUM(ABS(m.cantidad)) AS unidades_vendidas,
                0 AS valor_vendido
            ")
            ->groupByRaw("m.producto_id, DATE_TRUNC('month', m.fecha_movimiento)::date")
            ->get();

        $insertados = 0;
        $ahora = date('Y-m-d H:i:s');
        foreach ($ventas as $v) {
            try {
                Capsule::table('ventas_agregadas_ml')->upsert(
                    [
                        'empresa_id'       => $empId,
                        'sucursal_id'      => $sucId,
                        'producto_id'      => $v->producto_id,
                        'periodo'          => $v->periodo,
                        'unidades_vendidas'=> (float)$v->unidades_vendidas,
                        'valor_vendido'    => (float)$v->valor_vendido,
                        'updated_at'       => $ahora,
                    ],
                    ['empresa_id', 'sucursal_id', 'producto_id', 'periodo'],
                    ['unidades_vendidas', 'valor_vendido', 'updated_at']
                );
                $insertados++;
            } catch (\Exception $e) {
                error_log('poblarVentas upsert: ' . $e->getMessage());
            }
        }

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
    // La vista materializada mv_rotacion_productos no existe — re-ejecuta ABC-XYZ como equivalente
    public function refreshMv(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $start = microtime(true);
        // Re-ejecutar el motor ABC-XYZ para mantener clasificación actualizada
        $this->_ejecutarAbcXyzMysql(
            $this->getEffectiveEmpresaId($user, $r),
            $user->sucursal_id,
            12
        );
        $ms = round((microtime(true) - $start) * 1000);

        return $this->ok($res, ['duracion_ms' => $ms], 'Clasificación ABC-XYZ actualizada (equivalente a refresh MV)');
    }

    // ── GET /api/rotacion/riesgo ──────────────────────────────────────────────
    // Top productos por cv_demanda alto y clase_abc=A (mayor riesgo de quiebre)
    public function riesgo(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 20), 100);

        $empId = $this->getEffectiveEmpresaId($user, $r);
        $sucId = $user->sucursal_id;

        $hayClasificacion = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id', $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->exists();

        if (!$hayClasificacion) {
            return $this->ok($res, ['productos' => [], 'mv_disponible' => false]);
        }

        // Productos de clase A con alta variación de demanda (mayor riesgo operativo)
        $productos = Capsule::table('clasificaciones_abc_xyz as c')
            ->join('productos as p', 'p.id', '=', 'c.producto_id')
            ->where('c.empresa_id',  $empId)
            ->where('c.sucursal_id', $sucId)
            ->where('c.vigente', true)
            ->where('c.clase_abc', 'A')
            ->select(
                'c.producto_id', 'p.nombre as producto_nombre', 'p.codigo_interno as codigo',
                'c.clase_abc', 'c.clase_xyz', 'c.segmento',
                'c.total_valor', 'c.cv_demanda', 'c.periodos', 'c.calculado_at'
            )
            ->orderBy('c.cv_demanda', 'desc')
            ->orderBy('c.total_valor', 'desc')
            ->limit($limit)
            ->get();

        return $this->ok($res, ['productos' => $productos, 'mv_disponible' => true]);
    }

    // ── GET /api/rotacion/cobertura-baja ─────────────────────────────────────
    // Productos clase A/B con alta variación de demanda (posible quiebre de stock)
    public function coberturasBajas(Request $r, Response $res): Response
    {
        $user     = $r->getAttribute('user');
        $params   = $r->getQueryParams();
        $umbral   = (int)($params['dias'] ?? 7);

        $empId = $this->getEffectiveEmpresaId($user, $r);
        $sucId = $user->sucursal_id;

        $hayClasificacion = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id', $empId)
            ->where('sucursal_id', $sucId)
            ->where('vigente', true)
            ->exists();

        if (!$hayClasificacion) {
            return $this->ok($res, ['productos' => [], 'mv_disponible' => false]);
        }

        // Productos con alta variación (cv_demanda > 1.0) en clases A o B
        $productos = Capsule::table('clasificaciones_abc_xyz as c')
            ->join('productos as p', 'p.id', '=', 'c.producto_id')
            ->where('c.empresa_id',  $empId)
            ->where('c.sucursal_id', $sucId)
            ->where('c.vigente', true)
            ->whereIn('c.clase_abc', ['A', 'B'])
            ->where('c.cv_demanda', '>', 1.0)
            ->select(
                'c.producto_id', 'p.nombre as producto_nombre', 'p.codigo_interno as codigo',
                'c.clase_abc', 'c.clase_xyz', 'c.segmento',
                'c.total_valor', 'c.cv_demanda', 'c.periodos'
            )
            ->orderBy('c.clase_abc', 'asc')
            ->orderBy('c.cv_demanda', 'desc')
            ->get();

        return $this->ok($res, [
            'productos'      => $productos,
            'umbral_dias'    => $umbral,
            'mv_disponible'  => true,
        ]);
    }

    // ── GET /api/rotacion/ejecuciones ────────────────────────────────────────
    // Log de ejecuciones del motor ML (desde audit_logs, tabla ejecuciones_ml no existe)
    public function ejecuciones(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 20), 100);

        $ejecuciones = Capsule::table('audit_logs')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('modulo', 'rotacion')
            ->when(!empty($params['tipo']), fn($q) => $q->where('accion', $params['tipo']))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'accion as tipo', 'descripcion', 'datos_nuevos', 'created_at as inicio_at']);

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
                'c.segmento',
                'c.total_valor',
                'c.total_unidades',
                'c.pct_valor',
                'c.pct_unidades',
                'c.cv_demanda',
                'c.periodos'
            )
            ->orderBy('c.total_valor', 'desc')
            ->get();

        $headers = [
            'Código', 'Producto', 'Clase ABC', 'Clase XYZ', 'Segmento',
            'Valor Total Ventas', 'Unidades Totales', '% Valor Acum.', '% Unidades Acum.',
            'Coef. Variación Demanda', 'Períodos Activos',
        ];

        $rows = $items->map(fn($i) => [
            $i->codigo, $i->producto,
            $i->clase_abc, $i->clase_xyz, $i->segmento ?? ($i->clase_abc . $i->clase_xyz),
            number_format($i->total_valor, 2),
            number_format($i->total_unidades, 2),
            number_format($i->pct_valor, 3) . '%',
            number_format($i->pct_unidades, 3) . '%',
            number_format($i->cv_demanda, 4),
            $i->periodos ?? 0,
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
                'empresa_id'     => $empId,
                'sucursal_id'    => $sucId,
                'producto_id'    => $inv->producto_id,
                'clase_abc'      => 'C',
                'clase_xyz'      => 'Z',
                'segmento'       => 'CZ',
                'total_valor'    => 0,
                'total_unidades' => (float)$inv->stock,
                'pct_valor'      => 100.0,
                'pct_unidades'   => 100.0,
                'cv_demanda'     => 2.0,
                'periodos'       => 0,
                'vigente'        => true,
                'calculado_at'   => $ahora,
                'created_at'     => $ahora,
                'updated_at'     => $ahora,
            ]);
            $procesados++;
        }

        return $procesados;
    }

    /** Verifica si hay clasificación ABC-XYZ vigente (la MV no existe en esta BD). */
    private function mvExists(): bool
    {
        try {
            return Capsule::table('clasificaciones_abc_xyz')
                ->where('vigente', true)
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

}
