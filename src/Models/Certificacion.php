<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Certificacion extends Model
{
    use TenantScoped;
    protected $table = 'certificaciones';

    protected $fillable = [
        'empresa_id', 'usuario_id', 'tipo', 'fecha_inicio', 'fecha_fin', 'diferencias', 'observaciones',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Personal::class, 'usuario_id');
    }

    public function detalles()
    {
        return $this->hasMany(CertificacionDetalle::class, 'certificacion_id');
    }
}


