<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificacionDespacho extends BaseModel
{
    protected $table = 'certificacion_despachos';

    protected $fillable = [
        'despacho_id', 'producto_id', 'lote',
        'cantidad_certificada', 'escaneado_por', 'escaneado_at',
    ];

    protected $casts = [
        'escaneado_at' => 'datetime',
    ];

    public function despacho()
    {
        return $this->belongsTo(Despacho::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function escaneador()
    {
        return $this->belongsTo(Personal::class, 'escaneado_por');
    }
}
