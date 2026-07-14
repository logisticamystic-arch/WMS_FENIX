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

// ── Auto-fix: ampliar columnas zona/codigo en ubicaciones si son muy cortas ──
try {
    $pdo = Capsule::connection()->getPdo();
    $check = $pdo->query("SELECT column_name, character_maximum_length FROM information_schema.columns WHERE table_name='ubicaciones' AND column_name IN ('zona','codigo')");
    foreach ($check->fetchAll(\PDO::FETCH_ASSOC) as $col) {
        if ($col['column_name'] === 'zona' && (int)$col['character_maximum_length'] < 50) {
            $pdo->exec('ALTER TABLE ubicaciones ALTER COLUMN zona TYPE VARCHAR(50)');
        }
        if ($col['column_name'] === 'codigo' && (int)$col['character_maximum_length'] < 80) {
            $pdo->exec('ALTER TABLE ubicaciones ALTER COLUMN codigo TYPE VARCHAR(80)');
        }
    }
} catch (\Exception $e) {
    // no bloquea arranque
}

// ── Auto-seed: crear ambientes por defecto si la tabla existe y está vacía ────
try {
    $pdo2 = Capsule::connection()->getPdo();
    $hasTable = $pdo2->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name='ambientes')")->fetchColumn();
    if ($hasTable) {
        $count = (int)$pdo2->query("SELECT COUNT(*) FROM ambientes")->fetchColumn();
        if ($count === 0) {
            $empresas = $pdo2->query("SELECT id FROM empresas LIMIT 10")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($empresas as $empId) {
                $stmt = $pdo2->prepare("INSERT INTO ambientes (empresa_id, codigo, descripcion, color, activo, created_at, updated_at) VALUES (?,?,?,?,true,NOW(),NOW())");
                $stmt->execute([$empId, 'SECO', 'Productos temperatura ambiente', '#92400e']);
                $stmt->execute([$empId, 'REFRIGERADO', 'Productos refrigerados 2-8°C', '#0369a1']);
                $stmt->execute([$empId, 'CONGELADO', 'Productos congelados -18°C', '#7c3aed']);
            }
        }
    }
} catch (\Exception $e) {
    // no bloquea arranque
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
