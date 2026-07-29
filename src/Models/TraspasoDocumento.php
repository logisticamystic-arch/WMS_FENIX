<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TraspasoDocumento extends Model
{
    protected $table = 'traspaso_documentos';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'numero_documento',
        'fecha_movimiento',
        'cliente_id',
        'cliente_nombre',
        'quien_recibe',
        'firma_path',
        'motivo',
        'observaciones',
        'auxiliar_id',
        'estado',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function detalles()
    {
        return $this->hasMany(TraspasoDocumentoDetalle::class, 'traspaso_documento_id');
    }
}
