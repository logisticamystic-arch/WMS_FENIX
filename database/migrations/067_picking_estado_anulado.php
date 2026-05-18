<?php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // Extend picking_detalles.estado ENUM to include Anulado
        Capsule::statement("
            ALTER TABLE picking_detalles
            MODIFY COLUMN estado ENUM('Pendiente','EnProceso','Completado','Faltante','Anulado')
            NOT NULL DEFAULT 'Pendiente'
        ");
        // Extend orden_pickings.estado ENUM to include Anulado
        Capsule::statement("
            ALTER TABLE orden_pickings
            MODIFY COLUMN estado ENUM('Pendiente','EnProceso','Completada','Cancelada','Anulado')
            NOT NULL DEFAULT 'Pendiente'
        ");
    },
    'down' => function () {
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
    },
];
