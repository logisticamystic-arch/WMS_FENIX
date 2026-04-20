<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

// Simular lo que hizo detallesOperativa para el ID 3
try {
    Capsule::beginTransaction();
    echo "Intentando crear movimiento...\n";
    \App\Models\MovimientoInventario::create([
        'empresa_id'  => 1,
        'sucursal_id' => 1,
        'producto_id' => 3006,
        'tipo'        => 'Entrada',
        'referencia'  => 'Cap. Móvil ODC: TEST',
        'cantidad'    => 2160,
        'fecha'       => date('Y-m-d'),
        'ubicacion_destino_id' => 1407,
        'auxiliar_id' => 2
    ]);
    echo "Movimiento creado.\n";

    echo "Intentando actualizar inventario...\n";
    $inv = \App\Models\Inventario::firstOrNew([
        'empresa_id'   => 1,
        'sucursal_id'  => 1,
        'producto_id'  => 3006,
        'ubicacion_id' => 1407,
        'lote'         => 'N/A',
        'estado'       => 'Disponible',
    ]);
    $inv->cantidad           = ($inv->cantidad ?? 0) + 2160;
    $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
    $inv->fecha_vencimiento  = '2026-04-30';
    $inv->save();
    echo "Inventario actualizado.\n";

    Capsule::commit();
    echo "TODO OK.\n";
} catch (\Exception $e) {
    Capsule::rollBack();
    echo "ERROR DETECTADO: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
