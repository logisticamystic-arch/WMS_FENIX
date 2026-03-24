<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaProducto extends Model
{
    protected $table = 'categoria_productos';

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'requiere_foto_vencimiento',
    ];

    protected $casts = [
        'requiere_foto_vencimiento' => 'boolean',
    ];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}
