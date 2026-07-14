<?php
/**
 * Migración 099 — Ajuste de Inventario por Ubicación (con flujo de aprobación)
 * Crea las tablas staging para que auxiliares envíen ajustes físicos por ubicación
 * que deben ser aprobados por un administrador antes de afectar el inventario.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

// ── Tabla principal: cabecera del ajuste ──────────────────────────────────────
if ($driver === 'pgsql') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ajuste_ubicacion (
            id                SERIAL PRIMARY KEY,
            empresa_id        INT NOT NULL,
            sucursal_id       INT NOT NULL,
            ubicacion_id      INT NOT NULL,
            auxiliar_id       INT NOT NULL,
            estado            VARCHAR(20)  NOT NULL DEFAULT 'Pendiente',
            observaciones     TEXT,
            aprobado_por      INT,
            fecha_aprobacion  TIMESTAMP,
            created_at        TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at        TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ajuste_ubicacion_detalles (
            id                SERIAL PRIMARY KEY,
            ajuste_id         INT NOT NULL REFERENCES ajuste_ubicacion(id) ON DELETE CASCADE,
            producto_id       INT NOT NULL,
            cantidad_cajas    INT NOT NULL DEFAULT 0,
            saldos            DECIMAL(12,3) NOT NULL DEFAULT 0,
            cantidad          DECIMAL(12,3) NOT NULL DEFAULT 0,
            lote              VARCHAR(100),
            fecha_vencimiento DATE,
            created_at        TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at        TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    // Índices
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ajuste_ubicacion_empresa  ON ajuste_ubicacion(empresa_id, sucursal_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ajuste_ubicacion_estado   ON ajuste_ubicacion(estado)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ajuste_ubi_det_ajuste     ON ajuste_ubicacion_detalles(ajuste_id)");
} else {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ajuste_ubicacion (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id       INT NOT NULL,
            sucursal_id      INT NOT NULL,
            ubicacion_id     INT NOT NULL,
            auxiliar_id      INT NOT NULL,
            estado           VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
            observaciones    TEXT,
            aprobado_por     INT,
            fecha_aprobacion DATETIME,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (empresa_id, sucursal_id),
            INDEX (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ajuste_ubicacion_detalles (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            ajuste_id         INT NOT NULL,
            producto_id       INT NOT NULL,
            cantidad_cajas    INT NOT NULL DEFAULT 0,
            saldos            DECIMAL(12,3) NOT NULL DEFAULT 0,
            cantidad          DECIMAL(12,3) NOT NULL DEFAULT 0,
            lote              VARCHAR(100),
            fecha_vencimiento DATE,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (ajuste_id) REFERENCES ajuste_ubicacion(id) ON DELETE CASCADE,
            INDEX (ajuste_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

echo "Migración 099 completada: tablas ajuste_ubicacion y ajuste_ubicacion_detalles creadas.\n";
