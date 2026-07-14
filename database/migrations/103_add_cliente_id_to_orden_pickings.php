<?php
/**
 * Migración 103 — Agrega cliente_id (FK nullable) a orden_pickings
 * + backfill automático cruzando con clientes.razon_social (sin pérdida de datos).
 * Idempotente: IF NOT EXISTS / comprobación previa en MySQL.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$pdo    = Capsule::connection()->getPdo();
$driver = Capsule::connection()->getDriverName();

if ($driver === 'pgsql') {
    // ── 1. Columna nullable (no rompe filas existentes) ──────────────────
    $pdo->exec("ALTER TABLE orden_pickings ADD COLUMN IF NOT EXISTS cliente_id BIGINT NULL");

    // ── 3. Backfill: emparejar por nombre exacto (insensible a mayúsculas) ─
    $pdo->exec("
        UPDATE orden_pickings op
        SET    cliente_id = c.id
        FROM   clientes c
        WHERE  c.empresa_id = op.empresa_id
          AND  LOWER(TRIM(c.razon_social)) = LOWER(TRIM(op.cliente))
          AND  op.cliente_id IS NULL
    ");

    // ── 4. Índice para búsquedas por cliente+empresa ─────────────────────
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_op_cliente_id ON orden_pickings(empresa_id, cliente_id)");

} else {
    // MySQL / MariaDB
    $cols = $pdo->query("SHOW COLUMNS FROM orden_pickings LIKE 'cliente_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE orden_pickings
                ADD COLUMN cliente_id BIGINT UNSIGNED NULL AFTER cliente,
                ADD INDEX  idx_op_cliente_id (empresa_id, cliente_id)
        ");
    }

    // Backfill
    $pdo->exec("
        UPDATE orden_pickings op
        INNER JOIN clientes c
               ON  c.empresa_id = op.empresa_id
               AND LOWER(TRIM(c.razon_social)) = LOWER(TRIM(op.cliente))
        SET    op.cliente_id = c.id
        WHERE  op.cliente_id IS NULL
    ");

    // FK (solo si no existe)
    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
        WHERE  TABLE_NAME = 'orden_pickings'
          AND  CONSTRAINT_TYPE = 'FOREIGN KEY'
          AND  CONSTRAINT_NAME = 'fk_op_cliente_id'
    ")->fetchAll();
    if (empty($fks)) {
        try {
            $pdo->exec("
                ALTER TABLE orden_pickings
                    ADD CONSTRAINT fk_op_cliente_id
                    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
            ");
        } catch (\Throwable $e) {
            // Si la tabla clientes usa motor diferente u otro engine, ignorar FK silenciosamente
            echo "AVISO: FK no creada (motor incompatible): " . $e->getMessage() . "\n";
        }
    }
}

echo "Migración 103 completada: cliente_id agregado y backfill ejecutado en orden_pickings.\n";
