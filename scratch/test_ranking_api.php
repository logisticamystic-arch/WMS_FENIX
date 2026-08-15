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

echo "=== VERIFICACIÓN ENDPOINT /tv/picking-ranking ===" . PHP_EOL;

$dtv = new \App\Controllers\DashboardTVController();
$reqFactory = new RequestFactory();
$resFactory = new ResponseFactory();

$request = $reqFactory->createRequest('GET', '/api/tv/picking-ranking')
    ->withAttribute('user', (object)['id' => 1, 'empresa_id' => 2, 'sucursal_id' => 2, 'rol' => 'Admin']);
$response = $resFactory->createResponse(200);

$res = $dtv->getPickingRanking($request, $response);
$data = json_decode((string)$res->getBody(), true);

print_r($data);
