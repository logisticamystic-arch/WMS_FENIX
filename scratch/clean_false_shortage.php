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

echo "=== LIMPIEZA DE FALSOS AGOTADOS EN picking_faltantes ===" . PHP_EOL;

// Eliminar faltantes con causa 'Separada sin inventario' donde el detalle de picking está Completado
$deleted = Capsule::table('picking_faltantes as pf')
    ->join('picking_detalles as pd', function($join) {
        $join->on('pd.orden_picking_id', '=', 'pf.orden_picking_id')
             ->on('pd.producto_id', '=', 'pf.producto_id');
    })
    ->where('pf.causa', 'Separada sin inventario')
    ->where('pd.estado', 'Completado')
    ->delete();

echo "Filas erróneas eliminadas en picking_faltantes: " . $deleted . PHP_EOL;
