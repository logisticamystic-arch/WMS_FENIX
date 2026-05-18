<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Parametro extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'parametros';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'clave', 'valor', 'descripcion',
    ];

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
}


