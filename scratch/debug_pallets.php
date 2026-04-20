<?php
require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    $hasInventarios = Capsule::schema()->hasColumn('inventarios', 'numero_pallet');
    $hasRecepcionDet = Capsule::schema()->hasColumn('recepcion_detalles', 'numero_pallet');
    $hasMovimientos = Capsule::schema()->hasColumn('movimiento_inventarios', 'numero_pallet');

    echo "Table Schema Checks:\n";
    echo " - inventarios.numero_pallet: " . ($hasInventarios ? "EXISTS" : "MISSING") . "\n";
    echo " - recepcion_detalles.numero_pallet: " . ($hasRecepcionDet ? "EXISTS" : "MISSING") . "\n";
    echo " - movimiento_inventarios.numero_pallet: " . ($hasMovimientos ? "EXISTS" : "MISSING") . "\n";

    if ($hasInventarios) {
        $count = Capsule::table('inventarios')->whereNotNull('numero_pallet')->where('numero_pallet', '>', 0)->count();
        echo "\nData Audit:\n";
        echo " - Records in 'inventarios' with valid pallet ID (> 0): $count\n";
        
        $patioStock = Capsule::table('inventarios')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->where('ubicaciones.tipo_ubicacion', 'Patio')
            ->select('inventarios.id', 'inventarios.numero_pallet', 'inventarios.cantidad', 'inventarios.lote')
            ->get();
            
        echo " - Stock items in 'Patio' (Yard): " . count($patioStock) . "\n";
        echo "   Sample of Patio items:\n";
        foreach($patioStock as $s) {
            echo "   -> ID: {$s->id}, Pallet: ".($s->numero_pallet ?? 'NULL').", Qty: {$s->cantidad}, Lote: {$s->lote}\n";
        }
    }
    
    echo "\nLast 5 Reception Details:\n";
    $lastDets = Capsule::table('recepcion_detalles')->orderBy('id', 'desc')->limit(5)->get();
    foreach($lastDets as $d) {
        echo " - DetID: {$d->id}, Pallet: ".($d->numero_pallet ?? 'NULL').", Cant: {$d->cantidad_recibida}, Lote: {$d->lote}\n";
    }

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
