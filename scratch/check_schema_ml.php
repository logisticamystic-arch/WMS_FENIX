<?php
require 'bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

echo "--- PRODUCTOS ---\n";
$prodCols = Capsule::getSchemaBuilder()->getColumnListing('productos');
print_r($prodCols);
$prod = Capsule::table('productos')->first();
print_r($prod);

echo "\n--- INVENTARIOS ---\n";
$invCols = Capsule::getSchemaBuilder()->getColumnListing('inventarios');
print_r($invCols);
$inv = Capsule::table('inventarios')->first();
print_r($inv);
