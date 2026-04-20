<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SesionInventario
 * ================
 * Representa una sesión de inventario (Cíclico o General).
 * Una sesión agrupa las asignaciones, líneas contadas y ajustes resultantes.
 *
 * Estados del flujo:
 *   Borrador → EnCurso → PendienteAjuste → Ajustado → Cerrado
 */
class SesionInventario extends Model
{
    protected $table = 'sesiones_inventario';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'descripcion',
        'tipo', 'num_conteos', 'comparar_sistema', 'estado',
        'creado_por', 'ajustado_por',
        'fecha_inicio', 'fecha_cierre',
    ];

    protected $casts = [
        'comparar_sistema' => 'boolean',
        'num_conteos'      => 'integer',
        'fecha_inicio'     => 'date',
        'fecha_cierre'     => 'date',
    ];

    // ── Constantes ─────────────────────────────────────────────────────────
    const TIPO_CICLICO  = 'Ciclico';
    const TIPO_GENERAL  = 'General';

    const ESTADO_BORRADOR          = 'Borrador';
    const ESTADO_EN_CURSO          = 'EnCurso';
    const ESTADO_PENDIENTE_AJUSTE  = 'PendienteAjuste';
    const ESTADO_AJUSTADO          = 'Ajustado';
    const ESTADO_CERRADO           = 'Cerrado';

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function creadoPor()
    {
        return $this->belongsTo(Personal::class, 'creado_por');
    }

    public function ajustadoPor()
    {
        return $this->belongsTo(Personal::class, 'ajustado_por');
    }

    public function asignaciones()
    {
        return $this->hasMany(SesionAsignacion::class, 'sesion_id');
    }

    public function lineas()
    {
        return $this->hasMany(SesionLinea::class, 'sesion_id');
    }

    public function ajustes()
    {
        return $this->hasMany(AjusteInventario::class, 'sesion_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActivas($query)
    {
        return $query->whereIn('estado', [self::ESTADO_EN_CURSO, self::ESTADO_PENDIENTE_AJUSTE]);
    }

    public function scopeCiclicas($query)
    {
        return $query->where('tipo', self::TIPO_CICLICO);
    }

    public function scopeGenerales($query)
    {
        return $query->where('tipo', self::TIPO_GENERAL);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Verifica si todos los auxiliares de una ronda han finalizado su conteo.
     */
    public function rondaCompleta(int $ronda): bool
    {
        $total      = $this->asignaciones()->where('ronda', $ronda)->count();
        $finalizados = $this->asignaciones()
                            ->where('ronda', $ronda)
                            ->where('estado', 'Finalizado')
                            ->count();
        return $total > 0 && $total === $finalizados;
    }

    /**
     * Para General con 2 conteos: verifica si el conteo 1 y conteo 2
     * coinciden en referencia + ubicación + cantidad (sin diferencias).
     * Retorna array: ['ok' => bool, 'diferencias' => [...]]
     */
    public function verificarConsistenciaRondas(): array
    {
        if ($this->num_conteos < 2) {
            return ['ok' => true, 'diferencias' => []];
        }

        $ronda1 = $this->lineas()
            ->where('ronda', 1)->where('estado', 'Activo')
            ->get()
            ->keyBy(fn($l) => $l->producto_id . '_' . $l->ubicacion_id . '_' . ($l->lote ?? ''));

        $ronda2 = $this->lineas()
            ->where('ronda', 2)->where('estado', 'Activo')
            ->get()
            ->keyBy(fn($l) => $l->producto_id . '_' . $l->ubicacion_id . '_' . ($l->lote ?? ''));

        $diferencias = [];

        foreach ($ronda1 as $key => $l1) {
            $l2 = $ronda2->get($key);
            if (!$l2 || $l2->cantidad_contada !== $l1->cantidad_contada) {
                $diferencias[] = [
                    'producto_id'     => $l1->producto_id,
                    'ubicacion_id'    => $l1->ubicacion_id,
                    'lote'            => $l1->lote,
                    'cantidad_ronda1' => $l1->cantidad_contada,
                    'cantidad_ronda2' => $l2 ? $l2->cantidad_contada : null,
                ];
            }
        }

        // Referencias en ronda2 que no están en ronda1
        foreach ($ronda2 as $key => $l2) {
            if (!$ronda1->has($key)) {
                $diferencias[] = [
                    'producto_id'     => $l2->producto_id,
                    'ubicacion_id'    => $l2->ubicacion_id,
                    'lote'            => $l2->lote,
                    'cantidad_ronda1' => null,
                    'cantidad_ronda2' => $l2->cantidad_contada,
                ];
            }
        }

        return ['ok' => empty($diferencias), 'diferencias' => $diferencias];
    }
}
