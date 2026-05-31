<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class AlertaStock extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'alertas_stock';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id', 'tipo',
        'stock_actual', 'stock_minimo', 'fecha_vencimiento',
        'dias_para_vencer', 'estado', 'resuelta_por', 'resuelta_at',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function resuelto_por_usuario()
    {
        return $this->belongsTo(Personal::class, 'resuelta_por');
    }
}


