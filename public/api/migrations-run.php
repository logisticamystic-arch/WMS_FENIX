<?php
/**
 * Endpoint para ejecutar migraciones (solo para desarrollo)
 * URI: /migrations-run
 */

header('Content-Type: application/json; charset=utf-8');

// Protección mínima
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

try {
    // Cargar .env
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();

    // Conectar BD
    $cfg = require __DIR__ . '/../config/database.php';
    $capsule = new Illuminate\Database\Capsule\Manager();
    $capsule->addConnection($cfg);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    // Crear tabla migrations si no existe
    $schema = $capsule::schema();
    if (!$schema->hasTable('migrations')) {
        $schema->create('migrations', function ($t) {
            $t->increments('id');
            $t->string('migration');
            $t->integer('batch');
            $t->timestamp('ran_at')->useCurrent();
        });
    }

    $ran    = $capsule::table('migrations')->pluck('migration')->toArray();
    $batch  = (int)($capsule::table('migrations')->max('batch') ?? 0) + 1;
    $files  = glob(__DIR__ . '/../database/migrations/*.php');
    sort($files);

    $done   = [];
    $errors = [];
    $skip   = [];

    foreach ($files as $file) {
        $name = basename($file, '.php');
        if (in_array($name, $ran)) { 
            $skip[] = $name; 
            continue;
        }

        try {
            $migration = require $file;
            // Ejecutar método 'up()'
            if (is_array($migration) && isset($migration['up'])) {
                $migration['up']();
            } elseif (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
            
            $capsule::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
            $done[] = $name;
        } catch (\Throwable $e) {
            $errors[] = [
                'name' => $name,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => count($errors) === 0,
        'migrations' => [
            'executed' => $done,
            'skipped' => $skip,
            'errors' => $errors
        ],
        'summary' => [
            'total_executed' => count($done),
            'total_skipped' => count($skip),
            'total_errors' => count($errors)
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
