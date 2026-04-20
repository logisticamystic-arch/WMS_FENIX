<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
$c = new Illuminate\Database\Capsule\Manager;
$c->addConnection(require __DIR__ . '/../config/database.php');
$c->setAsGlobal(); $c->bootEloquent();

foreach (['A', 'B'] as $p) {
    $u = Illuminate\Database\Capsule\Manager::table('ubicaciones')->where('codigo', 'like', "WP/EX/$p-%")->first();
    echo "--- PASILLO $p ---\n";
    if ($u) {
        echo "ZONA: {$u->zona} | PASILLO: {$u->pasillo} | SECUENCIA INICIAL: {$u->secuencia_picking}\n";
    } else {
        echo "No encontrado.\n";
    }
}
