<?php

namespace App\Models;

class ProductoFoto extends BaseModel
{
    protected $table = 'producto_fotos';

    protected $fillable = [
        'producto_id', 'url',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
