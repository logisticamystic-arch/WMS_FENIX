<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionIcgLinea extends Model
{
    protected $table = 'sesion_icg_lineas';

    protected $fillable = [
        'sesion_id',
        'producto_id',
        'codigo_referencia',
        'nombre_referencia',
        'cantidad_icg',
        'empresa_id',
        'sucursal_id',
    ];

    protected $casts = [
        'cantidad_icg' => 'float',
    ];

    public function sesion()
    {
        return $this->belongsTo(SesionInventario::class, 'sesion_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
