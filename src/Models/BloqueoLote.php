<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class BloqueoLote extends BaseModel
{
    use TenantScoped;

    protected $table = 'bloqueo_lotes';

    protected $fillable = [
        'empresa_id', 'producto_id', 'lote', 'motivo', 'bloqueado_por',
        'activo', 'desbloqueado_por', 'desbloqueado_at', 'motivo_desbloqueo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
