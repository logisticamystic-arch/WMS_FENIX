<?php

namespace App\Models;

class PreoperacionalItemFoto extends BaseModel
{
    protected $table = 'preoperacional_item_fotos';
    public $timestamps = false;

    protected $fillable = [
        'preoperacional_item_id', 'url',
    ];

    public function item()
    {
        return $this->belongsTo(PreoperacionalItem::class, 'preoperacional_item_id');
    }
}
