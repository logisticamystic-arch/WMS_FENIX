<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Recepcion extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;





    protected $table = 'recepciones';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'cita_id', 'odc_id', 'numero_recepcion',
        'auxiliar_id', 'modo_ciego', 'estado',
        'fecha_movimiento', 'hora_inicio', 'hora_fin', 'observaciones',
    ];

    protected $casts = [
        'modo_ciego' => 'boolean',
        'fecha_movimiento' => 'date',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function cita()
    {
        return $this->belongsTo(Cita::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function ordenCompra()
    {
        return $this->belongsTo(OrdenCompra::class, 'odc_id');
    }

    public function detalles()
    {
        return $this->hasMany(RecepcionDetalle::class);
    }

    public function devoluciones()
    {
        return $this->hasMany(Devolucion::class);
    }

    /**
     * Generate unique reception number
     */
    public static function generarNumero(int $sucursalId): string
    {
        $prefix = 'REC';
        $date = date('Ymd');
        $maxSeq = self::where('sucursal_id', $sucursalId)
            ->where('numero_recepcion', 'like', "{$prefix}-{$date}-%")
            ->selectRaw('MAX(CAST(SUBSTRING_INDEX(numero_recepcion, \'-\', -1) AS UNSIGNED)) as max_seq')
            ->value('max_seq');
        return sprintf('%s-%s-%04d', $prefix, $date, ($maxSeq ?? 0) + 1);
    }
}




