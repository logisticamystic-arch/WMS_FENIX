<?php
/**
 * Migration 066 — Picking: numero_pedido_ref en picking_detalles
 * Permite rastrear a qué factura/pedido original pertenece cada línea
 * cuando múltiples facturas se consolidan en una sola orden por sucursal.
 */
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('picking_detalles', 'numero_pedido_ref')) {
                    $table->string('numero_pedido_ref', 100)->nullable()->after('ambiente');
                }
            });
            try {
                $isPg = Capsule::connection()->getDriverName() === 'pgsql';
                if ($isPg) {
                    Capsule::statement('CREATE INDEX IF NOT EXISTS idx_pd_ref ON picking_detalles (numero_pedido_ref)');
                } else {
                    Capsule::statement('ALTER TABLE picking_detalles ADD INDEX idx_pd_ref (numero_pedido_ref(50))');
                }
            } catch (\Exception $e) {}
        }
    },
    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $table) use ($schema) {
                if ($schema->hasColumn('picking_detalles', 'numero_pedido_ref')) {
                    $table->dropColumn('numero_pedido_ref');
                }
            });
        }
    },
];
