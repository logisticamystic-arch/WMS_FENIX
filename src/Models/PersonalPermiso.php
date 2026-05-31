<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class PersonalPermiso extends BaseModel
{
    use TenantScoped;

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
