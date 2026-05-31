<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvGeneralAsignacion extends BaseModel
{
    protected $table = 'inv_general_asignaciones';
    protected $fillable = ['evento_id', 'personal_id', 'rango_tipo', 'rango_valor', 'estado', 'asignado_por'];

    public function personal()
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function asigandoPor()
    {
        return $this->belongsTo(Personal::class, 'asignado_por');
    }
}
