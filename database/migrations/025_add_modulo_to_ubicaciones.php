<?php
/**
 * Migration 025 — Add 'modulo' column to ubicaciones table.
 * The model and controller already reference this column but it was
 * never added in the original schema.
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

$schema = DB::schema();

if ($schema->hasTable('ubicaciones') && !$schema->hasColumn('ubicaciones', 'modulo')) {
    $schema->table('ubicaciones', function (Blueprint $table) {
        $table->string('modulo', 10)->nullable()->after('pasillo');
    });
    echo "  [OK] Columna 'modulo' añadida a ubicaciones.\n";
} else {
    echo "  [--] Columna 'modulo' ya existe en ubicaciones.\n";
}

return ['up' => fn() => null, 'down' => fn() => null];
