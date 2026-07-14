<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. inventarios
        $schema->table('inventarios', function (Blueprint $table) {
            $table->decimal('cantidad', 12, 2)->change();
            $table->decimal('cantidad_reservada', 12, 2)->change();
        });

        // 2. recepcion_detalles
        $schema->table('recepcion_detalles', function (Blueprint $table) {
            $table->decimal('cantidad_esperada', 12, 2)->change();
            $table->decimal('cantidad_recibida', 12, 2)->change();
        });

        // 3. picking_detalles
        $schema->table('picking_detalles', function (Blueprint $table) {
            $table->decimal('cantidad_solicitada', 12, 2)->change();
            $table->decimal('cantidad_pickeada', 12, 2)->change();
            $table->decimal('devolucion_qty', 12, 2)->change();
        });

        // 4. orden_compra_detalles
        $schema->table('orden_compra_detalles', function (Blueprint $table) {
            $table->decimal('cantidad_solicitada', 12, 2)->change();
            $table->decimal('cantidad_recibida', 12, 2)->change();
        });

        // 5. movimientos_inventario
        $schema->table('movimientos_inventario', function (Blueprint $table) {
            $table->decimal('cantidad', 12, 2)->change();
        });
        
        echo "Cantidades convertidas a NUMERIC(12,2) exitosamente.\n";
    },
    'down' => function () {
        // En un downgrade, se perderian decimales truncando los valores,
        // por lo general no se recomienda hacer rollback de numeric a integer,
        // pero se puede poner el integer de nuevo.
        $schema = Capsule::schema();

        $schema->table('inventarios', function (Blueprint $table) {
            $table->integer('cantidad')->change();
            $table->integer('cantidad_reservada')->change();
        });

        $schema->table('recepcion_detalles', function (Blueprint $table) {
            $table->integer('cantidad_esperada')->change();
            $table->integer('cantidad_recibida')->change();
        });

        $schema->table('picking_detalles', function (Blueprint $table) {
            $table->integer('cantidad_solicitada')->change();
            $table->integer('cantidad_pickeada')->change();
            $table->integer('devolucion_qty')->change();
        });

        $schema->table('orden_compra_detalles', function (Blueprint $table) {
            $table->integer('cantidad_solicitada')->change();
            $table->integer('cantidad_recibida')->change();
        });

        $schema->table('movimientos_inventario', function (Blueprint $table) {
            $table->integer('cantidad')->change();
        });
    }
];
