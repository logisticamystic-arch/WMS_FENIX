<?php
/**
 * Fénix WMS — Performance Monitor API
 * Backend autónomo que proporciona métricas del sistema, base de datos, usuarios activos, logs y reportes de rendimiento.
 */

// Establecer cabeceras HTTP para JSON y evitar caché
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Habilitar CORS para consultas locales
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Desactivar visualización de errores HTML en producción/APIs para evitar corromper JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Cargar bootstrap del WMS para usar la conexión a la base de datos
$bootstrapPath = dirname(__DIR__) . '/bootstrap.php';
if (!file_exists($bootstrapPath)) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'No se pudo encontrar el archivo bootstrap.php del WMS.'
    ]);
    exit;
}

try {
    require_once $bootstrapPath;
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Error al inicializar la base de datos: ' . $e->getMessage()
    ]);
    exit;
}

// ── Autenticación: este endpoint estaba completamente abierto (sin token, sin
// JWT), exponiendo nombres/roles de empleados activos y el contenido crudo de
// logs/app.log a cualquiera que alcanzara la URL. Requiere MONITOR_TOKEN
// definido en .env; se envía como ?token=... o header X-Monitor-Token.
$monitorToken = getenv('MONITOR_TOKEN') ?: ($_ENV['MONITOR_TOKEN'] ?? null);
$givenToken   = $_GET['token'] ?? ($_SERVER['HTTP_X_MONITOR_TOKEN'] ?? '');
if (!$monitorToken || !is_string($givenToken) || !hash_equals($monitorToken, $givenToken)) {
    http_response_code(401);
    echo json_encode([
        'error'   => true,
        'message' => 'No autorizado. Configure MONITOR_TOKEN en .env y envíelo como ?token=... o header X-Monitor-Token.',
    ]);
    exit;
}

use Illuminate\Database\Capsule\Manager as Capsule;

// Directorio de reportes
define('REPORTS_DIR', __DIR__ . '/reports');

// Enrutador simple por parámetro "action"
$action = $_GET['action'] ?? 'metrics';

