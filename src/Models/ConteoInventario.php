<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConteoInventario extends Model
{
    protected $table = 'conteo_inventarios';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'tipo_conteo', 'estado',
        'auxiliar_id', 'aprobado_por',
        'fecha_movimiento', 'hora_inicio', 'hora_fin', 'observaciones',
    ];

    protected $casts = [
        'fecha_movimiento' => 'date',
    ];

    const TIPO_GENERAL = 'General';
    const TIPO_POR_REFERENCIA = 'PorReferencia';
    const TIPO_POR_UBICACION = 'PorUbicacion';

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function aprobador()
    {
        return $this->belongsTo(Personal::class, 'aprobado_por');
    }

    public function detalles()
    {
        return $this->hasMany(ConteoDetalle::class, 'conteo_id');
    }
}
