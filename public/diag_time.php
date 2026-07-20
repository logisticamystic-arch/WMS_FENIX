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

$sql = "
SELECT 
    aux.nombre,
    (SELECT SUM(
        EXTRACT(EPOCH FROM (
            COALESCE(NULLIF(op.hora_fin, '00:00:00')::time, CURRENT_TIME) - op.hora_inicio::time
        )) / 60
    ) 
    FROM orden_pickings op 
    WHERE op.id IN (SELECT d2.orden_picking_id FROM picking_detalles d2 WHERE d2.auxiliar_id = aux.id) 
    AND DATE(op.created_at) = '$today'
    AND op.hora_inicio IS NOT NULL
    AND op.hora_inicio != '00:00:00'
    ) as total_minutos
FROM personal aux
WHERE aux.id IN (22, 19, 17)
";

$stmt = $pdo->query($sql);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
