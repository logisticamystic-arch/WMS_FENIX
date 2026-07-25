<?php
require 'vendor/autoload.php';
require 'src/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$numeroOrdenes = ['DOC-01026', 'DOC-01059', '1026', '1059'];

$ordenes = Capsule::table('orden_pickings')
    ->whereIn('numero_orden', $numeroOrdenes)
    ->orWhereIn('planilla_numero', $numeroOrdenes)
    ->orWhereIn('id', [1026, 1059])
    ->get();

echo "=== ORDENES ===\n";
foreach ($ordenes as $o) {
    echo "ID: {$o->id} | NumeroOrden: {$o->numero_orden} | Planilla: {$o->planilla_numero} | Estado: {$o->estado} | Cert: {$o->estado_certificacion} | Cliente: {$o->sucursal_entrega}\n";
}

echo "\n=== DETALLES PICKING ===\n";
$detalles = Capsule::table('picking_detalles as pd')
    ->whereIn('pd.orden_picking_id', $ordenes->pluck('id'))
    ->get();

foreach ($detalles as $d) {
    echo "DetID: {$d->id} | OrdenID: {$d->orden_picking_id} | ProdID: {$d->producto_id} | Sol: {$d->cantidad_solicitada} | Pick: {$d->cantidad_pickeada} | Estado: {$d->estado} | Aux: {$d->auxiliar_id}\n";
}
