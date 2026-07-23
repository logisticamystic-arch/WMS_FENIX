<?php
/**
 * fenix WMS — API Entry Point
 * Slim Framework 4 with Eloquent ORM
 */

// Buffer ALL output so stray PHP errors/notices never corrupt the JSON response.
ob_start();
date_default_timezone_set('America/Bogota');

// Suppress PHP error output in HTTP responses — errors must not corrupt JSON bodies.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// ── Log de errores en archivo ─────────────────────────────────────────────────
$logFile = dirname(__DIR__) . '/logs/app.log';
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
error_reporting(E_ALL);

// Interceptar Fatal Errors (incluyendo Syntax Errors) para evitar HTML en respuestas JSON
register_shutdown_function(function() use ($logFile) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Limpiar cualquier output corrupto o sucio
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: application/json');
        
        $msg = "FATAL ERROR / SYNTAX ERROR: " . $error['message'] . " en " . $error['file'] . ":" . $error['line'];
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$ts}] [FATAL_SHUTDOWN] {$msg}" . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        echo json_encode([
            'error'   => true,
            'message' => 'Error crítico de código o sintaxis detectado. La plataforma fue protegida. Consulte logs/app.log para revisar el origen exacto.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// Helper global para escribir al log con contexto
function wmsLog(string $level, string $message, array $context = []): void {
    global $logFile;
    $ts      = date('Y-m-d H:i:s');
    $ctx     = empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $line    = "[{$ts}] [{$level}] {$message}{$ctx}" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// Reset opcache in development OR when running on localhost (XAMPP)
$_appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production');
$_appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
$_isLocal = $_appEnv === 'development'
    || strpos($_appUrl, 'localhost') !== false
    || strpos($_appUrl, '127.0.0.1') !== false
    || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
    || ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1';

if (function_exists('opcache_reset') && $_isLocal) {
    opcache_reset();
}
unset($_appEnv, $_appUrl, $_isLocal);

require_once __DIR__ . '/../bootstrap.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;

$app = AppFactory::create();

// Set base path for XAMPP subdirectory
$app->setBasePath('/WMS_FENIX/public');

// ── Custom error middleware: logs to file + returns JSON ──────────────────────
$errorMiddleware = $app->addErrorMiddleware(
    filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN),
    true,
    true
);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $method = $request->getMethod();
    $uri    = (string)$request->getUri();
    $msg    = $exception->getMessage();
    $trace  = $exception->getTraceAsString();

    wmsLog('ERROR', "{$method} {$uri} — {$msg}", [
        'file'  => $exception->getFile() . ':' . $exception->getLine(),
        'class' => get_class($exception),
    ]);
    wmsLog('TRACE', $trace);

    // Dotenv uses $_ENV, not putenv — read from there
    $isDebug = filter_var(
        $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'false',
        FILTER_VALIDATE_BOOLEAN
    );

    // Use the HTTP status code from HttpException subclasses (404, 405, etc.)
    $status = ($exception instanceof \Slim\Exception\HttpException) ? $exception->getCode() : 500;

    if ($status === 405) {
        $payload = ['error' => true, 'message' => 'Método HTTP no permitido para esta ruta.'];
    } elseif ($status === 404) {
        $payload = ['error' => true, 'message' => 'Ruta no encontrada.'];
    } else {
        $payload = [
            'error'   => true,
            'message' => $isDebug
                ? "Error interno: {$msg}"
                : 'Error interno del servidor. Revise logs/app.log para más detalles.',
        ];
        if ($isDebug) {
            $payload['detail'] = [
                'class' => get_class($exception),
                'file'  => $exception->getFile() . ':' . $exception->getLine(),
            ];
        }
    }

    $response = $app->getResponseFactory()->createResponse($status);
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Rotación de logs: se ejecuta al final del proceso, fuera del hot path ─────
\App\Controllers\BaseController::scheduleLogRotation();

// ── Cabeceras de seguridad globales ───────────────────────────────────────────
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('X-Content-Type-Options',  'nosniff')
        ->withHeader('X-Frame-Options',          'DENY')
        ->withHeader('Referrer-Policy',          'strict-origin-when-cross-origin');
});

// ── Monitoreo de rendimiento (registra requests > 1500ms) ────────────────────
$app->add(new \App\Middleware\PerformanceMiddleware(1500));

// Add JSON body parsing middleware
$app->addBodyParsingMiddleware();

