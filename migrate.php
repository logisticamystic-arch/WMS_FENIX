<?php
/**
 * CLI Migration Runner for Eloquent ORM
 * Usage:
 *   php migrate.php migrate    - Run all pending migrations
 *   php migrate.php rollback   - Rollback last batch
 *   php migrate.php reset      - Rollback all migrations
 *   php migrate.php seed       - Run seeders
 *   php migrate.php fresh      - Drop all + re-migrate + seed
 */

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$command = $argv[1] ?? 'migrate';

// Ensure migrations table exists
if (!Capsule::schema()->hasTable('migrations')) {
    Capsule::schema()->create('migrations', function ($table) {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
        $table->timestamp('ran_at')->useCurrent();
    });
    echo "Created migrations table.\n";
}

// Get migration files
$migrationPath = __DIR__ . '/database/migrations/';
$files = glob($migrationPath . '*.php');
sort($files);

switch ($command) {
    case 'migrate':
        $ran = Capsule::table('migrations')->pluck('migration')->toArray();
        $batch = (int)(Capsule::table('migrations')->max('batch') ?? 0) + 1;
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $ran)) {
                echo "Migrating: {$name}\n";
                $migration = require $file;
                try {
                    $migration['up']();
                    Capsule::table('migrations')->insert([
                        'migration' => $name,
                        'batch' => $batch,
                    ]);
                    echo "  ✓ Migrated: {$name}\n";
                    $count++;
                } catch (\Exception $e) {
                    echo "  ✗ Error in {$name}: " . $e->getMessage() . "\n";
                    exit(1);
                }
            }
        }

        echo $count > 0 ? "\nDone. {$count} migration(s) executed.\n" : "Nothing to migrate.\n";
        break;

    case 'rollback':
        $lastBatch = (int)Capsule::table('migrations')->max('batch');
        if ($lastBatch === 0) {
            echo "Nothing to rollback.\n";
            break;
        }
        $migrations = Capsule::table('migrations')
            ->where('batch', $lastBatch)
            ->orderBy('id', 'desc')
            ->pluck('migration')
            ->toArray();

        foreach ($migrations as $name) {
            $file = $migrationPath . $name . '.php';
            if (file_exists($file)) {
                echo "Rolling back: {$name}\n";
                $migration = require $file;
                $migration['down']();
                Capsule::table('migrations')->where('migration', $name)->delete();
                echo "  ✓ Rolled back: {$name}\n";
            }
        }
        echo "Done.\n";
        break;

    case 'reset':
        $migrations = Capsule::table('migrations')
            ->orderBy('id', 'desc')
            ->pluck('migration')
            ->toArray();

        foreach ($migrations as $name) {
            $file = $migrationPath . $name . '.php';
            if (file_exists($file)) {
                echo "Rolling back: {$name}\n";
                $migration = require $file;
                $migration['down']();
                Capsule::table('migrations')->where('migration', $name)->delete();
                echo "  ✓ Rolled back: {$name}\n";
            }
        }
        echo "Done.\n";
        break;

    case 'fresh':
        // Drop all tables — use information_schema so column name is always 'table_name'
        Capsule::schema()->disableForeignKeyConstraints();
        $dbName = Capsule::connection()->getDatabaseName();
        $tables = Capsule::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$dbName]
        );
        foreach ($tables as $table) {
            $tableName = $table->table_name ?? $table->TABLE_NAME ?? null;
            if (!$tableName) continue;
            Capsule::connection()->statement("DROP TABLE IF EXISTS `{$tableName}`");
            echo "Dropped: {$tableName}\n";
        }
        Capsule::schema()->enableForeignKeyConstraints();

        // Re-create migrations table
        Capsule::schema()->create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
            $table->timestamp('ran_at')->useCurrent();
        });

        // Run all migrations
        $batch = 1;
        foreach ($files as $file) {
            $name = basename($file, '.php');
            echo "Migrating: {$name}\n";
            $migration = require $file;
            try {
                $migration['up']();
                Capsule::table('migrations')->insert([
                    'migration' => $name,
                    'batch' => $batch,
                ]);
                echo "  ✓ Migrated: {$name}\n";
            } catch (\Exception $e) {
                echo "  ✗ Error in {$name}: " . $e->getMessage() . "\n";
                exit(1);
            }
        }

        // Seed
        echo "\nSeeding...\n";
        $seedFile = __DIR__ . '/database/seeds/DatabaseSeeder.php';
        if (file_exists($seedFile)) {
            require $seedFile;
            (new DatabaseSeeder())->run();
            echo "✓ Seeded.\n";
        }
        echo "Done.\n";
        break;

    case 'seed':
        $seedFile = __DIR__ . '/database/seeds/DatabaseSeeder.php';
        if (file_exists($seedFile)) {
            require $seedFile;
            (new DatabaseSeeder())->run();
            echo "✓ Seeded.\n";
        } else {
            echo "No seeder found.\n";
        }
        break;

    default:
        echo "Unknown command: {$command}\n";
        echo "Available: migrate, rollback, reset, fresh, seed\n";
        exit(1);
}
