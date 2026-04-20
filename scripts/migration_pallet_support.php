<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    echo "Iniciando migración para soporte de Pallets...\n";

    // 1. inventarios
    if (!Capsule::schema()->hasColumn('inventarios', 'numero_pallet')) {
        Capsule::schema()->table('inventarios', function($table) {
            $table->integer('numero_pallet')->nullable()->after('ubicacion_id')->index();
        });
        echo "- Columna 'numero_pallet' añadida a 'inventarios'.\n";
    }

    // 2. recepcion_detalles
    if (!Capsule::schema()->hasColumn('recepcion_detalles', 'numero_pallet')) {
        Capsule::schema()->table('recepcion_detalles', function($table) {
            $table->integer('numero_pallet')->nullable()->after('ubicacion_destino_id')->index();
        });
        echo "- Columna 'numero_pallet' añadida a 'recepcion_detalles'.\n";
    }

    // 3. movimiento_inventarios
    if (!Capsule::schema()->hasColumn('movimiento_inventarios', 'numero_pallet')) {
        Capsule::schema()->table('movimiento_inventarios', function($table) {
            $table->integer('numero_pallet')->nullable()->after('ubicacion_destino_id')->index();
        });
        echo "- Columna 'numero_pallet' añadida a 'movimiento_inventarios'.\n";
    }

    echo "MIGRACIÓN COMPLETADA EXITOSAMENTE.\n";
} catch (\Exception $e) {
    echo "ERROR EN MIGRACIÓN: " . $e->getMessage() . "\n";
}
