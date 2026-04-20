<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AjusteInventario
 * ================
 * Registro INMUTABLE de todos los ajustes de inventario.
 * REGLA CRÍTICA: No se puede eliminar ni modificar un registro de ajuste.
 * Toda corrección genera un nuevo ajuste compensatorio.
 *
 * Los ajustes se originan desde:
 *  - Conteo cíclico (línea individual o todo el conteo)
 *  - Inventario general aprobado
 *  - Corrección manual por administrador (sub-módulo de correcciones)
 *
 * Todos los ajustes crean también un MovimientoInventario del tipo
 * AjustePositivo o AjusteNegativo para que aparezcan en el Kardex.
 */
class AjusteInventario extends Model
{
    // Sin updated_at — registro inmutable
    const UPDATED_AT = null;

    protected $table = 'ajustes_inventario';

    protected $fillable = [
        'empresa_id', 'sucursal_id',
        'origen', 'sesion_id', 'linea_id', 'movimiento_id',
        'producto_id', 'ubicacion_id', 'lote', 'fecha_vencimiento',
        'cantidad_fisica', 'cantidad_sistema', 'diferencia',
        'tipo_ajuste', 'motivo',
        'auxiliar_id', 'ajustado_por',
        'fecha', 'hora',
    ];

    protected $casts = [
        'cantidad_fisica'  => 'integer',
        'cantidad_sistema' => 'integer',
        'diferencia'       => 'integer',
        'fecha'            => 'date',
        'fecha_vencimiento'=> 'date',
    ];

    // ── Constantes ─────────────────────────────────────────────────────────
    const ORIGEN_MANUAL         = 'Manual';
    const ORIGEN_CONTEO_LINEA   = 'ConteoLinea';
    const ORIGEN_CONTEO_TOTAL   = 'ConteoTotal';
    const ORIGEN_CORRECCION     = 'CorreccionAdmin';

    const TIPO_ENTRADA = 'Entrada';
    const TIPO_SALIDA  = 'Salida';

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function sesion()
    {
        return $this->belongsTo(SesionInventario::class, 'sesion_id');
    }

    public function linea()
    {
        return $this->belongsTo(SesionLinea::class, 'linea_id');
    }

    public function movimiento()
    {
        return $this->belongsTo(MovimientoInventario::class, 'movimiento_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function ajustadoPor()
    {
        return $this->belongsTo(Personal::class, 'ajustado_por');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeEntradas($query)
    {
        return $query->where('tipo_ajuste', self::TIPO_ENTRADA);
    }

    public function scopeSalidas($query)
    {
        return $query->where('tipo_ajuste', self::TIPO_SALIDA);
    }

    public function scopeDeConteo($query)
    {
        return $query->whereIn('origen', [self::ORIGEN_CONTEO_LINEA, self::ORIGEN_CONTEO_TOTAL]);
    }
}
