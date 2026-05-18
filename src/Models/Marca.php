<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Marca extends Model
{
    use TenantScoped;
    protected $table = 'marcas';

    protected $fillable = [
        'empresa_id', 'nombre', 'proveedor', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }
}


