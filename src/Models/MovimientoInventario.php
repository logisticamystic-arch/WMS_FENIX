<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

/**
 * Immutable transaction log — no updates allowed
 */
class MovimientoInventario extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    // Only created_at, no updated_at (immutable)
    const UPDATED_AT = null;

    protected $table = 'movimiento_inventarios';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id',
        'ubicacion_origen_id', 'ubicacion_destino_id',
        'tipo_movimiento', 'cantidad', 'lote', 'fecha_vencimiento',
        'referencia_tipo', 'referencia_id',
        'auxiliar_id', 'fecha_movimiento', 'hora_inicio', 'hora_fin',
        'observaciones', 'numero_pallet',
    ];

    protected $casts = [
        'fecha_movimiento' => 'date',
        'fecha_vencimiento' => 'date',
    ];

    // Movement type constants
    const TIPO_ENTRADA = 'Entrada';
    const TIPO_SALIDA = 'Salida';
    const TIPO_TRASLADO = 'Traslado';
    const TIPO_AJUSTE_POSITIVO = 'AjustePositivo';
    const TIPO_AJUSTE_NEGATIVO = 'AjusteNegativo';
    const TIPO_PICKING = 'Picking';
    const TIPO_REABASTECIMIENTO = 'Reabastecimiento';
    const TIPO_DEVOLUCION = 'Devolucion';

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
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

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }
}


