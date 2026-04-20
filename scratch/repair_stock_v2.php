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
        
        // Agrupar detalles por pallet primero
        $palletGroups = RecepcionDetalle::where('producto_id', $inv->producto_id)
            ->where('lote', $inv->lote)
            ->whereNotNull('numero_pallet')
            ->where('numero_pallet', '>', 0)
            ->get()
            ->groupBy('numero_pallet');
            
        foreach ($palletGroups as $palletId => $details) {
            $totalForPallet = $details->sum('cantidad_recibida');
            
            // Buscar o crear el registro segregado
            $targetInv = Inventario::where('empresa_id', $inv->empresa_id)
                ->where('sucursal_id', $inv->sucursal_id)
                ->where('producto_id', $inv->producto_id)
                ->where('ubicacion_id', $inv->ubicacion_id)
                ->where('lote', $inv->lote)
                ->where('numero_pallet', $palletId)
                ->first();
                
            if (!$targetInv) {
                echo " - Creating Pallet #{$palletId} with {$totalForPallet} units (from NULL record)\n";
                Inventario::create([
                    'empresa_id' => $inv->empresa_id,
                    'sucursal_id' => $inv->sucursal_id,
                    'producto_id' => $inv->producto_id,
                    'ubicacion_id' => $inv->ubicacion_id,
                    'lote' => $inv->lote,
                    'fecha_vencimiento' => $inv->fecha_vencimiento,
                    'numero_pallet' => $palletId,
                    'cantidad' => $totalForPallet,
                    'estado' => $inv->estado
                ]);
                $inv->cantidad -= $totalForPallet;
            } else {
                // Si el registro ya existe, verificamos si su cantidad es menor a la esperada por los detalles
                // (En este caso, detID 4 ya se movió, pero detID 5 y 6 no se sumaron).
                $missing = $totalForPallet - $targetInv->cantidad;
                if ($missing > 0 && $inv->cantidad >= $missing) {
                     echo " - Adding {$missing} missing units to Pallet #{$palletId}\n";
                     $targetInv->cantidad += $missing;
                     $targetInv->save();
                     $inv->cantidad -= $missing;
                } else {
                     echo " - Pallet #{$palletId} already has correct or more quantity ({$targetInv->cantidad}), skipping.\n";
                }
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
