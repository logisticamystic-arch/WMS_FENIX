<?php
require __DIR__ . '/../vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;

// Basic Eloquent Setup from index.php equivalent
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

$tipos = Capsule::table('ubicaciones')->distinct()->pluck('tipo_ubicacion');
echo "Tipos de ubicación encontrados:\n";
foreach($tipos as $t) echo "- [$t]\n";

$stockPatio = Capsule::table('inventarios')
    ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
    ->where('ubicaciones.tipo_ubicacion', 'Patio')
    ->where('inventarios.estado', 'Disponible')
    ->count();
echo "\nStock Disponible en Patio: $stockPatio registros.\n";
