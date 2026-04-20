<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$loc = Capsule::table('ubicaciones')->where('codigo', 'MUELLE 1')->first();
print_r($loc);
