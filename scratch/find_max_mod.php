<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
$c = new Illuminate\Database\Capsule\Manager;
$c->addConnection(require __DIR__ . '/../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$maxUbicacion = Illuminate\Database\Capsule\Manager::table('ubicaciones')
    ->where('codigo', 'like', 'WP/EX/A-%')
    ->orderBy('codigo', 'desc')
    ->first();

echo "MÁXIMA UBICACIÓN PASILLO A: " . ($maxUbicacion->codigo ?? 'No encontrada') . "\n";
