<?php
require_once __DIR__ . '/../bootstrap.php';
use App\Controllers\InventarioV2Controller;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Factory\ServerRequestFactory;

$user = (object)[
    'id' => 1,
    'empresa_id' => 1,
    'sucursal_id' => 1,
    'rol' => 'Admin'
];

$request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v2/inventario/sesiones');
$request = $request->withAttribute('user', $user);
$request = $request->withQueryParams(['limit' => 50]);

$response = new SlimResponse();
$controller = new InventarioV2Controller();
$result = $controller->getSesiones($request, $response);

echo $result->getBody();
