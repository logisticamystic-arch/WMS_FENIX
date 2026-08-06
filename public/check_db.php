<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use App\Models\PickingDetalle;
use Illuminate\Database\Capsule\Manager as Capsule;

$pds = Capsule::table('picking_detalles')
    ->join('orden_pickings', 'orden_pickings.id', '=', 'picking_detalles.orden_picking_id')
    ->join('productos', 'productos.id', '=', 'picking_detalles.producto_id')
    ->where('orden_pickings.planilla_numero', 'Planilla 420')
    ->select('picking_detalles.*', 'productos.nombre as producto_nombre', 'productos.unidades_caja', 'orden_pickings.numero_pedido', 'orden_pickings.numero_orden')
    ->get();

foreach($pds as $pd) {
    echo "ID: {$pd->id} | Sol: {$pd->cantidad_solicitada} | Pick: {$pd->cantidad_pickeada} | Prod: {$pd->producto_nombre} | U/E: {$pd->unidades_caja} | Pedido: {$pd->numero_pedido} | Orden: {$pd->numero_orden}<br>\n";
}
