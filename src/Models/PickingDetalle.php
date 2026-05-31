<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickingDetalle extends BaseModel
{
    protected $table = 'picking_detalles';

    protected $fillable = [
        'orden_picking_id', 'producto_id', 'ubicacion_id', 'auxiliar_id', 'lote',
        'fecha_vencimiento',
        'cantidad_solicitada', 'cantidad_pickeada', 'pasillo_lock', 'estado',
        'costo_unitario', 'descuento_porc', 'iva_porc', 'valor_iva', 'total_linea', 'devolucion_qty',
        'ambiente', 'numero_pedido_ref',
    ];

    public function ordenPicking()
    {
        return $this->belongsTo(OrdenPicking::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }
}
