<?php
require __DIR__ . '/../vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => '127.0.0.1',
    'database'  => 'wms_prooriente',
    'username'  => 'root',
    'password'  => '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$ubics = Capsule::table('ubicaciones')->get();
echo "Listado de Ubicaciones:\n";
foreach($ubics as $u) {
    echo "- Code: {$u->codigo} | Name: {$u->nombre} | Type: {$u->tipo_ubicacion} | Suc: {$u->sucursal_id}\n";
}