try {
    // Cron pasivo: generar reporte automáticamente si no hay uno en las últimas 2 horas
    checkAndTriggerAutoReport();

    switch ($action) {
        case 'metrics':
            getMetrics();
            break;
        case 'db_stats':
            getDbStats();
            break;
        case 'active_users':
            getActiveUsers();
            break;
        case 'logs':
            getSystemLogs();
            break;
        case 'system_stats':
            getSystemStats();
            break;
        case 'slow_requests_detail':
            getSlowRequestsDetail();
            break;
        case 'clear_metrics':
            clearMetrics();
            break;
        case 'reports_list':
            getReportsList();
            break;
        case 'get_report':
            getReportDetails();
            break;
        case 'generate_report':
            forceGenerateReport();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => true, 'message' => 'Acción no válida.']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Error ejecutando la acción: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

/**
 * Obtener métricas generales de rendimiento
 */
function getMetrics() {
    $hasMetricsTable = Capsule::schema()->hasTable('performance_metrics');
    if (!$hasMetricsTable) {
        echo json_encode([
            'has_metrics' => false,
            'message' => 'La tabla performance_metrics no existe en la base de datos.'
        ]);
        return;
    }

    $totalRequests = Capsule::table('performance_metrics')->count();
    $avgDuration = Capsule::table('performance_metrics')->avg('duracion_ms') ?? 0;
    $maxDuration = Capsule::table('performance_metrics')->max('duracion_ms') ?? 0;
    
    $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $requests24h = Capsule::table('performance_metrics')
        ->where('created_at', '>=', $yesterday)
        ->count();
    
    $avgDuration24h = Capsule::table('performance_metrics')
        ->where('created_at', '>=', $yesterday)
        ->avg('duracion_ms') ?? 0;
        
    $errors24h = Capsule::table('performance_metrics')
        ->where('created_at', '>=', $yesterday)
        ->where('status_code', '>=', 500)
        ->count();

    $slowestEndpoints = Capsule::table('performance_metrics')
        ->select([
            'endpoint_pattern',
            'metodo',
            Capsule::raw('COUNT(*) as total_llamados'),
            Capsule::raw('ROUND(AVG(duracion_ms)) as avg_duracion'),
            Capsule::raw('MAX(duracion_ms) as max_duracion'),
            Capsule::raw('ROUND(AVG(memoria_kb)) as avg_memoria')
        ])
        ->groupBy('endpoint_pattern', 'metodo')
        ->orderBy('avg_duracion', 'desc')
        ->limit(10)
        ->get();

    $statusDistribution = Capsule::table('performance_metrics')
        ->select('status_code', Capsule::raw('COUNT(*) as count'))
        ->groupBy('status_code')
        ->orderBy('count', 'desc')
        ->get();

    echo json_encode([
        'success' => true,
        'has_metrics' => true,
        'summary' => [
            'total_requests_logged' => $totalRequests,
            'avg_duration_ms' => (int)round($avgDuration),
            'max_duration_ms' => (int)$maxDuration,
            'requests_24h' => $requests24h,
            'avg_duration_24h_ms' => (int)round($avgDuration24h),
            'errors_24h' => $errors24h
        ],
        'slowest_endpoints' => $slowestEndpoints,
        'status_distribution' => $statusDistribution
    ]);
}

/**
 * Obtener estadísticas detalladas de PostgreSQL
 */
function getDbStats() {
    $driver = Capsule::connection()->getConfig('driver');
    
    if ($driver !== 'pgsql') {
        echo json_encode([
            'success' => true,
            'driver' => $driver,
            'message' => 'Métricas de base de datos detalladas solo disponibles para PostgreSQL.'
        ]);
        return;
    }

    $dbSizeQuery = Capsule::select("SELECT pg_size_pretty(pg_database_size(current_database())) as size");
    $dbSize = $dbSizeQuery[0]->size ?? 'N/A';

    $activeConnsQuery = Capsule::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'");
    $activeConns = $activeConnsQuery[0]->count ?? 0;
    
    $totalConnsQuery = Capsule::select("SELECT count(*) as count FROM pg_stat_activity");
    $totalConns = $totalConnsQuery[0]->count ?? 0;

    $cacheHitQuery = Capsule::select("
        SELECT 
            ROUND(
                (SUM(heap_blks_hit) * 100.0) / NULLIF(SUM(heap_blks_hit) + SUM(heap_blks_read), 0), 
                2
            ) as ratio 
        FROM pg_statio_user_tables
    ");
    $cacheHitRatio = $cacheHitQuery[0]->ratio ?? 100.0;

    $tableSizes = Capsule::select("
        SELECT 
            stat.relname AS table_name, 
            pg_size_pretty(pg_total_relation_size(class.oid)) AS total_size, 
            pg_size_pretty(pg_relation_size(class.oid)) AS table_size, 
            pg_size_pretty(pg_total_relation_size(class.oid) - pg_relation_size(class.oid)) AS index_size, 
            n_dead_tup AS dead_tuples 
        FROM pg_stat_user_tables stat 
        JOIN pg_class class ON stat.relid = class.oid 
        ORDER BY pg_total_relation_size(class.oid) DESC 
        LIMIT 10
    ");

    echo json_encode([
        'success' => true,
        'driver' => $driver,
        'db_size' => $dbSize,
        'connections' => [
            'active' => $activeConns,
            'total' => $totalConns
        ],
        'cache_hit_ratio' => $cacheHitRatio,
        'table_sizes' => $tableSizes
    ]);
}

/**
 * Obtener lista de usuarios activos (actividad en los últimos 5 minutos)
 */
function getActiveUsers() {
    $threshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    
    $activeUsers = Capsule::table('personal')
        ->leftJoin('empresas', 'personal.empresa_id', '=', 'empresas.id')
        ->leftJoin('sucursales', 'personal.sucursal_id', '=', 'sucursales.id')
        ->select([
            'personal.id',
            'personal.nombre',
            'personal.rol',
            'personal.ultima_actividad',
            'personal.activo',
            'empresas.razon_social as empresa_nombre',
            'sucursales.nombre as sucursal_nombre'
        ])
        ->where('personal.ultima_actividad', '>=', $threshold)
        ->orderBy('personal.ultima_actividad', 'desc')
        ->get();

    $totalUsers = Capsule::table('personal')->where('activo', true)->count();

    echo json_encode([
        'success' => true,
        'active_count' => count($activeUsers),
        'total_registered' => $totalUsers,
        'users' => $activeUsers
    ]);
}

/**
 * Retornar las últimas líneas del archivo de log de forma ultra-eficiente
 */
function getSystemLogs() {
    $logFile = dirname(__DIR__) . '/logs/app.log';
    $linesToRead = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
    
    if (!file_exists($logFile)) {
        echo json_encode([
            'success' => true,
            'log_exists' => false,
            'message' => 'El archivo logs/app.log no existe.',
            'content' => []
        ]);
        return;
    }

    $logSize = filesize($logFile);
    $formattedSize = round($logSize / 1024 / 1024, 2) . ' MB';
    $content = tailFileEfficiently($logFile, $linesToRead);

    echo json_encode([
        'success' => true,
        'log_exists' => true,
        'log_size' => $formattedSize,
        'lines_read' => count($content),
        'content' => $content
    ]);
}

/**
 * Leer el archivo de logs al revés (los registros más nuevos primero)
 */
function tailFileEfficiently($filepath, $lines = 100) {
    $f = @fopen($filepath, "rb");
    if (!$f) return [];

    fseek($f, 0, SEEK_END);
    $pos = ftell($f);
    $lineCount = 0;
    $buffer = '';

    while ($pos > 0 && $lineCount < $lines + 1) {
        $readSize = min($pos, 4096);
        $pos -= $readSize;
        fseek($f, $pos, SEEK_SET);
        $chunk = fread($f, $readSize);
        $buffer = $chunk . $buffer;
        $lineCount = substr_count($buffer, "\n");
    }

    fclose($f);

    $allLines = explode("\n", trim($buffer));
    $allLines = array_slice($allLines, -$lines);
    return array_reverse($allLines);
}

/**
 * Obtener estadísticas de consumo del sistema (disco, PHP)
 */
function getSystemStats() {
    $diskFree = @disk_free_space('.') ?: 0;
    $diskTotal = @disk_total_space('.') ?: 0;
    $diskUsed = $diskTotal - $diskFree;
    $diskUsedPct = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 2) : 0;

    echo json_encode([
        'success' => true,
        'php_version' => PHP_VERSION,
        'os' => PHP_OS,
        'memory_limit' => ini_get('memory_limit'),
        'disk' => [
            'free_pretty' => formatBytes($diskFree),
            'total_pretty' => formatBytes($diskTotal),
            'used_pretty' => formatBytes($diskUsed),
            'used_percent' => $diskUsedPct
        ],
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ]);
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Obtener log de transacciones (peticiones lentas)
 */
function getSlowRequestsDetail() {
    $hasMetricsTable = Capsule::schema()->hasTable('performance_metrics');
    if (!$hasMetricsTable) {
        echo json_encode([
            'success' => false,
            'message' => 'La tabla performance_metrics no existe.'
        ]);
        return;
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    
    $metrics = Capsule::table('performance_metrics as pm')
        ->leftJoin('personal as p', 'pm.usuario_id', '=', 'p.id')
        ->leftJoin('empresas as e', 'pm.empresa_id', '=', 'e.id')
        ->select([
            'pm.id',
            'pm.metodo',
            'pm.endpoint',
            'pm.endpoint_pattern',
            'pm.duracion_ms',
            'pm.status_code',
            'pm.memoria_kb',
            'pm.ip',
            'pm.usuario_id',
            'pm.slow_query_hint',
            'pm.created_at',
            'p.nombre as usuario_nombre',
            'e.razon_social as empresa_nombre'
        ])
        ->orderBy('pm.created_at', 'desc')
        ->limit($limit)
        ->get();

    echo json_encode([
        'success' => true,
        'count' => count($metrics),
        'metrics' => $metrics
    ]);
}

/**
 * Vaciar tabla de métricas (purgar log de transacciones)
 */
function clearMetrics() {
    $confirm = $_POST['confirm'] ?? $_GET['confirm'] ?? 'false';
    if ($confirm !== 'true') {
        http_response_code(400);
        echo json_encode([
            'error' => true,
            'message' => 'Debe confirmar explícitamente la purga de métricas.'
        ]);
        return;
    }

    try {
        Capsule::table('performance_metrics')->truncate();
        echo json_encode([
            'success' => true,
            'message' => 'Métricas de rendimiento purgadas correctamente.'
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'error' => true,
            'message' => 'Error al purgar métricas: ' . $e->getMessage()
        ]);
    }
}

// ════════════════════════════════════════════════════════════════════════
//  NUEVO SUBMÓDULO: REPORTES DE RENDIMIENTO (CADA 2 HORAS)
// ════════════════════════════════════════════════════════════════════════

/**
 * Valida si hace falta generar un reporte automáticamente (cada 2 horas)
 */
function checkAndTriggerAutoReport() {
    if (!is_dir(REPORTS_DIR)) {
        @mkdir(REPORTS_DIR, 0755, true);
    }

    $latestReport = getLatestReportFile();
    $needsNew = false;

    if (!$latestReport) {
        $needsNew = true;
    } else {
        $lastGeneratedTime = filemtime(REPORTS_DIR . '/' . $latestReport);
        // 2 horas = 7200 segundos
        if ((time() - $lastGeneratedTime) >= 7200) {
            $needsNew = true;
        }
    }

    if ($needsNew) {
        generateReportInternal();
    }
}

/**
 * Retorna el nombre del archivo de reporte más reciente
 */
function getLatestReportFile() {
    if (!is_dir(REPORTS_DIR)) return null;
    $files = glob(REPORTS_DIR . '/report_*.json');
    if (empty($files)) return null;
    rsort($files); // Ordenar alfabéticamente descendente (el más nuevo primero)
    return basename($files[0]);
}

/**
 * Acción API: Retornar lista de reportes disponibles
 */
function getReportsList() {
    if (!is_dir(REPORTS_DIR)) {
        echo json_encode(['success' => true, 'reports' => []]);
        return;
    }
    
    $files = glob(REPORTS_DIR . '/report_*.json');
    rsort($files);
    
    $reports = [];
    foreach ($files as $file) {
        $filename = basename($file);
        // Extraer fecha del nombre: report_YYYYMMDD_HHMMSS.json
        if (preg_match('/report_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})/', $filename, $m)) {
            $dateFormatted = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
        } else {
            $dateFormatted = date('Y-m-d H:i', filemtime($file));
        }
        
        $data = json_decode(file_get_contents($file), true);
        
        $reports[] = [
            'filename' => $filename,
            'date' => $dateFormatted,
            'score' => $data['score'] ?? 'N/A',
            'overall_status' => $data['overall_status'] ?? 'N/A',
            'total_requests' => $data['metrics']['total_requests_2h'] ?? 0,
            'errors_count' => $data['metrics']['errors_2h'] ?? 0,
            'avg_latency' => $data['metrics']['avg_latency_2h'] ?? 0
        ];
    }
    
    echo json_encode([
        'success' => true,
        'reports' => $reports
    ]);
}

/**
 * Acción API: Obtener detalles de un reporte específico
 */
function getReportDetails() {
    $filename = $_GET['filename'] ?? '';
    // Sanitizar nombre de archivo para evitar Path Traversal
    $filename = basename($filename);
    $path = REPORTS_DIR . '/' . $filename;
    
    if (empty($filename) || !file_exists($path)) {
        http_response_code(404);
        echo json_encode(['error' => true, 'message' => 'Reporte no encontrado.']);
        return;
    }
    
    $report = file_get_contents($path);
    echo $report; // Retorna el JSON directo del reporte guardado
}

/**
 * Acción API: Forzar la generación manual de un reporte
 */
function forceGenerateReport() {
    $reportData = generateReportInternal();
    echo json_encode([
        'success' => true,
        'message' => 'Reporte generado con éxito.',
        'report' => $reportData
    ]);
}

/**
 * Función interna para compilar métricas, detectar anomalías y guardar el reporte
 */
function generateReportInternal() {
    $hasMetricsTable = Capsule::schema()->hasTable('performance_metrics');
    
    $twoHoursAgo = date('Y-m-d H:i:s', time() - 7200);
    $now = date('Y-m-d H:i:s');
    
    // 1. Estadísticas del periodo de 2 horas
    $totalRequests = 0;
    $avgLatency = 0;
    $maxLatency = 0;
    $errorsCount = 0;
    $slowRequests = 0;
    $slowestEndpoints = [];

    if ($hasMetricsTable) {
        $totalRequests = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $twoHoursAgo)
            ->count();

        $avgLatency = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $twoHoursAgo)
            ->avg('duracion_ms') ?? 0;

        $maxLatency = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $twoHoursAgo)
            ->max('duracion_ms') ?? 0;

        $errorsCount = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $twoHoursAgo)
            ->where('status_code', '>=', 500)
            ->count();

        $slowRequests = Capsule::table('performance_metrics')
            ->where('created_at', '>=', $twoHoursAgo)
            ->where('duracion_ms', '>=', 1500)
            ->count();

        $slowestEndpoints = Capsule::table('performance_metrics')
            ->select([
                'endpoint_pattern',
                'metodo',
                Capsule::raw('COUNT(*) as total_llamados'),
                Capsule::raw('ROUND(AVG(duracion_ms)) as avg_duracion'),
                Capsule::raw('ROUND(AVG(memoria_kb)) as avg_memoria')
            ])
            ->where('created_at', '>=', $twoHoursAgo)
            ->groupBy('endpoint_pattern', 'metodo')
            ->orderBy('avg_duracion', 'desc')
            ->limit(5)
            ->get()
            ->toArray();
    }

    // 2. Analizar Logs de forma dinámica para categorizar errores recientes
    $logErrors = analyzeLogErrorsRecent($twoHoursAgo);

    // 3. Obtener estado de base de datos
    $dbSize = 'N/A';
    $activeConns = 0;
    $cacheHit = 100;
    $driver = Capsule::connection()->getConfig('driver');
    if ($driver === 'pgsql') {
        try {
            $dbSizeQuery = Capsule::select("SELECT pg_size_pretty(pg_database_size(current_database())) as size");
            $dbSize = $dbSizeQuery[0]->size ?? 'N/A';
            $activeConnsQuery = Capsule::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'");
            $activeConns = $activeConnsQuery[0]->count ?? 0;
            $cacheHitQuery = Capsule::select("SELECT ROUND((SUM(heap_blks_hit)*100.0)/NULLIF(SUM(heap_blks_hit)+SUM(heap_blks_read),0),2) as ratio FROM pg_statio_user_tables");
            $cacheHit = $cacheHitQuery[0]->ratio ?? 100.0;
        } catch (\Exception $e) {}
    }

    // 4. Detección Inteligente de Anomalías
    $anomalies = [];
    $recommendations = [];
    
    // Análisis de los 16 errores históricos de Ajustar Todo / Concurrencia
    $adjustTodoErrors = 0;
    if ($hasMetricsTable) {
        $adjustTodoErrors = Capsule::table('performance_metrics')
            ->where('endpoint', 'like', '%ajustar-todo%')
            ->where('status_code', '>=', 500)
            ->count();
    }
    
    if ($adjustTodoErrors > 0) {
        $anomalies[] = [
            'type' => 'Deadlock Transaccional / Multiclick Detectado',
            'severity' => 'Alta',
            'description' => "Se registran históricos de {$adjustTodoErrors} errores de código 500 en el endpoint masivo '/ajustar-todo'. Ocurrieron en ráfagas de 3 a 5 segundos de separación, lo que evidencia que múltiples usuarios (o un usuario haciendo clic repetidamente) enviaron la misma solicitud pesada antes de que finalizara la anterior, generando bloqueos mutuos (deadlocks) en PostgreSQL.",
            'solution' => "Bloquear el botón de ajuste masivo en el frontend ('debounce') inmediatamente al hacer clic, mostrando un cargador ('spinner') para impedir segundas peticiones concurrentes."
        ];
        $recommendations[] = "Implementar bloqueo de UI ('debounce' de 5s) en los botones de envío masivo de inventarios.";
    }

    // Anomalía: Tasa de error en las últimas 2 horas
    if ($totalRequests > 0) {
        $errorRate = ($errorsCount / $totalRequests) * 100;
        if ($errorRate > 5) {
            $anomalies[] = [
                'type' => 'Tasa de Errores Elevada',
                'severity' => 'Crítica',
                'description' => "El servidor ha registrado una tasa de error del " . round($errorRate, 1) . "% (un total de {$errorsCount} errores en {$totalRequests} peticiones) en las últimas 2 horas.",
                'solution' => "Revisar los registros detallados de logs para solucionar problemas de bases de datos o excepciones de código."
            ];
            $recommendations[] = "Inspeccionar las excepciones activas para mitigar errores del servidor (5xx).";
        }
    }

    // Anomalía: Tiempos de respuesta lentos
    if ($avgLatency > 1500) {
        $anomalies[] = [
            'type' => 'Latencia de API Degradada',
            'severity' => 'Media',
            'description' => "El promedio de tiempo de respuesta general es de " . round($avgLatency) . " ms en las últimas 2 horas, superando el umbral óptimo de 1500 ms.",
            'solution' => "Optimizar consultas SQL pesadas mediante la creación de índices compuestos y evitar bucles N+1 en Eloquent."
        ];
        $recommendations[] = "Crear índices de bases de datos en tablas clave (movimiento_inventarios, inventarios).";
    }

    // Errores específicos de logs parseados
    foreach ($logErrors as $logErr) {
        if ($logErr['count'] > 0) {
            $anomalies[] = [
                'type' => 'Error de Sistema: ' . $logErr['type'],
                'severity' => 'Media',
                'description' => "Se registraron {$logErr['count']} incidencias en las últimas 2 horas del tipo: '{$logErr['message']}'.",
                'solution' => $logErr['solution']
            ];
            $recommendations[] = $logErr['action'];
        }
    }

    // 5. Calcular Calificación ('Score') del sistema
    $score = 'A';
    $status = 'Saludable';
    
    if ($errorsCount > 10 || (isset($errorRate) && $errorRate > 10)) {
        $score = 'F';
        $status = 'Crítico';
    } elseif ($errorsCount > 0 || (isset($errorRate) && $errorRate > 2)) {
        $score = 'C';
        $status = 'Advertencia';
    } elseif ($avgLatency > 1000 || $slowRequests > 5) {
        $score = 'B';
        $status = 'Optimizable';
    }

    // Estructurar el reporte final
    $reportData = [
        'timestamp' => $now,
        'period' => [
            'start' => $twoHoursAgo,
            'end' => $now
        ],
        'score' => $score,
        'overall_status' => $status,
        'metrics' => [
            'total_requests_2h' => $totalRequests,
            'avg_latency_2h' => (int)round($avgLatency),
            'max_latency_2h' => (int)$maxLatency,
            'errors_2h' => $errorsCount,
            'slow_requests_2h' => $slowRequests,
            'db_size' => $dbSize,
            'db_active_connections' => $activeConns,
            'db_cache_hit_ratio' => $cacheHit
        ],
        'slowest_endpoints_2h' => $slowestEndpoints,
        'anomalies' => $anomalies,
        'recommendations' => array_values(array_unique($recommendations))
    ];

    // Escribir reporte en archivo JSON
    $filename = 'report_' . date('Ymd_His') . '.json';
    @file_put_contents(REPORTS_DIR . '/' . $filename, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $reportData;
}

/**
 * Escanea de forma eficiente el log para extraer errores en un rango de fecha
 */
function analyzeLogErrorsRecent($sinceDateTimeStr) {
    $logFile = dirname(__DIR__) . '/logs/app.log';
    if (!file_exists($logFile)) return [];

    $sinceTs = strtotime($sinceDateTimeStr);
    
    // Categorías de error predefinidas
    $dbColumnErrorCount = 0;
    $methodNotAllowedCount = 0;
    $sampleDbError = '';
    $sampleMethodError = '';

    // Leer últimas 1000 líneas para análisis de logs (eficiente, no bloquea memoria)
    $lines = tailFileEfficiently($logFile, 1000);
    
    foreach ($lines as $line) {
        // Formato estándar: [2026-07-10 12:31:33] [ERROR] ...
        if (preg_match('/^\[([\d\-:\s]+)\]\s+\[(ERROR|FATAL)\]\s+(.*)$/', $line, $m)) {
            $logTs = strtotime($m[1]);
            if ($logTs >= $sinceTs) {
                $message = $m[3];
                if (str_contains($message, 'Undefined column') || str_contains($message, 'no existe la columna')) {
                    $dbColumnErrorCount++;
                    $sampleDbError = $message;
                } elseif (str_contains($message, 'Method not allowed') || str_contains($message, 'HttpMethodNotAllowedException')) {
                    $methodNotAllowedCount++;
                    $sampleMethodError = $message;
                }
            }
        }
    }

    $results = [];
    if ($dbColumnErrorCount > 0) {
        $results[] = [
            'type' => 'Base de Datos Desincronizada (Columna Faltante)',
            'count' => $dbColumnErrorCount,
            'message' => $sampleDbError,
            'solution' => 'Ejecutar las migraciones pendientes en la base de datos para sincronizar el esquema con el código.',
            'action' => 'Ejecutar migraciones en el servidor (ej: run_migrations.php).'
        ];
    }
    if ($methodNotAllowedCount > 0) {
        $results[] = [
            'type' => 'Llamado de API con Método Incorrecto',
            'count' => $methodNotAllowedCount,
            'message' => $sampleMethodError,
            'solution' => 'Verificar las llamadas en el cliente web/móvil para asegurar que utilicen el verbo HTTP adecuado (GET, POST, PUT, DELETE).',
            'action' => 'Asegurar consistencia de verbos HTTP en peticiones AJAX desde el JS.'
        ];
    }

    return $results;
}
