<?php
/**
 * Migración 112 — Agrega 'CorreccionAdmin' al CHECK constraint de
 * movimiento_inventarios.tipo_movimiento.
 *
 * El modelo MovimientoInventario ya declaraba TIPO_CORRECCION = 'CorreccionAdmin'
 * (usado por PickingController::_ajustarReservaEdicionLinea al editar la cantidad
 * de una línea de picking desde escritorio), pero el CHECK constraint real nunca
 * incluyó ese valor — cualquier edición de cantidad que generara un movimiento de
 * corrección fallaba con SQLSTATE[23514] (violación de constraint).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo    = Capsule::connection()->getPdo();
        $driver = Capsule::connection()->getDriverName();
        if ($driver !== 'pgsql') return;

        $pdo->exec("ALTER TABLE movimiento_inventarios DROP CONSTRAINT IF EXISTS movimiento_inventarios_tipo_movimiento_check");
        $pdo->exec("
            ALTER TABLE movimiento_inventarios
            ADD CONSTRAINT movimiento_inventarios_tipo_movimiento_check
            CHECK (tipo_movimiento::text = ANY (ARRAY[
                'Entrada'::text,
                'Salida'::text,
                'Traslado'::text,
                'AjustePositivo'::text,
                'AjusteNegativo'::text,
                'Picking'::text,
                'Reabastecimiento'::text,
                'Devolucion'::text,
                'InvInicial'::text,
                'CorreccionAdmin'::text
            ]))
        ");
        echo "  [OK] 'CorreccionAdmin' agregado al CHECK constraint de tipo_movimiento.\n";
    },
    'down' => function () {
        $pdo    = Capsule::connection()->getPdo();
        $driver = Capsule::connection()->getDriverName();
        if ($driver !== 'pgsql') return;

        $pdo->exec("ALTER TABLE movimiento_inventarios DROP CONSTRAINT IF EXISTS movimiento_inventarios_tipo_movimiento_check");
        $pdo->exec("
            ALTER TABLE movimiento_inventarios
            ADD CONSTRAINT movimiento_inventarios_tipo_movimiento_check
            CHECK (tipo_movimiento::text = ANY (ARRAY[
                'Entrada'::text,'Salida'::text,'Traslado'::text,
                'AjustePositivo'::text,'AjusteNegativo'::text,
                'Picking'::text,'Reabastecimiento'::text,'Devolucion'::text,
                'InvInicial'::text
            ]))
        ");
    },
];
