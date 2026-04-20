<?php
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Eloquent
$dbConfig = [
    'driver'    => 'mysql',
    'host'      => '127.0.0.1',
    'database'  => 'wms_prooriente',
    'username'  => 'root',
    'password'  => '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
];

$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection($dbConfig);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use Illuminate\Database\Capsule\Manager as Capsule;

try {
    echo "Conectando a MySQL local (XAMPP)...\n";
    
    if (!Capsule::schema()->hasTable('personal')) {
        die("Error: La tabla 'personal' no existe en tu MySQL local.\n");
    }

    $personal = Capsule::table('personal')
        ->select('id', 'nombre', 'documento', 'rol', 'activo')
        ->limit(5)
        ->get();

    if ($personal->isEmpty()) {
        echo "ALERTA: Tu tabla 'personal' está VACÍA en MySQL local.\n";
    } else {
        echo "Usuarios encontrados en MySQL local:\n";
        foreach ($personal as $p) {
            echo "- ID: {$p->id} | Nombre: {$p->nombre} | Doc: {$p->documento} | Activo: {$p->activo}\n";
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
