<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class CategoriaProducto extends Model
{
    use TenantScoped;
    protected $table = 'categoria_productos';

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'activo', 'requiere_foto_vencimiento',
    ];

    protected $casts = [
        'requiere_foto_vencimiento' => 'boolean',
    ];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}


