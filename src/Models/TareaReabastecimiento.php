<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class TareaReabastecimiento extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'tarea_reabastecimientos';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'orden_picking_id', 'producto_id',
        'ubicacion_origen_id', 'ubicacion_destino_id', 'cantidad',
        'asignado_a', 'estado',
        'fecha_movimiento', 'hora_inicio', 'hora_fin',
    ];

    protected $casts = [
        'fecha_movimiento' => 'date',
    ];

    public function ordenPicking()
    {
        return $this->belongsTo(OrdenPicking::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function ubicacionOrigen()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_origen_id');
    }

    public function ubicacionDestino()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_destino_id');
    }

    public function montacarguista()
    {
        return $this->belongsTo(Personal::class, 'asignado_a');
    }
}


