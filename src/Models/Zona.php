<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zona extends Model
{
    protected $table = 'zonas';

    protected $fillable = [
        'codigo', 'descripcion', 'empresa_id',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ubicaciones()
    {
        return $this->hasMany(Ubicacion::class, 'zona', 'codigo');
    }

    /**
     * Get zones for a specific company
     */
    public static function getByEmpresa($empresaId)
    {
        return self::where('empresa_id', $empresaId)
                  ->orderBy('codigo')
                  ->get();
    }
}