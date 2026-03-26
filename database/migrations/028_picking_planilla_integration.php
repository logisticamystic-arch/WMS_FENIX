<?php
/**
 * Migration 028 — Picking-Planilla integration.
 * - Adds EnPicking + Separado states to archivos_planilla
 * - Adds planilla_numero + archivo_id to orden_pickings for traceability
 */
use Illuminate\Database\Capsule\Manager as DB;

return [
    'up' => function () {
        // Extend archivos_planilla.estado enum
        try {
            DB::connection()->statement(
                "ALTER TABLE archivos_planilla MODIFY COLUMN estado
                 ENUM('Importada','EnPicking','Separado','EnCertificacion','Certificada','Anulada')
                 NOT NULL DEFAULT 'Importada'"
            );
        } catch (\Exception $e) { /* already updated */ }

        // Add planilla traceability columns to orden_pickings
        $schema = DB::schema();
        if ($schema->hasTable('orden_pickings')) {
            if (!$schema->hasColumn('orden_pickings', 'planilla_numero')) {
                $schema->table('orden_pickings', function ($t) {
                    $t->string('planilla_numero', 100)->nullable()->after('cliente');
                });
            }
            if (!$schema->hasColumn('orden_pickings', 'archivo_id')) {
                $schema->table('orden_pickings', function ($t) {
                    $t->unsignedBigInteger('archivo_id')->nullable()->after('planilla_numero');
                });
            }
        }
    },
    'down' => function () {
        // No destructive down
    },
];
