<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable('.')->load();
$pdo = new PDO('pgsql:host='.$_ENV['DB_HOST'].';port=5432;dbname='.$_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$today = date('Y-m-d');
$sql = "
SELECT 
    aux.nombre,
    (SELECT EXTRACT(EPOCH FROM (MAX(COALESCE(NULLIF(op.hora_fin, '00:00:00')::time, CURRENT_TIME)) - MIN(op.hora_inicio::time))) / 60
     FROM orden_pickings op 
     WHERE op.id IN (SELECT d2.orden_picking_id FROM picking_detalles d2 WHERE d2.auxiliar_id = aux.id) 
     AND DATE(op.created_at) = '$today'
     AND op.hora_inicio IS NOT NULL
     AND op.hora_inicio != '00:00:00'
    ) as total_minutos,
    (SELECT COUNT(d.id) FROM picking_detalles d WHERE d.auxiliar_id = aux.id AND DATE(d.created_at) = '$today') as lineas
FROM personal aux
WHERE aux.id IN (22, 19, 17)
";
$stmt = $pdo->query($sql);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
