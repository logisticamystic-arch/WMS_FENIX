<?php
/**
 * Migration 032 — TMS API Keys & Despacho↔TMS Link
 *
 * Crea la tabla api_keys para autenticación máquina-a-máquina con el TMS
 * y agrega columna tms_referencia_id en despachos para rastrear el pedido
 * correspondiente en el sistema TMS externo.
 *
 * Compatible con MySQL (XAMPP) y PostgreSQL (producción futura).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // ── Tabla api_keys ─────────────────────────────────────
        if (!$schema->hasTable('api_keys')) {
            $schema->create('api_keys', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->string('nombre', 120);            // etiqueta descriptiva
                $table->string('api_key', 64)->unique();  // Bearer token
                $table->string('sistema', 60)->default('TMS'); // TMS | ERP | OTRO
                $table->boolean('activo')->default(true);
                $table->unsignedBigInteger('creado_por')->nullable(); // personal_id
                $table->timestamp('ultimo_uso')->nullable();
                $table->timestamps();

                $table->index(['empresa_id', 'activo']);
            });
        }

        // ── Columna tms_referencia_id en despachos ─────────────
        if ($schema->hasTable('despachos') &&
            !$schema->hasColumn('despachos', 'tms_referencia_id')) {
            $schema->table('despachos', function ($table) {
                $table->string('tms_referencia_id', 100)
                      ->nullable()
                      ->after('estado')
                      ->comment('ID del despacho en el TMS externo');
            });
        }

        // ── Columna tms_estado en despachos ────────────────────
        if ($schema->hasTable('despachos') &&
            !$schema->hasColumn('despachos', 'tms_estado')) {
            $schema->table('despachos', function ($table) {
                $table->string('tms_estado', 50)
                      ->nullable()
                      ->after('tms_referencia_id')
                      ->comment('Estado sincronizado desde el TMS: EnTransito, Entregado, etc.');
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();

        if ($schema->hasTable('despachos')) {
            $schema->table('despachos', function ($table) use ($schema) {
                if ($schema->hasColumn('despachos', 'tms_estado')) {
                    $table->dropColumn('tms_estado');
                }
                if ($schema->hasColumn('despachos', 'tms_referencia_id')) {
                    $table->dropColumn('tms_referencia_id');
                }
            });
        }

        if ($schema->hasTable('api_keys')) {
            $schema->drop('api_keys');
        }
    },
];
