<?php
require_once 'C:/xampp/htdocs/WMS_FENIX/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

try {
    echo "Agregando índices de rendimiento...\n";

    // 1. picking_detalles — búsquedas por orden + estado (confirmarConsolidado, completar, marcarFaltante)
    $pdIndexes = Capsule::select("SHOW INDEX FROM picking_detalles WHERE Key_name = 'idx_pd_orden_estado'");
    if (empty($pdIndexes)) {
        Capsule::schema()->table('picking_detalles', function (Blueprint $table) {
            $table->index(['orden_picking_id', 'estado'], 'idx_pd_orden_estado');
        });
        echo "- idx_pd_orden_estado creado en picking_detalles\n";
    } else {
        echo "- idx_pd_orden_estado ya existe\n";
    }

    // 2. inventarios — búsquedas por empresa + sucursal + producto + estado + ubicacion (confirmarConsolidado, marcarFaltante, completar)
    $invIndexes = Capsule::select("SHOW INDEX FROM inventarios WHERE Key_name = 'idx_inv_lookup'");
    if (empty($invIndexes)) {
        Capsule::schema()->table('inventarios', function (Blueprint $table) {
            $table->index(['empresa_id', 'sucursal_id', 'producto_id', 'estado', 'ubicacion_id'], 'idx_inv_lookup');
        });
        echo "- idx_inv_lookup creado en inventarios\n";
    } else {
        echo "- idx_inv_lookup ya existe\n";
    }

    // 3. inventarios — índice en cantidad_reservada (queries que filtran > 0)
    $invResIndexes = Capsule::select("SHOW INDEX FROM inventarios WHERE Key_name = 'idx_inv_reservada'");
    if (empty($invResIndexes)) {
        Capsule::schema()->table('inventarios', function (Blueprint $table) {
            $table->index(['empresa_id', 'sucursal_id', 'producto_id', 'cantidad_reservada'], 'idx_inv_reservada');
        });
        echo "- idx_inv_reservada creado en inventarios\n";
    } else {
        echo "- idx_inv_reservada ya existe\n";
    }

    // 4. orden_pickings — búsquedas por empresa + sucursal + estado (dashboard, misPlanillas)
    $opIndexes = Capsule::select("SHOW INDEX FROM orden_pickings WHERE Key_name = 'idx_op_empresa_estado'");
    if (empty($opIndexes)) {
        Capsule::schema()->table('orden_pickings', function (Blueprint $table) {
            $table->index(['empresa_id', 'sucursal_id', 'estado'], 'idx_op_empresa_estado');
        });
        echo "- idx_op_empresa_estado creado en orden_pickings\n";
    } else {
        echo "- idx_op_empresa_estado ya existe\n";
    }

    echo "\nListo.\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
