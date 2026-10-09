<?php
/**
 * Migración 165 — línea de pedido de cada registro de picking_faltantes.
 *
 * 2026-10-09: picking_faltantes se identificaba solo por pedido + producto. Si un
 * pedido tenía el mismo producto en varias líneas (split por ubicación), confirmar
 * una línea borraba el agotado real de la otra. Con picking_detalle_id cada registro
 * pertenece a su línea; los antiguos quedan en NULL (ver PickingController::
 * _borrarFaltantesDeLinea).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();
if (!$schema->hasColumn('picking_faltantes', 'picking_detalle_id')) {
    $schema->table('picking_faltantes', function ($t) {
        $t->unsignedBigInteger('picking_detalle_id')->nullable();
        $t->index('picking_detalle_id', 'idx_picking_faltantes_detalle');
    });
}
