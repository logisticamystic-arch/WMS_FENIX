<?php
/**
 * Migración 104 — Crea tabla picking_consolidados
 *
 * Registra agrupaciones de órdenes del mismo cliente para el día de operación.
 * Restricciones de unicidad: un solo consolidado activo por (empresa, sucursal, cliente, fecha).
 * Idempotente: CREATE TABLE IF NOT EXISTS.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS picking_consolidados (
            id                     BIGSERIAL PRIMARY KEY,
            empresa_id             BIGINT NOT NULL,
            sucursal_id            BIGINT NOT NULL,
            cliente                VARCHAR(200) NOT NULL,
            cliente_id             BIGINT NULL,
            fecha_consolidacion    DATE NOT NULL DEFAULT CURRENT_DATE,
            estado                 VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            orden_ids              JSONB NOT NULL DEFAULT '[]',
            auxiliar_principal_id  BIGINT NULL,
            ambiente_config        JSONB NULL,
            observaciones          TEXT NULL,
            completado_por_id      BIGINT NULL,
            fecha_completacion     TIMESTAMP NULL,
            certificador_id        BIGINT NULL,
            fecha_certificacion    TIMESTAMP NULL,
            created_at             TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at             TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    // Índice único: un consolidado por cliente por día (previene duplicados)
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS uq_consolidado_cliente_dia
        ON picking_consolidados (empresa_id, sucursal_id, cliente, fecha_consolidacion)
    ");

    // Índices de búsqueda frecuente
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_consolidado_fecha ON picking_consolidados(empresa_id, sucursal_id, fecha_consolidacion)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_consolidado_estado ON picking_consolidados(estado)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_consolidado_cliente_id ON picking_consolidados(empresa_id, cliente_id, fecha_consolidacion)");

} else {
    // MySQL / MariaDB
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS picking_consolidados (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_id             BIGINT UNSIGNED NOT NULL,
            sucursal_id            BIGINT UNSIGNED NOT NULL,
            cliente                VARCHAR(200) NOT NULL,
            cliente_id             BIGINT UNSIGNED NULL,
            fecha_consolidacion    DATE NOT NULL,
            estado                 ENUM('Pendiente','EnProceso','Completada','Anulada') NOT NULL DEFAULT 'Pendiente',
            orden_ids              JSON NOT NULL,
            auxiliar_principal_id  BIGINT UNSIGNED NULL,
            ambiente_config        JSON NULL,
            observaciones          TEXT NULL,
            completado_por_id      BIGINT UNSIGNED NULL,
            fecha_completacion     DATETIME NULL,
            certificador_id        BIGINT UNSIGNED NULL,
            fecha_certificacion    DATETIME NULL,
            created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_consolidado_cliente_dia (empresa_id, sucursal_id, cliente(100), fecha_consolidacion),
            KEY idx_consolidado_fecha (empresa_id, sucursal_id, fecha_consolidacion),
            KEY idx_consolidado_estado (estado),
            KEY idx_consolidado_cliente_id (empresa_id, cliente_id, fecha_consolidacion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

echo "Migración 104 completada: tabla picking_consolidados creada.\n";
