<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class CausalNovedad extends BaseModel
{
    use TenantScoped;

    protected $table    = 'causales_novedad';
    protected $fillable = ['empresa_id', 'nombre', 'area_responsable', 'afecta_nivel_servicio', 'activo'];
    protected $casts    = [
        'activo'                 => 'boolean',
        'afecta_nivel_servicio'  => 'boolean',
    ];
}
