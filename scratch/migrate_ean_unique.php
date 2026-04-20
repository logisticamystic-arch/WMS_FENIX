<?php
include __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    echo "--- ELIMINANDO ÍNDICE UNIQUE EN producto_eans ---\n";
    
    // 1. Eliminar el índice único
    DB::statement("ALTER TABLE producto_eans DROP INDEX producto_eans_codigo_ean_unique");
    echo "Índice producto_eans_codigo_ean_unique eliminado con éxito.\n";
    
    // 2. Crear un índice normal para mantener el performance de búsqueda
    DB::statement("CREATE INDEX idx_producto_eans_codigo_ean ON producto_eans(codigo_ean)");
    echo "Índice normal idx_producto_eans_codigo_ean creado.\n";
    
    echo "--- PROCESO COMPLETADO ---";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
