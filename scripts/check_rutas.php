<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
require dirname(__DIR__) . '/config/app.php';
use Illuminate\Database\Capsule\Manager as Capsule;
$c = new Capsule;
$c->addConnection([
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'     => $_ENV['DB_HOST']   ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT']   ?? '5432',
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8', 'prefix' => '', 'schema' => 'public',
]);
$c->setAsGlobal(); $c->bootEloquent();

$exists = Capsule::schema()->hasTable('rutas');
echo "rutas existe: " . ($exists ? 'SI' : 'NO') . "\n";
if ($exists) {
    $cols = Capsule::select("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name='rutas' ORDER BY ordinal_position");
    foreach ($cols as $col) echo "  {$col->column_name} | {$col->data_type} | {$col->column_default}\n";
    // Check primary key
    $pk = Capsule::select("SELECT kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name WHERE tc.table_name='rutas' AND tc.constraint_type='PRIMARY KEY'");
    foreach ($pk as $p) echo "  PK: {$p->column_name}\n";
}
