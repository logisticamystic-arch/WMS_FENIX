<?php
require 'bootstrap.php';
$prod = \App\Models\Producto::first();
if ($prod) {
    echo "Columns: " . implode(', ', array_keys($prod->getAttributes())) . "\n";
    echo "Fillable: " . implode(', ', $prod->getFillable()) . "\n";
} else {
    echo "No products found.\n";
}
