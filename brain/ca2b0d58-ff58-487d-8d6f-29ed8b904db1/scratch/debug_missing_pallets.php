<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

echo "--- RECEPCION DETALLES ---\n";
$detalles = Capsule::table('recepcion_detalles')->get();
print_r($detalles->toArray());

echo "\n--- RECEPCIONES ---\n";
$recep = Capsule::table('recepciones')->get();
print_r($recep->toArray());
