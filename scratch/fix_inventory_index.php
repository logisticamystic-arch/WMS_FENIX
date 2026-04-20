<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

try {
    echo "Iniciando reparación de restricciones en tabla 'inventarios'...\n";
    
    // 1. Identificar índices actuales
    $indexes = DB::select("SHOW INDEX FROM inventarios");
    $toDrop = [];
    foreach ($indexes as $idx) {
        if ($idx->Key_name === 'inv_prod_ubic_lote_unique') {
            $toDrop[] = 'inv_prod_ubic_lote_unique';
        }
        if ($idx->Key_name === 'inv_lpn_unique') {
            $toDrop[] = 'inv_lpn_unique';
        }
    }
    
    $toDrop = array_unique($toDrop);
    foreach ($toDrop as $idxName) {
        echo "Eliminando índice antiguo: $idxName\n";
        try {
            DB::statement("ALTER TABLE inventarios DROP INDEX `$idxName` ");
        } catch (\Exception $e) { echo "Aviso: No se pudo borrar $idxName (tal vez ya no existe).\n"; }
    }

    // 2. Crear el nuevo índice consolidado que INCLUYE numero_pallet y estado
    // Esto permite tener varios pallets del mismo producto/lote en la misma ubicación
    // sin que el sistema explote por "Duplicate Entry".
    echo "Creando nuevo índice consolidado 'inv_lpn_segregation_unique'...\n";
    DB::statement("ALTER TABLE inventarios ADD UNIQUE INDEX `inv_lpn_segregation_unique` 
        (`empresa_id`, `sucursal_id`, `producto_id`, `ubicacion_id`, `lote`, `numero_pallet`, `estado`) ");

    echo "✅ Reparación completada con éxito.\n";

} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
