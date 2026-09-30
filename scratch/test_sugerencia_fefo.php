<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Helpers/functions.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT'] ?? '5432',
    'database' => $_ENV['DB_NAME'] ?? 'wms_fenix',
    'username' => $_ENV['DB_USER'] ?? 'postgres',
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$controller = new class extends \App\Controllers\PickingController {
    public function testSugerir(int $empresaId, int $sucursalId, array $productoIds) {
        $ref = new \ReflectionMethod($this, '_sugerirUbicacionesFefo');
        $ref->setAccessible(true);
        return $ref->invoke($this, $empresaId, $sucursalId, $productoIds);
    }
};

$r = $controller->testSugerir(2, 2, [5755, 5760, 99999999]);
echo json_encode($r, JSON_PRETTY_PRINT), "\n";
