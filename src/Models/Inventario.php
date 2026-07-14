<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Inventario extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'inventarios';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id', 'ubicacion_id',
        'lote', 'fecha_vencimiento', 'cantidad', 'cantidad_reservada', 'estado',
        'numero_pallet', 'cantidad_cajas', 'saldos',
    ];

    protected $casts = [
        'fecha_vencimiento'  => 'date',
        'cantidad'           => 'decimal:2',
        'cantidad_reservada' => 'decimal:2',
        'cantidad_cajas'     => 'integer',
        'saldos'             => 'decimal:2',
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
     * und_total como float (alias semántico del campo 'cantidad')
     */
    public function getUndTotalAttribute(): float
    {
        return (float)$this->cantidad;
    }

    /**
     * Etiqueta legible del desglose: "X cajas × Y u/e + Z sueltos = N UND/TOTAL"
     * Si no hay desglose disponible retorna solo "N UND/TOTAL".
     */
    public function getDesgloseLabelAttribute(): string
    {
        $total = number_format((float)$this->cantidad, 2, '.', '');

        if ((int)$this->cantidad_cajas > 0 && $this->relationLoaded('producto') && $this->producto) {
            $cajas   = (int)$this->cantidad_cajas;
            $ue      = max(1, (int)($this->producto->unidades_caja ?? 1));
            $sueltos = number_format((float)$this->saldos, 2, '.', '');
            return "{$cajas} cajas × {$ue} u/e + {$sueltos} sueltos = {$total} UND/TOTAL";
        }

        return "{$total} UND/TOTAL";
    }

    /**
     * Array con el desglose completo: cajas, sueltos, und_total
     */
    public function getDesglosadoAttribute(): array
    {
        return [
            'cajas'     => $this->cantidad_cajas,
            'sueltos'   => (float)$this->saldos,
            'und_total' => (float)$this->cantidad,
        ];
    }

    /**
     * Available quantity (total - reserved)
     */
    public function getCantidadDisponibleAttribute(): float
    {
        return (float)$this->cantidad - (float)$this->cantidad_reservada;
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
     *
     * NOTA: El OR con saldos > 0 es intencional para cubrir sueltos sin caja
     * completa. Si aparecen filas con cantidad = 0 y saldos > 0, es un error
     * de sincronización en el proceso de ajuste, no en este scope.
     *
     * ADVERTENCIA: NO agregar SoftDeletes a este modelo ni a AjusteInventario
     * ni a MovimientoInventario. Causaría doble conteo de stock en scopes
     * que usen withTrashed() o whereNull('deleted_at') accidentalmente.
     */
    public function scopeDisponible($query)
    {
        return $query->where('estado', self::ESTADO_DISPONIBLE)
            ->where(function ($q) {
                $q->where('cantidad', '>', 0)->orWhere('saldos', '>', 0);
            });
    }
}


