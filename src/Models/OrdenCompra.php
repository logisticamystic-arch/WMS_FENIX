<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class OrdenCompra extends Model
{
    use TenantScoped;

    protected $table = 'ordenes_compra';

    protected $fillable = [
        'empresa_id', 'proveedor_id', 'auxiliar_id', 'numero_odc',
        'fecha', 'fecha_esperada', 'estado', 'observaciones',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function detalles()
    {
        return $this->hasMany(OrdenCompraDetalle::class, 'orden_compra_id');
    }

    public function recepciones()
    {
        return $this->hasMany(Recepcion::class, 'odc_id');
    }

    public function auxiliares()
    {
        return $this->belongsToMany(Personal::class, 'odc_personal', 'odc_id', 'personal_id');
    }
}
