<?php

namespace App\Models;

class MiscelaneoFoto extends BaseModel
{
    protected $table = 'miscelaneo_fotos';

    protected $fillable = ['miscelaneo_id', 'url'];

    public function miscelaneo()
    {
        return $this->belongsTo(Miscelaneo::class);
    }
}
