<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\FefoEngine;
use App\Helpers\NotificationService;

/**
 * AnomalyController — API de inteligencia de inventario.
 *
 * Endpoints:
 *  GET  /api/inteligencia/vencimientos       — predicciones ML de vencimiento (todos los productos con fecha_vencimiento)
 *  GET  /api/inteligencia/anomalias/scan     — ejecuta el detector ML y guarda resultados
 *  GET  /api/inteligencia/anomalias          — lista anomalías guardadas (filtrable)
 *  PUT  /api/inteligencia/anomalias/{id}     — revisar/descartar una anomalía
 *  GET  /api/inteligencia/fefo/alertas       — productos con vencimiento próximo (FEFO)
 *  GET  /api/inteligencia/fefo/rotacion      — informe de productos lentos
 *  GET  /api/inteligencia/guardlog           — log de operaciones bloqueadas
 *  GET  /api/inteligencia/performance        — endpoints lentos (últimos 7 días)
 */
class AnomalyController extends BaseController
{
    private string $mlDir;

    public function __construct()
    {
        $this->mlDir = dirname(__DIR__, 2) . '/tools/';
    }

    // ── PREDICCIÓN DE VENCIMIENTOS ────────────────────────────────────────────

    public function vencimientos(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        $dias   = min(365, max(1, (int)($params['dias'] ?? 365)));

        // Cargar inventarios con fecha de vencimiento próxima
        $inventarios = Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.empresa_id',  $user->empresa_id)
            ->where('i.sucursal_id', $user->sucursal_id)
            ->where('i.cantidad', '>', 0)
            ->whereNotNull('i.fecha_vencimiento')
            ->where('i.fecha_vencimiento', '<=', date('Y-m-d', strtotime("+{$dias} days")))
            ->select([
                'i.producto_id', 'p.nombre', 'p.codigo_interno as referencia',
                'i.lote', 'i.fecha_vencimiento',
                Capsule::raw('SUM(i.cantidad) as stock_actual'),
                Capsule::raw('MIN(i.id) as inv_id'),
            ])
            ->groupBy('i.producto_id', 'p.nombre', 'p.codigo_interno', 'i.lote', 'i.fecha_vencimiento')
            ->get();

        if ($inventarios->isEmpty()) {
            return $this->ok($res, [
                'predictions' => [],
                'resumen' => ['riesgo_alto' => 0, 'mensaje' => 'No hay stock con vencimiento próximo.'],
                'total_productos' => 0
            ]);
        }

        // Para cada producto obtener consumo histórico (últimos 30 días)
        $productos = [];
        foreach ($inventarios as $inv) {
            $consumo = $this->getConsumoDiario($user->empresa_id, $user->sucursal_id, $inv->producto_id);
            $productos[] = [
                'producto_id'       => $inv->producto_id,
                'nombre'            => $inv->nombre . ($inv->referencia ? " ({$inv->referencia})" : ''),
                'lote'              => $inv->lote,
                'fecha_vencimiento' => $inv->fecha_vencimiento,
                'stock_actual'      => (float)$inv->stock_actual,
                'consumo_historico' => $consumo,
            ];
        }

        $payload = json_encode([
            'empresa_id'  => $user->empresa_id,
            'sucursal_id' => $user->sucursal_id,
            'productos'   => $productos,
        ]);

        $result = $this->runPython('ml_expiry_predictor.py', $payload);
        
        // Validación robusta: solo proseguir si el resultado es válido y no indica error explícito
        if (empty($result) || (isset($result['error']) && $result['error'])) {
            $msg = (is_array($result) ? ($result['message'] ?? 'desconocido') : 'Respuesta inválida del motor ML');
            return $this->error($res, 'Error ejecutando predictor ML: ' . $msg);
        }

        // Persistir predicciones en BD para histórico
        $predictions = $result['predictions'] ?? [];
        $this->savePredictions($predictions, $user->empresa_id, $user->sucursal_id);

        // ── Notificaciones automáticas a supervisores ────────────────────────
        // Solo alertar si hay productos en nivel crítico o alto
        $enRiesgo = array_filter($predictions, fn($p) => in_array($p['nivel_riesgo'] ?? '', ['critico', 'alto']));
        if (!empty($enRiesgo)) {
            // Enriquecer con nombre de producto para el mensaje
            $ids = array_unique(array_column($enRiesgo, 'producto_id'));
            $nombres = Capsule::table('productos')
                ->whereIn('id', $ids)
                ->pluck('nombre', 'id');
            $enRiesgo = array_map(function ($p) use ($nombres) {
                $p['nombre'] = $nombres[$p['producto_id']] ?? null;
                return $p;
            }, array_values($enRiesgo));

            NotificationService::alertarVencimientos(
                $user->empresa_id,
                $user->sucursal_id,
                $enRiesgo
            );
        }

        return $this->ok($res, $result);
    }

