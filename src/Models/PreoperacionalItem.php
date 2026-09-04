<?php

namespace App\Models;

class PreoperacionalItem extends BaseModel
{
    protected $table = 'preoperacional_items';
    public $timestamps = false;

    protected $fillable = [
        'preoperacional_id', 'item_clave', 'item_nombre', 'calificacion', 'foto_url',
    ];

    public function preoperacional()
    {
        return $this->belongsTo(Preoperacional::class, 'preoperacional_id');
    }
}
