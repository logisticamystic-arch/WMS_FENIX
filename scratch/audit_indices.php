<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$indices = Capsule::select("SHOW INDEX FROM inventarios WHERE Non_unique = 0");
echo "Unique Indices on 'inventarios':\n";
foreach ($indices as $idx) {
    echo " - Key: {$idx->Key_name}, Column: {$idx->Column_name}\n";
}

$trigger = Capsule::select("SHOW TRIGGERS LIKE 'inventarios'");
if ($trigger) {
    echo "\nTriggers found on 'inventarios':\n";
    print_r($trigger);
} else {
    echo "\nNo triggers found on 'inventarios'.\n";
}
