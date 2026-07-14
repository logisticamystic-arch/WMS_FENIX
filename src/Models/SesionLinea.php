<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * SesionLinea
 * ===========
 * Línea individual contada dentro de una sesión de inventario.
 * Cada línea pertenece a una ronda (1, 2 o 3) y a un auxiliar.
 *
 * REGLA DE NEGOCIO:
 *  - No se elimina físicamente: se cambia estado a 'Eliminado'.
 *  - El admin puede editar cantidad_contada; queda registro del valor original.
 *  - La diferencia = cantidad_contada - cantidad_sistema (puede ser + o -).
 */
class SesionLinea extends BaseModel
{
    protected $table = 'sesion_lineas';
    protected $primaryKey = 'id';
    public $incrementing = true;

    protected $fillable = [
        'sesion_id', 'asignacion_id', 'auxiliar_id', 'ronda',
        'producto_id', 'ubicacion_id', 'lote', 'fecha_vencimiento',
        'cantidad_contada', 'cantidad_cajas', 'saldos', 'cantidad_sistema', 'diferencia',
        'hora_conteo',
        'cantidad_original', 'editado_por', 'editado_at', 'motivo_edicion',
        'estado', 'eliminado_por', 'eliminado_at', 'ajustado',
    ];

    protected $casts = [
        'ronda'              => 'integer',
        'cantidad_contada'   => 'integer',
        'cantidad_cajas'     => 'integer',
        'saldos'             => 'float',
        'cantidad_sistema'   => 'integer',
        'diferencia'         => 'integer',
        'cantidad_original'  => 'integer',
        'fecha_vencimiento'  => 'date',
        'hora_conteo'        => 'datetime',
        'editado_at'         => 'datetime',
        'eliminado_at'       => 'datetime',
    ];

    const ESTADO_ACTIVO    = 'Activo';
    const ESTADO_ELIMINADO = 'Eliminado';

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function sesion()
    {
        return $this->belongsTo(SesionInventario::class, 'sesion_id');
    }

    public function asignacion()
    {
        return $this->belongsTo(SesionAsignacion::class, 'asignacion_id');
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_id');
    }

    public function editadoPor()
    {
        return $this->belongsTo(Personal::class, 'editado_por');
    }

    public function eliminadoPor()
    {
        return $this->belongsTo(Personal::class, 'eliminado_por');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActivas($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    public function scopeConDiferencia($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO)->where('diferencia', '!=', 0);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Días de vida útil restantes basado en la fecha de vencimiento contada.
     */
    public function getDiasVidaUtilAttribute(): ?int
    {
        if (!$this->fecha_vencimiento) return null;
        return (int) Carbon::now()->startOfDay()->diffInDays($this->fecha_vencimiento, false);
    }

    /**
     * Recalcula y guarda la diferencia.
     */
    public function recalcularDiferencia(): void
    {
        $this->diferencia = $this->cantidad_contada - $this->cantidad_sistema;
    }
}
