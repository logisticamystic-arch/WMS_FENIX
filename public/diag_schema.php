<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable('.')->load();
$pdo = new PDO('pgsql:host='.$_ENV['DB_HOST'].';port=5432;dbname='.$_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'picking_detalles'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
