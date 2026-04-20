<?php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        Capsule::schema()->table('picking_detalles', function ($table) {
            if (!Capsule::schema()->hasColumn('picking_detalles', 'fecha_vencimiento')) {
                $table->date('fecha_vencimiento')->nullable()->after('lote');
            }
            if (!Capsule::schema()->hasColumn('picking_detalles', 'lote')) {
                $table->string('lote', 100)->nullable()->after('producto_id');
            }
        });
    },
    'down' => function () {
        Capsule::schema()->table('picking_detalles', function ($table) {
            $table->dropColumn(['fecha_vencimiento', 'lote']);
        });
    },
];