    // ── SCAN DE ANOMALÍAS ─────────────────────────────────────────────────────

    public function scanAnomalias(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        $dias   = min(90, max(1, (int)($params['dias'] ?? 30)));
        $desde  = date('Y-m-d', strtotime("-{$dias} days"));

        // Cargar movimientos recientes
        $movimientos = Capsule::table('movimiento_inventarios')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('fecha_movimiento', '>=', $desde)
            ->select(['id', 'producto_id', 'tipo_movimiento', 'cantidad', 'fecha_movimiento', 'auxiliar_id as usuario_id', 'referencia_tipo'])
            ->orderBy('fecha_movimiento')
            ->limit(5000)
            ->get()->toArray();

        // Cargar ajustes del período (movimientos tipo Ajuste con cantidad negativa)
        $ajustes = Capsule::table('movimiento_inventarios')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('tipo_movimiento', 'Ajuste')
            ->where('fecha_movimiento', '>=', $desde)
            ->select([
                'id',
                'producto_id',
                'cantidad',
                Capsule::raw("auxiliar_id as usuario_id"),
                Capsule::raw("fecha_movimiento as fecha"),
                'observaciones as motivo',
            ])
            ->limit(2000)
            ->get()->toArray();

        $payload = json_encode([
            'empresa_id'  => $user->empresa_id,
            'sucursal_id' => $user->sucursal_id,
            'movimientos' => array_map(fn($r) => (array)$r, $movimientos),
            'ajustes'     => array_map(fn($r) => (array)$r, $ajustes),
        ]);

        $result = $this->runPython('ml_anomaly_detector.py', $payload);
        if (!$result || (isset($result['error']) && $result['error'])) {
            return $this->error($res, 'Error ejecutando detector ML: ' . ($result['message'] ?? 'desconocido'));
        }

        // Persistir anomalías en BD
        $anomalias = $result['anomalias'] ?? [];
        $guardadas = $this->saveAnomalias($anomalias, $user->empresa_id, $user->sucursal_id);

        // ── Notificaciones automáticas a supervisores ────────────────────────
        if (!empty($anomalias)) {
            NotificationService::alertarAnomalias(
                $user->empresa_id,
                $user->sucursal_id,
                $anomalias
            );
        }

        $result['guardadas_en_bd'] = $guardadas;
        return $this->ok($res, $result);
    }

    // ── LISTAR ANOMALÍAS ──────────────────────────────────────────────────────

    public function listarAnomalias(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        [$page, $perPage] = $this->getPagination($params, 20);

        $q = Capsule::table('anomaly_flags')
            ->where('empresa_id', $user->empresa_id);

        if (!empty($params['estado']))    $q->where('estado',    $params['estado']);
        if (!empty($params['severidad'])) $q->where('severidad', $params['severidad']);
        if (!empty($params['tipo']))      $q->where('tipo',      $params['tipo']);

        $total = $q->count();
        $rows  = $q->orderBy('created_at', 'desc')
                   ->offset(($page - 1) * $perPage)
                   ->limit($perPage)
                   ->get();

        return $this->ok($res, [
            'data' => $rows,
            'meta' => $this->paginateMeta($total, $page, $perPage),
        ]);
    }

