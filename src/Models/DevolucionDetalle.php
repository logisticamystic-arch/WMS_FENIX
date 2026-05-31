<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevolucionDetalle extends BaseModel
{
    protected $table = 'devolucion_detalles';

    protected $fillable = [
        'devolucion_id', 'producto_id', 'lote', 'fecha_vencimiento',
        'cantidad', 'motivo', 'detalle_motivo', 'destino', 'ubicacion_destino_id',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
    ];

    const MOTIVO_AVERIA = 'Averia';
    const MOTIVO_VENCIDO = 'Vencido';
    const MOTIVO_ERROR_PROVEEDOR = 'ErrorProveedor';
    const MOTIVO_CALIDAD = 'CalidadDeficiente';
    const MOTIVO_OTRO = 'Otro';

    const DESTINO_OBSOLETO = 'InventarioObsoleto';
    const DESTINO_REINGRESO = 'Reingreso';
    const DESTINO_DEVOLUCION = 'DevolucionProveedor';

    public function devolucion()
    {
        return $this->belongsTo(Devolucion::class);
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
