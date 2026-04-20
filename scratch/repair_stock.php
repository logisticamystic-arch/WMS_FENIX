<?php
require_once __DIR__ . '/../bootstrap.php';
use App\Models\Inventario;
use App\Models\RecepcionDetalle;
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    Capsule::beginTransaction();
    
    // 1. Identificar registros consolidados en Patio con Pallet NULL
    $consolidated = Inventario::whereHas('ubicacion', function($q) {
        $q->where('tipo_ubicacion', 'Patio');
    })->whereNull('numero_pallet')->get();
    
    echo "Found " . $consolidated->count() . " consolidated records with Pallet NULL.\n";
    
    foreach ($consolidated as $inv) {
        echo "Repairing Inv ID: {$inv->id} (Prod: {$inv->producto_id}, Qty: {$inv->cantidad})\n";
        
        // Buscar recepciones que deberían haber creado su propio registro
        $details = RecepcionDetalle::where('producto_id', $inv->producto_id)
            ->where('lote', $inv->lote)
            ->whereNotNull('numero_pallet')
            ->where('numero_pallet', '>', 0)
            ->get();
            
        foreach ($details as $det) {
            // Verificar si este pallet ya existe en inventario para este producto/lote/sucursal (no debería según mi diagnóstico)
            $exists = Inventario::where('empresa_id', $inv->empresa_id)
                ->where('sucursal_id', $inv->sucursal_id)
                ->where('producto_id', $inv->producto_id)
                ->where('ubicacion_id', $inv->ubicacion_id)
                ->where('lote', $inv->lote)
                ->where('numero_pallet', $det->numero_pallet)
                ->first();
                
            if (!$exists) {
                echo " - Moving {$det->cantidad_recibida} units to Pallet #{$det->numero_pallet}\n";
                
                // Crear el nuevo registro de inventario segregado
                Inventario::create([
                    'empresa_id' => $inv->empresa_id,
                    'sucursal_id' => $inv->sucursal_id,
                    'producto_id' => $inv->producto_id,
                    'ubicacion_id' => $inv->ubicacion_id,
                    'lote' => $inv->lote,
                    'fecha_vencimiento' => $inv->fecha_vencimiento,
                    'numero_pallet' => $det->numero_pallet,
                    'cantidad' => $det->cantidad_recibida,
                    'estado' => $inv->estado
                ]);
                
                // Descontar del consolidado
                $inv->cantidad -= $det->cantidad_recibida;
            } else {
                echo " - Pallet #{$det->numero_pallet} already exists, skipping.\n";
            }
        }
        
        if ($inv->cantidad <= 0) {
            echo " - Consolidated record exhausted, deleting.\n";
            $inv->delete();
        } else {
            echo " - Consolidated record updated, remaining: {$inv->cantidad}\n";
            $inv->save();
        }
    }
    
    Capsule::commit();
    echo "REPAIR COMPLETED SUCCESSFULLY.\n";
} catch (\Exception $e) {
    Capsule::rollback();
    echo "ERROR DURING REPAIR: " . $e->getMessage() . "\n";
}