// ── DIAGNÓSTICO DE APROBACIONES ──────────────────────────────────────────────
// GET /wms-diagnostico-aprobacion — Sin JWT. Detecta qué impide aprobar pallets/líneas/ODC.
$app->get('/wms-diagnostico-aprobacion', function (Request $request, Response $response) {
    $resultado = [];
    $errores   = [];

    // ── 1. COLUMNAS EN BASE DE DATOS ──────────────────────────────────────────
    $columnasRequeridas = [
        'recepciones'          => ['odc_id', 'aprobado_admin'],
        'recepcion_detalles'   => ['aprobado_admin', 'novedad_observacion', 'cantidad_novedad', 'ubicacion_destino_id'],
        'orden_compra_detalles'=> ['aprobado_admin', 'novedad_motivo', 'novedad_observacion', 'cantidad_novedad'],
        'inventarios'          => ['estado', 'empresa_id', 'sucursal_id', 'producto_id', 'ubicacion_id', 'cantidad'],
    ];

    $colCheck = [];
    foreach ($columnasRequeridas as $tabla => $cols) {
        foreach ($cols as $col) {
            $existe = \Illuminate\Database\Capsule\Manager::schema()->hasColumn($tabla, $col);
            $colCheck[$tabla][$col] = $existe ? 'OK' : 'FALTA';
            if (!$existe) {
                $errores[] = "COLUMNA FALTANTE: {$tabla}.{$col} — Ejecute migration 038/041.";
            }
        }
    }
    $resultado['1_columnas_db'] = $colCheck;

    // ── 2. ENUM VALORES EN ordenes_compra.estado ─────────────────────────────
    try {
        $enumRow = \Illuminate\Database\Capsule\Manager::select(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'ordenes_compra'
               AND COLUMN_NAME  = 'estado'"
        );
        $enumStr = $enumRow[0]->COLUMN_TYPE ?? '';
        $tieneEnProceso  = str_contains($enumStr, 'En Proceso');
        $tieneConfirmada = str_contains($enumStr, 'Confirmada');
        $resultado['2_enum_ordenes_compra'] = [
            'definicion'      => $enumStr,
            'tiene_Confirmada'=> $tieneConfirmada ? 'SI' : 'NO — FALTA',
            'tiene_EnProceso' => $tieneEnProceso  ? 'SI' : 'NO — Ejecute migration 042',
        ];
        if (!$tieneEnProceso) {
            $errores[] = "ENUM: ordenes_compra.estado no tiene 'En Proceso'. Ejecute migration 042.";
        }
    } catch (\Exception $e) {
        $resultado['2_enum_ordenes_compra'] = 'Error: ' . $e->getMessage();
        $errores[] = 'No se pudo leer enum de ordenes_compra: ' . $e->getMessage();
    }

    // ── 3. ENUM VALORES EN citas.estado ──────────────────────────────────────
    try {
        $enumCita = \Illuminate\Database\Capsule\Manager::select(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'citas'
               AND COLUMN_NAME  = 'estado'"
        );
        $enumCitaStr   = $enumCita[0]->COLUMN_TYPE ?? '';
        $tieneEnPatio  = str_contains($enumCitaStr, 'EnPatio');
        $resultado['3_enum_citas'] = [
            'definicion'    => $enumCitaStr,
            'tiene_EnPatio' => $tieneEnPatio ? 'SI' : 'NO — Ejecute migration 042',
        ];
        if (!$tieneEnPatio) {
            $errores[] = "ENUM: citas.estado no tiene 'EnPatio'. Ejecute migration 042.";
        }
    } catch (\Exception $e) {
        $resultado['3_enum_citas'] = 'Error: ' . $e->getMessage();
    }

    // ── 4. VERIFICAR SINTAXIS PHP DE ARCHIVOS CRÍTICOS ────────────────────────
    $archivosVerificar = [
        'RecepcionController'    => __DIR__ . '/../src/Controllers/RecepcionController.php',
        'InboundController'      => __DIR__ . '/../src/Controllers/InboundController.php',
        'RecepcionDetalle (Model)'=> __DIR__ . '/../src/Models/RecepcionDetalle.php',
        'OrdenCompraDetalle (Model)'=> __DIR__ . '/../src/Models/OrdenCompraDetalle.php',
        'Recepcion (Model)'      => __DIR__ . '/../src/Models/Recepcion.php',
        'OrdenCompra (Model)'    => __DIR__ . '/../src/Models/OrdenCompra.php',
    ];

    $sintaxis = [];
    foreach ($archivosVerificar as $nombre => $ruta) {
        if (!file_exists($ruta)) {
            $sintaxis[$nombre] = 'ARCHIVO NO EXISTE: ' . $ruta;
            $errores[] = "ARCHIVO FALTANTE: {$ruta}";
            continue;
        }
        $output = [];
        $code   = 0;
        exec('php -l ' . escapeshellarg($ruta) . ' 2>&1', $output, $code);
        $outStr = implode(' ', $output);
        if ($code === 0) {
            $sintaxis[$nombre] = 'OK';
        } else {
            $sintaxis[$nombre] = 'ERROR SINTAXIS: ' . $outStr;
            $errores[] = "SINTAXIS PHP: {$nombre} — {$outStr}";
        }
    }
    $resultado['4_sintaxis_php'] = $sintaxis;

    // ── 5. DATOS DE MUESTRA (conteos) ─────────────────────────────────────────
    try {
        $resultado['5_conteos_datos'] = [
            'ordenes_compra'       => \Illuminate\Database\Capsule\Manager::table('ordenes_compra')->count(),
            'orden_compra_detalles'=> \Illuminate\Database\Capsule\Manager::table('orden_compra_detalles')->count(),
            'recepciones'          => \Illuminate\Database\Capsule\Manager::table('recepciones')->count(),
            'recepcion_detalles'   => \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')->count(),
            'inventarios_en_patio' => \Illuminate\Database\Capsule\Manager::table('inventarios')->where('estado', 'En Patio')->count(),
            'inventarios_disponible'=> \Illuminate\Database\Capsule\Manager::table('inventarios')->where('estado', 'Disponible')->count(),
        ];
    } catch (\Exception $e) {
        $resultado['5_conteos_datos'] = 'Error: ' . $e->getMessage();
        $errores[] = 'Error leyendo conteos: ' . $e->getMessage();
    }

    // ── 6. MUESTRA UN RecepcionDetalle PARA VER SUS COLUMNAS REALES ──────────
    try {
        $detalle = \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')->first();
        if ($detalle) {
            $cols = array_keys((array)$detalle);
            $resultado['6_recepcion_detalle_columnas_reales'] = $cols;
            $tieneAprobado = in_array('aprobado_admin', $cols);
            if (!$tieneAprobado) {
                $errores[] = 'recepcion_detalles.aprobado_admin NO existe en la tabla real de la BD (migration 038 no corrió).';
            }
        } else {
            $resultado['6_recepcion_detalle_columnas_reales'] = 'Tabla vacía — sin datos para verificar';
        }
    } catch (\Exception $e) {
        $resultado['6_recepcion_detalle_columnas_reales'] = 'Error: ' . $e->getMessage();
    }

    // ── 7. SIMULAR CONSULTA DE aprobarLineaODC (sin escribir nada) ───────────
    try {
        // Buscar un OrdenCompraDetalle cualquiera
        $odcDet = \Illuminate\Database\Capsule\Manager::table('orden_compra_detalles')
            ->select(['id', 'orden_compra_id', 'producto_id', 'aprobado_admin'])
            ->first();

        if ($odcDet) {
            $resultado['7_test_aprobarLineaODC'] = [
                'odc_detalle_encontrado' => true,
                'id'         => $odcDet->id,
                'aprobado_admin_valor' => $odcDet->aprobado_admin,
                'paso_siguiente' => 'Buscar Recepcion con odc_id = ' . $odcDet->orden_compra_id,
            ];

            // Verificar que se puede buscar recepciones por odc_id
            $recepcionIds = \Illuminate\Database\Capsule\Manager::table('recepciones')
                ->where('odc_id', $odcDet->orden_compra_id)
                ->pluck('id');
            $resultado['7_test_aprobarLineaODC']['recepciones_vinculadas'] = $recepcionIds->count();

            if ($recepcionIds->count() > 0) {
                $drCount = \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')
                    ->whereIn('recepcion_id', $recepcionIds)
                    ->where('producto_id', $odcDet->producto_id)
                    ->count();
                $resultado['7_test_aprobarLineaODC']['pallets_encontrados'] = $drCount;
            }
        } else {
            $resultado['7_test_aprobarLineaODC'] = 'No hay OrdenCompraDetalles en la BD — cree una ODC primero.';
        }
    } catch (\Exception $e) {
        $resultado['7_test_aprobarLineaODC'] = 'ERROR: ' . $e->getMessage();
        $errores[] = 'Error simulando aprobarLineaODC: ' . $e->getMessage();
    }

    // ── 8. SIMULAR CONSULTA DE aprobarDetalle (pallet) ────────────────────────
    try {
        $rdRow = \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')
            ->select(['id', 'recepcion_id', 'producto_id', 'aprobado_admin', 'ubicacion_destino_id'])
            ->first();

        if ($rdRow) {
            $resultado['8_test_aprobarDetallePallet'] = [
                'recepcion_detalle_encontrado' => true,
                'id'                 => $rdRow->id,
                'aprobado_admin'     => $rdRow->aprobado_admin,
                'ubicacion_destino_id'=> $rdRow->ubicacion_destino_id ?? 'NULL',
            ];

            // Verificar que la relación recepcion->empresa_id es accesible
            $rec = \Illuminate\Database\Capsule\Manager::table('recepciones')
                ->select(['id', 'empresa_id', 'sucursal_id'])
                ->where('id', $rdRow->recepcion_id)
                ->first();
            $resultado['8_test_aprobarDetallePallet']['recepcion_padre'] = $rec
                ? ['empresa_id' => $rec->empresa_id, 'sucursal_id' => $rec->sucursal_id]
                : 'Recepción padre no encontrada';
        } else {
            $resultado['8_test_aprobarDetallePallet'] = 'No hay RecepcionDetalles en la BD.';
        }
    } catch (\Exception $e) {
        $resultado['8_test_aprobarDetallePallet'] = 'ERROR: ' . $e->getMessage();
        $errores[] = 'Error simulando aprobarDetalle: ' . $e->getMessage();
    }

    // ── 9. RESUMEN ────────────────────────────────────────────────────────────
    $resultado['9_resumen'] = [
        'total_errores' => count($errores),
        'errores'       => $errores,
        'estado_general'=> empty($errores) ? '✓ Sin problemas detectados — revisa el log de la app (logs/app.log) para errores en tiempo de ejecución.' : '✗ Se encontraron ' . count($errores) . ' problema(s). Ver detalle arriba.',
    ];

    ob_clean();
    $response->getBody()->write(json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── OPTIONS pre-flight handler ────────────────────────────────────────────────
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// ── CORS + Security headers ───────────────────────────────────────────────────
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);

    // Determine allowed origin
    $allowedOrigins = array_filter(array_map(
        'trim',
        explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '*')
    ));
    $origin = $request->getHeaderLine('Origin');
    $allowOrigin = '*'; // fallback

    if ($origin && !in_array('*', $allowedOrigins)) {
        $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '';
    } elseif (in_array('*', $allowedOrigins)) {
        $allowOrigin = '*';
    }

    $headers = [
        'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-API-Key',
        'Access-Control-Allow-Credentials' => 'true',
        // Security headers
        'X-Content-Type-Options'           => 'nosniff',
        'X-Frame-Options'                  => 'DENY',
        'Referrer-Policy'                  => 'strict-origin-when-cross-origin',
        'X-XSS-Protection'                 => '1; mode=block',
    ];

    if ($allowOrigin) {
        $headers['Access-Control-Allow-Origin'] = $allowOrigin;
        if ($allowOrigin !== '*') {
            $headers['Vary'] = 'Origin';
        }
    }

    foreach ($headers as $name => $value) {
        $response = $response->withHeader($name, $value);
    }

    return $response;
});

