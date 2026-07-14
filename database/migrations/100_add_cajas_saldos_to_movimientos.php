<?php
/**
 * Migration 100 — Add cantidad_cajas and saldos to movimiento_inventarios
 * Run: php artisan migrate  OR execute SQL directly in psql
 *
 * SQL directo (copiar y pegar en psql o pgAdmin):
 *   ALTER TABLE movimiento_inventarios ADD COLUMN IF NOT EXISTS cantidad_cajas INTEGER DEFAULT 0;
 *   ALTER TABLE movimiento_inventarios ADD COLUMN IF NOT EXISTS saldos DECIMAL(14,3) DEFAULT 0;
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    Capsule::statement('ALTER TABLE movimiento_inventarios ADD COLUMN IF NOT EXISTS cantidad_cajas INTEGER NOT NULL DEFAULT 0');
    Capsule::statement('ALTER TABLE movimiento_inventarios ADD COLUMN IF NOT EXISTS saldos DECIMAL(14,3) NOT NULL DEFAULT 0');
} else {
    // MySQL
    $cols = Capsule::select("SHOW COLUMNS FROM movimiento_inventarios LIKE 'cantidad_cajas'");
    if (empty($cols)) {
        Capsule::statement('ALTER TABLE movimiento_inventarios ADD COLUMN cantidad_cajas INT NOT NULL DEFAULT 0 AFTER cantidad');
        Capsule::statement('ALTER TABLE movimiento_inventarios ADD COLUMN saldos DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER cantidad_cajas');
    }
}

echo "Migration 100 OK: cantidad_cajas y saldos agregados a movimiento_inventarios\n";
