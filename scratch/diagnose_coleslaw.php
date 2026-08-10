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

echo "=== DIAGNÓSTICO PLANILLA 598 / PRODUCTO 101072 (COLESLAW) ===" . PHP_EOL;

// 1. Producto 101072
$prod = Capsule::table('productos')
    ->where('codigo_interno', '101072')
    ->orWhere('nombre', 'ILIKE', '%COLESLAW%')
    ->get();
echo "--- PRODUCTO COLESLAW ---" . PHP_EOL;
print_r($prod->toArray());

$prodId = $prod->first()->id ?? null;

// 2. Órdenes Planilla 598 o Pedido 17271
$ordenes = Capsule::table('orden_pickings')
    ->where(function($q) {
        $q->where('planilla_numero', 'ILIKE', '%598%')
          ->orWhere('numero_orden', 'ILIKE', '%598%')
          ->orWhere('numero_pedido', 'ILIKE', '%17271%')
          ->orWhere('numero_pedido', 'ILIKE', '%17248%')
          ->orWhere('numero_pedido', 'ILIKE', '%17263%');
    })
    ->get();

echo "--- ÓRDENEN DE PICKING ENCONTRADAS (" . count($ordenes) . ") ---" . PHP_EOL;
foreach ($ordenes as $o) {
    echo "ID: {$o->id} | Planilla: {$o->planilla_numero} | Orden: {$o->numero_orden} | Pedido: {$o->numero_pedido} | Cliente: {$o->cliente} | Estado: {$o->estado} | Cert: {$o->estado_certificacion}" . PHP_EOL;
}

$ordenIds = $ordenes->pluck('id')->all();

// 3. Detalles de picking para estas órdenes
if (!empty($ordenIds)) {
    $detalles = Capsule::table('picking_detalles as pd')
        ->join('productos as pr', 'pr.id', '=', 'pd.producto_id')
        ->whereIn('pd.orden_picking_id', $ordenIds)
        ->select('pd.*', 'pr.codigo_interno', 'pr.nombre as producto_nombre', 'pr.unidades_caja')
        ->get();

    echo "--- TODOS LOS DETALLES DE PICKING DE PLANILLA 598 (" . count($detalles) . ") ---" . PHP_EOL;
    foreach ($detalles as $d) {
        echo "ID: {$d->id} | OrdenID: {$d->orden_picking_id} | Code: {$d->codigo_interno} | Prod: {$d->producto_nombre} | Sol: {$d->cantidad_solicitada} | Pick: {$d->cantidad_pickeada} | Estado: '{$d->estado}' | Amb: '{$d->ambiente}'" . PHP_EOL;
    }

    $faltantes = Capsule::table('picking_faltantes as pf')
        ->join('productos as pr', 'pr.id', '=', 'pf.producto_id')
        ->whereIn('pf.orden_picking_id', $ordenIds)
        ->select('pf.*', 'pr.codigo_interno', 'pr.nombre as producto_nombre')
        ->get();

    echo "--- FALTANTES REGISTRADOS (" . count($faltantes) . ") ---" . PHP_EOL;
    foreach ($faltantes as $f) {
        echo "ID: {$f->id} | OrdenID: {$f->orden_picking_id} | Code: {$f->codigo_interno} | Faltante: {$f->cantidad_faltante} | Causa: {$f->causa}" . PHP_EOL;
    }
}
