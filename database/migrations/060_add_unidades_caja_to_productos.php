<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        if (!Capsule::schema()->hasColumn('productos', 'unidades_caja')) {
            Capsule::schema()->table('productos', function ($table) {
                $table->integer('unidades_caja')->default(1)->after('stock_minimo');
            });
        }
    },
    'down' => function () {
        if (Capsule::schema()->hasColumn('productos', 'unidades_caja')) {
            Capsule::schema()->table('productos', function ($table) {
                $table->dropColumn('unidades_caja');
            });
        }
    },
];
