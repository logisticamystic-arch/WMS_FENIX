<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Inventario extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'inventarios';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id', 'ubicacion_id',
        'lote', 'fecha_vencimiento', 'cantidad', 'cantidad_reservada', 'estado',
        'numero_pallet',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
    ];

    const ESTADO_DISPONIBLE = 'Disponible';
    const ESTADO_RESERVADO = 'Reservado';
    const ESTADO_CUARENTENA = 'Cuarentena';
    const ESTADO_OBSOLETO = 'Obsoleto';

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

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    /**
     * Available quantity (total - reserved)
     */
    public function getCantidadDisponibleAttribute(): int
    {
        return $this->cantidad - $this->cantidad_reservada;
    }

    /**
     * Check if this stock is expiring within N days
     */
    public function isProximoAVencer(int $dias = 30): bool
    {
        if (!$this->fecha_vencimiento) return false;
        return $this->fecha_vencimiento->diffInDays(now()) <= $dias;
    }

    /**
     * Scope: FEFO ordering (First Expiry, First Out)
     */
    public function scopeFefo($query)
    {
        return $query->orderBy('fecha_vencimiento', 'asc')->orderBy('created_at', 'asc');
    }

    /**
     * Scope: Only available stock
     */
    public function scopeDisponible($query)
    {
        return $query->where('estado', self::ESTADO_DISPONIBLE)
            ->where('cantidad', '>', 0);
    }
}


