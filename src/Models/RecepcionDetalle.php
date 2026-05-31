<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionDetalle extends BaseModel
{
    protected $table = 'recepcion_detalles';

    protected $fillable = [
        'recepcion_id', 'producto_id', 'cantidad_esperada', 'cantidad_recibida',
        'cantidad_cajas', 'cajas_por_unidad',           // conversión física recibida
        'lote', 'fecha_vencimiento', 'estado_mercancia', 'novedad_motivo',
        'novedad_observacion', 'cantidad_novedad',
        'ubicacion_destino_id', 'aprobado_admin', 'numero_pallet',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'aprobado_admin'    => 'boolean',
    ];

    public function recepcion()
    {
        return $this->belongsTo(Recepcion::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function ubicacionDestino()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_destino_id');
    }
}
