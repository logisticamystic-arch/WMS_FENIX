<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$app = require __DIR__ . '/../bootstrap/app.php'; 
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
$ordenes = $q->with(['auxiliar:id,nombre', 'detalles.producto:id,empresa_id,nombre,codigo_interno,unidades_caja,ambiente_id', 'detalles.auxiliar:id,nombre'])
    ->withCount(['detalles as total_count'])
    ->limit(10)
    ->get();

echo "Ordenes: " . count($ordenes) . "\n";
if (count($ordenes) > 0) {
    echo "First order details count: " . count($ordenes[0]->detalles) . "\n";
    if (count($ordenes[0]->detalles) > 0) {
        echo "First detail auxiliar_id: " . $ordenes[0]->detalles[0]->auxiliar_id . "\n";
        echo "First detail auxiliar array: " . json_encode($ordenes[0]->detalles[0]->auxiliar) . "\n";
    }
}
