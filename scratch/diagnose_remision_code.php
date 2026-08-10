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

echo "=== DIAGNÓSTICO GENERACIÓN REMISIÓN ===" . PHP_EOL;

// 1. Probar consulta de remisionAgotadosHtml para orden_ids = [1368, 1369, 1370]
$ordenIds = [1368, 1369, 1370];

$agotados = Capsule::table('picking_faltantes as pf')
    ->join('productos as p', 'p.id', '=', 'pf.producto_id')
    ->leftJoin('causales_novedad as cn', 'cn.id', '=', 'pf.causal_id')
    ->join('orden_pickings as op', 'op.id', '=', 'pf.orden_picking_id')
    ->whereIn('pf.orden_picking_id', $ordenIds)
    ->select([
        'p.codigo_interno as codigo',
        'p.nombre',
        'pf.cantidad_solicitada',
        'pf.cantidad_faltante',
        'pf.causa',
        'cn.nombre as causal_nombre',
        'cn.afecta_nivel_servicio',
        'op.numero_factura',
        'op.numero_orden'
    ])
    ->get();

echo "Faltantes devueltos por remisionAgotadosHtml para órdenes [1368, 1369, 1370]:" . PHP_EOL;
print_r($agotados->toArray());

// 2. Probar items devueltos por certRemisionDirecta o remisionAmbientesHtml
$itemsCertificados = Capsule::table('picking_detalles as pd')
    ->join('productos as p', 'p.id', '=', 'pd.producto_id')
    ->whereIn('pd.orden_picking_id', $ordenIds)
    ->where('pd.cantidad_pickeada', '>', 0)
    ->select('pd.id', 'pd.orden_picking_id', 'p.codigo_interno', 'p.nombre', 'pd.cantidad_pickeada')
    ->get();

echo "Items cert/pickeados devueltos:" . PHP_EOL;
print_r($itemsCertificados->toArray());
