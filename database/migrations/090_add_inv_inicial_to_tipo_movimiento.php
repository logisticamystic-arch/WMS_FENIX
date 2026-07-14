<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        // Eliminar el check constraint existente y recrearlo incluyendo InvInicial
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
                'InvInicial'::text
            ]))
        ");
        echo "  [OK] 'InvInicial' agregado al CHECK constraint de tipo_movimiento.\n";
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("ALTER TABLE movimiento_inventarios DROP CONSTRAINT IF EXISTS movimiento_inventarios_tipo_movimiento_check");
        $pdo->exec("
            ALTER TABLE movimiento_inventarios
            ADD CONSTRAINT movimiento_inventarios_tipo_movimiento_check
            CHECK (tipo_movimiento::text = ANY (ARRAY[
                'Entrada'::text,'Salida'::text,'Traslado'::text,
                'AjustePositivo'::text,'AjusteNegativo'::text,
                'Picking'::text,'Reabastecimiento'::text,'Devolucion'::text
            ]))
        ");
    },
];
