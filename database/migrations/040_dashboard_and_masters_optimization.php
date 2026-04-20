<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // 1. Personal Table - Add last activity
        if (!Capsule::schema()->hasColumn('personal', 'ultima_actividad')) {
            Capsule::schema()->table('personal', function ($t) {
                $t->timestamp('ultima_actividad')->nullable();
            });
        }

        // 2. Productos Table - Add stock_minimo
        if (!Capsule::schema()->hasColumn('productos', 'stock_minimo')) {
            Capsule::schema()->table('productos', function ($t) {
                $t->decimal('stock_minimo', 15, 2)->default(0);
            });
        }

        // 3. Ubicaciones Table - Add m3 and clase
        Capsule::schema()->table('ubicaciones', function ($t) {
            if (!Capsule::schema()->hasColumn('ubicaciones', 'm3')) {
                $t->decimal('m3', 15, 4)->default(0);
            }
            if (!Capsule::schema()->hasColumn('ubicaciones', 'clase')) {
                $t->string('clase', 50)->default('Normal');
            }
        });
    }
];
