<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dsn = 'pgsql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'wms_fenix');
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'postgres', $_ENV['DB_PASS'] ?? '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Test `/picking` output structure specifically for the `auxiliar` on details.
$stmt = $pdo->query("SELECT o.id as o_id, d.id as d_id, d.auxiliar_id, a.nombre FROM orden_pickings o JOIN picking_detalles d ON o.id = d.orden_picking_id LEFT JOIN personal a ON d.auxiliar_id = a.id WHERE DATE(o.created_at) = CURRENT_DATE LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
