<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Ambiente extends BaseModel
{
    use TenantScoped;

    protected $table = 'ambientes';

    protected $fillable = [
        'empresa_id', 'codigo', 'descripcion', 'icono', 'color', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'ambiente_id');
    }
}
