<?php
/**
 * Migración 109 — Agrega llave foránea packing_items.picking_detalle_id -> picking_detalles.id
 * (ON DELETE SET NULL). El esquema original no tenía ninguna restricción referencial ahí,
 * por lo que borrar una línea de picking ya empacada dejaba filas huérfanas en packing_items
 * sin ningún aviso. Antes de crear la FK se ponen en NULL los huérfanos ya existentes
 * (referencian un picking_detalle_id que ya no existe), porque de lo contrario la FK
 * fallaría al crearse.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

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
