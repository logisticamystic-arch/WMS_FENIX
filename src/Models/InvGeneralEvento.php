<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvGeneralEvento extends Model
{
    protected $table = 'inv_general_eventos';
    protected $fillable = ['empresa_id', 'sucursal_id', 'nombre', 'tipo', 'estado', 'fecha_programada', 'notas', 'creado_por'];

    public function asignaciones()
    {
        return $this->hasMany(InvGeneralAsignacion::class, 'evento_id');
    }

    public function conteos()
    {
        return $this->hasMany(InvGeneralConteo::class, 'evento_id');
    }

    public function diferencias()
    {
        return $this->hasMany(InvGeneralDiferencia::class, 'evento_id');
    }
}
