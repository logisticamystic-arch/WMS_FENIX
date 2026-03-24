<?php
/**
 * Prooriente WMS — API Entry Point
 * Slim Framework 4 with Eloquent ORM
 */

// Only reset opcache in development (never in production — costs ~2ms per request)
if (function_exists('opcache_reset') && getenv('APP_ENV') === 'development') {
    opcache_reset();
}

require_once __DIR__ . '/../bootstrap.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Set base path for XAMPP subdirectory
$app->setBasePath('/Prooriente/public');

// Add error middleware
$app->addErrorMiddleware(
    filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN),
    true,
    true
);

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
    $group->get('/odc/buscar-producto', [\App\Controllers\InboundController::class, 'buscarProducto']);
    $group->get('/odc/{id}', [\App\Controllers\InboundController::class, 'getODC']);
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
    $group->post('/picking', [\App\Controllers\PickingController::class, 'crear']);
    $group->get('/picking/dashboard', [\App\Controllers\PickingController::class, 'dashboard']);
    $group->get('/picking/{id}', [\App\Controllers\PickingController::class, 'ver']);
    $group->post('/picking/{orden_id}/generar-ruta', [\App\Controllers\PickingController::class, 'generateRoute']);
    $group->post('/picking/{orden_id}/confirmar-linea', [\App\Controllers\PickingController::class, 'confirmLine']);
    $group->post('/picking/{id}/completar', [\App\Controllers\PickingController::class, 'completar']);
    $group->post('/picking/reabast/{id}/completar', [\App\Controllers\PickingController::class, 'completarReabast']);
    $group->delete('/picking/{id}', [\App\Controllers\PickingController::class, 'eliminar']);

    // Módulo: Despachos (Outbound Certification)
    $group->get('/despachos', [\App\Controllers\DespachoController::class, 'listar']);
    $group->post('/despachos', [\App\Controllers\DespachoController::class, 'store']);
    $group->get('/despachos/{id}', [\App\Controllers\DespachoController::class, 'ver']);
    $group->get('/despachos/{id}/reporte', [\App\Controllers\DespachoController::class, 'reporte']);
    $group->post('/despachos/{id}/certificar', [\App\Controllers\DespachoController::class, 'certify']);
    $group->post('/despachos/{id}/cerrar', [\App\Controllers\DespachoController::class, 'close']);
    $group->delete('/despachos/{id}', [\App\Controllers\DespachoController::class, 'eliminar']);

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

    // Módulo: Dashboard (Real-time Analytics)
    $group->get('/dashboard', [\App\Controllers\DashboardController::class, 'index']);

    // Módulo: Parametrización (Maestros)
    $group->get('/param/empresas', [\App\Controllers\ParametrosController::class, 'getEmpresas']);
    $group->post('/param/empresas', [\App\Controllers\ParametrosController::class, 'createEmpresa']);
    $group->get('/param/sucursales', [\App\Controllers\ParametrosController::class, 'getSucursales']);
    $group->post('/param/sucursales', [\App\Controllers\ParametrosController::class, 'createSucursal']);
    $group->put('/param/sucursales/{id}', [\App\Controllers\ParametrosController::class, 'editSucursal']);
    $group->get('/param/marcas', [\App\Controllers\ParametrosController::class, 'getMarcas']);
    $group->post('/param/marcas', [\App\Controllers\ParametrosController::class, 'createMarca']);
    $group->get('/param/productos', [\App\Controllers\ParametrosController::class, 'getProductos']);
    $group->post('/param/productos', [\App\Controllers\ParametrosController::class, 'createProducto']);
    $group->put('/param/productos/{id}', [\App\Controllers\ParametrosController::class, 'editProducto']);
    $group->get('/param/personal', [\App\Controllers\ParametrosController::class, 'getPersonal']);
    $group->post('/param/personal', [\App\Controllers\ParametrosController::class, 'createPersonal']);
    $group->put('/param/personal/{id}', [\App\Controllers\ParametrosController::class, 'editPersonal']);
    $group->get('/param/ubicaciones', [\App\Controllers\ParametrosController::class, 'getUbicaciones']);
    $group->post('/param/ubicaciones', [\App\Controllers\ParametrosController::class, 'createUbicacion']);
    $group->put('/param/ubicaciones/{id}', [\App\Controllers\ParametrosController::class, 'editUbicacion']);
    $group->get('/param/proveedores', [\App\Controllers\ParametrosController::class, 'getProveedores']);
    $group->post('/param/proveedores', [\App\Controllers\ParametrosController::class, 'createProveedor']);
    $group->put('/param/proveedores/{id}', [\App\Controllers\ParametrosController::class, 'editProveedor']);
    $group->get('/param/productos/{id}/eans', [\App\Controllers\ParametrosController::class, 'getProductoEans']);
    $group->post('/param/productos/{id}/eans', [\App\Controllers\ParametrosController::class, 'addProductoEan']);
    $group->put('/param/productos/{id}/eans/{ean_id}', [\App\Controllers\ParametrosController::class, 'updateProductoEan']);
    $group->delete('/param/productos/{id}/eans/{ean_id}', [\App\Controllers\ParametrosController::class, 'deleteProductoEan']);
    $group->get('/param/clientes', [\App\Controllers\ParametrosController::class, 'getClientes']);
    $group->post('/param/clientes', [\App\Controllers\ParametrosController::class, 'createCliente']);
    $group->put('/param/clientes/{id}', [\App\Controllers\ParametrosController::class, 'updateCliente']);
    $group->get('/param/roles', [\App\Controllers\ParametrosController::class, 'getRoles']);
    $group->get('/param/permisos-matriz/{rol}', [\App\Controllers\ParametrosController::class, 'getPermissionsMatrix']);
    $group->post('/param/permisos-toggle', [\App\Controllers\ParametrosController::class, 'togglePermission']);
    $group->get('/param/rutas', [\App\Controllers\ParametrosController::class, 'getRutas']);
    $group->post('/param/rutas', [\App\Controllers\ParametrosController::class, 'createRuta']);
    $group->put('/param/rutas/{id}', [\App\Controllers\ParametrosController::class, 'updateRuta']);
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

$app->run();
