<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        // 1. Personal: ultima_actividad
        if (!Capsule::schema()->hasColumn('personal', 'ultima_actividad')) {
            Capsule::schema()->table('personal', function (Blueprint $table) {
                $table->timestamp('ultima_actividad')->nullable()->after('ultimo_login');
                $table->index('ultima_actividad');
            });
        }

        // 2. Productos: stock_minimo
        if (!Capsule::schema()->hasColumn('productos', 'stock_minimo')) {
            Capsule::schema()->table('productos', function (Blueprint $table) {
                $table->decimal('stock_minimo', 12, 2)->default(0)->after('activo');
            });
        }

        // 3. Ubicaciones: m3 y clase
        Capsule::schema()->table('ubicaciones', function (Blueprint $table) {
            if (!Capsule::schema()->hasColumn('ubicaciones', 'm3')) {
                $table->decimal('m3', 10, 4)->default(0)->after('capacidad_maxima');
            }
            if (!Capsule::schema()->hasColumn('ubicaciones', 'clase')) {
                $table->string('clase', 50)->default('Normal')->after('m3');
            }
        });
    },
    'down' => function () {
        Capsule::schema()->table('personal', function (Blueprint $table) {
            $table->dropColumn('ultima_actividad');
        });
        Capsule::schema()->table('productos', function (Blueprint $table) {
            $table->dropColumn('stock_minimo');
        });
        Capsule::schema()->table('ubicaciones', function (Blueprint $table) {
            $table->dropColumn(['m3', 'clase']);
        });
    }
];
