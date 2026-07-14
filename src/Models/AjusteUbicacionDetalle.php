<?php

namespace App\Models;

class AjusteUbicacionDetalle extends BaseModel
{
    protected $table = 'ajuste_ubicacion_detalles';

    protected $fillable = [
        'ajuste_id', 'producto_id',
        'cantidad_cajas', 'saldos', 'cantidad',
        'lote', 'fecha_vencimiento',
    ];

    protected $casts = [
        'cantidad_cajas'    => 'integer',
        'saldos'            => 'decimal:3',
        'cantidad'          => 'decimal:3',
        'fecha_vencimiento' => 'date',
    ];

    public function ajuste()
    {
        return $this->belongsTo(AjusteUbicacion::class, 'ajuste_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
