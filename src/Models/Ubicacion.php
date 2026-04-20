<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ubicacion extends Model
{
    protected $table = 'ubicaciones';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'codigo', 'zona', 'pasillo', 'modulo', 'nivel', 'posicion',
        'tipo_ubicacion', 'capacidad_maxima', 'estado', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'capacidad_maxima' => 'integer',
    ];

    // Location type constants
    const TIPO_PICKING = 'Picking';
    const TIPO_ALMACENAMIENTO = 'Almacenamiento';
    const TIPO_MUELLE = 'Muelle';
    const TIPO_CARRO = 'Carro';
    const TIPO_PATIO = 'Patio';

    // State constants
    const ESTADO_LIBRE = 'Libre';
    const ESTADO_OCUPADA = 'Ocupada';
    const ESTADO_PARCIAL = 'Parcial';
    const ESTADO_LOCKED = 'Locked';

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function zona()
    {
        return $this->belongsTo(Zona::class, 'zona', 'codigo');
    }

    public function inventarios()
    {
        return $this->hasMany(Inventario::class);
    }

    /**
     * Check if location has available capacity
     */
    public function tieneCapacidad(int $cantidadAdicional = 0): bool
    {
        if ($this->capacidad_maxima === 0) return true; // unlimited
        $stockActual = $this->inventarios()->sum('cantidad');
        return ($stockActual + $cantidadAdicional) <= $this->capacidad_maxima;
    }

    /**
     * Check if location is locked (in cycle count)
     */
    public function isLocked(): bool
    {
        return $this->estado === self::ESTADO_LOCKED;
    }

    /**
     * Recalcula el estado de la ubicación (Libre/Ocupada) basándose en la existencia de inventario.
     * Útil tras desbloquear una ubicación por inventario.
     */
    public function recalcularEstado(): bool
    {
        $stockActual = $this->inventarios()->sum('cantidad');
        $nuevoEstado = ($stockActual > 0) ? self::ESTADO_OCUPADA : self::ESTADO_LIBRE;
        
        if ($this->estado !== $nuevoEstado) {
            $this->estado = $nuevoEstado;
            return $this->save();
        }
        return true;
    }
}

