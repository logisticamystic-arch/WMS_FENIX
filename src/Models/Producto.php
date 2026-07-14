<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Producto extends BaseModel
{
    use TenantScoped;

    protected $table = 'productos';

    protected $fillable = [
        'empresa_id', 'marca_id', 'categoria_id', 'ambiente_id', 'codigo_interno', 'nombre', 'descripcion',
        'imagen_url', 'unidad_medida', 'peso_unitario', 'volumen_unitario',
        'controla_lote', 'controla_vencimiento', 'vida_util_dias',
        'temperatura_almacen', 'stock_minimo', 'unidades_caja', 'activo',
        'factor_udm', 'unidad_contenido',
    ];

    protected $casts = [
        'controla_lote' => 'boolean',
        'controla_vencimiento' => 'boolean',
        'activo' => 'boolean',
        'peso_unitario' => 'decimal:3',
        'volumen_unitario' => 'decimal:4',
        'stock_minimo' => 'decimal:2',
        'factor_udm' => 'decimal:4',
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

    public function ambiente()
    {
        return $this->belongsTo(Ambiente::class, 'ambiente_id');
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
    public function fotos()
    {
        return $this->hasMany(ProductoFoto::class);
    }

    public function tieneUdm(): bool
    {
        return !empty($this->factor_udm) && (float)$this->factor_udm > 0;
    }

    public function calcularUdm(float $unidades): float
    {
        return $unidades * (float)($this->factor_udm ?? 1);
    }

    public function calcularUnidades(float $udm): float
    {
        $factor = (float)($this->factor_udm ?? 0);
        if ($factor <= 0) return $udm;
        return $udm / $factor;
    }
}
