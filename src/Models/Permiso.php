<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends BaseModel
{
    protected $table = 'permisos';
    public $timestamps = false;

    protected $fillable = [
        'modulo', 'accion', 'descripcion',
    ];
}
