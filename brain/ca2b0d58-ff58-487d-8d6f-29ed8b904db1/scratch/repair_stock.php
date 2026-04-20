<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    Capsule::beginTransaction();
    echo "Reparando stock para los 2 pallets recibidos...\n";

    // Pallet 1: 576 unidades
    \App\Models\MovimientoInventario::create([
        'empresa_id'  => 1,
        'sucursal_id' => 1,
        'producto_id' => 3006,
        'tipo_movimiento' => 'Entrada',
        'referencia_tipo' => 'Reparación Real-Time (Antigravity)',
        'cantidad'    => 576,
        'fecha_movimiento' => date('Y-m-d'),
        'hora_inicio'      => date('H:i:s'),
        'ubicacion_destino_id' => 1407,
        'auxiliar_id' => 2
    ]);

    // Pallet 2: 2160 unidades
    \App\Models\MovimientoInventario::create([
        'empresa_id'  => 1,
        'sucursal_id' => 1,
        'producto_id' => 3006,
        'tipo_movimiento' => 'Entrada',
        'referencia_tipo' => 'Reparación Real-Time (Antigravity)',
        'cantidad'    => 2160,
        'fecha_movimiento' => date('Y-m-d'),
        'hora_inicio'      => date('H:i:s'),
        'ubicacion_destino_id' => 1407,
        'auxiliar_id' => 2
    ]);

    $inv = \App\Models\Inventario::firstOrNew([
        'empresa_id'   => 1,
        'sucursal_id'  => 1,
        'producto_id'  => 3006,
        'ubicacion_id' => 1407,
        'lote'         => 'N/A',
        'estado'       => 'Disponible',
    ]);
    $inv->cantidad           = ($inv->cantidad ?? 0) + (576 + 2160);
    $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
    $inv->save();

    // Actualizar los detalles para que marquen aprobado_admin=1
    Capsule::table('recepcion_detalles')->where('recepcion_id', 1)->update(['aprobado_admin' => 1, 'ubicacion_destino_id' => 1407]);

    Capsule::commit();
    echo "REPARACIÓN COMPLETADA. 2736 unidades en Patio.\n";
} catch (\Exception $e) {
    Capsule::rollBack();
    echo "ERROR EN REPARACIÓN: " . $e->getMessage() . "\n";
}
