<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Traspaso extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;

    protected $table = 'traspasos';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'numero_traspaso', 'producto_id',
        'ubicacion_id', 'lote', 'fecha_vencimiento', 'cantidad',
        'cliente_id', 'cliente_nombre', 'motivo', 'observaciones',
        'auxiliar_id', 'estado',
    ];

    const MOTIVOS = [
        'Traspaso a cliente',
        'Muestra comercial',
        'Donación',
        'Destrucción',
        'Consumo interno',
        'Devolución a proveedor',
        'Ajuste por calidad',
        'Otro',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }
}
