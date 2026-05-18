<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Devolucion extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'devoluciones';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'recepcion_id', 'odc_id', 'numero_devolucion',
        'proveedor', 'tipo', 'auxiliar_id',
        'fecha_movimiento', 'hora_inicio', 'hora_fin',
        'estado', 'motivo_general', 'fotos_json',
        'autorizado_por', 'fecha_autorizacion', 'fecha_devolucion', 'observaciones',
    ];

    protected $casts = [
        'fecha_movimiento'   => 'date',
        'fecha_autorizacion' => 'datetime',
        'fotos_json'         => 'array',
    ];

    const TIPO_AVERIA = 'AProveedorAveria';
    const TIPO_VENCIDO = 'AProveedorVencido';
    const TIPO_REINGRESO = 'ReingresoBuenEstado';

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function recepcion()
    {
        return $this->belongsTo(Recepcion::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function detalles()
    {
        return $this->hasMany(DevolucionDetalle::class);
    }

    public static function generarNumero(int $sucursalId): string
    {
        $prefix = 'DEV';
        $date = date('Ymd');
        $last = self::where('sucursal_id', $sucursalId)
            ->where('numero_devolucion', 'like', "{$prefix}-{$date}-%")
            ->count();
        return sprintf('%s-%s-%04d', $prefix, $date, $last + 1);
    }
}


