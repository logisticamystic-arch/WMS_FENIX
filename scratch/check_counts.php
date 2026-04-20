<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
$c = new Illuminate\Database\Capsule\Manager;
$c->addConnection(require __DIR__ . '/../config/database.php');
$c->setAsGlobal(); $c->bootEloquent();

$countA = Illuminate\Database\Capsule\Manager::table('ubicaciones')->where('codigo', 'like', 'WP/EX/A-%')->count();
$countB = Illuminate\Database\Capsule\Manager::table('ubicaciones')->where('codigo', 'like', 'WP/EX/B-%')->count();

echo "TOTAL UBICACIONES PASILLO A: $countA\n";
echo "TOTAL UBICACIONES PASILLO B: $countB\n";

$maxSeq = Illuminate\Database\Capsule\Manager::table('ubicaciones')->max('secuencia_picking');
echo "MÁXIMA SECUENCIA ACTUAL: $maxSeq\n";
