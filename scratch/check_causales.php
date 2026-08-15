<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver'   => 'pgsql',
    'host'     => $_ENV['DB_HOST'],
    'port'     => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "--- causales_novedad ---\n";
$rows = Illuminate\Database\Capsule\Manager::table('causales_novedad')->get();
foreach ($rows as $r) {
    echo "{$r->id} | {$r->nombre}\n";
}
