<?php
/**
 * Migration 022 — API Keys table for TMS / external integrations.
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

$schema = DB::schema();

if (!$schema->hasTable('api_keys')) {
    $schema->create('api_keys', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('empresa_id')->index();
        $table->string('nombre', 120);          // e.g. "TMS Producción"
        $table->string('key_hash', 64);          // SHA-256 of the plain key
        $table->json('permisos')->nullable();    // ["read","write"]
        $table->boolean('activo')->default(true)->index();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamps();

        $table->unique('key_hash');
    });
    echo "  [OK] Tabla api_keys creada.\n";
} else {
    echo "  [--] Tabla api_keys ya existe.\n";
}

// TMS webhook log
if (!$schema->hasTable('tms_webhooks')) {
    $schema->create('tms_webhooks', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('empresa_id')->index();
        $table->string('evento', 80);
        $table->json('payload')->nullable();
        $table->boolean('procesado')->default(false)->index();
        $table->text('error_msg')->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
    echo "  [OK] Tabla tms_webhooks creada.\n";
} else {
    echo "  [--] Tabla tms_webhooks ya existe.\n";
}

// Add TMS columns to despachos if not present
if ($schema->hasTable('despachos')) {
    if (!$schema->hasColumn('despachos', 'tms_tracking_code')) {
        $schema->table('despachos', function (Blueprint $table) {
            $table->string('tms_tracking_code', 120)->nullable()->after('estado');
            $table->string('tms_transportista', 120)->nullable()->after('tms_tracking_code');
            $table->timestamp('tms_entregado_at')->nullable()->after('tms_transportista');
        });
        echo "  [OK] Columnas TMS añadidas a despachos.\n";
    } else {
        echo "  [--] Columnas TMS ya existen en despachos.\n";
    }
}

return ['up' => fn() => null, 'down' => fn() => null];
