<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
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

$cols = Capsule::select("SELECT column_name FROM information_schema.columns WHERE table_name='audit_logs' ORDER BY ordinal_position");
echo "Columnas audit_logs: " . implode(', ', array_map(fn($c)=>$c->column_name, $cols)) . "\n\n";

echo "== Últimos eventos 'cerrar_ambiente' ==\n";
$rows = Capsule::table('audit_logs')->where('accion', 'cerrar_ambiente')->orderByDesc('id')->limit(15)->get();
foreach ($rows as $r) {
    echo "  id={$r->id} created_at={$r->created_at} " . json_encode($r) . "\n";
}
echo "Total: " . count($rows) . "\n";
