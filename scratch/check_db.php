<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->load();
require __DIR__ . '/../../config/app.php';

use Illuminate\Database\Capsule\Manager as Capsule;

echo "=== DIAGNÓSTICO BASE DE DATOS ML ===" . PHP_EOL;
echo "Tablas:" . PHP_EOL;
try {
    echo "- clasificaciones_abc_xyz total: " . Capsule::table('clasificaciones_abc_xyz')->count() . PHP_EOL;
    echo "- clasificaciones_abc_xyz vigentes: " . Capsule::table('clasificaciones_abc_xyz')->where('vigente', true)->count() . PHP_EOL;
    $clasif = Capsule::table('clasificaciones_abc_xyz')->where('vigente', true)->get();
    print_r($clasif->toArray());
} catch (\Exception $e) {
    echo "Error clasificaciones: " . $e->getMessage() . PHP_EOL;
}

try {
    echo "- productos total: " . Capsule::table('productos')->count() . PHP_EOL;
    $prods = Capsule::table('productos')->limit(5)->get(['id', 'empresa_id', 'nombre', 'codigo_interno', 'activo']);
    print_r($prods->toArray());
} catch (\Exception $e) {
    echo "Error productos: " . $e->getMessage() . PHP_EOL;
}

try {
    echo "- inventarios total: " . Capsule::table('inventarios')->count() . PHP_EOL;
} catch (\Exception $e) {
    echo "Error inventarios: " . $e->getMessage() . PHP_EOL;
}
