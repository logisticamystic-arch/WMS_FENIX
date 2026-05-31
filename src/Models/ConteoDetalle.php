<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConteoDetalle extends BaseModel
{
    protected $table = 'conteo_detalles';

    protected $fillable = [
        'conteo_id', 'ubicacion_id', 'producto_id', 'lote',
        'cantidad_sistema', 'cantidad_fisica', 'diferencia',
        'es_hallazgo', 'estado',
    ];

    protected $casts = [
        'es_hallazgo' => 'boolean',
    ];

    public function conteo()
    {
        return $this->belongsTo(ConteoInventario::class, 'conteo_id');
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
