<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionDetalleCalidad extends Model
{
    protected $table = 'recepcion_detalle_calidad';

    protected $fillable = [
        'recepcion_detalle_id',
        'olor',
        'color',
        'textura',
        'temperatura',
        'empaque',
        'rotulado',
    ];

    public function detalle()
    {
        return $this->belongsTo(RecepcionDetalle::class, 'recepcion_detalle_id');
    }
}
