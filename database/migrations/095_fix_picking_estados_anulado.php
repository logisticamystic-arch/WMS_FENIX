<?php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        if (Capsule::connection()->getDriverName() !== 'pgsql') return;

        // picking_detalles: eliminar AMBOS nombres posibles del constraint y recrear
        Capsule::statement("ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS picking_detalles_estado_check");
        Capsule::statement("ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS chk_picking_detalles_estado");
        Capsule::statement("ALTER TABLE picking_detalles ADD CONSTRAINT picking_detalles_estado_check
            CHECK (estado IN ('Pendiente','EnProceso','Completado','Faltante','Anulado'))");

        // orden_pickings: ídem
        Capsule::statement("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS orden_pickings_estado_check");
        Capsule::statement("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS chk_orden_pickings_estado");
        Capsule::statement("ALTER TABLE orden_pickings ADD CONSTRAINT orden_pickings_estado_check
            CHECK (estado IN ('Pendiente','EnProceso','Completada','Cancelada','Anulado'))");
    },
    'down' => function () {
        if (Capsule::connection()->getDriverName() !== 'pgsql') return;

        Capsule::statement("ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS picking_detalles_estado_check");
        Capsule::statement("ALTER TABLE picking_detalles ADD CONSTRAINT picking_detalles_estado_check
            CHECK (estado IN ('Pendiente','EnProceso','Completado','Faltante'))");

        Capsule::statement("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS orden_pickings_estado_check");
        Capsule::statement("ALTER TABLE orden_pickings ADD CONSTRAINT orden_pickings_estado_check
            CHECK (estado IN ('Pendiente','EnProceso','Completada','Cancelada'))");
    },
];
