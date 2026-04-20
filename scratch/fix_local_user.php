<?php
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Eloquent
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dbConfig = require __DIR__ . '/../config/database.php';
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection($dbConfig);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use Illuminate\Database\Capsule\Manager as Capsule;

try {
    echo "Conectando a PostgreSQL local...\n";
    
    // Verificar si existe la tabla
    $exists = Capsule::schema()->hasTable('personal');
    if (!$exists) {
        die("Error: La tabla 'personal' no existe en PostgreSQL local. Ejecute las migraciones primero.\n");
    }

    // Insertar o actualizar usuario de prueba
    // Documento: 1010, PIN: 1234
    // Usamos password_hash compatible con AuthController
    $documento = '1010';
    $pinHash = password_hash('1234', PASSWORD_BCRYPT);
    
    $user = Capsule::table('personal')->where('documento', $documento)->first();
    
    if ($user) {
        Capsule::table('personal')->where('documento', $documento)->update([
            'pin' => $pinHash,
            'activo' => true,
            'rol' => 'Admin'
        ]);
        echo "Usuario '1010' actualizado con éxito. PIN: 1234\n";
    } else {
        Capsule::table('personal')->insert([
            'empresa_id' => 1,
            'sucursal_id' => 1,
            'nombre' => 'ADMINISTRADOR LOCAL',
            'documento' => $documento,
            'pin' => $pinHash,
            'rol' => 'Admin',
            'activo' => true,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo "Usuario '1010' creado con éxito. PIN: 1234\n";
    }

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
