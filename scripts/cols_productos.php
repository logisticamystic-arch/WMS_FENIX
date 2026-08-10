<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
use Illuminate\Database\Capsule\Manager as Capsule;
$c = new Capsule;
$c->addConnection([
    'driver'=>$_ENV['DB_DRIVER']??'pgsql','host'=>$_ENV['DB_HOST']??'127.0.0.1','port'=>$_ENV['DB_PORT']??'5432',
    'database'=>$_ENV['DB_NAME'],'username'=>$_ENV['DB_USER'],'password'=>$_ENV['DB_PASS'],
    'charset'=>'utf8','prefix'=>'','schema'=>'public',
]);
$c->setAsGlobal(); $c->bootEloquent();
$cols = Capsule::select("SELECT column_name FROM information_schema.columns WHERE table_name='productos' AND (column_name ILIKE '%precio%' OR column_name ILIKE '%valor%' OR column_name ILIKE '%costo%')");
foreach ($cols as $col) echo $col->column_name . "\n";
