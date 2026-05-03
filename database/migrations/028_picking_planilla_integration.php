<?php
/**
 * Migration 028 — Picking-Planilla integration.
 * - Adds EnPicking + Separado states to archivos_planilla
 * - Adds planilla_numero + archivo_id to orden_pickings for traceability
 */
use Illuminate\Database\Capsule\Manager as DB;

return [
    'up' => function () {
        // Extend archivos_planilla.estado enum (PostgreSQL-compatible via CHECK constraint)
        try {
            DB::connection()->statement(
                "ALTER TABLE archivos_planilla DROP CONSTRAINT IF EXISTS archivos_planilla_estado_check"
            );
            DB::connection()->statement(
                "ALTER TABLE archivos_planilla ADD CONSTRAINT archivos_planilla_estado_check
                 CHECK (estado IN ('Importada','EnPicking','Separado','EnCertificacion','Certificada','Anulada'))"
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
