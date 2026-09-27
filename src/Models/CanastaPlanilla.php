<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class CanastaPlanilla extends BaseModel
{
    use TenantScoped;

    protected $table = 'canastas_planilla';

    protected $fillable = [
        'empresa_id', 'planilla', 'sucursal_entrega', 'ambiente_id', 'cantidad', 'created_by',
    ];

    public function ambiente()
    {
        return $this->belongsTo(Ambiente::class, 'ambiente_id');
    }
}
