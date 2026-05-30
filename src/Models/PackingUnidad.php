<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackingUnidad extends Model
{
    protected $table      = 'packing_unidades';
    public    $timestamps = false;
    protected $fillable   = [
        'sesion_id', 'consecutivo', 'estado', 'total_unidades', 'sticker_impreso', 'closed_at',
    ];
    protected $casts = [
        'consecutivo'    => 'integer',
        'sticker_impreso' => 'boolean',
        'total_unidades'  => 'float',
    ];

    public function items()
    {
        return $this->hasMany(PackingItem::class, 'unidad_id');
    }
}
