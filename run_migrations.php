<?php
/**
 * Script standalone para ejecutar migraciones pendientes.
 * Uso: php run_migrations.php
 */
require_once __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

echo "=== Ejecutando migraciones pendientes ===\n\n";

// Crear tabla de migraciones si no existe
if (!DB::schema()->hasTable('migrations')) {
    DB::schema()->create('migrations', function ($t) {
        $t->increments('id');
        $t->string('migration');
        $t->integer('batch');
        $t->timestamp('ran_at')->useCurrent();
    });
    echo "[OK] Tabla 'migrations' creada.\n";
}

$migPath = __DIR__ . '/database/migrations/';
$files   = glob($migPath . '*.php');
sort($files);

$ran   = DB::table('migrations')->pluck('migration')->toArray();
$batch = (int)(DB::table('migrations')->max('batch') ?? 0) + 1;
$done  = [];
$errors = [];

foreach ($files as $file) {
    $name = basename($file, '.php');
    if (in_array($name, $ran)) {
        echo "[SKIP] {$name} (ya ejecutada)\n";
        continue;
    }
    try {
        $m = require $file;
        if (is_array($m) && isset($m['up'])) {
            $m['up']();
        }
        DB::table('migrations')->insert([
            'migration' => $name,
            'batch'     => $batch,
        ]);
        $done[] = $name;
        echo "[OK]   {$name}\n";
    } catch (\Exception $e) {
        $errors[] = "{$name}: " . $e->getMessage();
        echo "[ERR]  {$name}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Resumen ===\n";
echo "Ejecutadas: " . count($done) . "\n";
echo "Errores:    " . count($errors) . "\n";

if (!empty($done)) {
    echo "\nMigraciones aplicadas:\n";
    foreach ($done as $d) echo "  - {$d}\n";
}
if (!empty($errors)) {
    echo "\nErrores encontrados:\n";
    foreach ($errors as $e) echo "  - {$e}\n";
}
echo "\nListo.\n";
