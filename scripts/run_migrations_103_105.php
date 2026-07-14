<?php
/**
 * Runner para migraciones 103, 104, 105
 * Uso: php scripts/run_migrations_103_105.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
require dirname(__DIR__) . '/config/app.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => $_ENV['DB_DRIVER']   ?? 'pgsql',
    'host'     => $_ENV['DB_HOST']     ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT']     ?? '5432',
    'database' => $_ENV['DB_NAME']     ?? 'wms_fenix',
    'username' => $_ENV['DB_USER']     ?? 'postgres',
    'password' => $_ENV['DB_PASS']     ?? '',
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$migraciones = [
    '103_add_cliente_id_to_orden_pickings',
    '104_create_picking_consolidados',
    '105_create_picking_cert_ambiente',
];

foreach ($migraciones as $m) {
    $file = dirname(__DIR__) . "/database/migrations/{$m}.php";
    echo "\n--- Ejecutando {$m} ---\n";
    try {
        require $file;
    } catch (\Throwable $e) {
        echo "ERROR en {$m}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migraciones 103-105 completadas ===\n";
