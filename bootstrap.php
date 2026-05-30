<?php
/**
 * Bootstrap — inicializa Eloquent ORM, timezone y configuración global.
 *
 * Performance notes:
 *  - Capsule se instancia UNA sola vez por proceso PHP (shared memory en Apache prefork).
 *  - El EventDispatcher se omite si ELOQUENT_EVENTS=false en .env (ahorra ~2 ms/req).
 *  - Se verifica la conexión al inicio para fallar rápido y con mensaje claro.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

// ── Cargar variables de entorno ───────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createMutable(__DIR__);
$dotenv->safeLoad();

if (file_exists(__DIR__ . '/.env.local')) {
    $dotenvLocal = Dotenv\Dotenv::createMutable(__DIR__, '.env.local');
    $dotenvLocal->safeLoad();
}

// ── Timezone — Bogotá UTC-5 ───────────────────────────────────────────────────
date_default_timezone_set('America/Bogota');

// ── Configuraciones ───────────────────────────────────────────────────────────
$dbConfig  = require __DIR__ . '/config/database.php';
$appConfig = require __DIR__ . '/config/app.php';

// ── Eloquent ORM ──────────────────────────────────────────────────────────────
$capsule = new Capsule;
$capsule->addConnection($dbConfig);

/*
 * EventDispatcher habilita callbacks onCreate/onUpdate en modelos.
 * Si tu proyecto no usa Eloquent events, desactívalo para ahorrar CPU:
 *   ELOQUENT_EVENTS=false  en .env
 */
$eventsEnabled = filter_var(
    getenv('ELOQUENT_EVENTS') ?: ($_ENV['ELOQUENT_EVENTS'] ?? 'true'),
    FILTER_VALIDATE_BOOLEAN
);
if ($eventsEnabled) {
    $capsule->setEventDispatcher(new Dispatcher(new Container));
}

$capsule->setAsGlobal();
$capsule->bootEloquent();

// PostgreSQL: SET TIME ZONE para cada conexión nueva (equivalente al MYSQL_ATTR_INIT_COMMAND de MySQL)
if (($dbConfig['driver'] ?? 'mysql') === 'pgsql') {
    try {
        Capsule::connection()->statement("SET TIME ZONE 'America/Bogota'");
    } catch (\Exception $e) {
        // no bloquea arranque si la conexión aún no está lista
    }
}

// ── Verificación de conexión en entorno de desarrollo ─────────────────────────
// Solo comprueba en local para no añadir latencia en producción.
$appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production');
if ($appEnv === 'development') {
    try {
        Capsule::connection()->getPdo();
    } catch (\Exception $e) {
        $logFile = __DIR__ . '/logs/app.log';
        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . "] [FATAL] DB connection failed: " . $e->getMessage() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => true,
            'message' => 'Base de datos no disponible. Verifica la configuración en .env',
        ]);
        exit;
    }
}
