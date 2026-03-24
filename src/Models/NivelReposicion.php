<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NivelReposicion extends Model
{
    protected $table = 'niveles_reposicion';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id',
        'stock_minimo', 'stock_maximo', 'punto_reposicion', 'activo',
    ];

    protected $casts = ['activo' => 'boolean'];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
