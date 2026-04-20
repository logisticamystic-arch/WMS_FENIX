<?php
/**
 * Runner CLI para ejecutar migraciones pendientes.
 */
require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

echo "--- INICIANDO MIGRACIONES CLI ---\n";

try {
    $schema = Capsule::schema();
    
    // 1. Asegurar tabla migrations
    if (!$schema->hasTable('migrations')) {
        echo "Creando tabla 'migrations'...\n";
        $schema->create('migrations', function ($t) {
            $t->increments('id');
            $t->string('migration');
            $t->integer('batch');
            $t->timestamp('ran_at')->useCurrent();
        });
    }

    // 2. Obtener migraciones ejecutadas
    $ran = Capsule::table('migrations')->pluck('migration')->toArray();
    $batch = (int)(Capsule::table('migrations')->max('batch') ?? 0) + 1;

    // 3. Buscar archivos
    $files = glob(__DIR__ . '/../database/migrations/*.php');
    sort($files);

    $executed = 0;
    foreach ($files as $file) {
        $name = basename($file, '.php');
        if (in_array($name, $ran)) continue;

        echo "Ejecutando: $name... ";
        
        try {
            $migration = require $file;
            
            if (is_array($migration) && isset($migration['up'])) {
                $migration['up']();
            } elseif (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            } else {
                echo "SKIP (No tiene método 'up')\n";
                continue;
            }

            Capsule::table('migrations')->insert([
                'migration' => $name,
                'batch' => $batch,
                'ran_at' => date('Y-m-d H:i:s')
            ]);
            
            echo "OK ✅\n";
            $executed++;
        } catch (\Throwable $e) {
            echo "ERROR ❌\n";
            echo "Mensaje: " . $e->getMessage() . "\n";
            echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
            // No detenemos el loop para intentar las demás, aunque usualmente fallarán
        }
    }

    echo "--- MIGRACIONES FINALIZADAS ---\n";
    echo "Total ejecutadas: $executed\n";

} catch (\Throwable $e) {
    echo "ERROR CRÍTICO: " . $e->getMessage() . "\n";
}
