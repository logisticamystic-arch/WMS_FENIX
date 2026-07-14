<?php
/**
 * Migración 105 — Crea tabla picking_cert_ambiente
 *
 * Guarda el estado de certificación por ambiente (Seco/Refrigerado/Congelado)
 * para cada orden de picking. Permite certificar parcialmente sin bloquear
 * ambientes que aún están en proceso.
 *
 * Unicidad garantizada por (orden_picking_id, ambiente): no puede haber dos
 * registros para el mismo ambiente en la misma orden.
 * Idempotente: CREATE TABLE IF NOT EXISTS.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS picking_cert_ambiente (
            id                 BIGSERIAL PRIMARY KEY,
            consolidado_id     BIGINT NULL,
            orden_picking_id   BIGINT NOT NULL,
            ambiente           VARCHAR(30) NOT NULL,
            estado             VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            fecha_certificacion TIMESTAMP NULL,
            certificador_id    BIGINT NULL,
            created_at         TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at         TIMESTAMP NOT NULL DEFAULT NOW(),
            CONSTRAINT uq_cert_orden_ambiente UNIQUE (orden_picking_id, ambiente)
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cert_amb_consolidado ON picking_cert_ambiente(consolidado_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cert_amb_estado ON picking_cert_ambiente(ambiente, estado)");

} else {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS picking_cert_ambiente (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            consolidado_id     BIGINT UNSIGNED NULL,
            orden_picking_id   BIGINT UNSIGNED NOT NULL,
            ambiente           VARCHAR(30) NOT NULL,
            estado             ENUM('Pendiente','Certificada') NOT NULL DEFAULT 'Pendiente',
            fecha_certificacion DATETIME NULL,
            certificador_id    BIGINT UNSIGNED NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cert_orden_ambiente (orden_picking_id, ambiente),
            KEY idx_cert_amb_consolidado (consolidado_id),
            KEY idx_cert_amb_estado (ambiente, estado),
            CONSTRAINT fk_cert_amb_orden FOREIGN KEY (orden_picking_id)
                REFERENCES orden_pickings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

echo "Migración 105 completada: tabla picking_cert_ambiente creada.\n";
