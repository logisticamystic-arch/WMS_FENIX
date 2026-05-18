<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Ruta extends Model
{
    use TenantScoped;
    protected $table = 'rutas';
    protected $fillable = [
        'empresa_id',
        'nombre',
        'comercial',
        'frecuencia', // Store as JSON or comma-separated string of days
        'activo'
    ];

    protected $casts = [
        'activo' => 'boolean'
    ];

    public function clientes()
    {
        return $this->hasMany(Cliente::class);
    }
}


