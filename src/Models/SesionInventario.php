<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

/**
 * SesionInventario
 * ================
 * Representa una sesión de inventario (Cíclico o General).
 * Una sesión agrupa las asignaciones, líneas contadas y ajustes resultantes.
 *
 * Estados del flujo:
 *   Borrador → EnCurso → PendienteAjuste → Ajustado → Cerrado
 */
class SesionInventario extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;





    protected $table = 'sesiones_inventario';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'descripcion',
        'tipo', 'num_conteos', 'comparar_sistema', 'estado',
        'creado_por', 'ajustado_por',
        'fecha_inicio', 'fecha_cierre',
        'fv_obligatorio',
    ];

    protected $casts = [
        'comparar_sistema' => 'boolean',
        'fv_obligatorio'   => 'boolean',
        'num_conteos'      => 'integer',
        'fecha_inicio'     => 'date',
        'fecha_cierre'     => 'date',
    ];

    // ── Constantes ─────────────────────────────────────────────────────────
    const TIPO_CICLICO        = 'Ciclico';
    const TIPO_GENERAL        = 'General';
    const TIPO_CARGUE_INICIAL = 'CargueInicial';

    const ESTADO_BORRADOR          = 'Borrador';
    const ESTADO_EN_CURSO          = 'EnCurso';
    const ESTADO_PENDIENTE_AJUSTE  = 'PendienteAjuste';
    const ESTADO_AJUSTADO          = 'Ajustado';
    const ESTADO_CERRADO           = 'Cerrado';
    const ESTADO_FINALIZADO        = 'Finalizado';

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
     * Comparativo entre rondas POR REFERENCIA (producto), solo sobre las
     * referencias que se enviaron a recontar (segundo/tercer conteo).
     *
     * Antes comparaba toda la ronda 1 contra toda la ronda 2 por
     * producto+ubicación+lote, asumiendo un segundo conteo COMPLETO — pero los
     * segundos conteos del sistema son parciales (solo las referencias con
     * diferencia, ver crearSegundosConteosBatch()), así que todo lo no
     * recontado salía como "diferencia" y el ajuste de un General con 2
     * conteos quedaba bloqueado siempre (auditoría 2026-09-28).
     *
     * Por referencia recontada:
     *   R1 == R2            → 'Confirmado'    (vale R2)
     *   R1 != R2, con R3    → 'Resuelto R3'   (vale R3)
     *   R1 != R2, sin R3    → 'Sin confirmar' (vale R2; si la sesión tiene
     *                          3 conteos configurados, bloquea el ajuste)
     * Retorna ['ok' => bool, 'diferencias' => [...], 'referencias' => [...]]
     * donde 'diferencias' son las 'Sin confirmar'.
     */
    public function verificarConsistenciaRondas(): array
    {
        $porProducto = [];
        foreach ($this->lineas()->where('estado', 'Activo')->get(['producto_id', 'ronda', 'cantidad_contada']) as $l) {
            $porProducto[$l->producto_id][$l->ronda] = ($porProducto[$l->producto_id][$l->ronda] ?? 0) + (float)$l->cantidad_contada;
        }
        // Una referencia recontada sin encontrar nada no deja líneas en esa
        // ronda: su conteo es 0, no "no recontada".
        $recontadas = $this->asignaciones()
            ->where('ronda', '>=', 2)->where('estado', 'Finalizado')->whereNotNull('producto_id')
            ->get(['producto_id', 'ronda']);
        foreach ($recontadas as $a) {
            $porProducto[$a->producto_id][$a->ronda] = $porProducto[$a->producto_id][$a->ronda] ?? 0.0;
        }

        $referencias = [];
        $diferencias = [];
        foreach ($porProducto as $prodId => $r) {
            if (!isset($r[2])) continue;
            $r1 = $r[1] ?? 0.0;
            $r2 = $r[2];
            $r3 = $r[3] ?? null;
            $estado = abs($r1 - $r2) < 0.0001 ? 'Confirmado' : ($r3 !== null ? 'Resuelto R3' : 'Sin confirmar');
            $fila = [
                'producto_id'     => $prodId,
                'cantidad_ronda1' => $r1,
                'cantidad_ronda2' => $r2,
                'cantidad_ronda3' => $r3,
                'estado'          => $estado,
            ];
            $referencias[] = $fila;
            if ($estado === 'Sin confirmar') $diferencias[] = $fila;
        }

        return ['ok' => empty($diferencias), 'diferencias' => $diferencias, 'referencias' => $referencias];
    }
}
