<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Impresora extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'impresoras';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'ip', 'puerto',
        'tipo', 'modulos', 'tipos_trabajo', 'activo',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'puerto'        => 'integer',
        'tipos_trabajo' => 'array',
    ];
}
