<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // Eliminar unique global en codigo si existe (puede no estar en todos los entornos)
        try {
            Capsule::schema()->table('zonas', function ($table) {
                $table->dropUnique(['codigo']);
            });
        } catch (\Exception $e) {
            // Constraint no existe — continuar
        }
        Capsule::schema()->table('zonas', function ($table) {
            // Unique compuesto: dos empresas pueden tener el mismo código de zona
            $table->unique(['empresa_id', 'codigo'], 'zonas_empresa_codigo_unique');
            // Ampliar VARCHAR(10) → VARCHAR(20) para coincidir con maxlength del formulario
            $table->string('codigo', 20)->change();
        });
    },
    'down' => function () {
        Capsule::schema()->table('zonas', function ($table) {
            $table->dropUnique('zonas_empresa_codigo_unique');
            $table->string('codigo', 10)->change();
        });
    },
];
