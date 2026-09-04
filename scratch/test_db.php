<?php
$host = '127.0.0.1';
$db   = 'wms_fenix';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$stmt = $pdo->query("SELECT id, planilla_numero, numero_orden, planilla_lote FROM orden_picking ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_PRETTY_PRINT);
