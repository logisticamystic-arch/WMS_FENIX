<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

class AjusteUbicacion extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;

    protected $table = 'ajuste_ubicacion';

    const ESTADO_PENDIENTE = 'Pendiente';
    const ESTADO_APROBADO  = 'Aprobado';
    const ESTADO_RECHAZADO = 'Rechazado';

    const TIPO_AJUSTE_COMPLETO   = 'AjusteCompleto';
    const TIPO_AGREGAR_INVENTARIO = 'AgregarInventario';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'ubicacion_id', 'auxiliar_id',
        'tipo', 'estado', 'observaciones', 'aprobado_por', 'fecha_aprobacion',
    ];

    protected $casts = [
        'fecha_aprobacion' => 'datetime',
    ];

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function aprobador()
    {
        return $this->belongsTo(Personal::class, 'aprobado_por');
    }

    public function detalles()
    {
        return $this->hasMany(AjusteUbicacionDetalle::class, 'ajuste_id');
    }
}
