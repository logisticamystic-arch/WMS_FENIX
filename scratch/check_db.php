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
    echo "- clasificaciones_abc_xyz total: " . Capsule::table('clasificaciones_abc_xyz')->count() . PHP_EOL;
    echo "- clasificaciones_abc_xyz vigentes: " . Capsule::table('clasificaciones_abc_xyz')->where('vigente', true)->count() . PHP_EOL;
    $clasif = Capsule::table('clasificaciones_abc_xyz')->where('vigente', true)->limit(5)->get();
    print_r($clasif->toArray());
} catch (\Exception $e) {
    echo "Error clasificaciones: " . $e->getMessage() . PHP_EOL;
}

try {
    echo "- productos total: " . Capsule::table('productos')->count() . PHP_EOL;
    $prods = Capsule::table('productos')->limit(5)->get(['id', 'empresa_id', 'nombre', 'codigo_interno', 'activo']);
    print_r($prods->toArray());
} catch (\Exception $e) {
    echo "Error productos: " . $e->getMessage() . PHP_EOL;
}

try {
    echo "- inventarios total: " . Capsule::table('inventarios')->count() . PHP_EOL;
} catch (\Exception $e) {
    echo "Error inventarios: " . $e->getMessage() . PHP_EOL;
}
