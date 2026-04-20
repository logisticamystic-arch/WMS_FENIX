<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // Enriquecer Ordenes de Picking
        Capsule::schema()->table('orden_pickings', function ($table) {
            $table->string('auxiliares_json', 500)->nullable()->after('auxiliar_id');
            $table->integer('consecutivo')->nullable()->after('numero_orden');
            $table->string('tipo_picking', 30)->default('Normal')->after('estado'); // Consolidated, Aisle, etc.
        });

        // Enriquecer Ubicaciones para Cubaje
        Capsule::schema()->table('ubicaciones', function ($table) {
            $table->decimal('volumen_maximo', 10, 4)->default(0)->after('capacidad_maxima');
        });

        // Enriquecer Tareas de Reabastecimiento con FEFO
        Capsule::schema()->table('tarea_reabastecimientos', function ($table) {
            $table->string('lote', 50)->nullable()->after('producto_id');
            $table->date('fecha_vencimiento')->nullable()->after('lote');
        });
        
        // Agregar campo para el asesor en novedades de stock (si no existe)
        if (Capsule::schema()->hasTable('picking_novedades_stock')) {
            Capsule::schema()->table('picking_novedades_stock', function ($table) {
                if (!Capsule::schema()->hasColumn('picking_novedades_stock', 'asesor')) {
                    $table->string('asesor', 150)->nullable()->after('cliente');
                }
            });
        }
    },
    'down' => function () {
        Capsule::schema()->table('orden_pickings', function ($table) {
            $table->dropColumn(['auxiliares_json', 'consecutivo', 'tipo_picking']);
        });
        Capsule::schema()->table('ubicaciones', function ($table) {
            $table->dropColumn('volumen_maximo');
        });
        Capsule::schema()->table('tarea_reabastecimientos', function ($table) {
            $table->dropColumn(['lote', 'fecha_vencimiento']);
        });
    },
];
