<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. sesion_lineas
        if ($schema->hasTable('sesion_lineas')) {
            $schema->table('sesion_lineas', function (Blueprint $table) {
                $table->decimal('cantidad_contada', 12, 2)->change();
                $table->decimal('cantidad_sistema', 12, 2)->change();
                $table->decimal('diferencia', 12, 2)->change();
            });
        }
        
        echo "Cantidades en sesion_lineas convertidas a NUMERIC(12,2) exitosamente.\n";
    },
    'down' => function () {
        $schema = Capsule::schema();

        if ($schema->hasTable('sesion_lineas')) {
            $schema->table('sesion_lineas', function (Blueprint $table) {
                $table->integer('cantidad_contada')->change();
                $table->integer('cantidad_sistema')->change();
                $table->integer('diferencia')->change();
            });
        }
        
    }
];
