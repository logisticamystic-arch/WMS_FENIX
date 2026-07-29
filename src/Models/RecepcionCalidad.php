<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionCalidad extends Model
{
    protected $table = 'recepcion_calidad';

    protected $fillable = [
        'recepcion_id',
        'factura',
        'trans_placa',
        'prod_olor',
        'prod_color',
        'prod_textura',
        'prod_temperatura',
        'prod_empaque',
        'prod_rotulado',
        'trans_temperatura',
        'trans_limpieza',
        'trans_concepto_sanitario',
        'trans_carnet_manipulacion',
        'foto_evidencia'
    ];

    public function recepcion()
    {
        return $this->belongsTo(Recepcion::class);
    }
}
