<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenCompraDetalle extends Model
{
    protected $table = 'orden_compra_detalles';

    protected $fillable = [
        'orden_compra_id', 'producto_id', 'cantidad_solicitada', 'cantidad_recibida',
        'aprobado_admin', 'novedad_motivo', 'novedad_observacion', 'cantidad_novedad',
    ];

    protected $casts = [
        'aprobado_admin' => 'boolean',
    ];

    public function ordenCompra()
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
