<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class RolPermiso extends BaseModel
{
    use TenantScoped;
    protected $table = 'rol_permisos';

    protected $fillable = [
        'empresa_id', 'rol', 'permiso_id', 'concedido',
    ];

    protected $casts = [
        'concedido' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function permiso()
    {
        return $this->belongsTo(Permiso::class);
    }
}


