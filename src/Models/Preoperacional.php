<?php

namespace App\Models;

class Preoperacional extends BaseModel
{
    protected $table = 'preoperacionales';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'fecha', 'vehiculo', 'conductor', 'ruta',
        'observaciones', 'creado_por',
    ];

    public function items()
    {
        return $this->hasMany(PreoperacionalItem::class, 'preoperacional_id');
    }

    public function fotos()
    {
        return $this->hasMany(PreoperacionalFoto::class, 'preoperacional_id');
    }

    public function creador()
    {
        return $this->belongsTo(Personal::class, 'creado_por');
    }
}
