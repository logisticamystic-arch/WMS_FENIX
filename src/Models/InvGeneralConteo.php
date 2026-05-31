<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvGeneralConteo extends BaseModel
{
    protected $table = 'inv_general_conteos';
    protected $fillable = ['evento_id', 'personal_id', 'ubicacion_id', 'producto_id', 'lote', 'fecha_vencimiento', 'cantidad', 'ciclo'];

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function personal()
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }
}
