<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Helpers/functions.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use Illuminate\Database\Capsule\Manager as Capsule;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

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

echo "=== PRUEBA DE CORRECCIÓN EN REMISIÓN DE PACKING ===" . PHP_EOL;

// Buscar sesión de packing para CLAP CHICKEN VIVA ENVIGADO o para estas órdenes
$sesion = Capsule::table('packing_sesiones')
    ->where('sucursal_entrega', 'ILIKE', '%CHICKEN VIVA%')
    ->orderBy('id', 'desc')
    ->first();

if (!$sesion) {
    echo "No se encontró sesión de packing específica, probando lógica sobre planilla 'Planilla 598'..." . PHP_EOL;
} else {
    echo "Sesión de packing encontrada: ID " . $sesion->id . PHP_EOL;
}

$sesionOrdenIds = [1368, 1369]; // Las que tenían items en packing_items

// Con la lógica nueva:
$planillasSesion = Capsule::table('orden_pickings')
    ->whereIn('id', $sesionOrdenIds)
    ->pluck('planilla_numero')
    ->filter()
    ->unique()
    ->toArray();

$ordenesObj = Capsule::table('orden_pickings')
    ->where('empresa_id', 2)
    ->where(function($q) use ($sesionOrdenIds, $planillasSesion) {
        $q->whereIn('id', $sesionOrdenIds);
        if (!empty($planillasSesion)) {
            $q->orWhere(function($subQ) use ($planillasSesion) {
                $subQ->whereIn('planilla_numero', $planillasSesion)
                     ->whereIn('estado', ['Completada', 'EnProceso', 'Cerrada']);
            });
        }
    })
    ->get(['id', 'numero_orden', 'numero_factura', 'numero_pedido', 'planilla_numero', 'fecha_movimiento']);

echo "Órdenes resultantes para la remisión (" . count($ordenesObj) . "):" . PHP_EOL;
foreach ($ordenesObj as $o) {
    echo " - ID: {$o->id} | Factura/Pedido: {$o->numero_factura} | Orden: {$o->numero_orden} | Planilla: {$o->planilla_numero}" . PHP_EOL;
}

$ordenIds = $ordenesObj->pluck('id')->toArray();

// Obtener faltantes
$agotados = Capsule::table('picking_faltantes as pf')
    ->join('productos as p', 'p.id', '=', 'pf.producto_id')
    ->whereIn('pf.orden_picking_id', $ordenIds)
    ->select('pf.id', 'pf.orden_picking_id', 'p.codigo_interno', 'p.nombre', 'pf.cantidad_faltante')
    ->get();

echo "Faltantes que aparecerán en la remisión (" . count($agotados) . "):" . PHP_EOL;
foreach ($agotados as $a) {
    echo " - Code: {$a->codigo_interno} | Producto: {$a->nombre} | Cant: {$a->cantidad_faltante}" . PHP_EOL;
}
