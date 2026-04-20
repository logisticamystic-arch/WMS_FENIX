<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('recepcion_detalles')) {
            $schema->table('recepcion_detalles', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('recepcion_detalles', 'cantidad_cajas'))
                    $table->unsignedInteger('cantidad_cajas')->default(0);
                if (!$schema->hasColumn('recepcion_detalles', 'cajas_por_unidad'))
                    $table->unsignedInteger('cajas_por_unidad')->default(1);
            });
        }
        echo "  Migration 047 completada.\n";
    },
    'down' => function () {},
];
