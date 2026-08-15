<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
use Illuminate\Database\Capsule\Manager as Capsule;
$c = new Capsule;
$c->addConnection([
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'     => $_ENV['DB_HOST']   ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT']   ?? '5432',
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8', 'prefix' => '', 'schema' => 'public',
]);
$c->setAsGlobal(); $c->bootEloquent();

foreach (['Planilla 621','Planilla 613','Planilla 623'] as $num) {
    echo "== $num ==\n";
    $ordenes = Capsule::table('orden_pickings')->where('planilla_numero', $num)->get(['id','estado','estado_certificacion','estado_despacho','hora_fin']);
    foreach ($ordenes as $o) {
        echo "  orden={$o->id} estado={$o->estado} cert={$o->estado_certificacion} despacho={$o->estado_despacho} hora_fin={$o->hora_fin}\n";
    }
    $ordenIds = $ordenes->pluck('id');
    $detalles = Capsule::table('picking_detalles')->whereIn('orden_picking_id', $ordenIds)
        ->selectRaw('auxiliar_id, ambiente, estado, count(*) as n')
        ->groupBy('auxiliar_id','ambiente','estado')->get();
    foreach ($detalles as $d) {
        echo "    aux={$d->auxiliar_id} ambiente={$d->ambiente} estado={$d->estado} n={$d->n}\n";
    }
    echo "\n";
}
