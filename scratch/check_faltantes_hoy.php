<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver'   => 'pgsql',
    'host'     => $_ENV['DB_HOST'],
    'port'     => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$C = Illuminate\Database\Capsule\Manager::class;

echo "=== picking_faltantes por dia (ultimos 5 dias) ===\n";
$rows = $C::table('picking_faltantes')
    ->select($C::raw('DATE(created_at) as dia'), $C::raw('COUNT(*) as total'))
    ->where('created_at', '>=', date('Y-m-d', strtotime('-5 days')))
    ->groupBy('dia')->orderBy('dia')->get();
foreach ($rows as $r) echo "{$r->dia}: {$r->total}\n";

echo "\n=== picking_detalles estado=Faltante por dia (ultimos 5 dias, por updated_at) ===\n";
$rows2 = $C::table('picking_detalles')
    ->select($C::raw('DATE(updated_at) as dia'), $C::raw('COUNT(*) as total'))
    ->where('estado', 'Faltante')
    ->where('updated_at', '>=', date('Y-m-d', strtotime('-5 days')))
    ->groupBy('dia')->orderBy('dia')->get();
foreach ($rows2 as $r) echo "{$r->dia}: {$r->total}\n";

echo "\n=== orden_pickings actividad por dia (ultimos 5 dias) ===\n";
$rows3 = $C::table('orden_pickings')
    ->select($C::raw('DATE(created_at) as dia'), $C::raw('COUNT(*) as total'))
    ->where('created_at', '>=', date('Y-m-d', strtotime('-5 days')))
    ->groupBy('dia')->orderBy('dia')->get();
foreach ($rows3 as $r) echo "{$r->dia}: {$r->total}\n";

echo "\n=== Hoy: ultimos 10 registros picking_faltantes (cualquier hora) ===\n";
$hoy = $C::table('picking_faltantes')->where('created_at','>=',date('Y-m-d').' 00:00:00')->orderBy('created_at','desc')->limit(10)->get();
foreach ($hoy as $r) echo "{$r->created_at} | prod:{$r->producto_id} | causa:{$r->causa}\n";
if ($hoy->isEmpty()) echo "(vacio)\n";

echo "\n=== Hoy: picking_detalles con estado Faltante, muestras ===\n";
$hoyD = $C::table('picking_detalles')->where('estado','Faltante')->where('updated_at','>=',date('Y-m-d').' 00:00:00')->orderBy('updated_at','desc')->limit(10)->get();
foreach ($hoyD as $r) echo "id:{$r->id} orden:{$r->orden_picking_id} prod:{$r->producto_id} sol:{$r->cantidad_solicitada} pick:{$r->cantidad_pickeada} ubic:{$r->ubicacion_id} novedad:{$r->novedad} updated:{$r->updated_at}\n";
if ($hoyD->isEmpty()) echo "(vacio)\n";
