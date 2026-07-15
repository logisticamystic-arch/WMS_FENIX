<?php
/**
 * Migración 111 — orden_picking_id nullable en tarea_reabastecimientos.
 *
 * La tabla asumía que toda tarea de reabastecimiento nace de una orden de picking
 * específica (reactivo). El auto-reabastecimiento real (ReplenishmentController::
 * runAutoReplenishment) es proactivo: escanea niveles de reposición contra stock
 * de picking-face sin estar atado a ninguna orden puntual — necesita poder crear
 * tareas con orden_picking_id = NULL.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("ALTER TABLE tarea_reabastecimientos ALTER COLUMN orden_picking_id DROP NOT NULL");
} else {
    $pdo->exec("ALTER TABLE tarea_reabastecimientos MODIFY COLUMN orden_picking_id BIGINT UNSIGNED NULL");
}

echo "Migración 111 completada: orden_picking_id ahora es nullable en tarea_reabastecimientos.\n";
