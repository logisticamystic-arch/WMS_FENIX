<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificacionDetalle extends BaseModel
{
    protected $table = 'certificacion_detalles';

    protected $fillable = [
        'certificacion_id', 'producto_id', 'cliente_id', 'cantidad_esperada', 'cantidad_contada',
    ];

    public function certificacion()
    {
        return $this->belongsTo(Certificacion::class, 'certificacion_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }
}
