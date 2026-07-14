<?php
/**
 * Migración 108 — Agrega cantidad_cajas y saldos (nullable) a sesion_lineas.
 * Permite persistir el desglose Cajas/Saldos que el auxiliar/supervisor capturó,
 * en vez de recalcularlo a partir de cantidad_contada al reabrir una línea para editar
 * (evita que una combinación cajas+saldos distinta a la "canónica" floor/resto
 * se muestre mezclada al reabrir el conteo).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("ALTER TABLE sesion_lineas ADD COLUMN IF NOT EXISTS cantidad_cajas INTEGER NULL");
    $pdo->exec("ALTER TABLE sesion_lineas ADD COLUMN IF NOT EXISTS saldos NUMERIC(12,3) NULL");
} else {
    $cols = $pdo->query("SHOW COLUMNS FROM sesion_lineas LIKE 'cantidad_cajas'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE sesion_lineas
                ADD COLUMN cantidad_cajas INT NULL AFTER cantidad_contada,
                ADD COLUMN saldos DECIMAL(12,3) NULL AFTER cantidad_cajas
        ");
    }
}

echo "Migración 108 completada: cantidad_cajas y saldos agregados a sesion_lineas.\n";
