<?php
/**
 * Migración 166 — archivar agotados del día.
 *
 * 2026-10-12 (pedido de Camilo): en Picking › Agotados › "Agotados del día" una
 * referencia con justificación se puede ARCHIVAR (p. ej. se envía más tarde en
 * ruta) para que el informe del día muestre solo el agotado real (sin inventario).
 * El archivado vive en la misma fila de la justificación (referencia+día+sucursal):
 * sin justificación no hay fila, así que no se puede archivar.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();
if (!$schema->hasColumn('agotados_observaciones', 'archivado_at')) {
    $schema->table('agotados_observaciones', function ($t) {
        $t->timestamp('archivado_at')->nullable();
        $t->unsignedBigInteger('archivado_por')->nullable();
    });
}