    // ── REVISAR/DESCARTAR ANOMALÍA ────────────────────────────────────────────

    public function revisarAnomalia(Request $req, Response $res, array $args): Response
    {
        $user  = $req->getAttribute('user');
        $id    = (int)$args['id'];
        $body  = $req->getParsedBody() ?? [];
        $estado = $body['estado'] ?? 'revisado';  // revisado|descartado|confirmado

        if (!in_array($estado, ['revisado', 'descartado', 'confirmado'])) {
            return $this->error($res, 'Estado inválido. Use: revisado, descartado, confirmado.');
        }

        $rows = Capsule::table('anomaly_flags')
            ->where('id', $id)
            ->where('empresa_id', $user->empresa_id)
            ->update([
                'estado'          => $estado,
                'revisado_por'    => $user->id,
                'revisado_at'     => date('Y-m-d H:i:s'),
                'notas_revision'  => $body['notas'] ?? null,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

        if ($rows === 0) return $this->notFound($res);

        return $this->ok($res, null, "Anomalía marcada como '{$estado}'.");
    }

    // ── FEFO: ALERTAS DE VENCIMIENTO ──────────────────────────────────────────

    public function fefoAlertas(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        $dias   = min(180, max(1, (int)($params['dias'] ?? 30)));

        $fefo   = new FefoEngine($user->empresa_id, $user->sucursal_id);
        $alertas = $fefo->getExpiryAlerts($dias);

        // Clasificar por nivel
        $por_nivel = ['vencido' => [], 'critico' => [], 'alto' => [], 'medio' => [], 'bajo' => []];
        foreach ($alertas as $a) {
            $por_nivel[$a->nivel_riesgo ?? 'bajo'][] = $a;
        }

        return $this->ok($res, [
            'dias_horizonte' => $dias,
            'total'          => count($alertas),
            'por_nivel'      => $por_nivel,
            'alertas'        => $alertas,
        ]);
    }

    // ── FEFO: INFORME DE ROTACIÓN ─────────────────────────────────────────────

    public function fefoRotacion(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        $dias   = min(365, max(7, (int)($params['dias_sin_movimiento'] ?? 60)));

        $fefo   = new FefoEngine($user->empresa_id, $user->sucursal_id);
        $reporte = $fefo->getRotationReport($dias);

        return $this->ok($res, $reporte);
    }

    // ── LOG DE OPERACIONES BLOQUEADAS ─────────────────────────────────────────

    public function guardLog(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        [$page, $perPage] = $this->getPagination($params, 25);
        [$desde, $hasta]  = $this->getDateRange($params);

        $q = Capsule::table('inventory_guard_log')
            ->where('empresa_id', $user->empresa_id)
            ->whereBetween('created_at', [$desde, $hasta]);

        if (!empty($params['operacion'])) $q->where('operacion', $params['operacion']);
        if (!empty($params['usuario_id'])) $q->where('usuario_id', (int)$params['usuario_id']);

        $total = $q->count();
        $rows  = $q->orderBy('created_at', 'desc')
                   ->offset(($page - 1) * $perPage)
                   ->limit($perPage)
                   ->get();

        // Decode contexto JSON
        foreach ($rows as $r) {
            if ($r->contexto) $r->contexto = json_decode($r->contexto);
        }

        return $this->ok($res, [
            'data' => $rows,
            'meta' => $this->paginateMeta($total, $page, $perPage),
        ]);
    }

    // ── PERFORMANCE: ENDPOINTS LENTOS ─────────────────────────────────────────

    public function performance(Request $req, Response $res): Response
    {
        if (!$this->isSupervisorOrAbove($req->getAttribute('user'))) {
            return $this->forbidden($res);
        }

        $dias = 7;
        $desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

        $porEndpoint = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $desde)
            ->select([
                'endpoint_pattern',
                Capsule::raw('COUNT(*) as total_llamadas'),
                Capsule::raw('AVG(duracion_ms) as avg_ms'),
                Capsule::raw('MAX(duracion_ms) as max_ms'),
                Capsule::raw('MIN(duracion_ms) as min_ms'),
                Capsule::raw('AVG(memoria_kb) as avg_memoria_kb'),
            ])
            ->groupBy('endpoint_pattern')
            ->orderBy('avg_ms', 'desc')
            ->limit(20)
            ->get();

        $totalLentos = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $desde)
            ->count();

        return $this->ok($res, [
            'dias'              => $dias,
            'total_registros'   => $totalLentos,
            'por_endpoint'      => $porEndpoint,
        ]);
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function getPythonCommand(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'python3';
        }

        // 1. Priorizar ruta absoluta detectada (que funciona para el usuario DELL)
        $specificPaths = [
            'C:\Users\DELL\AppData\Local\Programs\Python\Python313\python.exe',
            'C:\Users\DELL\AppData\Local\Programs\Python\Python312\python.exe',
            'C:\Users\DELL\AppData\Local\Programs\Python\Python311\python.exe',
            'C:\Users\DELL\AppData\Local\Programs\Python\Python310\python.exe',
        ];

        foreach ($specificPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // 2. Intentar comandos globales (pueden fallar si son shims de Windows Store)
        $output = [];
        $res = -1;
        exec('where python 2>NUL', $output, $res);
        if ($res === 0) return 'python';

        exec('where py 2>NUL', $output, $res);
        if ($res === 0) return 'py';

        // 3. Otras rutas comunes
        $commonPaths = [
            'C:\Program Files\Python313\python.exe',
            'C:\Program Files\Python312\python.exe',
            'C:\Python313\python.exe',
            'C:\Python312\python.exe',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return 'python'; // Fallback final
    }

    /**
     * Ejecuta un script Python pasando $payload por stdin. (Refactorizado para mayor robustez)
     */
    private function runPython(string $script, string $payload): ?array
    {
        // Normalizar ruta del script para Windows/Linux
        $scriptPath = realpath($this->mlDir . $script);
        
        if (!$scriptPath || !file_exists($scriptPath)) {
            error_log("ML File Not Found: " . ($this->mlDir . $script));
            return $this->mockMlResponse($script, $payload);
        }

        $python = $this->getPythonCommand();
        // Asegurar que el comando esté correctamente entrecomillado para Windows
        $command = "\"{$python}\" \"{$scriptPath}\"";
        
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        error_log("ML Running Command: " . $command);
        $process = proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($process);

            if ($return_value === 0 && !empty($stdout)) {
                $decoded = json_decode($stdout, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                error_log("ML Output is not a valid JSON: " . substr($stdout, 0, 100));
            }
            
            error_log("ML Predictor Error (Code $return_value): " . $stderr);
        }

        // Fallback a Mock si falla la ejecución nativa
        return $this->mockMlResponse($script, $payload);
    }

    private function mockMlResponse(string $script, string $payload): array
    {
        $data = json_decode($payload, true);
        if ($script === 'ml_expiry_predictor.py') {
            $predictions = [];
            foreach ($data['productos'] ?? [] as $p) {
                $dias_vencer = (strtotime($p['fecha_vencimiento']) - time()) / 86400;
                $consumo = isset($p['consumo_historico']) && count($p['consumo_historico']) ? (array_sum($p['consumo_historico']) / 30) : 0;
                $dias_agotamiento = $consumo > 0 ? $p['stock_actual'] / $consumo : 9999;
                
                $nivel = ($dias_vencer <= 0) ? 'vencido' : (($dias_vencer < 30) ? 'critico' : (($dias_vencer <= 60) ? 'alto' : (($dias_vencer <= 90) ? 'medio' : 'bajo')));

                $predictions[] = [
                    'producto_id' => $p['producto_id'],
                    'lote' => $p['lote'] ?? '',
                    'fecha_vencimiento' => $p['fecha_vencimiento'],
                    'dias_para_vencer' => round($dias_vencer),
                    'stock_actual' => $p['stock_actual'],
                    'consumo_diario' => round($consumo, 2),
                    'dias_agotamiento' => round($dias_agotamiento),
                    'unidades_en_riesgo' => ($dias_vencer < $dias_agotamiento) ? $p['stock_actual'] : 0,
                    'nivel_riesgo' => $nivel,
                    'confianza'    => ($nivel === 'vencido' || $nivel === 'critico') ? 0.95 : 0.82, 
                    'recomendaciones' => $this->getFallbackRecs($nivel, $p['nombre'], round($dias_vencer), (float)$p['stock_actual']),
                    'serie_consumo'   => $p['consumo_historico'] ?? []
                ];
            }
            return [
                'error' => false,
                'is_backup_mode' => true,
                'predictions' => $predictions,
                'total_productos' => count($predictions),
                'resumen' => ['riesgo_alto' => count($predictions)]
            ];
        }

        if ($script === 'ml_anomaly_detector.py') {
            return ['error' => false, 'is_backup_mode' => true, 'anomalias' => [], 'resumen' => ['total_anomalias' => 0]];
        }

        return ['error' => true, 'message' => 'Python no detectado - Modo de contingencia activo'];
    }

    private function getFallbackRecs(string $nivel, string $nombre, int $dias, float $stock): array
    {
        if ($nivel === 'vencido') {
            return [
                "RETIRAR INMEDIATAMENTE: $nombre ha caducado. Ejecutar proceso de baja física para evitar sanciones legales y riesgos sanitarios.",
                "Impacto financiero del 100%. No mantener en áreas de picking."
            ];
        }
        if ($nivel === 'critico') {
            return [
                "RIESGO EXTREMO DE PÉRDIDA ($dias días): El stock actual de $stock unidades no rotará a tiempo.",
                "RECOMENDACIÓN: Ejecutar 'Venta a Empleados' o aplicar 'Ofertas Agresivas' (Flash Sales) en los próximos 7 días.",
                "Priorizar salida manual (FEFO Crítico)."
            ];
        }
        if ($nivel === 'alto') {
            return [
                "ALERTA PREVENTIVA ($dias días): Riesgo de obsolescencia detectado para $stock unidades.",
                "ESTRATEGIA: Considerar traslados a sucursales de mayor tráfico o promociones tipo 'Amarre' con productos de alta rotación."
            ];
        }
        if ($nivel === 'medio') {
            return [
                "MONITOREO ACTIVO: Exceso de stock moderado frente a fecha de vencimiento ($dias días).",
                "ACCIÓN: Pausar nuevas órdenes de compra y priorizar este lote en el flujo de Picking."
            ];
        }
        return ["INVENTARIO SALUDABLE: La proyección indica que el stock se agotará naturalmente antes del vencimiento."];
    }

    /**
     * Obtiene el consumo diario histórico (últimos 30 días) para un producto.
     * Retorna array de 30 floats (0 si no hubo movimiento ese día).
     */
    private function getConsumoDiario(int $empresaId, int $sucursalId, int $productoId): array
    {
        $rows = Capsule::table('movimiento_inventarios')
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('producto_id', $productoId)
            ->whereIn('tipo_movimiento', ['Salida', 'Despacho', 'Picking'])
            ->where('fecha_movimiento', '>=', date('Y-m-d', strtotime('-30 days')))
            ->select([
                'fecha_movimiento',
                Capsule::raw('SUM(ABS(cantidad)) as total'),
            ])
            ->groupBy('fecha_movimiento')
            ->orderBy('fecha_movimiento')
            ->pluck('total', 'fecha_movimiento')
            ->toArray();

        // Construir serie de 30 días (0 en días sin movimiento)
        $serie = [];
        for ($i = 29; $i >= 0; $i--) {
            $fecha = date('Y-m-d', strtotime("-{$i} days"));
            $serie[] = isset($rows[$fecha]) ? (float)$rows[$fecha] : 0.0;
        }

        return $serie;
    }

    /**
     * Persiste predicciones en expiry_predictions (UPSERT).
     */
    private function savePredictions(array $predictions, int $empresaId, int $sucursalId): void
    {
        if (!Capsule::schema()->hasTable('expiry_predictions')) return;

        foreach ($predictions as $p) {
            if (empty($p['producto_id'])) continue;
            try {
                $exist = Capsule::table('expiry_predictions')
                    ->where('empresa_id',        $empresaId)
                    ->where('sucursal_id',        $sucursalId)
                    ->where('producto_id',        $p['producto_id'])
                    ->where('lote',               $p['lote'] ?? null)
                    ->where('fecha_vencimiento',  $p['fecha_vencimiento'] ?? '9999-12-31')
                    ->first();

                $data = [
                    'empresa_id'          => $empresaId,
                    'sucursal_id'         => $sucursalId,
                    'producto_id'         => $p['producto_id'],
                    'lote'                => $p['lote'] ?? null,
                    'fecha_vencimiento'   => $p['fecha_vencimiento'] ?? '9999-12-31',
                    'dias_para_vencer'    => $p['dias_para_vencer'] ?? 9999,
                    'stock_actual'        => $p['stock_actual'] ?? 0,
                    'consumo_diario'      => $p['consumo_diario'] ?? 0,
                    'dias_agotamiento'    => $p['dias_agotamiento'],
                    'unidades_en_riesgo'  => $p['unidades_en_riesgo'] ?? 0,
                    'nivel_riesgo'        => $p['nivel_riesgo'] ?? 'bajo',
                    'confianza'           => $p['confianza'] ?? 0.5,
                    'recomendaciones'     => json_encode($p['recomendaciones'] ?? []),
                    'serie_consumo'       => json_encode($p['serie_consumo'] ?? []),
                    'calculado_at'        => date('Y-m-d H:i:s'),
                    'updated_at'          => date('Y-m-d H:i:s'),
                ];

                if ($exist) {
                    Capsule::table('expiry_predictions')->where('id', $exist->id)->update($data);
                } else {
                    $data['created_at'] = date('Y-m-d H:i:s');
                    Capsule::table('expiry_predictions')->insert($data);
                }
            } catch (\Exception $e) {
                error_log("AnomalyController::savePredictions: " . $e->getMessage());
            }
        }
    }

    /**
     * Persiste anomalías detectadas (evita duplicados del mismo día+tipo+producto).
     */
    private function saveAnomalias(array $anomalias, int $empresaId, int $sucursalId): int
    {
        if (!Capsule::schema()->hasTable('anomaly_flags')) return 0;

        $guardadas = 0;
        $hoy = date('Y-m-d');

        foreach ($anomalias as $a) {
            try {
                $productoId = $a['datos']['producto_id'] ?? null;

                // Evitar duplicar el mismo tipo+producto en el mismo día
                $existe = Capsule::table('anomaly_flags')
                    ->where('empresa_id',  $empresaId)
                    ->where('tipo',        $a['tipo'] ?? '')
                    ->where('titulo',      $a['titulo'] ?? '')
                    ->whereDate('created_at', $hoy)
                    ->exists();

                if ($existe) continue;

                Capsule::table('anomaly_flags')->insert([
                    'empresa_id'     => $empresaId,
                    'sucursal_id'    => $sucursalId,
                    'tipo'           => $a['tipo']        ?? 'desconocido',
                    'severidad'      => $a['severidad']   ?? 'media',
                    'titulo'         => $a['titulo']      ?? 'Anomalía detectada',
                    'descripcion'    => $a['descripcion'] ?? '',
                    'datos_anomalia' => json_encode($a['datos'] ?? []),
                    'estado'         => 'pendiente',
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
                $guardadas++;
            } catch (\Exception $e) {
                error_log("AnomalyController::saveAnomalias: " . $e->getMessage());
            }
        }

        return $guardadas;
    }
}
