<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false; // tabla usa created_at manual

    protected $fillable = [
        'empresa_id', 'usuario_id', 'modulo', 'accion',
        'tabla', 'registro_id', 'datos_anteriores', 'datos_nuevos',
        'descripcion', 'ip', 'created_at',
    ];

    protected $casts = [
        'datos_anteriores' => 'array',
        'datos_nuevos'     => 'array',
    ];

    public function usuario()
    {
        return $this->belongsTo(Personal::class, 'usuario_id');
    }
}
