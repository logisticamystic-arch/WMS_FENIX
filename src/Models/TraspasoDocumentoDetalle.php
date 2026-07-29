<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TraspasoDocumentoDetalle extends Model
{
    protected $table = 'traspaso_documento_detalles';

    protected $fillable = [
        'traspaso_documento_id',
        'producto_id',
        'ubicacion_id',
        'lote',
        'fecha_vencimiento',
        'cantidad',
    ];

    public function traspasoDocumento()
    {
        return $this->belongsTo(TraspasoDocumento::class, 'traspaso_documento_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_id');
    }
}
