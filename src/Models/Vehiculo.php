<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Vehiculo extends BaseModel
{
    use TenantScoped;
    protected $table = 'vehiculos';
    protected $fillable = [
        'empresa_id',
        'placa',
        'tipo',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
