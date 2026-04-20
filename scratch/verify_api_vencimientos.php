<?php
/**
 * Prueba de ejecución del controlador InventarioV2Controller para Vencimientos.
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\InventarioV2Controller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Factory\ServerRequestFactory;

try {
    // 1. Mock Request con Usuario Admin (id=1 usualmente)
    $user = (object)[
        'id' => 1,
        'empresa_id' => 1,
        'sucursal_id' => 1,
        'nombre' => 'Admin'
    ];
    
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v2/inventario/vencimientos');
    $request = $request->withAttribute('user', $user);
    $request = $request->withQueryParams(['solo_proximos' => 0]);

    $response = new SlimResponse();
    
    // 2. Ejecutar Controlador
    $controller = new InventarioV2Controller();
    $result = $controller->getVencimientos($request, $response);
    
    $status = $result->getStatusCode();
    $body = (string)$result->getBody();
    
    echo "STATUS: $status\n";
    if ($status === 200) {
        echo "API RESPONDIO CORRECTAMENTE ✅\n";
        // echo "BODY: " . substr($body, 0, 100) . "...\n";
    } else {
        echo "API FALLO CON STATUS $status ❌\n";
        echo "BODY: $body\n";
        exit(1);
    }

} catch (\Throwable $e) {
    echo "ERROR CRITICAL: " . $e->getMessage() . "\n";
    exit(1);
}
