<?php
/**
 * Migración 109 — Agrega punto_reposicion (nullable) a niveles_reposicion.
 *
 * El modelo NivelReposicion ya declaraba 'punto_reposicion' en su $fillable, pero la
 * columna nunca existió en el esquema real — cualquier intento de guardar un nivel
 * de reposición con ese campo fallaba (SQLSTATE 42703). Necesaria para que
 * ReplenishmentController::runAutoReplenishment() pueda comparar el stock real
 * contra un punto de reposición explícito (o caer a stock_minimo si no se define).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("ALTER TABLE niveles_reposicion ADD COLUMN IF NOT EXISTS punto_reposicion NUMERIC(12,2) NULL");
} else {
    $cols = $pdo->query("SHOW COLUMNS FROM niveles_reposicion LIKE 'punto_reposicion'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE niveles_reposicion ADD COLUMN punto_reposicion DECIMAL(12,2) NULL AFTER stock_maximo");
    }
}

echo "Migración 109 completada: punto_reposicion agregado a niveles_reposicion.\n";
