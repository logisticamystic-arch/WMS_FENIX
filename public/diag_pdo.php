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
$stmt = $pdo->query("SELECT id FROM orden_pickings WHERE DATE(created_at) = '$today' LIMIT 1");
$orderId = $stmt->fetchColumn();

if ($orderId) {
    $stmt = $pdo->query("SELECT d.id, d.auxiliar_id, a.nombre FROM picking_detalles d LEFT JOIN personal a ON a.id = d.auxiliar_id WHERE d.orden_picking_id = $orderId LIMIT 5");
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Detalles of Order $orderId:\n";
    print_r($detalles);
} else {
    echo "No orders today.\n";
}
