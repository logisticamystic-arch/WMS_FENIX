<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$app = require __DIR__ . '/../bootstrap/app.php'; // get slim app
$container = $app->getContainer();
$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver' => 'pgsql',
    'host' => $_ENV['DB_HOST'],
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$today = date('Y-m-d');
$q = \App\Models\OrdenPicking::query();
$q->whereBetween('orden_pickings.created_at', [$today . ' 00:00:00', $today . ' 23:59:59']);
$ordenes = $q->with(['auxiliar:id,nombre', 'detalles.auxiliar:id,nombre'])
    ->withCount(['detalles as total_count'])
    ->limit(1)
    ->get();

echo json_encode($ordenes->toArray(), JSON_PRETTY_PRINT);
