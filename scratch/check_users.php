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

echo "=== DIAGNÓSTICO BASE DE DATOS ML ===" . PHP_EOL;

try {
    $users = Capsule::table('users')->get(['id', 'nombre', 'documento', 'empresa_id', 'sucursal_id', 'rol']);
    echo "Users count: " . count($users) . PHP_EOL;
    foreach ($users as $u) {
        echo "ID: {$u->id} | Nombre: {$u->nombre} | Empresa: {$u->empresa_id} | Sucursal: {$u->sucursal_id} | Rol: {$u->rol}" . PHP_EOL;
    }
} catch (\Exception $e) {
    echo "Error users: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "Clasificaciones por Empresa/Sucursal:" . PHP_EOL;
$group = Capsule::table('clasificaciones_abc_xyz')
    ->selectRaw('empresa_id, sucursal_id, vigente, COUNT(*) as cnt')
    ->groupBy('empresa_id', 'sucursal_id', 'vigente')
    ->get();
foreach ($group as $g) {
    echo "Empresa: {$g->empresa_id} | Sucursal: {$g->sucursal_id} | Vigente: {$g->vigente} | Total: {$g->cnt}" . PHP_EOL;
}