// ── Health Check (public) ─────────────────────────────────────────────────────
$app->get('/api/health', function (Request $request, Response $response) {
    $data = [
        'status'    => 'ok',
        'app'       => 'fenix WMS',
        'version'   => '1.1.0',
        'env'       => getenv('APP_ENV') ?: 'production',
        'timestamp' => date('Y-m-d H:i:s'),
    ];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── PWA Route (serve static HTML) ────────────────────────────────────────────
$app->get('/', function (Request $request, Response $response) {
    $html = file_get_contents(__DIR__ . '/index.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// ── Auth (public) ─────────────────────────────────────────────────────────────
$app->post('/api/auth/login', [\App\Controllers\AuthController::class, 'login']);
$app->get('/api/auth/empresas', [\App\Controllers\ParametrosController::class, 'getEmpresas']);

// ── Authenticated API routes ──────────────────────────────────────────────────
$app->group('/api', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('/auth/me',  [\App\Controllers\AuthController::class, 'me']);
    $group->put('/auth/pin', [\App\Controllers\AuthController::class, 'cambiarPin']);

    // Módulo: Citas (Inbound)
    $group->get('/citas', [\App\Controllers\CitaController::class, 'index']);
    $group->post('/citas', [\App\Controllers\CitaController::class, 'store']);
    $group->put('/citas/{id}', [\App\Controllers\CitaController::class, 'update']);
    $group->delete('/citas/{id}', [\App\Controllers\CitaController::class, 'destroy']);
    $group->get('/citas/disponibilidad', [\App\Controllers\CitaController::class, 'getDisponibilidad']);
    $group->post('/citas/{id}/llegada', [\App\Controllers\CitaController::class, 'marcarLlegada']);
    $group->post('/citas/{id}/completar', [\App\Controllers\CitaController::class, 'completarYMS']);

    // Módulo: Recepción (Inbound)
    $group->get('/recepciones/proximo-pallet', [\App\Controllers\RecepcionController::class, 'getProximoPallet']);
    $group->get('/recepcion/kpis', [\App\Controllers\RecepcionController::class, 'kpis']);
    $group->get('/recepcion/control-panel', [\App\Controllers\RecepcionController::class, 'getControlPanelData']);
    $group->get('/recepciones', [\App\Controllers\RecepcionController::class, 'index']);
    $group->post('/recepciones', [\App\Controllers\RecepcionController::class, 'store']);
    $group->get('/recepciones/buscar-qr', [\App\Controllers\RecepcionController::class, 'buscarProductoPorQr']);
    $group->post('/recepciones/detalles-operativa', [\App\Controllers\RecepcionController::class, 'detallesOperativa']);
    $group->post('/recepciones/sin-odc', [\App\Controllers\RecepcionController::class, 'detallesOperativaSinOdc']);
    $group->get('/recepciones/{id}', [\App\Controllers\RecepcionController::class, 'ver']);
    $group->post('/recepciones/{id}/detalle', [\App\Controllers\RecepcionController::class, 'addDetail']);
    $group->patch('/recepciones/{id}/detalle/{detalleId}', [\App\Controllers\RecepcionController::class, 'actualizarDetalleSinOdc']);
    $group->delete('/recepciones/{id}/detalle/{detalleId}', [\App\Controllers\RecepcionController::class, 'eliminarDetalleSinOdc']);
    $group->post('/recepciones/{id}/cerrar', [\App\Controllers\RecepcionController::class, 'confirm']);
    $group->delete('/recepciones/detalle/{id}', [\App\Controllers\RecepcionController::class, 'eliminarDetalle']);
    $group->put('/recepcion-detalle/{id}', [\App\Controllers\RecepcionController::class, 'actualizarDetalle']);
    $group->delete('/recepciones/{id}', [\App\Controllers\RecepcionController::class, 'eliminar']);
    $group->get('/recepcion/dashboard', [\App\Controllers\RecepcionController::class, 'index']);
    $group->get('/recepcion/dashboard/{id}', [\App\Controllers\RecepcionController::class, 'detalle']);
    $group->get('/recepcion/analytics/{id}', [\App\Controllers\RecepcionController::class, 'getOdcAnalytics']);

    // Dashboard de Control de Recepción
    $group->post('/recepcion/control-panel/odc/linea/{id}/aprobar', [\App\Controllers\RecepcionController::class, 'aprobarLinea']);
    $group->post('/recepcion/control-panel/odc/{id}/linea', [\App\Controllers\RecepcionController::class, 'agregarLinea']);
    $group->put('/recepcion/control-panel/odc/linea/{id}', [\App\Controllers\RecepcionController::class, 'editarLinea']);
    $group->delete('/recepcion/control-panel/odc/linea/{id}', [\App\Controllers\RecepcionController::class, 'eliminarLinea']);

    // Módulo: Orden de Compra (ODC)
    $group->get('/odc', [\App\Controllers\InboundController::class, 'getOrdenesCompra']);
    $group->post('/odc', [\App\Controllers\InboundController::class, 'createOrdenCompra']);
    $group->post('/odc/importar', [\App\Controllers\InboundController::class, 'importarODC']);
    $group->get('/odc/buscar-producto', [\App\Controllers\InboundController::class, 'buscarProducto']);
    $group->get('/odc/{id}', [\App\Controllers\InboundController::class, 'getODC']);
    $group->get('/odc/{id}/exportar', [\App\Controllers\InboundController::class, 'exportarODC']);
    $group->put('/odc/{id}', [\App\Controllers\InboundController::class, 'updateOrdenCompra']);
    $group->post('/odc/{id}/confirmar', [\App\Controllers\InboundController::class, 'confirmarOrdenCompra']);
    $group->post('/odc/{id}/cerrar', [\App\Controllers\InboundController::class, 'cerrarOrdenCompra']);
    $group->post('/odc/{id}/reabrir', [\App\Controllers\InboundController::class, 'reabrirOrdenCompra']);
    $group->post('/odc/{id}/asignar', [\App\Controllers\InboundController::class, 'asignarAuxiliar']);
    $group->delete('/odc/{id}', [\App\Controllers\InboundController::class, 'deleteOrdenCompra']);
    $group->post('/odc/{id}/iniciar', [\App\Controllers\InboundController::class, 'iniciarReciboODC']);
    $group->get('/odc/{id}/verificar-ean', [\App\Controllers\InboundController::class, 'verificarEanODC']);
    $group->post('/odc/detalle/{id}/aprobar', [\App\Controllers\InboundController::class, 'aprobarLineaODC']);
    $group->post('/odc/{id}/aprobar-todo', [\App\Controllers\InboundController::class, 'aprobarODCTodo']);
    $group->get('/odc/{id}/imprimir', [\App\Controllers\InboundController::class, 'imprimirRecibo']);

    // Módulo: Certificaciones (Outbound)
    $group->get('/certificaciones/reporte', [\App\Controllers\OutboundController::class, 'getCertificacionesReport']);
    $group->post('/certificaciones/start', [\App\Controllers\OutboundController::class, 'startCertificacion']);
    $group->post('/certificaciones/{id}/linea', [\App\Controllers\OutboundController::class, 'addCertificacionLinea']);
    $group->post('/certificaciones/{id}/end', [\App\Controllers\OutboundController::class, 'endCertificacion']);

    // Módulo: Devoluciones
    $group->get('/devoluciones/causales',         [\App\Controllers\DevolucionController::class, 'getCausales']);
    $group->post('/devoluciones/causales',        [\App\Controllers\DevolucionController::class, 'createCausal']);
    $group->put('/devoluciones/causales/{id}',    [\App\Controllers\DevolucionController::class, 'updateCausal']);
    $group->get('/devoluciones/dashboard',        [\App\Controllers\DevolucionController::class, 'getDashboard']);
    $group->get('/devoluciones', [\App\Controllers\DevolucionController::class, 'index']);
    $group->post('/devoluciones', [\App\Controllers\DevolucionController::class, 'store']);
    $group->post('/devoluciones/desde-odc', [\App\Controllers\DevolucionController::class, 'desdeOdcMovil']);
    $group->get('/devoluciones/odc/{odcId}', [\App\Controllers\DevolucionController::class, 'getByOdc']);
    $group->get('/devoluciones/resumen/proveedor/{proveedor_id}', [\App\Controllers\DevolucionController::class, 'resumenProveedor']);
    $group->get('/devoluciones/{id}', [\App\Controllers\DevolucionController::class, 'ver']);
    $group->post('/devoluciones/{id}/aprobar',  [\App\Controllers\DevolucionController::class, 'aprobar']);
    $group->post('/devoluciones/{id}/rechazar', [\App\Controllers\DevolucionController::class, 'rechazar']);
    $group->post('/devoluciones/{id}/procesar', [\App\Controllers\DevolucionController::class, 'procesar']);
    $group->post('/devoluciones/{id}/anular',   [\App\Controllers\DevolucionController::class, 'anular']);
    $group->delete('/devoluciones/{id}', [\App\Controllers\DevolucionController::class, 'eliminar']);

    // Módulo: Inventario
    $group->group('/inventario', function(\Slim\Routing\RouteCollectorProxy $g) {
        $g->get('/stock', [\App\Controllers\InventarioController::class, 'getStock']);
        $g->get('/conteos', [\App\Controllers\InventarioController::class, 'getConteos']);
        $g->get('/conteo/{id}/dashboard', [\App\Controllers\InventarioController::class, 'getDashboardData']);
        $g->post('/conteo/nuevo', [\App\Controllers\InventarioController::class, 'crearConteo']);
        $g->post('/conteo/{id}/linea', [\App\Controllers\InventarioController::class, 'addLineaConteo']);
        $g->post('/conteo/{id}/finalizar-ronda', [\App\Controllers\InventarioController::class, 'finalizarRonda']);
        $g->post('/conteo/{id}/finalizar', [\App\Controllers\InventarioController::class, 'finalizarConteo']);
        $g->post('/conteo/{id}/auxiliares', [\App\Controllers\InventarioController::class, 'syncAuxiliares']);
        $g->get('/niveles-reposicion', [\App\Controllers\InventarioController::class, 'getNivelesReposicion']);
        $g->post('/niveles-reposicion', [\App\Controllers\InventarioController::class, 'saveNivelReposicion']);
        $g->post('/ajuste', [\App\Controllers\InventarioController::class, 'ajusteManual']);
        // ── Ajuste x Ubicación (flujo mobile → aprobación desktop) ─────────────
        $g->get('/ajuste-ubicacion',                 [\App\Controllers\AjusteUbicacionController::class, 'listar']);
        $g->post('/ajuste-ubicacion',                [\App\Controllers\AjusteUbicacionController::class, 'crear']);
        $g->get('/ajuste-ubicacion/{id}',            [\App\Controllers\AjusteUbicacionController::class, 'detalle']);
        $g->post('/ajuste-ubicacion/{id}/aprobar',   [\App\Controllers\AjusteUbicacionController::class, 'aprobar']);
        $g->post('/ajuste-ubicacion/{id}/rechazar',  [\App\Controllers\AjusteUbicacionController::class, 'rechazar']);
        $g->get('/dashboard', [\App\Controllers\InventarioController::class, 'getDashboard']);
        $g->get('/ubicaciones-en-cero', [\App\Controllers\InventarioController::class, 'getUbicacionesEnCero']);
        $g->get('/kardex', [\App\Controllers\InventarioController::class, 'getKardex']);
        $g->get('/mapa-detallado', [\App\Controllers\InventarioController::class, 'getMapaDetallado']);
        $g->post('/vaciar-ubicacion',             [\App\Controllers\InventarioController::class, 'vaciarUbicacion']);
        $g->post('/cargue-inicial/linea',        [\App\Controllers\InventarioController::class, 'agregarLineaCargue']);
        $g->post('/cargue-inicial/aprobar-todo',  [\App\Controllers\InventarioController::class, 'aprobarTodoCargue']);
        $g->post('/cargue-inicial/{id}/aprobar',  [\App\Controllers\InventarioController::class, 'aprobarLineaCargue']);
        $g->delete('/cargue-inicial/{id}',        [\App\Controllers\InventarioController::class, 'eliminarLineaCargue']);
        $g->get('/cargue-inicial/pendientes',     [\App\Controllers\InventarioController::class, 'getLineasPendientes']);
        $g->get('/cargue-inicial',                [\App\Controllers\InventarioController::class, 'getCargueInicialKardex']);
        $g->post('/reconciliar',                  [\App\Controllers\InventarioController::class, 'reconciliar']);
    });

    // Ruta directa para /api/inventario/traslado
    $group->post('/inventario/traslado', [\App\Controllers\InventarioController::class, 'traslado']);
    $group->get('/inv-general/eventos',           [\App\Controllers\InventarioController::class, 'getEventos']);
    $group->post('/inv-general/eventos',          [\App\Controllers\InventarioController::class, 'crearEvento']);
    $group->post('/inv-general/asignaciones', [\App\Controllers\InventarioController::class, 'crearAsignacion']);
    $group->post('/inv-general/conteo', [\App\Controllers\InventarioController::class, 'registrarConteo']);
    $group->get('/inv-general/eventos/{id}/acta', [\App\Controllers\InventarioController::class, 'getActaHtml']);

    // ════════════════════════════════════════════════════════════════════════
    // MÓDULO INVENTARIOS V2 — Cíclico / General / Ajustes / Kardex / Vencimientos
    // ════════════════════════════════════════════════════════════════════════

    // ── Sesiones de inventario ──────────────────────────────────────────────
    $group->get('/v2/inventario/sesiones',                  [\App\Controllers\InventarioV2Controller::class, 'getSesiones']);
    $group->post('/v2/inventario/sesiones',                 [\App\Controllers\InventarioV2Controller::class, 'crearSesion']);
    $group->get('/v2/inventario/sesiones/{id}',             [\App\Controllers\InventarioV2Controller::class, 'getSesion']);
    $group->put('/v2/inventario/sesiones/{id}/iniciar',     [\App\Controllers\InventarioV2Controller::class, 'iniciarSesion']);
    $group->get('/v2/inventario/sesiones/{id}/dashboard',   [\App\Controllers\InventarioV2Controller::class, 'getDashboard']);
    $group->get('/v2/inventario/sesiones/{id}/reporte',     [\App\Controllers\InventarioV2Controller::class, 'getReporteConteo']);

    // ── Ajustes desde el dashboard ─────────────────────────────────────────
    $group->post('/v2/inventario/sesiones/{id}/ajustar-linea', [\App\Controllers\InventarioV2Controller::class, 'ajustarLinea']);
    $group->post('/v2/inventario/sesiones/{id}/ajustar-todo',  [\App\Controllers\InventarioV2Controller::class, 'ajustarTodo']);
    // ── Análisis ML: referencias no contadas (preview, sin ejecutar ajuste) ─
    $group->get('/v2/inventario/sesiones/{id}/ml-analisis',    [\App\Controllers\InventarioV2Controller::class, 'mlAnalisis']);
    $group->post('/v2/inventario/sesiones/{id}/conteo-manual', [\App\Controllers\InventarioV2Controller::class, 'addManualLinea']);
    $group->get('/v2/inventario/sesiones/{id}/mis-lineas',     [\App\Controllers\InventarioV2Controller::class, 'getMisLineas']);
    $group->get('/v2/inventario/productos/{id}/fechas-vencimiento', [\App\Controllers\InventarioV2Controller::class, 'getUltimasFechasVencimiento']);
    $group->get('/v2/inventario/productos/{id}/ubicaciones',        [\App\Controllers\InventarioV2Controller::class, 'getProductoUbicaciones']);

    // ── Asignaciones a auxiliares ───────────────────────────────────────────
    $group->post('/v2/inventario/sesiones/{id}/asignaciones',  [\App\Controllers\InventarioV2Controller::class, 'crearAsignacion']);
    $group->delete('/v2/inventario/asignaciones/{id}',         [\App\Controllers\InventarioV2Controller::class, 'eliminarAsignacion']);
    $group->delete('/v2/inventario/sesiones/{id}',             [\App\Controllers\InventarioV2Controller::class, 'eliminarSesion']);
    $group->post('/v2/inventario/sesiones/{id}/cerrar',        [\App\Controllers\InventarioV2Controller::class, 'cerrarSesion']);


    // ── API Móvil — Auxiliar ────────────────────────────────────────────────
    $group->get('/v2/inventario/mis-asignaciones',             [\App\Controllers\InventarioV2Controller::class, 'getMisAsignaciones']);
    $group->post('/v2/inventario/asignaciones/{id}/iniciar',   [\App\Controllers\InventarioV2Controller::class, 'iniciarConteo']);
    $group->post('/v2/inventario/asignaciones/{id}/linea',     [\App\Controllers\InventarioV2Controller::class, 'registrarLinea']);
    $group->post('/v2/inventario/asignaciones/{id}/finalizar', [\App\Controllers\InventarioV2Controller::class, 'finalizarAsignacion']);

    // ── Edición / eliminación de líneas (admin) ─────────────────────────────
    $group->put('/v2/inventario/lineas/{id}',                  [\App\Controllers\InventarioV2Controller::class, 'editarLinea']);
    $group->delete('/v2/inventario/lineas/{id}',               [\App\Controllers\InventarioV2Controller::class, 'eliminarLinea']);

    // ── Corrección manual de inventario ────────────────────────────────────
    $group->post('/v2/inventario/correccion',                  [\App\Controllers\InventarioV2Controller::class, 'correccionManual']);

    // ── Reportes ────────────────────────────────────────────────────────────
    $group->get('/v2/inventario/ajustes',                      [\App\Controllers\InventarioV2Controller::class, 'getAjustes']);
    $group->get('/v2/inventario/kardex',                       [\App\Controllers\InventarioV2Controller::class, 'getKardexCompleto']);
    $group->get('/v2/inventario/vencimientos',                 [\App\Controllers\InventarioV2Controller::class, 'getVencimientos']);

    // Módulo: Reabastecimiento automático
    $group->post('/reabastecimiento/auto', [\App\Controllers\ReplenishmentController::class, 'runAutoReplenishment']);
    $group->get('/reabastecimiento/tareas', [\App\Controllers\ReplenishmentController::class, 'listarTareas']);

    $group->group('/picking', function($group) {
        // Operaciones base
        $group->get('', [\App\Controllers\PickingController::class, 'listar']);
        $group->post('', [\App\Controllers\PickingController::class, 'crearBatch']);
        $group->get('/template', [\App\Controllers\PickingController::class, 'getTemplate']);
        $group->post('/importar', [\App\Controllers\PickingController::class, 'importarPedidos']);
        $group->get('/reservas',             [\App\Controllers\PickingController::class, 'reservas']);
        $group->get('/productos-pendientes', [\App\Controllers\PickingController::class, 'listarProductosPendientes']);
        $group->delete('/productos-pendientes', [\App\Controllers\PickingController::class, 'limpiarProductosPendientes']);
        $group->delete('/productos-pendientes/{id}', [\App\Controllers\PickingController::class, 'eliminarProductoPendiente']);
        $group->get('/dashboard', [\App\Controllers\PickingController::class, 'dashboard']);
        $group->get('/consolidados', [\App\Controllers\PickingController::class, 'consolidados']);
        $group->get('/consolidado/{id}/remision', [\App\Controllers\PickingController::class, 'consolidadoRemision']);
        $group->post('/asignar-multiple', [\App\Controllers\PickingController::class, 'asignarMultiple']);
        $group->post('/asignar-ruta', [\App\Controllers\PickingController::class, 'asignarRuta']);
        $group->post('/asignar-ambiente', [\App\Controllers\PickingController::class, 'asignarPorAmbiente']);
        $group->post('/validar-cobertura', [\App\Controllers\PickingController::class, 'validarCobertura']);
        $group->get('/mis-planillas', [\App\Controllers\PickingController::class, 'misPlanillas']);
        $group->get('/planilla/{numero}', [\App\Controllers\PickingController::class, 'planillaDetalles']);
        $group->post('/planilla/{numero}/iniciar', [\App\Controllers\PickingController::class, 'iniciarPlanilla']);
        $group->post('/planilla/{numero}/completar', [\App\Controllers\PickingController::class, 'completarPlanilla']);
        $group->post('/planilla/{numero}/liberar-vacias', [\App\Controllers\PickingController::class, 'liberarLineasVacias']);
        $group->post('/planilla/{numero}/liberar-linea', [\App\Controllers\PickingController::class, 'liberarLineaSeparada']);
        $group->post('/planilla/{numero}/agregar-linea', [\App\Controllers\PickingController::class, 'agregarLineaPlanilla']);
        $group->post('/planilla/{numero}/reemplazar-linea', [\App\Controllers\PickingController::class, 'reemplazarLineaPlanilla']);
        $group->get('/parametros', [\App\Controllers\PickingController::class, 'getParametrosPicking']);
        $group->put('/parametros', [\App\Controllers\PickingController::class, 'setParametrosPicking']);
        $group->post('/confirmar-consolidado', [\App\Controllers\PickingController::class, 'confirmarConsolidado']);
        $group->post('/marcar-agotado-consolidado', [\App\Controllers\PickingController::class, 'marcarAgotadoConsolidado']);
        $group->get('/agotados',                    [\App\Controllers\PickingController::class, 'getAgotados']);
        $group->post('/asignar-consolidado', [\App\Controllers\PickingController::class, 'asignarConsolidado']);
        $group->post('/assign', [\App\Controllers\PickingController::class, 'assignLines']);
        $group->post('/transfer', [\App\Controllers\PickingController::class, 'transferTasks']);
        $group->get('/reabastecimientos', [\App\Controllers\PickingController::class, 'reabastecimientos']);
        $group->post('/reabast/{id}/completar', [\App\Controllers\PickingController::class, 'completarReabastLegacy']);
        $group->get('/novedades-stock',   [\App\Controllers\PickingController::class, 'novedadesStock']);
        $group->delete('/faltantes',      [\App\Controllers\PickingController::class, 'limpiarFaltantes']);
        $group->post('/backorder',         [\App\Controllers\PickingController::class, 'procesarBackorder']);
        $group->get('/reporte',           [\App\Controllers\PickingController::class, 'reporte']);
        $group->get('/stock-alternativo',  [\App\Controllers\PickingController::class, 'stockAlternativo']);
        $group->post('/reasignar-ubicacion', [\App\Controllers\PickingController::class, 'reasignarUbicacion']);
        
        // Consulta dinámica picking (estáticas antes de /{id})
        $group->get('/consulta', [\App\Controllers\PickingController::class, 'consultaPicking']);

        // Novedades de picking (estáticas antes de /{id})
        $group->get('/novedades', [\App\Controllers\PickingController::class, 'getNovedades']);
        $group->put('/novedades/{id}/resolver', [\App\Controllers\PickingController::class, 'resolverNovedad']);

        // Edición de líneas con ajuste de inventario
        $group->put('/detalles/{id}/cantidad', [\App\Controllers\PickingController::class, 'editarLineaPicking']);

        // Edición de líneas individuales (requiere admin/supervisor)
        $group->patch('/{id}/linea/{lineaId}', [\App\Controllers\PickingController::class, 'actualizarLinea']);
        $group->delete('/{id}/linea/{lineaId}', [\App\Controllers\PickingController::class, 'eliminarLinea']);

        // Gestión de auxiliares por orden
        $group->put('/{id}/auxiliar', [\App\Controllers\PickingController::class, 'cambiarAuxiliar']);
        $group->post('/{id}/auxiliar', [\App\Controllers\PickingController::class, 'agregarAuxiliar']);

        // Rutas por ID de Orden
        $group->get('/{id}', [\App\Controllers\PickingController::class, 'detalle']);
        $group->put('/{id}', [\App\Controllers\PickingController::class, 'actualizar']);
        $group->delete('/{id}', [\App\Controllers\PickingController::class, 'eliminar']);
        $group->put('/{id}/ruta',         [\App\Controllers\PickingController::class, 'asignarRutaOrden']);
        $group->get('/{orden_id}/siguiente-linea', [\App\Controllers\PickingController::class, 'siguienteLinea']);
        $group->post('/{orden_id}/generar-ruta', [\App\Controllers\PickingController::class, 'generateRoute']);
        $group->post('/{orden_id}/confirmar-linea', [\App\Controllers\PickingController::class, 'confirmLine']);
        $group->post('/{id}/completar', [\App\Controllers\PickingController::class, 'completar']);
        $group->post('/{id}/reabrir',   [\App\Controllers\PickingController::class, 'reabrir']);
        $group->post('/{id}/marcar-faltante', [\App\Controllers\PickingController::class, 'marcarFaltante']);
        $group->post('/{id}/despachado-directo', [\App\Controllers\PickingController::class, 'marcarDespachadoDirecto']);
        $group->post('/{id}/lineas', [\App\Controllers\PickingController::class, 'agregarLinea']);

        // Certificación por Sucursal
        $group->get('/certificacion/pendientes',             [\App\Controllers\PickingController::class, 'certPendientes']);
        $group->get('/certificacion/certificadas',           [\App\Controllers\PickingController::class, 'certCertificadas']);
        $group->get('/certificacion/despachados-directo',    [\App\Controllers\PickingController::class, 'certDespachadosDirecto']);
        $group->get('/certificacion/detalle/{sucursal}',        [\App\Controllers\PickingController::class, 'certDetalle']);
        $group->get('/certificacion/admin-detalle/{sucursal}',  [\App\Controllers\PickingController::class, 'certAdminDetalle']);
        $group->put('/certificacion/admin-lote/{sucursal}',     [\App\Controllers\PickingController::class, 'certAdminLote']);
        $group->post('/certificacion/confirmar',             [\App\Controllers\PickingController::class, 'certConfirmar']);
        $group->post('/certificacion/finalizar',             [\App\Controllers\PickingController::class, 'certFinalizar']);
        $group->post('/certificacion/resetear/{sucursal}',   [\App\Controllers\PickingController::class, 'resetearCertificacion']);
        $group->get('/certificacion/imprimir/{sucursal}',    [\App\Controllers\PickingController::class, 'imprimirCertificado']);
        $group->get('/certificacion/remision-multiple',      [\App\Controllers\PickingController::class, 'certRemisionMultiple']);
        $group->get('/certificacion/remision/{sucursal}',   [\App\Controllers\PickingController::class, 'certRemisionDirecta']);
        $group->get('/certificacion/vista-hoy',             [\App\Controllers\PickingController::class, 'certVistaHoy']);

        // VRs de Planilla
        $group->post('/planillas/vr',                        [\App\Controllers\PickingController::class, 'agregarVrPlanilla']);
        $group->delete('/planillas/vr/{id}',                 [\App\Controllers\PickingController::class, 'eliminarVrPlanilla']);

        // Certificaciones (admin)
        $group->put('/{id}/certificaciones/{det_id}', [\App\Controllers\PickingController::class, 'editarCertificacion']);
        $group->post('/{id}/recalcular-remision', [\App\Controllers\PickingController::class, 'recalcularRemision']);
    });

    // ── Packing & Certificación ───────────────────────────────────────────────
    $group->group('/packing', function ($group) {
        $group->post('/autopack',                    [\App\Controllers\PackingController::class, 'autoPack']);
        $group->post('/sesion',                      [\App\Controllers\PackingController::class, 'iniciarSesion']);
        $group->get('/sesiones',                     [\App\Controllers\PackingController::class, 'listarSesiones']);
        $group->get('/sesion/activa/{sucursal}',     [\App\Controllers\PackingController::class, 'getSesionActiva']);
        $group->get('/sesion/{id}',                  [\App\Controllers\PackingController::class, 'getSesion']);
        $group->post('/sesion/{id}/item',            [\App\Controllers\PackingController::class, 'agregarItem']);
        $group->post('/sesion/{id}/finalizar',       [\App\Controllers\PackingController::class, 'finalizarSesion']);
        $group->post('/sesion/{id}/recertificar',    [\App\Controllers\PackingController::class, 'recertificar']);
        $group->post('/sesion/{id}/reset',           [\App\Controllers\PackingController::class, 'resetCertificacion']);
        $group->post('/sesion/{id}/cancelar',        [\App\Controllers\PackingController::class, 'cancelarSesion']);
        $group->get('/sesion/{id}/remision',         [\App\Controllers\PackingController::class, 'getRemision']);
        $group->put('/sesion/{id}/impresoras',       [\App\Controllers\PackingController::class, 'actualizarImpresoras']);
        $group->delete('/item/{id}',                 [\App\Controllers\PackingController::class, 'eliminarItem']);
        $group->post('/unidad/{id}/cerrar',          [\App\Controllers\PackingController::class, 'cerrarUnidad']);
        $group->get('/unidad/{id}/etiqueta',         [\App\Controllers\PackingController::class, 'getEtiquetaCanasta']);
        $group->post('/unidad/{id}/imprimir',        [\App\Controllers\PackingController::class, 'imprimirEtiquetaRed']);
        $group->get('/sesion/{id}/etiquetas',        [\App\Controllers\PackingController::class, 'getEtiquetasTodas']);
        $group->get('/sesion/{id}/agotados',         [\App\Controllers\PackingController::class, 'agotadosSesion']);
    });

    // Módulo: Aprobaciones de Vencimiento
    $group->get('/aprobaciones/vencimiento/pendientes', [\App\Controllers\AprobacionController::class, 'pendientes']);
    $group->post('/aprobaciones/{id}/resolver',          [\App\Controllers\AprobacionController::class, 'resolver']);
    $group->get('/aprobaciones/{id}/estado',             [\App\Controllers\AprobacionController::class, 'estado']);
    $group->delete('/aprobaciones/{id}',                 [\App\Controllers\AprobacionController::class, 'cancelar']);

    // Módulo: Impresoras
    $group->group('/impresoras', function($group) {
        $group->get('', [\App\Controllers\ImpresoraController::class, 'listar']);
        $group->post('', [\App\Controllers\ImpresoraController::class, 'guardar']);
        $group->post('/imprimir-rotulo', [\App\Controllers\ImpresoraController::class, 'imprimirRotulo']);
        $group->post('/{id}/test-print', [\App\Controllers\ImpresoraController::class, 'testPrint']);
        $group->delete('/{id}', [\App\Controllers\ImpresoraController::class, 'eliminar']);
    });



    // Módulo: Planillas (Certificación por Cliente)
    $group->get('/planillas', [\App\Controllers\PlanillaController::class, 'listar']);
    $group->get('/planillas/progreso', [\App\Controllers\PlanillaController::class, 'planillaProgreso']);
    $group->post('/planillas/asignar', [\App\Controllers\PlanillaController::class, 'asignar']);
    $group->get('/planillas/cert/dashboard', [\App\Controllers\PlanillaController::class, 'dashboard']);
    $group->get('/planillas/cert/{id}/analytics', [\App\Controllers\PlanillaController::class, 'getCertificationAnalytics']);
    $group->post('/planillas/cert/{id}/editar', [\App\Controllers\PlanillaController::class, 'editarCantidad']);
    $group->post('/planillas/importar', [\App\Controllers\PlanillaController::class, 'importar']);
    $group->post('/planillas/cert/iniciar', [\App\Controllers\PlanillaController::class, 'iniciarCertificacion']);
    $group->get('/planillas/cert/{id}', [\App\Controllers\PlanillaController::class, 'verCertificacion']);
    $group->post('/planillas/cert/{id}/linea', [\App\Controllers\PlanillaController::class, 'registrarLinea']);
    $group->post('/planillas/cert/{id}/finalizar', [\App\Controllers\PlanillaController::class, 'finalizarCertificacion']);
    $group->post('/planillas/{id}/habilitar-cert', [\App\Controllers\PlanillaController::class, 'habilitarCertificacion']);
    $group->get('/planillas/{id}', [\App\Controllers\PlanillaController::class, 'ver']);

    // Módulo: Despachos (Outbound Certification)
    $group->get('/despachos', [\App\Controllers\DespachoController::class, 'listar']);
    $group->post('/despachos', [\App\Controllers\DespachoController::class, 'store']);
    $group->get('/despachos/{id}', [\App\Controllers\DespachoController::class, 'ver']);
    $group->get('/despachos/{id}/reporte', [\App\Controllers\DespachoController::class, 'reporte']);
    $group->post('/despachos/{id}/cerrar', [\App\Controllers\DespachoController::class, 'close']);
    $group->post('/despachos/{id}/pedidos', [\App\Controllers\DespachoController::class, 'agregarPedidos']);
    $group->delete('/despachos/{id}/pedidos/{orden_id}', [\App\Controllers\DespachoController::class, 'eliminarPedido']);
    $group->post('/despachos/{id}/liquidar', [\App\Controllers\DespachoController::class, 'liquidar']);
    $group->delete('/despachos/{id}', [\App\Controllers\DespachoController::class, 'eliminar']);

    // Módulo: Almacenamiento / Putaway
    $group->get('/putaway/patio', [\App\Controllers\PutawayController::class, 'listarPatio']);
    $group->get('/putaway/resolver-ean', [\App\Controllers\PutawayController::class, 'resolverEan']);
    $group->get('/putaway/sugerir/{producto_id}', [\App\Controllers\PutawayController::class, 'sugerirUbicacion']);
    $group->post('/putaway/ubicar', [\App\Controllers\PutawayController::class, 'ubicar']);
    $group->post('/putaway/trasladar', [\App\Controllers\PutawayController::class, 'trasladar']);

    // Módulo: Alertas
    $group->get('/alertas', [\App\Controllers\AlertasController::class, 'index']);
    $group->get('/alertas/export', [\App\Controllers\AlertasController::class, 'export']);
    $group->post('/alertas/generar', [\App\Controllers\AlertasController::class, 'generar']);
    $group->post('/alertas/{id}/resolver', [\App\Controllers\AlertasController::class, 'resolver']);
    $group->post('/alertas/{id}/ignorar', [\App\Controllers\AlertasController::class, 'ignorar']);

    // Módulo: Reportes
    $group->get('/reportes/kardex', [\App\Controllers\ReportesController::class, 'kardex']);
    $group->get('/reportes/stock', [\App\Controllers\ReportesController::class, 'stockActual']);
    $group->get('/reportes/recepciones', [\App\Controllers\ReportesController::class, 'recepciones']);
    $group->get('/reportes/despachos', [\App\Controllers\ReportesController::class, 'despachos']);
    $group->get('/reportes/devoluciones', [\App\Controllers\ReportesController::class, 'devoluciones']);
    $group->get('/reportes/picking', [\App\Controllers\ReportesController::class, 'picking']);
    $group->get('/reportes/conteos', [\App\Controllers\ReportesController::class, 'conteos']);
    $group->get('/reportes/odc', [\App\Controllers\ReportesController::class, 'odcReporte']);
    $group->get('/reportes/vencimientos', [\App\Controllers\ReportesController::class, 'vencimientos']);
    $group->get('/reportes/agotados', [\App\Controllers\ReportesController::class, 'agotadosYBajoMinimo']);
    $group->get('/reportes/agotados-demanda', [\App\Controllers\ReportesController::class, 'agotados']);
    // Alias semánticos: stock-real y por-ubicacion
    $group->get('/reportes/stock-real', [\App\Controllers\ReportesController::class, 'stockActual']);
    $group->get('/reportes/por-ubicacion', [\App\Controllers\ReportesController::class, 'stockPorUbicacion']);

    // ── Reportes de Contingencia (imprimibles sin internet) ───────────────────
    $group->get('/reportes/contingencia/separacion', [\App\Controllers\ReportesController::class, 'contingenciaSeparacion']);
    $group->get('/reportes/contingencia/certificacion', [\App\Controllers\ReportesController::class, 'contingenciaCertificacion']);
    $group->get('/reportes/dashboard-gerencial', [\App\Controllers\ReportesController::class, 'dashboardGerencial']);
    $group->get('/reportes/dashboard-bi', [\App\Controllers\ReportesController::class, 'dashboardBI']);
    $group->get('/reportes/audit-log', [\App\Controllers\ReportesController::class, 'auditLog']);
    $group->get('/reportes/evaluacion-proveedores', [\App\Controllers\ReportesController::class, 'evaluacionProveedores']);

    // Módulo: Dashboard (Real-time Analytics)
    $group->get('/dashboard', [\App\Controllers\DashboardController::class, 'index']);
    $group->get('/dashboard/summary', [\App\Controllers\DashboardController::class, 'summary']);
    $group->get('/dashboard/actividad', [\App\Controllers\DashboardController::class, 'actividad']);

    // TV Dashboard — 4-secciones (Ingresos + Picking + Agotados + Alertas)
    $group->get('/tv/dashboard', [\App\Controllers\DashboardTVController::class, 'getDashboardTV']);

    // TV Dashboard — Tab Nivel de Servicio
    $group->get('/tv/nivel-servicio', [\App\Controllers\DashboardTVController::class, 'nivelServicio']);

    // TV Dashboard — Chart de ingresos por día/proveedor
    $group->get('/tv/ingresos-chart', [\App\Controllers\DashboardTVController::class, 'ingresosChart']);

    // Dashboard: KPI Nivel de Servicio
    $group->get('/dashboard/nivel-servicio', [\App\Controllers\DashboardTVController::class, 'getNivelServicio']);

    // Causales de Novedad (Picking/Agotados)
    $group->get('/causales-novedad',         [\App\Controllers\CausalesController::class, 'index']);
    $group->post('/causales-novedad',        [\App\Controllers\CausalesController::class, 'store']);
    $group->put('/causales-novedad/{id}',    [\App\Controllers\CausalesController::class, 'update']);
    $group->delete('/causales-novedad/{id}', [\App\Controllers\CausalesController::class, 'destroy']);

    // Módulo: Consulta Rápida de Producto
    $group->get('/consulta-rapida/buscar', [\App\Controllers\ConsultaRapidaController::class, 'buscar']);
    $group->get('/consulta-rapida/{producto_id}', [\App\Controllers\ConsultaRapidaController::class, 'dashboard']);

    // Módulo: Trazabilidad
    $group->get('/trazabilidad/buscar-producto', [\App\Controllers\TrazabilidadController::class, 'buscarProducto']);
    $group->get('/trazabilidad/buscar-ubicacion', [\App\Controllers\TrazabilidadController::class, 'buscarUbicacion']);
    $group->get('/trazabilidad/producto/{id}', [\App\Controllers\TrazabilidadController::class, 'porProducto']);
    $group->get('/trazabilidad/ubicacion/{id}', [\App\Controllers\TrazabilidadController::class, 'porUbicacion']);

    // Módulo: Chat IA (FENIX IA)
    $group->post('/chat-ia/mensaje', [\App\Controllers\ChatIAController::class, 'mensaje']);

    // Herramienta de desarrollo: Ejecutar migraciones pendientes (solo Admin)
    $group->post('/system/migrate', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        if (!$user || ($user->rol ?? '') !== 'Admin') {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Solo administradores']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        $DB      = \Illuminate\Database\Capsule\Manager::class;
        $migPath = __DIR__ . '/../database/migrations/';
        $files   = glob($migPath . '*.php');
        sort($files);
        if (!\Illuminate\Database\Capsule\Manager::schema()->hasTable('migrations')) {
            \Illuminate\Database\Capsule\Manager::schema()->create('migrations', function ($t) {
                $t->increments('id');
                $t->string('migration');
                $t->integer('batch');
                $t->timestamp('ran_at')->useCurrent();
            });
        }
        $ran    = \Illuminate\Database\Capsule\Manager::table('migrations')->pluck('migration')->toArray();
        $batch  = (int)(\Illuminate\Database\Capsule\Manager::table('migrations')->max('batch') ?? 0) + 1;
        $done   = [];
        $errors = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $ran)) continue;
            try {
                $m = require $file;
                if (is_array($m) && isset($m['up'])) $m['up']();
                \Illuminate\Database\Capsule\Manager::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
                $done[] = $name;
            } catch (\Exception $e) {
                $errors[] = "{$name}: " . $e->getMessage();
            }
        }
        $response->getBody()->write(json_encode(['error' => false, 'migrated' => $done, 'errors' => $errors]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── Visor de logs (solo Admin) ────────────────────────────────────────────
    $group->get('/system/logs', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        if (!$user || ($user->rol ?? '') !== 'Admin') {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Solo administradores']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        $logPath = __DIR__ . '/../logs/app.log';
        $lines   = (int)($request->getQueryParams()['lines'] ?? 200);
        $lines   = min(max($lines, 10), 2000);
        if (!file_exists($logPath)) {
            $response->getBody()->write(json_encode(['error' => false, 'data' => [], 'size' => 0]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        // Tail last N lines
        $all     = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail    = array_slice($all, -$lines);
        $size    = filesize($logPath);
        $response->getBody()->write(json_encode([
            'error' => false,
            'data'  => array_reverse($tail),  // newest first
            'total' => count($all),
            'size'  => $size,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── Limpiar log (solo Admin) ──────────────────────────────────────────────
    $group->post('/system/logs/clear', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        if (!$user || ($user->rol ?? '') !== 'Admin') {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Solo administradores']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        $logPath = __DIR__ . '/../logs/app.log';
        file_put_contents($logPath, '');
        wmsLog('INFO', "Log limpiado por admin id={$user->id}");
        $response->getBody()->write(json_encode(['error' => false, 'message' => 'Log limpiado']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── Backup manual de BD (solo Admin) ─────────────────────────────────────
    $group->post('/system/backup', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        if (!$user || ($user->rol ?? '') !== 'Admin') {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Solo administradores']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        require_once __DIR__ . '/../src/Helpers/BackupHelper.php';
        try {
            $result = \App\Helpers\BackupHelper::run();
            $response->getBody()->write(json_encode(['error' => false, 'backup' => $result]));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => true, 'message' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── Estado de backups (solo Admin) ───────────────────────────────────────
    $group->get('/system/backup', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        if (!$user || ($user->rol ?? '') !== 'Admin') {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Solo administradores']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        require_once __DIR__ . '/../src/Helpers/BackupHelper.php';
        $files = \App\Helpers\BackupHelper::listar();
        $response->getBody()->write(json_encode(['error' => false, 'data' => $files]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Módulo: Notificaciones
    $group->get('/notificaciones', [\App\Controllers\NotificacionesController::class, 'index']);
    $group->get('/notificaciones/badge', [\App\Controllers\NotificacionesController::class, 'badge']);
    $group->put('/notificaciones/leer-todas', [\App\Controllers\NotificacionesController::class, 'marcarTodasLeidas']);
    $group->delete('/notificaciones/limpiar-leidas', [\App\Controllers\NotificacionesController::class, 'eliminarLeidas']);
    $group->put('/notificaciones/{id}/leer', [\App\Controllers\NotificacionesController::class, 'marcarLeida']);
    $group->put('/notificaciones/{id}/completar', [\App\Controllers\NotificacionesController::class, 'marcarCompletada']);
    $group->delete('/notificaciones/{id}', [\App\Controllers\NotificacionesController::class, 'eliminar']);
    $group->post('/notificaciones/enviar', [\App\Controllers\NotificacionesController::class, 'enviar']);

    // RBAC individual (permisos por usuario)
    $group->get('/personal/{id}/permisos', [\App\Controllers\PermisoPersonalController::class, 'getPermisos']);
    $group->post('/personal/{id}/permisos/toggle', [\App\Controllers\PermisoPersonalController::class, 'togglePermiso']);
    $group->delete('/personal/{id}/permisos', [\App\Controllers\PermisoPersonalController::class, 'resetPermisos']);

    // Módulo: Parametrización (Maestros)
    // ── Sistema / Diagnóstico (solo Admin) ───────────────────────────────────
    $group->get('/sistema/validar',        [\App\Controllers\SystemController::class, 'validar']);
    $group->post('/sistema/opcache-reset', [\App\Controllers\SystemController::class, 'opcacheReset']);
    $group->post('/sistema/limpiar-logs',  [\App\Controllers\SystemController::class, 'limpiarLogs']);

    $group->get('/param/empresas', [\App\Controllers\ParametrosController::class, 'getEmpresas']);
    $group->post('/param/empresas', [\App\Controllers\ParametrosController::class, 'createEmpresa']);
    $group->put('/param/empresas/{id}', [\App\Controllers\ParametrosController::class, 'editEmpresa']);
    $group->delete('/param/empresas/{id}', [\App\Controllers\ParametrosController::class, 'deleteEmpresa']);
    $group->get('/param/sucursales', [\App\Controllers\ParametrosController::class, 'getSucursales']);
    $group->post('/param/sucursales', [\App\Controllers\ParametrosController::class, 'createSucursal']);
    $group->put('/param/sucursales/{id}', [\App\Controllers\ParametrosController::class, 'editSucursal']);
    $group->delete('/param/sucursales/{id}', [\App\Controllers\ParametrosController::class, 'deleteSucursal']);
    $group->get('/param/marcas', [\App\Controllers\ParametrosController::class, 'getMarcas']);
    $group->post('/param/marcas', [\App\Controllers\ParametrosController::class, 'createMarca']);
    $group->put('/param/marcas/{id}', [\App\Controllers\ParametrosController::class, 'editMarca']);
    $group->delete('/param/marcas/{id}', [\App\Controllers\ParametrosController::class, 'deleteMarca']);
    $group->get('/param/productos', [\App\Controllers\ParametrosController::class, 'getProductos']);
    $group->post('/productos/{id}/toggle', [\App\Controllers\ParametrosController::class, 'toggleProductoEstado']);
    $group->get('/productos/buscar', [\App\Controllers\ParametrosController::class, 'buscarProductos']);
    $group->get('/param/productos/buscar', [\App\Controllers\ParametrosController::class, 'buscarProductos']);
    $group->get('/param/productos/{id}', [\App\Controllers\ParametrosController::class, 'getProducto']);
    $group->post('/param/productos', [\App\Controllers\ParametrosController::class, 'createProducto']);
    $group->put('/param/productos/{id}', [\App\Controllers\ParametrosController::class, 'editProducto']);
    $group->put('/param/productos/{id}/toggle-status', [\App\Controllers\ParametrosController::class, 'toggleStatusProducto']);
    $group->delete('/param/productos/{id}', [\App\Controllers\ParametrosController::class, 'deleteProducto']);
    
    // Fotos de producto
    $group->post('/param/productos/{id}/fotos', [\App\Controllers\ParametrosController::class, 'uploadProductoFotos']);
    $group->delete('/param/productos/fotos/{foto_id}', [\App\Controllers\ParametrosController::class, 'deleteProductoFoto']);
    $group->get('/param/categorias', [\App\Controllers\ParametrosController::class, 'getCategorias']);
    $group->post('/param/categorias', [\App\Controllers\ParametrosController::class, 'createCategoria']);
    $group->put('/param/categorias/{id}', [\App\Controllers\ParametrosController::class, 'editCategoria']);
    $group->delete('/param/categorias/{id}', [\App\Controllers\ParametrosController::class, 'deleteCategoria']);
    $group->get('/param/personal', [\App\Controllers\ParametrosController::class, 'getPersonal']);
    $group->post('/param/personal', [\App\Controllers\ParametrosController::class, 'createPersonal']);
    $group->put('/param/personal/{id}', [\App\Controllers\ParametrosController::class, 'editPersonal']);
    $group->delete('/param/personal/{id}', [\App\Controllers\ParametrosController::class, 'deletePersonal']);
    $group->get('/param/ubicaciones', [\App\Controllers\ParametrosController::class, 'getUbicaciones']);
    $group->post('/param/ubicaciones', [\App\Controllers\ParametrosController::class, 'createUbicacion']);
    $group->put('/param/ubicaciones/{id}', [\App\Controllers\ParametrosController::class, 'editUbicacion']);
    $group->patch('/param/ubicaciones/{id}/toggle', [\App\Controllers\ParametrosController::class, 'toggleStatusUbicacion']);
    $group->delete('/param/ubicaciones/{id}', [\App\Controllers\ParametrosController::class, 'deleteUbicacion']);
    $group->get('/param/zonas', [\App\Controllers\ParametrosController::class, 'getZonas']);
    $group->post('/param/zonas', [\App\Controllers\ParametrosController::class, 'createZona']);
    $group->put('/param/zonas/{id}', [\App\Controllers\ParametrosController::class, 'editZona']);
    $group->delete('/param/zonas/{id}', [\App\Controllers\ParametrosController::class, 'deleteZona']);
    $group->get('/param/ambientes', [\App\Controllers\ParametrosController::class, 'getAmbientes']);
    $group->post('/param/ambientes', [\App\Controllers\ParametrosController::class, 'createAmbiente']);
    $group->put('/param/ambientes/{id}', [\App\Controllers\ParametrosController::class, 'editAmbiente']);
    $group->delete('/param/ambientes/{id}', [\App\Controllers\ParametrosController::class, 'deleteAmbiente']);
    $group->get('/param/proveedores', [\App\Controllers\ParametrosController::class, 'getProveedores']);
    $group->post('/param/proveedores', [\App\Controllers\ParametrosController::class, 'createProveedor']);
    $group->put('/param/proveedores/{id}', [\App\Controllers\ParametrosController::class, 'editProveedor']);
    $group->delete('/param/proveedores/{id}', [\App\Controllers\ParametrosController::class, 'deleteProveedor']);
    $group->get('/param/proveedores/{id}/performance', [\App\Controllers\ParametrosController::class, 'getProveedorPerformance']);
    $group->get('/param/productos/{id}/eans', [\App\Controllers\ParametrosController::class, 'getProductoEans']);
    $group->post('/param/productos/{id}/eans', [\App\Controllers\ParametrosController::class, 'addProductoEan']);
    $group->put('/param/productos/{id}/eans/{ean_id}', [\App\Controllers\ParametrosController::class, 'updateProductoEan']);
    $group->delete('/param/productos/{id}/eans/{ean_id}', [\App\Controllers\ParametrosController::class, 'deleteProductoEan']);
    $group->get('/param/clientes', [\App\Controllers\ParametrosController::class, 'getClientes']);
    $group->post('/param/clientes', [\App\Controllers\ParametrosController::class, 'createCliente']);
    $group->put('/param/clientes/{id}', [\App\Controllers\ParametrosController::class, 'updateCliente']);
    $group->delete('/param/clientes/{id}', [\App\Controllers\ParametrosController::class, 'deleteCliente']);
    $group->get('/param/roles', [\App\Controllers\ParametrosController::class, 'getRoles']);
    $group->get('/param/permisos-matriz/{rol}', [\App\Controllers\ParametrosController::class, 'getPermissionsMatrix']);
    $group->post('/param/permisos-toggle', [\App\Controllers\ParametrosController::class, 'togglePermission']);
    $group->get('/param/rutas', [\App\Controllers\ParametrosController::class, 'getRutas']);
    $group->post('/param/rutas', [\App\Controllers\ParametrosController::class, 'createRuta']);
    $group->put('/param/rutas/{id}', [\App\Controllers\ParametrosController::class, 'updateRuta']);
    $group->delete('/param/rutas/{id}', [\App\Controllers\ParametrosController::class, 'deleteRuta']);
    $group->get('/param/import-export/template/{tipo}', [\App\Controllers\ImportExportController::class, 'getTemplate']);
    $group->get('/param/import-export/export/productos', [\App\Controllers\ImportExportController::class, 'exportProductos']);
    $group->post('/param/import-export/upload/{tipo}', [\App\Controllers\ImportExportController::class, 'uploadCSV']);
    $group->post('/param/import-export/preview/{tipo}', [\App\Controllers\ImportExportController::class, 'previewCSV']);

    // ── TRASPASOS ─────────────────────────────────────────────────────────────
    $group->get('/traspasos', [\App\Controllers\TraspasoController::class, 'index']);
    $group->get('/traspasos/motivos', [\App\Controllers\TraspasoController::class, 'motivos']);
    $group->get('/traspasos/buscar-stock', [\App\Controllers\TraspasoController::class, 'buscarStock']);
    $group->post('/traspasos', [\App\Controllers\TraspasoController::class, 'create']);

    // ── BLOQUEO DE PRODUCTOS ─────────────────────────────────────────────────
    $group->get('/bloqueos', [\App\Controllers\BloqueoController::class, 'index']);
    $group->post('/bloqueos/producto/{id}', [\App\Controllers\BloqueoController::class, 'bloquearProducto']);
    $group->delete('/bloqueos/producto/{id}', [\App\Controllers\BloqueoController::class, 'desbloquearProducto']);
    $group->post('/bloqueos/lote', [\App\Controllers\BloqueoController::class, 'bloquearLote']);
    $group->delete('/bloqueos/lote/{id}', [\App\Controllers\BloqueoController::class, 'desbloquearLote']);
    $group->get('/bloqueos/inventario', [\App\Controllers\BloqueoController::class, 'inventarioBloqueado']);

    // ── MISCELÁNEOS ────────────────────────────────────────────────────────────
    $group->get('/miscelaneos', [\App\Controllers\MiscelaneoController::class, 'index']);
    $group->get('/miscelaneos/{id}', [\App\Controllers\MiscelaneoController::class, 'show']);
    $group->post('/miscelaneos', [\App\Controllers\MiscelaneoController::class, 'create']);
    $group->put('/miscelaneos/{id}', [\App\Controllers\MiscelaneoController::class, 'update']);
    $group->delete('/miscelaneos/{id}', [\App\Controllers\MiscelaneoController::class, 'delete']);
    $group->post('/miscelaneos/{id}/fotos', [\App\Controllers\MiscelaneoController::class, 'uploadFotos']);
    $group->delete('/miscelaneos/fotos/{foto_id}', [\App\Controllers\MiscelaneoController::class, 'deleteFoto']);
    $group->post('/miscelaneos/{id}/despachar', [\App\Controllers\MiscelaneoController::class, 'marcarDespachado']);
    $group->get('/miscelaneos/cliente/{cliente_id}/pendientes', [\App\Controllers\MiscelaneoController::class, 'pendientesPorCliente']);

    // ── INTELIGENCIA / ML / ANOMALÍAS ────────────────────────────────────────
    // Predicción de vencimientos (ML + EMA + regresión lineal)
    $group->get('/inteligencia/vencimientos',           [\App\Controllers\AnomalyController::class, 'vencimientos']);
    // Escaneo de anomalías estadísticas (Z-score + IQR + frecuencia)
    $group->get('/inteligencia/anomalias/scan',         [\App\Controllers\AnomalyController::class, 'scanAnomalias']);
    // Listado paginado de anomalías detectadas
    $group->get('/inteligencia/anomalias',              [\App\Controllers\AnomalyController::class, 'listarAnomalias']);
    // Revisar / descartar / confirmar una anomalía
    $group->put('/inteligencia/anomalias/{id}',         [\App\Controllers\AnomalyController::class, 'revisarAnomalia']);
    // Alertas de productos próximos a vencer (FEFO)
    $group->get('/inteligencia/fefo/alertas',           [\App\Controllers\AnomalyController::class, 'fefoAlertas']);
    // Reporte de rotación (productos sin movimiento)
    $group->get('/inteligencia/fefo/rotacion',          [\App\Controllers\AnomalyController::class, 'fefoRotacion']);
    // Log de bloqueos de integridad (InventoryGuard)
    $group->get('/inteligencia/guardlog',               [\App\Controllers\AnomalyController::class, 'guardLog']);
    // Métricas de rendimiento de endpoints lentos
    $group->get('/inteligencia/performance',            [\App\Controllers\AnomalyController::class, 'performance']);

    // ════════════════════════════════════════════════════════════════════════
    // MÓDULOS ENTERPRISE — Repotencialización Multi-Agente
    // ════════════════════════════════════════════════════════════════════════

    // ── ROTACIÓN / ABC-XYZ (Motor de clasificación de inventario) ─────────
    $group->get('/rotacion',                            [\App\Controllers\RotacionController::class, 'index']);
    $group->get('/rotacion/abc-xyz',                    [\App\Controllers\RotacionController::class, 'abcXyz']);
    $group->post('/rotacion/abc-xyz/ejecutar',          [\App\Controllers\RotacionController::class, 'ejecutarAbcXyz']);
    $group->post('/rotacion/poblar-ventas',             [\App\Controllers\RotacionController::class, 'poblarVentas']);
    $group->post('/rotacion/refresh-mv',                [\App\Controllers\RotacionController::class, 'refreshMv']);
    $group->get('/rotacion/riesgo',                     [\App\Controllers\RotacionController::class, 'riesgo']);
    $group->get('/rotacion/coberturas-bajas',           [\App\Controllers\RotacionController::class, 'coberturasBajas']);
    $group->get('/rotacion/ejecuciones',                [\App\Controllers\RotacionController::class, 'ejecuciones']);
    $group->get('/rotacion/export',                     [\App\Controllers\RotacionController::class, 'export']);

    // ── FORECAST / PREDICCIÓN DE DEMANDA ──────────────────────────────────
    $group->get('/forecast',                            [\App\Controllers\ForecastController::class, 'index']);
    $group->get('/forecast/alertas',                    [\App\Controllers\ForecastController::class, 'alertas']);
    $group->get('/forecast/producto/{id}',              [\App\Controllers\ForecastController::class, 'producto']);
    $group->post('/forecast/ingest',                    [\App\Controllers\ForecastController::class, 'ingest']);
    $group->post('/forecast/calcular',                  [\App\Controllers\ForecastController::class, 'calcularInterno']);
    $group->get('/forecast/precision',                  [\App\Controllers\ForecastController::class, 'precision']);
    $group->get('/forecast/export',                     [\App\Controllers\ForecastController::class, 'export']);

    // ── SLOTTING / OPTIMIZACIÓN DE UBICACIONES ────────────────────────────
    $group->get('/slotting',                            [\App\Controllers\SlottingController::class, 'index']);
    $group->post('/slotting/ejecutar',                  [\App\Controllers\SlottingController::class, 'ejecutar']);
    $group->post('/slotting/{id}/aprobar',              [\App\Controllers\SlottingController::class, 'aprobar']);
    $group->post('/slotting/{id}/rechazar',             [\App\Controllers\SlottingController::class, 'rechazar']);
    $group->get('/slotting/sugerencias',                [\App\Controllers\SlottingController::class, 'sugerencias']);
    $group->get('/slotting/mapa',                       [\App\Controllers\SlottingController::class, 'mapa']);
    $group->get('/slotting/export',                     [\App\Controllers\SlottingController::class, 'export']);

    // ── CROSS-DOCKING (Transferencia directa entrada→salida) ──────────────
    $group->get('/crossdock',                           [\App\Controllers\CrossDockController::class, 'index']);
    $group->get('/crossdock/{id}',                      [\App\Controllers\CrossDockController::class, 'show']);
    $group->post('/crossdock',                          [\App\Controllers\CrossDockController::class, 'crear']);
    $group->post('/crossdock/{id}/recibir',             [\App\Controllers\CrossDockController::class, 'recibir']);
    $group->post('/crossdock/{id}/transferir',          [\App\Controllers\CrossDockController::class, 'transferir']);
    $group->post('/crossdock/{id}/completar',           [\App\Controllers\CrossDockController::class, 'completar']);
    $group->get('/crossdock/kpis/resumen',              [\App\Controllers\CrossDockController::class, 'kpis']);
    $group->get('/crossdock/export/csv',                [\App\Controllers\CrossDockController::class, 'export']);

    // ── UBICACIONES ML (Mapa físico del almacén) ──────────────────────────
    $group->get('/ubicaciones-ml',                      [\App\Controllers\UbicacionesController::class, 'index']);
    $group->get('/ubicaciones-ml/{id}',                 [\App\Controllers\UbicacionesController::class, 'show']);
    $group->post('/ubicaciones-ml',                     [\App\Controllers\UbicacionesController::class, 'crear']);
    $group->put('/ubicaciones-ml/{id}',                 [\App\Controllers\UbicacionesController::class, 'actualizar']);
    $group->patch('/ubicaciones-ml/{id}/estado',        [\App\Controllers\UbicacionesController::class, 'cambiarEstado']);
    $group->delete('/ubicaciones-ml/{id}',              [\App\Controllers\UbicacionesController::class, 'eliminar']);
    $group->get('/ubicaciones-ml/filtro/disponibles',   [\App\Controllers\UbicacionesController::class, 'disponibles']);
    $group->get('/ubicaciones-ml/mapa/ocupacion',       [\App\Controllers\UbicacionesController::class, 'ocupacion']);
    $group->get('/ubicaciones-ml/export/csv',           [\App\Controllers\UbicacionesController::class, 'export']);
    $group->post('/ubicaciones-ml/importar',            [\App\Controllers\UbicacionesController::class, 'importar']);

    // ── YARD MANAGEMENT (Gestión de patio / muelles) ──────────────────────
    $group->get('/yard',                                [\App\Controllers\YardController::class, 'index']);
    $group->get('/yard/{id}',                           [\App\Controllers\YardController::class, 'show']);
    $group->post('/yard',                               [\App\Controllers\YardController::class, 'crear']);
    $group->put('/yard/{id}',                           [\App\Controllers\YardController::class, 'actualizar']);
    $group->post('/yard/{id}/entrada',                  [\App\Controllers\YardController::class, 'registrarEntrada']);
    $group->post('/yard/{id}/inicio-operacion',         [\App\Controllers\YardController::class, 'registrarInicioOperacion']);
    $group->post('/yard/{id}/fin-operacion',            [\App\Controllers\YardController::class, 'registrarFinOperacion']);
    $group->post('/yard/{id}/salida',                   [\App\Controllers\YardController::class, 'registrarSalida']);
    $group->post('/yard/{id}/cancelar',                 [\App\Controllers\YardController::class, 'cancelar']);
    $group->get('/yard/muelles/estado',                 [\App\Controllers\YardController::class, 'muelles']);
    $group->get('/yard/kpis/resumen',                   [\App\Controllers\YardController::class, 'kpis']);
    $group->get('/yard/export/csv',                     [\App\Controllers\YardController::class, 'export']);

    // ── WAVE PICKING (Consolidación de planillas en olas) ─────────────────
    $group->get('/wave',                                [\App\Controllers\WaveController::class, 'index']);
    $group->get('/wave/{id}',                           [\App\Controllers\WaveController::class, 'show']);
    $group->post('/wave',                               [\App\Controllers\WaveController::class, 'crear']);
    $group->post('/wave/{id}/iniciar',                  [\App\Controllers\WaveController::class, 'iniciar']);
    $group->post('/wave/{id}/completar',                [\App\Controllers\WaveController::class, 'completar']);
    $group->post('/wave/{id}/cancelar',                 [\App\Controllers\WaveController::class, 'cancelar']);
    $group->post('/wave/auto-generar',                  [\App\Controllers\WaveController::class, 'autoGenerar']);
    $group->get('/wave/kpis/resumen',                   [\App\Controllers\WaveController::class, 'kpis']);
    $group->get('/wave/export/csv',                     [\App\Controllers\WaveController::class, 'export']);

})->add(new \App\Middleware\TenantMiddleware())
  ->add(new \App\Middleware\JwtMiddleware());

// Ruta pública para información de conexión
$app->get('/api/system/connection-info', [\App\Controllers\SystemController::class, 'getConnectionInfo']);

// ── TMS: rutas unificadas (JWT admin o ApiKey M2M — TmsAuthMiddleware) ──────
$app->group('/api/tms', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('/stock',                     [\App\Controllers\TmsController::class, 'stock']);
    $group->get('/ordenes',                   [\App\Controllers\TmsController::class, 'ordenes']);
    $group->get('/despachos',                 [\App\Controllers\TmsController::class, 'despachos']);
    $group->post('/despacho/{id}/transportar',[\App\Controllers\TmsController::class, 'marcarEnTransito']);
    $group->get('/keys',                      [\App\Controllers\TmsController::class, 'listKeys']);
    $group->post('/keys',                     [\App\Controllers\TmsController::class, 'createKey']);
    $group->delete('/keys/{id}',              [\App\Controllers\TmsController::class, 'revokeKey']);
    $group->post('/webhook',                  [\App\Controllers\TmsController::class, 'webhook']);
})->add(new \App\Middleware\TenantMiddleware())
  ->add(new \App\Middleware\TmsAuthMiddleware());

$app->run();
