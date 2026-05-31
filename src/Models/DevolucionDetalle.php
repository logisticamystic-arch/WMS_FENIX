<?php
// src/Models/DevolucionDetalle.php
namespace App\Models;

class DevolucionDetalle extends BaseModel
{
    protected $table = 'devolucion_detalles';

    protected $fillable = [
        'devolucion_id', 'producto_id', 'lote', 'fecha_vencimiento',
        'cantidad', 'condicion', 'motivo', 'detalle_motivo',
        'destino', 'ubicacion_destino_id',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'cantidad'          => 'float',
    ];

    // Destinos legacy
    const DESTINO_OBSOLETO   = 'InventarioObsoleto';
    const DESTINO_REINGRESO  = 'Reingreso';
    const DESTINO_DEVOLUCION = 'DevolucionProveedor';

    // Destinos nuevos
    const DESTINO_RESTOCK   = 'restock';
    const DESTINO_DESCARTE  = 'descarte';
    const DESTINO_PROVEEDOR = 'proveedor';

    // Condiciones
    const CONDICION_BUENO   = 'bueno';
    const CONDICION_DANADO  = 'dañado';
    const CONDICION_VENCIDO = 'vencido';
    const CONDICION_OTRO    = 'otro';

    public function devolucion() { return $this->belongsTo(Devolucion::class); }
    public function producto()   { return $this->belongsTo(Producto::class); }
}
