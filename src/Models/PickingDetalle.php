<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickingDetalle extends BaseModel
{
    protected $table = 'picking_detalles';

    protected $fillable = [
        'orden_picking_id', 'producto_id', 'ubicacion_id', 'auxiliar_id', 'lote',
        'fecha_vencimiento',
        'cantidad_solicitada', 'cantidad_pickeada', 'pasillo_lock', 'estado',
        'costo_unitario', 'descuento_porc', 'iva_porc', 'valor_iva', 'total_linea', 'devolucion_qty',
        'ambiente', 'numero_pedido_ref',
        'unid_pedido_empaque', 'unid_pedido_total',
        'cantidad_certificada', 'estado_certificacion',
        'novedad',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $detalle) {
            if (!empty($detalle->ambiente)) return;
            if (!$detalle->producto_id) { $detalle->ambiente = 'Seco'; return; }
            $prod = Producto::find($detalle->producto_id);
            if ($prod && $prod->ambiente_id) {
                $amb = Ambiente::find($prod->ambiente_id);
                $detalle->ambiente = $amb?->codigo ?? 'Seco';
            } else {
                $detalle->ambiente = 'Seco';
            }
        });
    }

    public function ordenPicking()
    {
        return $this->belongsTo(OrdenPicking::class);
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
}
