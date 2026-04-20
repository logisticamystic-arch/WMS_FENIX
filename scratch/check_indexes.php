<?php
include __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    echo "--- TABLE: producto_eans ---\n";
    $indexes = DB::select("SHOW INDEX FROM producto_eans");
    foreach($indexes as $idx) {
        printf("%s | Unique: %s | Column: %s\n", $idx->Key_name, $idx->Non_unique == 0 ? 'YES' : 'NO', $idx->Column_name);
    }
    
    echo "\n--- SCHEMA: producto_eans ---\n";
    $cols = DB::select("DESCRIBE producto_eans");
    print_r($cols);

    echo "\n--- TABLE: productos ---\n";
    $indexesProd = DB::select("SHOW INDEX FROM productos");
    foreach($indexesProd as $idx) {
        printf("%s | Unique: %s | Column: %s\n", $idx->Key_name, $idx->Non_unique == 0 ? 'YES' : 'NO', $idx->Column_name);
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
