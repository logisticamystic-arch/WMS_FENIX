<?php
/**
 * Migration 023 — Performance indexes on critical tables.
 * Run once; each block is idempotent.
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

$schema = DB::schema();

// Helper: add index only if it doesn't already exist
function addIndexIfMissing($schema, string $table, string $indexName, array $cols, bool $unique = false): void
{
    if (!$schema->hasTable($table)) return;

    // Check all requested columns exist before creating index
    foreach ($cols as $col) {
        if (!$schema->hasColumn($table, $col)) {
            echo "  [SKIP] Índice $indexName omitido: columna '$col' no existe en $table.\n";
            return;
        }
    }

    // Check by querying INFORMATION_SCHEMA
    $exists = DB::select(
        "SELECT COUNT(*) as cnt FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
        [$table, $indexName]
    );
    if ($exists[0]->cnt > 0) {
        echo "  [--] Índice $indexName en $table ya existe.\n";
        return;
    }

    $schema->table($table, function (Blueprint $t) use ($cols, $indexName, $unique) {
        if ($unique) {
            $t->unique($cols, $indexName);
        } else {
            $t->index($cols, $indexName);
        }
    });
    echo "  [OK] Índice $indexName añadido a $table.\n";
}

// ── inventarios ───────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'inventarios', 'idx_inv_empresa_prod',     ['empresa_id', 'producto_id']);
addIndexIfMissing($schema, 'inventarios', 'idx_inv_empresa_venc',     ['empresa_id', 'fecha_vencimiento']);
addIndexIfMissing($schema, 'inventarios', 'idx_inv_empresa_ubicacion',['empresa_id', 'ubicacion_id']);
addIndexIfMissing($schema, 'inventarios', 'idx_inv_lote',             ['lote']);

// ── movimiento_inventarios ────────────────────────────────────────────────────
addIndexIfMissing($schema, 'movimiento_inventarios', 'idx_mov_empresa_fecha',  ['empresa_id', 'created_at']);
addIndexIfMissing($schema, 'movimiento_inventarios', 'idx_mov_producto_fecha', ['producto_id', 'created_at']);
addIndexIfMissing($schema, 'movimiento_inventarios', 'idx_mov_tipo',           ['tipo_movimiento']);

// ── orden_pickings ────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'orden_pickings', 'idx_pick_empresa_estado',  ['empresa_id', 'estado']);
addIndexIfMissing($schema, 'orden_pickings', 'idx_pick_empresa_fecha',   ['empresa_id', 'created_at']);
addIndexIfMissing($schema, 'orden_pickings', 'idx_pick_operador',        ['operador_id']);

// ── ordenes_compra ────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'ordenes_compra', 'idx_odc_empresa_estado',  ['empresa_id', 'estado']);
addIndexIfMissing($schema, 'ordenes_compra', 'idx_odc_proveedor',       ['proveedor_id']);
addIndexIfMissing($schema, 'ordenes_compra', 'idx_odc_empresa_fecha',   ['empresa_id', 'created_at']);

// ── despachos ─────────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'despachos', 'idx_desp_empresa_estado', ['empresa_id', 'estado']);
addIndexIfMissing($schema, 'despachos', 'idx_desp_empresa_fecha',  ['empresa_id', 'created_at']);
addIndexIfMissing($schema, 'despachos', 'idx_desp_cliente',        ['cliente_id']);

// ── alertas ───────────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'alertas', 'idx_alertas_empresa_estado', ['empresa_id', 'estado']);
addIndexIfMissing($schema, 'alertas', 'idx_alertas_empresa_tipo',   ['empresa_id', 'tipo']);

// ── audit_logs ────────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'audit_logs', 'idx_audit_empresa_fecha',  ['empresa_id', 'created_at']);
addIndexIfMissing($schema, 'audit_logs', 'idx_audit_usuario',        ['usuario_id']);
addIndexIfMissing($schema, 'audit_logs', 'idx_audit_modulo',         ['modulo']);

// ── recepciones ───────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'recepciones', 'idx_rec_empresa_fecha', ['empresa_id', 'created_at']);
addIndexIfMissing($schema, 'recepciones', 'idx_rec_odc',           ['odc_id']);

// ── personal ─────────────────────────────────────────────────────────────────
addIndexIfMissing($schema, 'personal', 'idx_personal_documento', ['documento']);
addIndexIfMissing($schema, 'personal', 'idx_personal_empresa_rol',['empresa_id', 'rol']);

echo "\n  [DONE] Migración 023 completada.\n";

return ['up' => fn() => null, 'down' => fn() => null];
