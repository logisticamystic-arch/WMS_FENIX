<?php
// v1.0.2 - Force reload for fillable updates

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Notificacion extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;





    protected $table = 'notificaciones';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'personal_id',
        'usuario_id',
        'emisor_id',
        'tipo',
        'titulo',
        'mensaje',
        'link_accion',
        'url_accion',
        'modulo',
        'referencia_tipo',
        'referencia_id',
        'leida',
        'completada',
        'sonido',
        'leida_en',
    ];

    protected $casts = [
        'leida'     => 'boolean',
        'completada'=> 'boolean',
        'sonido'    => 'boolean',
        'leida_en'  => 'datetime',
    ];

    public function destinatario()
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function emisor()
    {
        return $this->belongsTo(Personal::class, 'emisor_id');
    }

    public function scopeNoLeidas($query)
    {
        return $query->where('leida', false);
    }
}




