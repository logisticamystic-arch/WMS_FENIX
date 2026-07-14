<?php
/**
 * Migración 102 — Agrega columna "tipo" a ajuste_ubicacion
 * Valores: 'AjusteCompleto' (borra todo y reemplaza) | 'AgregarInventario' (suma al existente)
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("
        ALTER TABLE ajuste_ubicacion
        ADD COLUMN IF NOT EXISTS tipo VARCHAR(30) NOT NULL DEFAULT 'AjusteCompleto'
    ");
} else {
    // MySQL/MariaDB: comprobar si la columna ya existe antes de agregar
    $cols = $pdo->query("SHOW COLUMNS FROM ajuste_ubicacion LIKE 'tipo'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE ajuste_ubicacion
            ADD COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'AjusteCompleto'
            AFTER auxiliar_id
        ");
    }
}

echo "Migración 102 completada: columna tipo agregada a ajuste_ubicacion.\n";
