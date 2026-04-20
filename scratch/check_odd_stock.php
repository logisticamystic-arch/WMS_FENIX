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

$stock = Capsule::table('inventarios')
    ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
    ->select('ubicaciones.codigo', 'ubicaciones.tipo_ubicacion', 'inventarios.cantidad')
    ->where('ubicaciones.tipo_ubicacion', 'NOT LIKE', 'Picking')
    ->where('ubicaciones.tipo_ubicacion', 'NOT LIKE', 'Almacenamiento')
    ->get();

echo "Stock en ubicaciones NO estándar:\n";
foreach($stock as $s) {
    echo "- Code: {$s->codigo} | Type: {$s->tipo_ubicacion} | Qty: {$s->cantidad}\n";
}
