<?php
/**
 * Prooriente WMS — API Entry Point
 * Slim Framework 4 with Eloquent ORM
 */

// Buffer ALL output so stray PHP errors/notices never corrupt the JSON response.
ob_start();

// Suppress PHP error output in HTTP responses — errors must not corrupt JSON bodies.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// ── Log de errores en archivo ─────────────────────────────────────────────────
$logFile = dirname(__DIR__) . '/logs/app.log';
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
error_reporting(E_ALL);

// Helper global para escribir al log con contexto
function wmsLog(string $level, string $message, array $context = []): void {
    global $logFile;
    $ts      = date('Y-m-d H:i:s');
    $ctx     = empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $line    = "[{$ts}] [{$level}] {$message}{$ctx}" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// Only reset opcache in development
if (function_exists('opcache_reset') && getenv('APP_ENV') === 'development') {
    opcache_reset();
}

require_once __DIR__ . '/../bootstrap.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;

$app = AppFactory::create();

// Set base path for XAMPP subdirectory
$app->setBasePath('/WMS_PROORIENTE/public');

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

    $status  = 500;
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

    $response = $app->getResponseFactory()->createResponse($status);
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

// Add JSON body parsing middleware
$app->addBodyParsingMiddleware();

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
        'app'       => 'Prooriente WMS',
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

// ── Authenticated API routes ──────────────────────────────────────────────────
$app->group('/api', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('/auth/me', [\App\Controllers\AuthController::class, 'me']);

    // Módulo: Citas (Inbound)
    $group->get('/citas', [\App\Controllers\CitaController::class, 'index']);
    $group->post('/citas', [\App\Controllers\CitaController::class, 'store']);
    $group->put('/citas/{id}', [\App\Controllers\CitaController::class, 'update']);
    $group->delete('/citas/{id}', [\App\Controllers\CitaController::class, 'destroy']);
    $group->get('/citas/disponibilidad', [\App\Controllers\CitaController::class, 'getDisponibilidad']);

    // Módulo: Recepción (Inbound)
    $group->get('/recepciones', [\App\Controllers\RecepcionController::class, 'index']);
    $group->post('/recepciones', [\App\Controllers\RecepcionController::class, 'store']);
    $group->get('/recepciones/{id}', [\App\Controllers\RecepcionController::class, 'ver']);
    $group->post('/recepciones/{id}/detalle', [\App\Controllers\RecepcionController::class, 'addDetail']);
    $group->post('/recepciones/{id}/confirm', [\App\Controllers\RecepcionController::class, 'confirm']);
    $group->delete('/recepciones/{id}', [\App\Controllers\RecepcionController::class, 'eliminar']);

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
    $group->delete('/odc/{id}', [\App\Controllers\InboundController::class, 'deleteOrdenCompra']);

    // Módulo: Certificaciones (Outbound)
    $group->get('/certificaciones/reporte', [\App\Controllers\OutboundController::class, 'getCertificacionesReport']);
    $group->post('/certificaciones/start', [\App\Controllers\OutboundController::class, 'startCertificacion']);
    $group->post('/certificaciones/{id}/linea', [\App\Controllers\OutboundController::class, 'addCertificacionLinea']);
    $group->post('/certificaciones/{id}/end', [\App\Controllers\OutboundController::class, 'endCertificacion']);

    // Módulo: Devoluciones
    $group->get('/devoluciones', [\App\Controllers\DevolucionController::class, 'index']);
    $group->post('/devoluciones', [\App\Controllers\DevolucionController::class, 'store']);
    $group->get('/devoluciones/{id}', [\App\Controllers\DevolucionController::class, 'ver']);
    $group->delete('/devoluciones/{id}', [\App\Controllers\DevolucionController::class, 'eliminar']);

    // Módulo: Inventario
    $group->get('/inventario/stock', [\App\Controllers\InventarioController::class, 'getStock']);
    $group->get('/inventario/kardex', [\App\Controllers\InventarioController::class, 'getKardex']);
    $group->get('/inventario/conteos', [\App\Controllers\InventarioController::class, 'getConteos']);
    $group->get('/inventario/niveles-reposicion', [\App\Controllers\InventarioController::class, 'getNivelesReposicion']);
    $group->post('/inventario/traslado', [\App\Controllers\InventarioController::class, 'traslado']);
    $group->post('/inventario/ajuste', [\App\Controllers\InventarioController::class, 'ajuste']);
    $group->post('/inventario/conteo/nuevo', [\App\Controllers\InventarioController::class, 'crearConteo']);
    $group->post('/inventario/conteo/{id}/linea', [\App\Controllers\InventarioController::class, 'addLineaConteo']);
    $group->post('/inventario/conteo/{id}/finalizar', [\App\Controllers\InventarioController::class, 'finalizarConteo']);
    $group->post('/inventario/niveles-reposicion', [\App\Controllers\InventarioController::class, 'saveNivelReposicion']);

    // Módulo: Picking (Outbound)
    $group->get('/picking', [\App\Controllers\PickingController::class, 'listar']);
    $group->post('/picking', [\App\Controllers\PickingController::class, 'crearBatch']);
    $group->post('/picking/importar', [\App\Controllers\PickingController::class, 'importarPedidos']);
    $group->get('/picking/dashboard', [\App\Controllers\PickingController::class, 'dashboard']);
    $group->get('/picking/consolidados', [\App\Controllers\PickingController::class, 'consolidados']);
    $group->post('/picking/asignar-multiple', [\App\Controllers\PickingController::class, 'asignarMultiple']);
    $group->get('/picking/reabastecimientos', [\App\Controllers\PickingController::class, 'reabastecimientos']);
    $group->get('/picking/{id}', [\App\Controllers\PickingController::class, 'detalle']);
    $group->post('/picking/{orden_id}/generar-ruta', [\App\Controllers\PickingController::class, 'generateRoute']);
    $group->post('/picking/{orden_id}/confirmar-linea', [\App\Controllers\PickingController::class, 'confirmLine']);
    $group->post('/picking/{id}/completar', [\App\Controllers\PickingController::class, 'completar']);
    $group->post('/picking/{id}/marcar-faltante', [\App\Controllers\PickingController::class, 'marcarFaltante']);
    $group->post('/picking/reabast/{id}/completar', [\App\Controllers\PickingController::class, 'completarReabast']);
    $group->delete('/picking/{id}', [\App\Controllers\PickingController::class, 'eliminar']);

    // Módulo: Planillas (Certificación por Cliente)
    $group->get('/planillas', [\App\Controllers\PlanillaController::class, 'listar']);
    $group->get('/planillas/progreso', [\App\Controllers\PlanillaController::class, 'planillaProgreso']);
    $group->post('/planillas/asignar', [\App\Controllers\PlanillaController::class, 'asignar']);
    $group->get('/planillas/cert/dashboard', [\App\Controllers\PlanillaController::class, 'dashboard']);
    $group->post('/planillas/importar', [\App\Controllers\PlanillaController::class, 'importar']);
    $group->post('/planillas/cert/iniciar', [\App\Controllers\PlanillaController::class, 'iniciarCertificacion']);
    $group->get('/planillas/cert/{id}', [\App\Controllers\PlanillaController::class, 'verCertificacion']);
    $group->post('/planillas/cert/{id}/linea', [\App\Controllers\PlanillaController::class, 'registrarLinea']);
    $group->post('/planillas/cert/{id}/finalizar', [\App\Controllers\PlanillaController::class, 'finalizarCertificacion']);
    $group->get('/planillas/{id}', [\App\Controllers\PlanillaController::class, 'ver']);

    // Módulo: Despachos (Outbound Certification)
    $group->get('/despachos', [\App\Controllers\DespachoController::class, 'listar']);
    $group->post('/despachos', [\App\Controllers\DespachoController::class, 'store']);
    $group->get('/despachos/{id}', [\App\Controllers\DespachoController::class, 'ver']);
    $group->get('/despachos/{id}/reporte', [\App\Controllers\DespachoController::class, 'reporte']);
    $group->post('/despachos/{id}/certificar', [\App\Controllers\DespachoController::class, 'certify']);
    $group->post('/despachos/{id}/cerrar', [\App\Controllers\DespachoController::class, 'close']);
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
    $group->get('/reportes/dashboard-gerencial', [\App\Controllers\ReportesController::class, 'dashboardGerencial']);
    $group->get('/reportes/audit-log', [\App\Controllers\ReportesController::class, 'auditLog']);
    $group->get('/reportes/evaluacion-proveedores', [\App\Controllers\ReportesController::class, 'evaluacionProveedores']);

    // Módulo: Dashboard (Real-time Analytics)
    $group->get('/dashboard', [\App\Controllers\DashboardController::class, 'index']);

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

    // Módulo: Parametrización (Maestros)
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
    $group->get('/param/productos/buscar', [\App\Controllers\ParametrosController::class, 'buscarProductos']);
    $group->post('/param/productos', [\App\Controllers\ParametrosController::class, 'createProducto']);
    $group->put('/param/productos/{id}', [\App\Controllers\ParametrosController::class, 'editProducto']);
    $group->delete('/param/productos/{id}', [\App\Controllers\ParametrosController::class, 'deleteProducto']);
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
    $group->delete('/param/ubicaciones/{id}', [\App\Controllers\ParametrosController::class, 'deleteUbicacion']);
    $group->get('/param/proveedores', [\App\Controllers\ParametrosController::class, 'getProveedores']);
    $group->post('/param/proveedores', [\App\Controllers\ParametrosController::class, 'createProveedor']);
    $group->put('/param/proveedores/{id}', [\App\Controllers\ParametrosController::class, 'editProveedor']);
    $group->delete('/param/proveedores/{id}', [\App\Controllers\ParametrosController::class, 'deleteProveedor']);
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
    $group->post('/param/import-export/upload/{tipo}', [\App\Controllers\ImportExportController::class, 'uploadCSV']);

})->add(new \App\Middleware\JwtMiddleware());

