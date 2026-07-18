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
        // Default 30 días (no 365): antes cargaba y corría el predictor ML sobre
        // prácticamente todo el catálogo con vencimiento en el próximo año en cada
        // apertura del módulo — lento y sin priorizar lo realmente urgente. El
        // usuario puede ampliar la ventana explícitamente (?dias=90, ?dias=365).
        $dias   = min(365, max(1, (int)($params['dias'] ?? 30)));

        // Cargar inventarios con fecha de vencimiento próxima — orden ASC: lo más
        // próximo a vencer primero, tanto para la respuesta como para el predictor.
        $inventarios = Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.empresa_id',  $this->getEffectiveEmpresaId($user, $req))
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
            ->orderBy('i.fecha_vencimiento', 'asc')
            ->get();

        if ($inventarios->isEmpty()) {
            return $this->ok($res, [
                'predictions' => [],
                'resumen' => ['riesgo_alto' => 0, 'mensaje' => 'No hay stock con vencimiento próximo.'],
                'total_productos' => 0,
                'dias_ventana' => $dias,
            ]);
        }

        // Para cada producto obtener consumo histórico (últimos 30 días)
        $productos = [];
        foreach ($inventarios as $inv) {
            $consumo = $this->getConsumoDiario($this->getEffectiveEmpresaId($user, $req), $user->sucursal_id, $inv->producto_id);
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
            'empresa_id'  => $this->getEffectiveEmpresaId($user, $req),
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
        $this->savePredictions($predictions, $this->getEffectiveEmpresaId($user, $req), $user->sucursal_id);

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
                $this->getEffectiveEmpresaId($user, $req),
                $user->sucursal_id,
                $enRiesgo
            );
        }

        $result['dias_ventana'] = $dias;
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
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $req))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('fecha_movimiento', '>=', $desde)
            ->select(['id', 'producto_id', 'tipo_movimiento', 'cantidad', 'fecha_movimiento', 'auxiliar_id as usuario_id', 'referencia_tipo'])
            ->orderBy('fecha_movimiento')
            ->limit(5000)
            ->get()->toArray();

        // Cargar ajustes del período (movimientos tipo Ajuste con cantidad negativa)
        $ajustes = Capsule::table('movimiento_inventarios')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $req))
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
            'empresa_id'  => $this->getEffectiveEmpresaId($user, $req),
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
        $guardadas = $this->saveAnomalias($anomalias, $this->getEffectiveEmpresaId($user, $req), $user->sucursal_id);

        // ── Notificaciones automáticas a supervisores ────────────────────────
        if (!empty($anomalias)) {
            NotificationService::alertarAnomalias(
                $this->getEffectiveEmpresaId($user, $req),
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
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $req));

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
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
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

        $fefo   = new FefoEngine($this->getEffectiveEmpresaId($user, $req), $user->sucursal_id);
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

        $fefo   = new FefoEngine($this->getEffectiveEmpresaId($user, $req), $user->sucursal_id);
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
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
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
        // -X utf8 fuerza modo UTF-8 en stdin/stdout/stderr (esencial en Windows)
        $command = "\"{$python}\" -X utf8 \"{$scriptPath}\"";
        
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
                    'recomendaciones' => $this->getFallbackRecs($nivel, $p['nombre'], (int)round($dias_vencer), (float)$p['stock_actual'], (float)round($consumo, 2)),
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

    private function getFallbackRecs(string $nivel, string $nombre, int $dias, float $stock, float $consumo = 0.0): array
    {
        $cat        = $this->categorizarProducto($nombre);
        $categoria  = $cat['categoria'];
        $outlets    = implode(' -> ', $cat['outlets']);
        $outletPrim = $cat['outlet_primario'];
        $estrategia = $cat['estrategia_rapida'];
        $nota       = $cat['nota'];
        $esEmpaque  = $cat['es_empaque'] ?? false;

        $evento    = $this->getEventoProximo($dias);

        // ── Empaque / Material Operativo (no alimento) ───────────────────────
        if ($esEmpaque) {
            $urgencia = $nivel === 'vencido' ? 'VENCIDO' : "{$dias} días para vencer";
            if (in_array($nivel, ['vencido', 'critico', 'alto'])) {
                $recs = [
                    "ALERTA MATERIAL OPERATIVO — $nombre | $urgencia\n"
                    . "IMPORTANTE: Este es un EMPAQUE o SUMINISTRO, no un alimento. "
                    . "El vencimiento es la vida útil del material según el proveedor. "
                    . "Stock: {$stock} unidades.",
                    "INSPECCION FISICA: Verificar condición del material (humedad, deformación). "
                    . "Si está en buen estado: usar antes que lotes más nuevos (FEFO). "
                    . "Si hay deterioro: gestionar baja con Supervisor de Calidad.",
                ];
                if ($nivel === 'critico') {
                    $recs[] = "ACCION INMEDIATA: Comunicar a Jefe de Producción para priorizar estas {$stock} "
                        . "unidades en todos los despachos y empaques. No requiere acción comercial ni de cocina.";
                }
                return $recs;
            }
            return [
                "MATERIAL OPERATIVO — $categoria: {$stock} unidades con {$dias} días de vida útil restante. "
                . "Rotación normal recomendada aplicando FEFO. Sin urgencia inmediata."
            ];
        }

        if ($nivel === 'vencido') {
            return [
                "PRODUCTO VENCIDO — ACCION INMEDIATA: $nombre ($categoria) superó su fecha de vencimiento. "
                . "Stock afectado: {$stock} uds. Impacto financiero: 100%.",
                "PROTOCOLO WMS: Ejecutar baja física → mover a zona CUARENTENA → registrar merma → notificar Supervisor de Calidad.",
                "Este insumo ({$nota}) no puede permanecer en zona de picking ni en producción."
            ];
        }

        if ($nivel === 'critico') {
            if ($consumo > 0) {
                $deficit = round(max(0, $stock - $consumo * $dias));
                $recs = [
                    "DICTAMEN CRITICO — $categoria ({$dias} días): Con consumo de {$consumo} uds/día "
                    . "se proyecta un déficit de {$deficit} uds sin rotar antes de vencer.",
                    "RUTA DE DESPACHO URGENTE: $outlets. $estrategia. Comunicar HOY a Jefe de Cocina de $outletPrim.",
                ];
                if ($evento) {
                    $recs[] = "OPORTUNIDAD FESTIVA: {$evento['nombre']} en {$evento['dias_hasta']} día(s) "
                        . "(factor demanda ×{$evento['factor']}). {$evento['descripcion']}";
                }
                $recs[] = $cat['aplica_oferta']
                    ? "ACCION TACTICA: Activar salida urgente en $outletPrim — menú especial, combo o oferta empleados."
                    : "ACCION TACTICA: Incorporar en preparación inmediata en $outletPrim — próximos 2 turnos de cocina.";
            } else {
                // Sin historial: alerta honesta, sin proyecciones falsas
                $recs = [
                    "ALERTA DE VENCIMIENTO — $categoria | Vence en {$dias} día(s) | Stock: {$stock} uds\n"
                    . "SIN HISTORIAL DE CONSUMO: No se puede proyectar demanda. "
                    . "Cada unidad no despachada antes de vencer es pérdida directa. "
                    . "Producto: {$nota}.",
                ];
                if ($evento) {
                    $recs[] = "VENTANA DE OPORTUNIDAD: {$evento['nombre']} en {$evento['dias_hasta']} día(s) "
                        . "(demanda ×{$evento['factor']} en $outletPrim). {$evento['descripcion']} "
                        . "Coordinar despacho ANTES del festivo.";
                }
                $diasAcc = min($dias, 2);
                $recs[] = $cat['aplica_oferta']
                    ? "ACCION URGENTE: Activar salida de $nombre en $outletPrim en las próximas "
                      . ($diasAcc * 24) . " horas. Opciones ({$nota}): "
                      . "(a) Menú especial o carta del día, (b) Combo sin cargo adicional, (c) Oferta interna empleados."
                    : "ACCION URGENTE: Incorporar $nombre en preparaciones de $outletPrim en los próximos "
                      . "{$diasAcc} turnos de cocina. Ruta: $outlets. Informar a Jefe de Cocina.";
            }
            return $recs;
        }

        if ($nivel === 'alto') {
            $recs = [
                $consumo > 0
                    ? "ALERTA PREVENTIVA — $categoria ({$dias} días): Excedente proyectado requiere rotación activa. Consumo: {$consumo} uds/día."
                    : "ALERTA PREVENTIVA — $categoria | {$dias} días para vencer | Stock: {$stock} uds\nSIN HISTORIAL DE CONSUMO — se requiere plan de rotación activo. Producto: {$nota}.",
                "Ruta sugerida: $outlets. $estrategia",
            ];
            if ($evento) {
                $recs[] = "OPORTUNIDAD: {$evento['nombre']} en {$evento['dias_hasta']} día(s) (×{$evento['factor']}). {$evento['descripcion']}";
            } else {
                $recs[] = "ESTRATEGIA: Vincular $nombre con producto de alta rotación en $outletPrim. Traslado del 50% del excedente.";
            }
            return $recs;
        }

        if ($nivel === 'medio') {
            $recs = [
                "MONITOREO ACTIVO — $categoria ({$dias} días): Excedente moderado detectado. Pausar OC de $nombre hasta que el stock baje al 50%.",
                "FEFO activo: priorizar este lote en {$cat['outlets'][0]}.",
            ];
            if ($evento) {
                $recs[] = "OPORTUNIDAD: {$evento['nombre']} en {$evento['dias_hasta']} días — puede normalizar inventario con factor ×{$evento['factor']}.";
            }
            return $recs;
        }

        return [
            "INVENTARIO SALUDABLE — $categoria: Rotación proyectada completa antes de vencer. Sin acción requerida.",
            $evento
                ? "PLANIFICACION: {$evento['nombre']} próximo — verificar stock suficiente para cubrir el pico de demanda en $outletPrim."
                : "Continuar monitoreo estándar FEFO.",
        ];
    }

    private function categorizarProducto(string $nombre): array
    {
        $n          = mb_strtolower($nombre);
        $primerWord = explode(' ', trim($n))[0] ?? '';

        // ── PRIMERO: Empaques y materiales operativos (NO alimentos) ─────────
        // Si el nombre empieza con un clasificador de empaque, es material operativo.
        // Esto evita que "CAJA PIZZA MEDIANA" sea clasificada como ingrediente de pizza.
        $empaque_inicio = ['caja','bolsa','bolsas','empaque','empaques','embalaje',
                           'rollo','manga','papel','servilleta','servilletas',
                           'desechable','desechables','guante','guantes','bandeja',
                           'carton','cartón','envase','envases','precinto','etiqueta',
                           'palillo','cubierto','cubiertos','stretch','film','tapa'];
        foreach ($empaque_inicio as $ek) {
            if (str_starts_with($n, $ek) || $primerWord === $ek) {
                return [
                    'categoria' => 'Empaque / Material Operativo',
                    'outlet_primario' => 'Producción',
                    'outlets' => ['Almacén Central - Materiales', 'Jefe de Producción'],
                    'aplica_oferta' => false,
                    'es_empaque' => true,
                    'nota' => 'material de empaque o suministro operativo — NO es un alimento',
                    'estrategia_rapida' => 'Priorizar uso en producción corriente; inspección física del material',
                ];
            }
        }

        $patrones = [
            ['keys' => ['pizza','masa pizza','pepperoni','mozzarella','oregano'],
             'categoria' => 'Masa / Pizza', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Horno de Pizzas', 'Clap Burger - Menú Pizza Especial'],
             'aplica_oferta' => false, 'es_empaque' => false,
             'nota' => 'insumo base para pizzas artesanales',
             'estrategia_rapida' => 'Activar en menú del día o pizza especial en Olivia'],

            ['keys' => ['carne','res','burger','hamburguesa','pollo','cerdo','tocino','bacon','proteina','costilla'],
             'categoria' => 'Proteína / Carne', 'outlet_primario' => 'Clap Burger',
             'outlets' => ['Clap Burger - Línea de Hamburguesas', 'Olivia - Platos Fuertes del Día'],
             'aplica_oferta' => true, 'es_empaque' => false,
             'nota' => 'proteína principal — degradación acelerada en calor',
             'estrategia_rapida' => 'Lanzar Burger del Día en Clap o Plato Especial en Olivia'],

            ['keys' => ['pan','bun','brioche','bollería','almendra','bread','focaccia'],
             'categoria' => 'Panadería / Masas', 'outlet_primario' => 'Clap Burger',
             'outlets' => ['Clap Burger - Buns de Hamburguesa', 'Olivia - Panes Artesanales'],
             'aplica_oferta' => false, 'es_empaque' => false,
             'nota' => 'insumo de presentación — impacta calidad percibida',
             'estrategia_rapida' => 'Rotar como complemento de combos en Clap Burger'],

            ['keys' => ['leche','queso','yogur','crema','mantequilla','lacteo','lácteo'],
             'categoria' => 'Lácteos', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Cocina Central', 'Clap Burger - Complementos'],
             'aplica_oferta' => true, 'es_empaque' => false,
             'nota' => 'cadena de frío crítica — rotar en horas',
             'estrategia_rapida' => 'Incorporar a salsas del día o postres especiales en Olivia inmediatamente'],

            ['keys' => ['tomate','lechuga','cebolla','pimentón','aguacate','pepino','zanahoria','vegetal','ensalada','sopa'],
             'categoria' => 'Vegetales / Frescos', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Ensaladas y Guarniciones', 'Clap Burger - Toppings Frescos'],
             'aplica_oferta' => true, 'es_empaque' => false,
             'nota' => 'producto perecedero — ventana máx. 24-48h',
             'estrategia_rapida' => 'Activar como topping extra o ensalada del día en Olivia'],

            ['keys' => ['jugo','bebida','refresco','gaseosa','cerveza','vino','limonada'],
             'categoria' => 'Bebidas', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Servicio de Mesa / Bar', 'Clap Burger - Combos y Bebidas'],
             'aplica_oferta' => true, 'es_empaque' => false,
             'nota' => 'alta rotación en servicio de mesa',
             'estrategia_rapida' => 'Incluir en combo 2×1 o bebida del combo sin costo adicional'],

            // Dulces/confitería colombiana antes que la detección genérica de "salsa"
            ['keys' => ['arequipe','dulce de leche','manjar'],
             'categoria' => 'Dulces / Repostería', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Carta de Postres', 'Clap Burger - Postres y Complementos Dulces'],
             'aplica_oferta' => true, 'es_empaque' => false,
             'nota' => 'dulce colombiano — uso en postres, rellenos y decoración de repostería',
             'estrategia_rapida' => 'Incorporar en postre del día o relleno de repostería en Olivia'],

            ['keys' => ['salsa','ketchup','mostaza','mayonesa','vinagre','aceite','aderezo','condimento','chile'],
             'categoria' => 'Condimentos / Salsas', 'outlet_primario' => 'Clap Burger',
             'outlets' => ['Clap Burger - Salsas de Mesa', 'Olivia - Cocina (preparaciones)'],
             'aplica_oferta' => false, 'es_empaque' => false,
             'nota' => 'insumo de apoyo — alto volumen por uso diario en línea de producción',
             'estrategia_rapida' => 'Incrementar uso en preparaciones de fondo; no requiere acción comercial'],

            ['keys' => ['postre','helado','torta','dulce','chocolate','azúcar','azucar',
                        'galleta','brownie','mousse','flan','tiramisu','tiramisú',
                        'panna cotta','cheesecake','mermelada','caramelo','natilla'],
             'categoria' => 'Postres / Repostería', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Carta de Postres', 'Clap Burger - Menú Dulce'],
             'aplica_oferta' => true, 'es_empaque' => false,
             'nota' => 'alta demanda en festivos y fines de semana',
             'estrategia_rapida' => 'Postre del día o postre gratis al pedir plato fuerte en Olivia'],

            ['keys' => ['harina','arroz','pasta','fideos','cereal','avena','lenteja','frijol','maíz','maiz'],
             'categoria' => 'Granos / Carbohidratos', 'outlet_primario' => 'Olivia',
             'outlets' => ['Olivia - Cocina Central', 'Clap Burger - Guarniciones'],
             'aplica_oferta' => false, 'es_empaque' => false,
             'nota' => 'larga vida útil relativa — revisar si el riesgo de datos es real',
             'estrategia_rapida' => 'Incorporar como guarnición en menú del día para acelerar consumo'],
        ];

        foreach ($patrones as $p) {
            foreach ($p['keys'] as $k) {
                if (str_contains($n, $k)) {
                    return $p;
                }
            }
        }

        return [
            'categoria' => 'Insumo General', 'outlet_primario' => 'Olivia',
            'outlets' => ['Olivia', 'Clap Burger'],
            'aplica_oferta' => true, 'es_empaque' => false,
            'nota' => 'insumo operativo general',
            'estrategia_rapida' => 'Evaluar uso en preparaciones de fondo o como complemento de menú del día'
        ];
    }

    private function getEventoProximo(int $diasHorizonte): ?array
    {
        $hoy = new \DateTime();
        $fin = (new \DateTime())->modify("+{$diasHorizonte} days");

        $festivos = [
            ['mes' => 2,  'dia' => 14, 'nombre' => 'San Valentín',          'factor' => 2.5, 'descripcion' => 'Olivia con reservas completas. Oportunidad en postres y bebidas especiales.'],
            ['mes' => 5,  'dia' => 1,  'nombre' => 'Día del Trabajo',        'factor' => 1.4, 'descripcion' => 'Festivo con alto tráfico. Clap Burger supera en afluencia casual.'],
            ['mes' => 7,  'dia' => 20, 'nombre' => 'Independencia Colombia', 'factor' => 1.5, 'descripcion' => 'Festivo nacional. Alto tráfico en ambos conceptos.'],
            ['mes' => 10, 'dia' => 31, 'nombre' => 'Halloween',              'factor' => 1.8, 'descripcion' => 'Noche de alta demanda en Clap Burger. Olivia: menú temático.'],
            ['mes' => 12, 'dia' => 8,  'nombre' => 'Inmaculada Concepción',  'factor' => 1.4, 'descripcion' => 'Inicio temporada navideña. Postres y bebidas suben.'],
            ['mes' => 12, 'dia' => 24, 'nombre' => 'Nochebuena',             'factor' => 2.5, 'descripcion' => 'Pico alto. Olivia: reservas agotadas. Clap: tráfico familiar.'],
            ['mes' => 12, 'dia' => 31, 'nombre' => 'Fin de Año',             'factor' => 2.8, 'descripcion' => 'Pico máximo del año. Rotación total de bebidas y proteínas.'],
        ];

        $año = (int)$hoy->format('Y');
        foreach ($festivos as $f) {
            foreach ([$año, $año + 1] as $yr) {
                try {
                    $d = new \DateTime("{$yr}-{$f['mes']}-{$f['dia']}");
                    if ($d > $hoy && $d <= $fin) {
                        return [
                            'nombre'     => $f['nombre'],
                            'dias_hasta' => (int)$hoy->diff($d)->days,
                            'factor'     => $f['factor'],
                            'descripcion'=> $f['descripcion'],
                        ];
                    }
                } catch (\Exception $e) {}
            }
        }
        return null;
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
