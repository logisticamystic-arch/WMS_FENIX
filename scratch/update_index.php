<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Schema;

try {
    Capsule::schema()->table('inventarios', function($table) {
        $table->dropUnique('inv_prod_ubic_lote_unique');
        echo "Dropped old unique index.\n";
        
        $table->unique(
            ['empresa_id', 'sucursal_id', 'producto_id', 'ubicacion_id', 'lote', 'numero_pallet', 'estado'], 
            'inv_lpn_unique'
        );
        echo "Created new LPN-aware unique index.\n";
    });
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Attempting raw SQL if Eloquent failed...\n";
    try {
        Capsule::statement("ALTER TABLE inventarios DROP INDEX inv_prod_ubic_lote_unique");
        Capsule::statement("ALTER TABLE inventarios ADD UNIQUE INDEX inv_lpn_unique (empresa_id, sucursal_id, producto_id, ubicacion_id, lote, numero_pallet, estado)");
        echo "SUCCESS via raw SQL.\n";
    } catch (\Exception $e2) {
        echo "FATAL ERROR: " . $e2->getMessage() . "\n";
    }
}
