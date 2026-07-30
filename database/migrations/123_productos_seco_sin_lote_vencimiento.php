<?php

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migration 123: Asegurar que todos los productos en ambiente SECO no manejen lotes ni vencimiento.
 */
$secoAmbienteIds = Capsule::table('ambientes')
    ->whereRaw("UPPER(codigo) = 'SECO'")
    ->pluck('id')
    ->toArray();

if (!empty($secoAmbienteIds)) {
    Capsule::table('productos')
        ->whereIn('ambiente_id', $secoAmbienteIds)
        ->update([
            'controla_lote' => false,
            'controla_vencimiento' => false
        ]);
}

Capsule::table('productos')
    ->whereRaw("UPPER(temperatura_almacen) = 'SECO'")
    ->update([
        'controla_lote' => false,
        'controla_vencimiento' => false
    ]);
