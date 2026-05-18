<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Sucursal extends Model
{
    use TenantScoped;

    protected $table = 'sucursales';

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'direccion', 'ciudad', 'telefono', 'tipo', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function parametros()
    {
        return $this->hasMany(Parametro::class);
    }

    public function ubicaciones()
    {
        return $this->hasMany(Ubicacion::class);
    }

    public function citas()
    {
        return $this->hasMany(Cita::class);
    }

    public function personal()
    {
        return $this->hasMany(Personal::class);
    }
}
