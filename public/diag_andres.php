<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dsn = 'pgsql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'wms_fenix');
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'postgres', $_ENV['DB_PASS'] ?? '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$today = date('Y-m-d');

$stmt = $pdo->query("SELECT id, nombre FROM personal WHERE nombre LIKE '%ANDRES ACOSTA%' LIMIT 1");
$andres = $stmt->fetch(PDO::FETCH_ASSOC);

if ($andres) {
    echo "Auxiliar: " . $andres['nombre'] . " (ID: " . $andres['id'] . ")\n";
    $stmt = $pdo->query("
        SELECT DISTINCT o.id, o.estado, o.hora_inicio, o.hora_fin 
        FROM orden_pickings o 
        JOIN picking_detalles d ON o.id = d.orden_picking_id 
        WHERE d.auxiliar_id = {$andres['id']} AND DATE(o.created_at) = '$today'
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Orders:\n";
    print_r($orders);
} else {
    echo "No se encontró el auxiliar.\n";
}