// ── TMS Integration API v1 (API Key auth — machine-to-machine) ─────────────────
$app->group('/api/v1/tms', function (\Slim\Routing\RouteCollectorProxy $group) {
    // Stock snapshot for TMS
    $group->get('/stock', [\App\Controllers\TmsController::class, 'stock']);
    // Active outbound orders
    $group->get('/ordenes', [\App\Controllers\TmsController::class, 'ordenes']);
    // Dispatched shipments
    $group->get('/despachos', [\App\Controllers\TmsController::class, 'despachos']);
    // TMS notifies WMS a shipment is now in transit
    $group->post('/despacho/{id}/transportar', [\App\Controllers\TmsController::class, 'marcarEnTransito']);
    // TMS delivers event webhook to WMS
    $group->post('/webhook', [\App\Controllers\TmsController::class, 'webhook']);
    // API key management (admin via JWT still needed)
    $group->get('/keys', [\App\Controllers\TmsController::class, 'listKeys']);
    $group->post('/keys', [\App\Controllers\TmsController::class, 'createKey']);
    $group->delete('/keys/{id}', [\App\Controllers\TmsController::class, 'revokeKey']);
})->add(new \App\Middleware\ApiKeyMiddleware());

// Discard any stray output buffered before Slim runs (notices, warnings, BOM…)
ob_end_clean();
$app->run();
