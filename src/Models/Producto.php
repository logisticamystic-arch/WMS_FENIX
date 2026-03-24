<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'empresa_id', 'marca_id', 'categoria_id', 'codigo_interno', 'nombre', 'descripcion',
        'imagen_url', 'unidad_medida', 'peso_unitario', 'volumen_unitario',
        'controla_lote', 'controla_vencimiento', 'vida_util_dias',
        'temperatura_almacen', 'activo',
    ];

    protected $casts = [
        'controla_lote' => 'boolean',
        'controla_vencimiento' => 'boolean',
        'activo' => 'boolean',
        'peso_unitario' => 'decimal:3',
        'volumen_unitario' => 'decimal:4',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function marca()
    {
        return $this->belongsTo(Marca::class);
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    public function eans()
    {
        return $this->hasMany(ProductoEan::class, 'producto_id');
    }

    public function eanPrincipal()
    {
        return $this->hasOne(ProductoEan::class)->where('es_principal', true);
    }

    public function inventarios()
    {
        return $this->hasMany(Inventario::class);
    }

    /**
     * Find product by any of its EAN codes
     */
    public static function findByEan(string $ean)
    {
        $productoEan = ProductoEan::where('codigo_ean', $ean)->where('activo', true)->first();
        return $productoEan ? $productoEan->producto : null;
    }
}
