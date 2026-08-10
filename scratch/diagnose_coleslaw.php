<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use Illuminate\Database\Capsule\Manager as Capsule;

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

echo "=== REVISIÓN DE PACKING ITEMS PARA ÓRDENES 1368, 1369, 1370 ===" . PHP_EOL;

$packingItems = Capsule::table('packing_items as pi')
    ->join('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
    ->whereIn('pd.orden_picking_id', [1368, 1369, 1370])
    ->get();

echo "Packing items de órdenes 1368, 1369, 1370 (" . count($packingItems) . "):" . PHP_EOL;
print_r($packingItems->toArray());

$sesiones = Capsule::table('packing_sesiones')
    ->whereIn('orden_picking_id', [1368, 1369, 1370])
    ->get();

echo "Packing sesiones por orden_picking_id (" . count($sesiones) . "):" . PHP_ENV;
print_r($sesiones->toArray());
