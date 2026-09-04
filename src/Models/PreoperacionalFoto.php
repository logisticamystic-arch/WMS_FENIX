<?php

namespace App\Models;

class PreoperacionalFoto extends BaseModel
{
    protected $table = 'preoperacional_fotos';
    public $timestamps = false;

    protected $fillable = [
        'preoperacional_id', 'url',
    ];

    public function preoperacional()
    {
        return $this->belongsTo(Preoperacional::class, 'preoperacional_id');
    }
}
