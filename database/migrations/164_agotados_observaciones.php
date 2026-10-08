<?php
/**
 * Migración 164 — justificación de agotados por referencia y día.
 *
 * 2026-10-08 (pedido de Camilo): en Picking › Agotados, el consolidado por
 * referencia lleva una "Observación" digitable (justificación del agotado) y la
 * sección "Agotados del día" la muestra junto a lo solicitado y lo pendiente.
 * Una observación por referencia, por día y por sucursal.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();
if (!$schema->hasTable('agotados_observaciones')) {
    $schema->create('agotados_observaciones', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('empresa_id');
        $t->unsignedBigInteger('sucursal_id');
        $t->unsignedBigInteger('producto_id');
        $t->date('fecha');
        $t->text('observacion');
        $t->unsignedBigInteger('usuario_id')->nullable();
        $t->timestamps();
        $t->unique(['empresa_id', 'sucursal_id', 'producto_id', 'fecha'], 'agotados_obs_unica');
        $t->foreign('producto_id')->references('id')->on('productos');
    });
}
