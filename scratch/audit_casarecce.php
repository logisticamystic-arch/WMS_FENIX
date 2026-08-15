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

echo "=== AUDITORÍA PROFUNDA PASTA CASARECCE [108049] Y PLANILLA 621 ===" . PHP_EOL;

// 1. Producto 108049
$prod = Capsule::table('productos')
    ->where('codigo_interno', '108049')
    ->orWhere('nombre', 'ILIKE', '%CASARECCE%')
    ->get();
echo "--- DATOS DEL PRODUCTO ---" . PHP_EOL;
print_r($prod->toArray());

// 2. Ordenes Planilla 621 / 622
$ordenes = Capsule::table('orden_pickings')
    ->where('planilla_numero', 'ILIKE', '%621%')
    ->orWhere('planilla_numero', 'ILIKE', '%622%')
    ->orWhere('numero_orden', 'ILIKE', '%621%')
    ->orWhere('numero_factura', '17280')
    ->orWhere('numero_factura', '17283')
    ->get();

echo "--- ÓRDENES DE PICKING PLANILLA 621 (" . count($ordenes) . ") ---" . PHP_EOL;
foreach ($ordenes as $o) {
    echo "ID: {$o->id} | Planilla: {$o->planilla_numero} | Orden: {$o->numero_orden} | Factura: {$o->numero_factura} | Cliente: {$o->cliente} | Sucursal: {$o->sucursal_entrega}" . PHP_EOL;
}

$ordenIds = $ordenes->pluck('id')->all();

if (!empty($ordenIds)) {
    // 3. Detalles de picking para CASARECCE
    $detalles = Capsule::table('picking_detalles as pd')
        ->join('productos as pr', 'pr.id', '=', 'pd.producto_id')
        ->whereIn('pd.orden_picking_id', $ordenIds)
        ->select('pd.*', 'pr.codigo_interno', 'pr.nombre as producto_nombre', 'pr.unidades_caja')
        ->get();

    echo "--- DETALLES DE PICKING PLANILLA 621 (" . count($detalles) . ") ---" . PHP_EOL;
    foreach ($detalles as $d) {
        if (strpos($d->producto_nombre, 'CASARECCE') !== false || $d->codigo_interno == '108049') {
            echo "--> DETALLE CASARECCE:" . PHP_EOL;
            print_r($d);
        }
    }

    // 4. Faltantes para CASARECCE
    $faltantes = Capsule::table('picking_faltantes as pf')
        ->join('productos as pr', 'pr.id', '=', 'pf.producto_id')
        ->whereIn('pf.orden_picking_id', $ordenIds)
        ->get();

    echo "--- FALTANTES REGISTRADOS (" . count($faltantes) . ") ---" . PHP_EOL;
    foreach ($faltantes as $f) {
        print_r($f);
    }
}
