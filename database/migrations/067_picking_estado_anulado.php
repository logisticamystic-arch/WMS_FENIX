<?php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $isPg = Capsule::connection()->getDriverName() === 'pgsql';
        if ($isPg) {
            // PostgreSQL: ALTER TYPE para CHECK constraint (no usa ENUM nativo)
            Capsule::statement("ALTER TABLE picking_detalles ALTER COLUMN estado TYPE VARCHAR(30)");
            Capsule::statement("ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS picking_detalles_estado_check");
            Capsule::statement("ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS chk_picking_detalles_estado");
            Capsule::statement("ALTER TABLE picking_detalles ADD CONSTRAINT picking_detalles_estado_check
                CHECK (estado IN ('Pendiente','EnProceso','Completado','Faltante','Anulado'))");
            Capsule::statement("ALTER TABLE picking_detalles ALTER COLUMN estado SET DEFAULT 'Pendiente'");

            Capsule::statement("ALTER TABLE orden_pickings ALTER COLUMN estado TYPE VARCHAR(30)");
            Capsule::statement("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS orden_pickings_estado_check");
            Capsule::statement("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS chk_orden_pickings_estado");
            Capsule::statement("ALTER TABLE orden_pickings ADD CONSTRAINT orden_pickings_estado_check
                CHECK (estado IN ('Pendiente','EnProceso','Completada','Cancelada','Anulado'))");
            Capsule::statement("ALTER TABLE orden_pickings ALTER COLUMN estado SET DEFAULT 'Pendiente'");
        } else {
            Capsule::statement("
                ALTER TABLE picking_detalles
                MODIFY COLUMN estado ENUM('Pendiente','EnProceso','Completado','Faltante','Anulado')
                NOT NULL DEFAULT 'Pendiente'
            ");
            Capsule::statement("
                ALTER TABLE orden_pickings
                MODIFY COLUMN estado ENUM('Pendiente','EnProceso','Completada','Cancelada','Anulado')
                NOT NULL DEFAULT 'Pendiente'
            ");
        }
    },
    'down' => function () {
        $isPg = Capsule::connection()->getDriverName() === 'pgsql';
        if ($isPg) {
            Capsule::statement("ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS chk_picking_detalles_estado");
            Capsule::statement("ALTER TABLE picking_detalles ADD CONSTRAINT chk_picking_detalles_estado
                CHECK (estado IN ('Pendiente','EnProceso','Completado','Faltante'))");
            Capsule::statement("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS chk_orden_pickings_estado");
            Capsule::statement("ALTER TABLE orden_pickings ADD CONSTRAINT chk_orden_pickings_estado
                CHECK (estado IN ('Pendiente','EnProceso','Completada','Cancelada'))");
        } else {
            Capsule::statement("
                ALTER TABLE picking_detalles
                MODIFY COLUMN estado ENUM('Pendiente','EnProceso','Completado','Faltante')
                NOT NULL DEFAULT 'Pendiente'
            ");
            Capsule::statement("
                ALTER TABLE orden_pickings
                MODIFY COLUMN estado ENUM('Pendiente','EnProceso','Completada','Cancelada')
                NOT NULL DEFAULT 'Pendiente'
            ");
        }
    },
];
