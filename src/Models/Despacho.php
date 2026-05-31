<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Despacho extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'despachos';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'numero_despacho', 'cliente', 'ruta',
        'muelle_id', 'total_bultos', 'peso_total', 'estado',
        'auxiliar_id', 'fecha_movimiento', 'hora_inicio', 'hora_fin',
    ];

    protected $casts = [
        'fecha_movimiento' => 'date',
        'peso_total' => 'decimal:2',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function muelle()
    {
        return $this->belongsTo(Ubicacion::class, 'muelle_id');
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function certificaciones()
    {
        return $this->hasMany(CertificacionDespacho::class);
    }

    public static function generarNumero(int $sucursalId): string
    {
        $prefix = 'DESP';
        $date = date('Ymd');
        $last = self::where('sucursal_id', $sucursalId)
            ->where('numero_despacho', 'like', "{$prefix}-{$date}-%")
            ->count();
        return sprintf('%s-%s-%04d', $prefix, $date, $last + 1);
    }
}


