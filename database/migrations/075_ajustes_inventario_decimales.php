<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. ajustes_inventario
        if ($schema->hasTable('ajustes_inventario')) {
            $schema->table('ajustes_inventario', function (Blueprint $table) {
                $table->decimal('cantidad_fisica', 12, 2)->change();
                $table->decimal('cantidad_sistema', 12, 2)->change();
                $table->decimal('diferencia', 12, 2)->change();
            });
        }
        
        // 2. picking_faltantes (just in case they have quantities)
        if ($schema->hasTable('picking_faltantes')) {
            $schema->table('picking_faltantes', function (Blueprint $table) {
                $table->decimal('cantidad_faltante', 12, 2)->change();
            });
        }
        
        // 3. miscelaneos 
        if ($schema->hasTable('miscelaneos')) {
            $schema->table('miscelaneos', function (Blueprint $table) {
                $table->decimal('cantidad', 12, 2)->change();
            });
        }

        echo "Cantidades en ajustes, faltantes y miscelaneos convertidas a NUMERIC(12,2) exitosamente.\n";
    },
    'down' => function () {
        $schema = Capsule::schema();

        if ($schema->hasTable('ajustes_inventario')) {
            $schema->table('ajustes_inventario', function (Blueprint $table) {
                $table->integer('cantidad_fisica')->change();
                $table->integer('cantidad_sistema')->change();
                $table->integer('diferencia')->change();
            });
        }
    }
];
