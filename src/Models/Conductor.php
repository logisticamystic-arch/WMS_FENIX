<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Conductor extends BaseModel
{
    use TenantScoped;
    protected $table = 'conductores';
    protected $fillable = [
        'empresa_id',
        'nombre',
        'documento',
        'telefono',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
