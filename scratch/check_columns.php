<?php
require 'bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$columns = Capsule::select("SHOW COLUMNS FROM conteo_inventarios");
echo json_encode($columns, JSON_PRETTY_PRINT);
