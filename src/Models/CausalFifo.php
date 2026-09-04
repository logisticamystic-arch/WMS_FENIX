<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class CausalFifo extends BaseModel
{
    use TenantScoped;

    protected $table    = 'causales_fifo';
    protected $fillable = ['empresa_id', 'nombre', 'activo'];
    protected $casts    = [
        'activo' => 'boolean',
    ];
}
