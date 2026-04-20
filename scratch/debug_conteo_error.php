<?php
require 'bootstrap.php';

use App\Models\ConteoInventario;
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    Capsule::transaction(function() {
        $conteo = ConteoInventario::create([
            'empresa_id'      => 1,
            'sucursal_id'     => 1,
            'analista_id'     => 1,
            'tipo_conteo'     => 'General',
            'tipo_interno'    => 'Ciclico',
            'ronda_actual'    => 1,
            'usa_bloqueo'     => true,
            'estado'          => 'EnConteo',
            'auxiliar_id'     => 1, // Fallback for old code
            'fecha_movimiento'=> date('Y-m-d'),
            'hora_inicio'     => date('H:i:s'),
            'observaciones'   => 'Test with relationship',
        ]);
        
        // Test Relationship
        echo "Created ID: " . $conteo->id . "\n";
        echo "Testing Relationship sync...\n";
        $conteo->auxiliares()->sync([1]); // Assuming user ID 1 exists
        echo "Relationship synced OK!\n";
        
        // Check if fields were actually saved (Mass assignment check)
        $dbVal = ConteoInventario::find($conteo->id);
        echo "Tipo Interno Saved: " . $dbVal->tipo_interno . "\n";
        echo "Analista ID Saved: " . $dbVal->analista_id . "\n";
        
        if ($dbVal->tipo_interno === 'Ciclico' && $dbVal->analista_id == 1) {
            echo "MASS ASSIGNMENT VERIFIED ✅\n";
        } else {
            echo "MASS ASSIGNMENT FAIL ❌\n";
        }
    });
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
