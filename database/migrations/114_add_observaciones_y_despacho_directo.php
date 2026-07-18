<?php
// database/migrations/114_add_observaciones_y_despacho_directo.php
//
// Dos campos nuevos en orden_pickings:
// - observaciones: texto libre, capturable al crear el pedido (manual o import) y
//   editable después de cargado; visible en escritorio y móvil (picking,
//   certificación, despacho).
// - despachado_directo (+ _at/_por): marca un pedido que el cliente retiró
//   directamente en bodega — no debe imprimirse ni mezclarse con la remisión de
//   la planilla. Es aditivo (no toca el enum de `estado`), así que se puede
//   filtrar con un simple WHERE sin afectar ninguna lógica existente.
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        if (!$schema->hasColumn('orden_pickings', 'observaciones')) {
            $schema->table('orden_pickings', function (Blueprint $table) {
                $table->text('observaciones')->nullable();
            });
        }

        if (!$schema->hasColumn('orden_pickings', 'despachado_directo')) {
            $schema->table('orden_pickings', function (Blueprint $table) {
                $table->boolean('despachado_directo')->default(false);
                $table->timestamp('despachado_directo_at')->nullable();
                $table->unsignedBigInteger('despachado_directo_por')->nullable();
            });
        }
    },
    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasColumn('orden_pickings', 'observaciones')) {
            $schema->table('orden_pickings', function (Blueprint $table) {
                $table->dropColumn('observaciones');
            });
        }
        if ($schema->hasColumn('orden_pickings', 'despachado_directo')) {
            $schema->table('orden_pickings', function (Blueprint $table) {
                $table->dropColumn(['despachado_directo', 'despachado_directo_at', 'despachado_directo_por']);
            });
        }
    },
];
