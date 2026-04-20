<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    protected $table = 'citas';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'proveedor', 'fecha', 'hora_programada',
        'cantidad_cajas', 'tipo_vehiculo', 'kilos', 'odc', 'odc_id', 'estado', 'notas',
        'hora_llegada', 'hora_inicio_descargue', 'hora_fin_descargue',
        'evaluacion_proveedor', 'tipo_descargue',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'kilos' => 'decimal:2',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function recepciones()
    {
        return $this->hasMany(Recepcion::class);
    }
}
