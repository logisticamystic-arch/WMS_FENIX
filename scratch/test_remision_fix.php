<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Helpers/functions.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use Illuminate\Database\Capsule\Manager as Capsule;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT'] ?? '5432',
    'database' => $_ENV['DB_NAME'] ?? 'wms_fenix',
    'username' => $_ENV['DB_USER'] ?? 'postgres',
    'password' => $_ENV['DB_PASS'] ?? 'Logistica2101+',
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "=== PRUEBA ENDPOINT /packing/sesion/386/remision ===" . PHP_EOL;

$packingCtrl = new \App\Controllers\PackingController();
$reqFactory = new RequestFactory();
$resFactory = new ResponseFactory();

$request = $reqFactory->createRequest('GET', '/api/packing/sesion/386/remision')
    ->withAttribute('user', (object)['id' => 1, 'empresa_id' => 2, 'sucursal_id' => 2, 'rol' => 'Admin']);
$response = $resFactory->createResponse(200);

$res = $packingCtrl->getRemision($request, $response, ['id' => 386]);
$html = (string)$res->getBody();

echo "Status Code: " . $res->getStatusCode() . PHP_EOL;
echo "Tiene Pedido 17271: " . (strpos($html, '17271') !== false ? 'SI' : 'NO') . PHP_EOL;
echo "Tiene Producto 101072 (COLESLAW): " . (strpos($html, '101072') !== false ? 'SI' : 'NO') . PHP_EOL;

if (strpos($html, '101072') !== false) {
    echo "--- SNIPPET AGOTADOS EN EL HTML DE REMISIÓN ---" . PHP_EOL;
    $pos = strpos($html, 'PRODUCTOS AGOTADOS');
    if ($pos !== false) {
        echo substr($html, $pos, 600) . PHP_EOL;
    }
}
