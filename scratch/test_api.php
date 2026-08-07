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

echo "=== TEST QUERY /api/rotacion/abc-xyz ===" . PHP_EOL;

$empId = 2;
$sucId = 2;

$q = Capsule::table('clasificaciones_abc_xyz as c')
    ->join('productos as p', 'c.producto_id', '=', 'p.id')
    ->where('c.empresa_id',  $empId)
    ->when($sucId, fn($q) => $q->where('c.sucursal_id', $sucId))
    ->where('c.vigente', true)
    ->select(
        'c.id', 'c.empresa_id', 'c.sucursal_id', 'c.producto_id',
        'c.clase_abc', 'c.clase_xyz', 'c.segmento',
        'c.total_valor', 'c.total_unidades',
        'c.pct_valor', 'c.pct_unidades',
        'c.cv_demanda', 'c.periodos',
        'c.vigente', 'c.calculado_at',
        'p.nombre', 'p.nombre as producto_nombre',
        'p.codigo_interno', 'p.codigo_interno as codigo'
    );

$total = (clone $q)->count();
$items = $q->orderBy('c.total_valor', 'desc')->limit(5)->get();

echo "Total items found: " . $total . PHP_EOL;
print_r($items->toArray());
