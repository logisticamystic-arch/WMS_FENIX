<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvGeneralDiferencia extends Model
{
    protected $table = 'inv_general_diferencias';
    protected $fillable = [
        'evento_id', 'ubicacion_id', 'producto_id', 'lote', 'vencimiento_esperado',
        'cantidad_sistema', 'conteo_1', 'conteo_2', 'conteo_3', 'cantidad_final_aprobada',
        'estado', 'resuelto_por'
    ];

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
