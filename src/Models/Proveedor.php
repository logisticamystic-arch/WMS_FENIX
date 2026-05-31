<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Proveedor extends BaseModel
{
    use TenantScoped;
    protected $table = 'proveedores';
    protected $fillable = [
        'empresa_id', 'nit', 'razon_social', 'telefono', 'email', 'contacto_nombre', 'activo'
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}


