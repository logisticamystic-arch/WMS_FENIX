<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    $schema = Capsule::schema();
    if (!$schema->hasTable('migrations')) {
        echo "Table 'migrations' does not exist. All migrations are pending.\n";
    } else {
        $ran = Capsule::table('migrations')->pluck('migration')->toArray();
        $files = glob(__DIR__ . '/../database/migrations/*.php');
        sort($files);

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $ran)) {
                $pending[] = $name;
            }
        }

        if (empty($pending)) {
            echo "All migrations have been run.\n";
        } else {
            echo "Pending migrations:\n";
            foreach ($pending as $p) {
                echo "- $p\n";
            }
        }
    }
} catch (\Throwable $e) {
    echo "Error checking migrations: " . $e->getMessage() . "\n";
}
