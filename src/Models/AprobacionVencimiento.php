<?php

namespace App\Models;

class AprobacionVencimiento extends BaseModel
{
    protected $table = 'aprobaciones_vencimiento';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id', 'lote',
        'dias_restantes', 'solicitado_por', 'aprobado_por',
        'estado', 'valid_until',
    ];

    protected $casts = [
        'valid_until' => 'date',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function solicitante()
    {
        return $this->belongsTo(Personal::class, 'solicitado_por');
    }

    public function aprobador()
    {
        return $this->belongsTo(Personal::class, 'aprobado_por');
    }
}
