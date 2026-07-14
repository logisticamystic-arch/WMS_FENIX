<?php
/**
 * Migración 106 — Crea tabla novedades_picking
 *
 * Registra las novedades detectadas durante la importación de archivos de picking:
 * cantidades vacías, cantidades negativas o referencias no encontradas.
 * Permite al administrador gestionar cada novedad (resolver, ignorar, anotar).
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS novedades_picking (
            id                   BIGSERIAL PRIMARY KEY,
            empresa_id           BIGINT NOT NULL,
            sucursal_id          BIGINT NULL,
            producto_id          BIGINT NULL,
            codigo_producto      VARCHAR(100) NULL,
            nombre_producto      TEXT NULL,
            nombre_sucursal      VARCHAR(255) NULL,
            cantidad_solicitada  DECIMAL(12,3) NULL,
            motivo               VARCHAR(50) NULL,
            archivo_origen       VARCHAR(255) NULL,
            planilla_numero      VARCHAR(100) NULL,
            estado               VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            nota_resolucion      TEXT NULL,
            resuelto_por         BIGINT NULL,
            resuelto_at          TIMESTAMP NULL,
            created_at           TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at           TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nov_pick_empresa  ON novedades_picking(empresa_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nov_pick_sucursal ON novedades_picking(sucursal_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nov_pick_estado   ON novedades_picking(estado)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nov_pick_created  ON novedades_picking(created_at)");

} else {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS novedades_picking (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_id           BIGINT UNSIGNED NOT NULL,
            sucursal_id          BIGINT UNSIGNED NULL,
            producto_id          BIGINT UNSIGNED NULL,
            codigo_producto      VARCHAR(100) NULL,
            nombre_producto      TEXT NULL,
            nombre_sucursal      VARCHAR(255) NULL,
            cantidad_solicitada  DECIMAL(12,3) NULL,
            motivo               VARCHAR(50) NULL,
            archivo_origen       VARCHAR(255) NULL,
            planilla_numero      VARCHAR(100) NULL,
            estado               VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            nota_resolucion      TEXT NULL,
            resuelto_por         BIGINT UNSIGNED NULL,
            resuelto_at          DATETIME NULL,
            created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_nov_pick_empresa  (empresa_id),
            KEY idx_nov_pick_sucursal (sucursal_id),
            KEY idx_nov_pick_estado   (estado),
            KEY idx_nov_pick_created  (created_at),
            CONSTRAINT fk_nov_pick_empresa  FOREIGN KEY (empresa_id)  REFERENCES empresas(id)   ON DELETE CASCADE,
            CONSTRAINT fk_nov_pick_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL,
            CONSTRAINT fk_nov_pick_producto FOREIGN KEY (producto_id) REFERENCES productos(id)  ON DELETE SET NULL,
            CONSTRAINT fk_nov_pick_resuelto FOREIGN KEY (resuelto_por) REFERENCES personal(id)  ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

echo "Migración 106 completada: tabla novedades_picking creada.\n";
