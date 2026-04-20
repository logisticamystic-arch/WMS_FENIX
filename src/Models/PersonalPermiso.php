<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalPermiso extends Model
{
    protected $table = 'personal_permisos';

    protected $fillable = [
        'empresa_id', 
        'personal_id', 
        'modulo', 
        'submodulo',
        'accion', 
        'concedido'
    ];

    protected $casts = [
        'concedido' => 'boolean',
    ];

    public function personal()
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
