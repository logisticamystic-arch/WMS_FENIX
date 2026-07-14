<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Cliente extends BaseModel
{
    use TenantScoped;
    protected $table = 'clientes';
    protected $fillable = [
        'empresa_id',
        'ruta_id',
        'nit',
        'razon_social',
        'direccion',
        'ciudad',
        'telefono',
        'email',
        'contacto_nombre',
        'latitud',
        'longitud',
        'horario',
        'frecuencia_tipo',
        'frecuencia_config',
        'frecuencia',
        'activo',
    ];

    public function ruta()
    {
        return $this->belongsTo(Ruta::class);
    }
}


