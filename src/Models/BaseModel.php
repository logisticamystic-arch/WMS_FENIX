<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BaseModel — todos los modelos del proyecto extienden esta clase.
 * Serializa timestamps en hora Colombia (UTC-5) en lugar de UTC ISO.
 */
class BaseModel extends Model
{
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return \Carbon\Carbon::instance($date)
            ->setTimezone('America/Bogota')
            ->format('Y-m-d H:i:s');
    }
}
