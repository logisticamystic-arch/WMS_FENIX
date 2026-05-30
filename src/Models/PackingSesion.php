<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackingSesion extends Model
{
    protected $table    = 'packing_sesiones';
    protected $fillable = [
        'empresa_id', 'sucursal_id', 'sucursal_entrega', 'tipo_empaque',
        'certificador_id', 'impresora_sticker_id', 'impresora_doc_id', 'estado',
    ];

    public function unidades()
    {
        return $this->hasMany(PackingUnidad::class, 'sesion_id');
    }
}
