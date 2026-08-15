<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver' => 'pgsql', 'host' => $_ENV['DB_HOST'], 'port' => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_NAME'], 'username' => $_ENV['DB_USER'], 'password' => $_ENV['DB_PASS'],
    'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',
]);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$C = Illuminate\Database\Capsule\Manager::class;
$r = $C::select("SELECT NOW() as ahora, current_setting('TIMEZONE') as tz");
print_r($r);
$last = $C::table('picking_faltantes')->orderBy('id','desc')->first();
echo "Ultimo registro id={$last->id} created_at={$last->created_at}\n";
