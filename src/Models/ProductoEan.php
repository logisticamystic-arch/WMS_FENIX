<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoEan extends BaseModel
{
    protected $table = 'producto_eans';

    protected $fillable = [
        'producto_id', 'codigo_ean', 'tipo', 'es_principal', 'activo',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'activo' => 'boolean',
    ];

    // EAN type constants
    const TIPO_EAN13 = 'EAN13';
    const TIPO_EAN128 = 'EAN128';
    const TIPO_DUN14 = 'DUN14';
    const TIPO_QR = 'QR';
    const TIPO_INTERNO = 'INTERNO';

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
