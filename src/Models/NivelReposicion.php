<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class NivelReposicion extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
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


