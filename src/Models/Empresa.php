<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends BaseModel
{
    protected $table = 'empresas';

    protected $fillable = [
        'nit', 'razon_social', 'direccion', 'telefono', 'email', 'logo_url', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // --- Relationships ---

    public function sucursales()
    {
        return $this->hasMany(Sucursal::class);
    }

    public function personal()
    {
        return $this->hasMany(Personal::class);
    }

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    public function marcas()
    {
        return $this->hasMany(Marca::class);
    }

    public function rolPermisos()
    {
        return $this->hasMany(RolPermiso::class);
    }
}
