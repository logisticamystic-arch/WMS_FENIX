<?php
// src/Models/CausalDevolucion.php
namespace App\Models;

use App\Models\Concerns\TenantScoped;

class CausalDevolucion extends BaseModel
{
    use TenantScoped;

    protected $table = 'causales_devolucion';

    protected $fillable = [
        'empresa_id', 'causal', 'responsable', 'descripcion', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }
}
