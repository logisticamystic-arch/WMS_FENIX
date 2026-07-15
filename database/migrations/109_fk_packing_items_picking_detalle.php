<?php
/**
 * Migración 109 — Agrega llave foránea packing_items.picking_detalle_id -> picking_detalles.id
 * (ON DELETE SET NULL). El esquema original no tenía ninguna restricción referencial ahí,
 * por lo que borrar una línea de picking ya empacada dejaba filas huérfanas en packing_items
 * sin ningún aviso. Antes de crear la FK se ponen en NULL los huérfanos ya existentes
 * (referencian un picking_detalle_id que ya no existe), porque de lo contrario la FK
 * fallaría al crearse.
 *
 * Hallazgo adicional: picking_detalles.id no tenía NINGUNA restricción PRIMARY KEY/UNIQUE
 * a nivel de base de datos (solo era un bigint autoincremental de facto). Postgres exige
 * que la columna referida por una FK tenga una, así que esta migración también la agrega
 * (se confirmó primero que no hay ids duplicados).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

// Agregar PRIMARY KEY en picking_detalles.id si no existe (requisito para poder
// referenciarla desde una FK)
if ($driver === 'pgsql') {
    $tienePk = $pdo->query("
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'picking_detalles' AND constraint_type = 'PRIMARY KEY'
    ")->fetchColumn();
    if (!$tienePk) {
        $pdo->exec("ALTER TABLE picking_detalles ADD PRIMARY KEY (id)");
    }
} else {
    $tienePk = $pdo->query("
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'picking_detalles' AND CONSTRAINT_TYPE = 'PRIMARY KEY'
    ")->fetchColumn();
    if (!$tienePk) {
        $pdo->exec("ALTER TABLE picking_detalles ADD PRIMARY KEY (id)");
    }
}

// Limpiar huérfanos existentes antes de poder crear la FK
$pdo->exec("
    UPDATE packing_items
    SET picking_detalle_id = NULL
    WHERE picking_detalle_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM picking_detalles pd WHERE pd.id = packing_items.picking_detalle_id)
");

if ($driver === 'pgsql') {
    $existe = $pdo->query("
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_packing_items_picking_detalle'
    ")->fetchColumn();
    if (!$existe) {
        $pdo->exec("
            ALTER TABLE packing_items
                ADD CONSTRAINT fk_packing_items_picking_detalle
                FOREIGN KEY (picking_detalle_id) REFERENCES picking_detalles(id) ON DELETE SET NULL
        ");
    }
} else {
    $existe = $pdo->query("
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_packing_items_picking_detalle'
    ")->fetchColumn();
    if (!$existe) {
        $pdo->exec("
            ALTER TABLE packing_items
                ADD CONSTRAINT fk_packing_items_picking_detalle
                FOREIGN KEY (picking_detalle_id) REFERENCES picking_detalles(id) ON DELETE SET NULL
        ");
    }
}

echo "Migración 109 completada: FK packing_items.picking_detalle_id -> picking_detalles.id agregada (huérfanos previos puestos en NULL).\n";
