<?php
/**
 * Prooriente WMS — Migration v4
 * Runs: 022_create_api_keys_table.php
 *       023_add_indexes_performance.php
 *
 * Usage (from project root):
 *   php migrate_v4.php
 */
require __DIR__ . '/bootstrap.php';

echo "\n=== Prooriente WMS — Migrate v4 ===\n\n";

$migrations = [
    __DIR__ . '/database/migrations/022_create_api_keys_table.php',
    __DIR__ . '/database/migrations/023_add_indexes_performance.php',
];

foreach ($migrations as $file) {
    $name = basename($file);
    echo "► Ejecutando: $name\n";
    try {
        require $file;
    } catch (\Throwable $e) {
        echo "  [ERROR] $name: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "=== Migración v4 finalizada ===\n\n";
