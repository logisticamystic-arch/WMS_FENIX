<?php
require 'bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$columns = Capsule::select("SHOW COLUMNS FROM productos");
echo json_encode($columns, JSON_PRETTY_PRINT);
