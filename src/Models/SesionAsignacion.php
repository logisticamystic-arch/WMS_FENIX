<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SesionAsignacion
 * ================
 * Instrucción de conteo asignada a un auxiliar dentro de una sesión.
 * Define qué debe contar (por Pasillo, Módulo, Referencia o Libre)
 * y en qué ronda (1, 2 o 3 para inventarios generales).
 *
 * Cuando la asignación es creada el sistema envía una notificación al auxiliar.
 * El auxiliar sólo ve en su módil las asignaciones con estado != Finalizado.
 */
class SesionAsignacion extends Model
{
    protected $table = 'sesion_asignaciones';

    protected $fillable = [
        'sesion_id', 'auxiliar_id', 'ronda',
        'tipo_instruccion', 'pasillo', 'modulo', 'producto_id',
        'instruccion_libre', 'estado',
        'notificado_at', 'iniciado_at', 'finalizado_at',
    ];

    protected $casts = [
        'ronda'          => 'integer',
        'notificado_at'  => 'datetime',
        'iniciado_at'    => 'datetime',
        'finalizado_at'  => 'datetime',
    ];

    // ── Constantes ─────────────────────────────────────────────────────────
    const INSTRUCCION_PASILLO    = 'Pasillo';
    const INSTRUCCION_MODULO     = 'Modulo';
    const INSTRUCCION_REFERENCIA = 'Referencia';
    const INSTRUCCION_LIBRE      = 'Libre';

    const ESTADO_PENDIENTE   = 'Pendiente';
    const ESTADO_NOTIFICADO  = 'Notificado';
    const ESTADO_EN_CONTEO   = 'EnConteo';
    const ESTADO_FINALIZADO  = 'Finalizado';

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function sesion()
    {
        return $this->belongsTo(SesionInventario::class, 'sesion_id');
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function lineas()
    {
        return $this->hasMany(SesionLinea::class, 'asignacion_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Genera la descripción textual de la instrucción para mostrar al auxiliar.
     */
    public function getDescripcionInstruccionAttribute(): string
    {
        switch ($this->tipo_instruccion) {
            case self::INSTRUCCION_PASILLO:
                return "Contar Pasillo: {$this->pasillo}";
            case self::INSTRUCCION_MODULO:
                return "Contar Módulo: {$this->modulo}";
            case self::INSTRUCCION_REFERENCIA:
                return "Contar Referencia específica (ID: {$this->producto_id})";
            default:
                return $this->instruccion_libre ?: 'Conteo libre — sin restricción de ubicación';
        }
    }
}
