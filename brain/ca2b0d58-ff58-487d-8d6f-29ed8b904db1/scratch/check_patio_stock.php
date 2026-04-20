<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$patios = Capsule::table('ubicaciones')
    ->where('sucursal_id', 1)
    ->where('tipo_ubicacion', 'Patio')
    ->get();

print_r($patios->toArray());
