<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Miscelaneo extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;

    protected $table = 'miscelaneos';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'numero_recepcion', 'proveedor', 'articulo',
        'cantidad', 'unidad_medida', 'observaciones', 'recibido_por',
        'cliente_id', 'cliente_nombre', 'despacho_id', 'estado',
    ];

    const ESTADO_RECIBIDO    = 'Recibido';
    const ESTADO_ASIGNADO    = 'Asignado';
    const ESTADO_DESPACHADO  = 'Despachado';

    public function fotos()
    {
        return $this->hasMany(MiscelaneoFoto::class);
    }

    public function recibidoPor()
    {
        return $this->belongsTo(Personal::class, 'recibido_por');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }
}
