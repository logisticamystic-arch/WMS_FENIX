<?php
require 'bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;
$tables = Capsule::select('SHOW TABLES');
foreach ($tables as $table) {
    echo current((array)$table) . "\n";
}
