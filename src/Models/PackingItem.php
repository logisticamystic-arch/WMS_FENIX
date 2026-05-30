<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackingItem extends Model
{
    protected $table      = 'packing_items';
    public    $timestamps = false;
    protected $fillable   = [
        'unidad_id', 'picking_detalle_id', 'producto_id',
        'lote', 'fecha_vencimiento', 'separador_id', 'cantidad',
    ];
    protected $casts = [
        'cantidad' => 'float',
    ];
}
