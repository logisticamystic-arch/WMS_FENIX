<?php

namespace App\Models;

class PreoperacionalItem extends BaseModel
{
    protected $table = 'preoperacional_items';
    public $timestamps = false;

    protected $fillable = [
        'preoperacional_id', 'item_clave', 'item_nombre', 'calificacion', 'foto_url',
        'detalle_no_conformidad', 'accion_correctiva', 'producto_desinfeccion',
    ];

    public function preoperacional()
    {
        return $this->belongsTo(Preoperacional::class, 'preoperacional_id');
    }

    public function fotos()
    {
        return $this->hasMany(PreoperacionalItemFoto::class, 'preoperacional_item_id');
    }
}
