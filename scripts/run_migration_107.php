<?php
/**
 * Runner para migración 107 — Planilla de Cargue: rutas, pedidos y estado Entregado
 * Uso: php scripts/run_migration_107.php
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

$file = dirname(__DIR__) . '/database/migrations/107_planilla_cargue_rutas_entrega.php';
echo "\n--- Ejecutando 107_planilla_cargue_rutas_entrega ---\n";
try {
    $migration = require $file;
    if (is_array($migration) && isset($migration['up'])) {
        $migration['up']();
    }
    echo "\n=== Migración 107 completada ===\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
