<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class OrdenPicking extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'orden_pickings';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'numero_orden', 'numero_factura', 'cliente',
        'direccion_cliente', 'asesor_comercial', 'area_comercial', 'numero_pedido',
        'planilla_numero', 'planilla_lote', 'estado', 'prioridad', 'auxiliar_id',
        'fecha_movimiento', 'hora_inicio', 'hora_fin', 'fecha_requerida',
        'sucursal_entrega', 'ruta', 'orden_logico',
        'estado_certificacion', 'fecha_certificacion', 'certificador_id', 'archivo_id',
        'estado_despacho', 'despacho_id', 'cliente_id',
        'observaciones',
        // despachado_directo: cliente retiró el pedido directamente en bodega — NO
        // confundir con estado_despacho='Despachado' (asignado a ruta de reparto,
        // ver DespachoController.php:300). Se excluye de la remisión agrupada.
        'despachado_directo', 'despachado_directo_at', 'despachado_directo_por',
    ];

    protected $casts = [
        'fecha_movimiento' => 'date',
        'fecha_requerida' => 'date',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function auxiliar()
    {
        return $this->belongsTo(Personal::class, 'auxiliar_id');
    }

    public function detalles()
    {
        return $this->hasMany(PickingDetalle::class);
    }

    public function tareasReabastecimiento()
    {
        return $this->hasMany(TareaReabastecimiento::class);
    }

    public static function generarNumero(int $sucursalId): string
    {
        $prefix = 'PICK';
        $date = date('Ymd');
        $last = self::where('sucursal_id', $sucursalId)
            ->where('numero_orden', 'like', "{$prefix}-{$date}-%")
            ->count();
        return sprintf('%s-%s-%04d', $prefix, $date, $last + 1);
    }
}

