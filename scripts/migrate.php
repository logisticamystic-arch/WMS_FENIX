<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

if (!Capsule::schema()->hasTable('_migrations')) {
    Capsule::schema()->create('_migrations', function ($t) {
        $t->string('migration', 100)->primary();
        $t->timestamp('executed_at')->useCurrent();
    });
}

$files = glob(__DIR__ . '/../database/migrations/*.php');
sort($files);
$executed = Capsule::table('_migrations')->pluck('migration')->toArray();
$args = array_slice($argv, 1);

if (in_array('--list', $args)) {
    echo "\nEstado de migraciones:\n" . str_repeat('-', 50) . "\n";
    foreach ($files as $f) {
        $n = basename($f, '.php');
        printf("  %-40s %s\n", $n, in_array($n, $executed) ? 'OK' : 'pendiente');
    }
    echo "\n"; exit(0);
}

$targets = empty($args)
    ? array_values(array_filter($files, fn($f) => !in_array(basename($f,'.php'), $executed)))
    : array_values(array_filter($files, fn($f) => array_reduce($args, fn($c,$a) => $c || strpos(basename($f,'.php'),$a)===0, false)));

$ok = $err = 0;
foreach ($targets as $file) {
    $name = basename($file, '.php');
    if (in_array($name, $executed) && !empty($args)) { echo "[SKIP] $name\n"; continue; }
    echo "[RUN]  $name\n";
    try {
        $m = require $file;
        Capsule::transaction(fn() => ($m['up'])());
        Capsule::table('_migrations')->insertOrIgnore(['migration'=>$name,'executed_at'=>date('Y-m-d H:i:s')]);
        echo "[OK]   $name\n"; $ok++;
    } catch(\Throwable $e) { echo "[ERR]  $name: ".$e->getMessage()."\n"; $err++; }
}
echo "\nResultado: $ok OK, $err error(es)\n";
exit($err > 0 ? 1 : 0);
