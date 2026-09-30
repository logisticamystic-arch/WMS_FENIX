<?php
/**
 * Migración 160 — ubicacion_id nullable en sesion_lineas.
 *
 * Conteo cíclico "por Referencia": si el sistema no tiene NINGUNA existencia
 * registrada de una referencia (rowsStock vacío en conteoReferenciaCompleto()),
 * hoy no hay forma de dejar constancia de que se verificó y no se encontró
 * nada — no hay ubicación real que asignarle a esa línea. A pedido explícito
 * de Camilo (2026-09-30): permitir una línea de conteo con ubicacion_id NULL
 * y cantidad_contada/cantidad_sistema en 0 (diferencia 0 → nunca dispara
 * ajuste, ver InventarioV2Controller::ajustarLinea línea ~2008), que sí queda
 * guardada para poder exportarla en los informes.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("ALTER TABLE sesion_lineas ALTER COLUMN ubicacion_id DROP NOT NULL");
} else {
    $pdo->exec("ALTER TABLE sesion_lineas MODIFY COLUMN ubicacion_id BIGINT UNSIGNED NULL");
}

echo "Migración 160 completada: ubicacion_id ahora es nullable en sesion_lineas.\n";
