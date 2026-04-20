<?php
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = DB::schema();
        $addIdx = function($table, $indexName, $cols, $unique = false) use ($schema) {
            if (!$schema->hasTable($table)) return;
            foreach ($cols as $col) {
                if (!$schema->hasColumn($table, $col)) { echo "  [SKIP] $indexName: '$col' no existe.\n"; return; }
            }
            $exists = DB::select("SELECT COUNT(*) as cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            if ($exists[0]->cnt > 0) { echo "  [--] $indexName ya existe.\n"; return; }
            $schema->table($table, function (Blueprint $t) use ($cols, $indexName, $unique) {
                if ($unique) $t->unique($cols, $indexName); else $t->index($cols, $indexName);
            });
            echo "  [OK] $indexName añadido.\n";
        };
        $addIdx('inventarios', 'idx_inv_empresa_prod',      ['empresa_id', 'producto_id']);
        $addIdx('inventarios', 'idx_inv_empresa_venc',      ['empresa_id', 'fecha_vencimiento']);
        $addIdx('inventarios', 'idx_inv_empresa_ubicacion', ['empresa_id', 'ubicacion_id']);
        $addIdx('inventarios', 'idx_inv_lote',              ['lote']);
        $addIdx('movimiento_inventarios', 'idx_mov_empresa_fecha',  ['empresa_id', 'created_at']);
        $addIdx('movimiento_inventarios', 'idx_mov_producto_fecha', ['producto_id', 'created_at']);
        $addIdx('movimiento_inventarios', 'idx_mov_tipo',           ['tipo_movimiento']);
        $addIdx('orden_pickings', 'idx_pick_empresa_estado', ['empresa_id', 'estado']);
        $addIdx('orden_pickings', 'idx_pick_empresa_fecha',  ['empresa_id', 'created_at']);
        $addIdx('ordenes_compra', 'idx_odc_empresa_estado',  ['empresa_id', 'estado']);
        $addIdx('ordenes_compra', 'idx_odc_proveedor',       ['proveedor_id']);
        $addIdx('ordenes_compra', 'idx_odc_empresa_fecha',   ['empresa_id', 'created_at']);
        $addIdx('despachos', 'idx_desp_empresa_estado', ['empresa_id', 'estado']);
        $addIdx('despachos', 'idx_desp_empresa_fecha',  ['empresa_id', 'created_at']);
        $addIdx('audit_logs', 'idx_audit_empresa_fecha', ['empresa_id', 'created_at']);
        $addIdx('audit_logs', 'idx_audit_usuario',       ['usuario_id']);
        $addIdx('audit_logs', 'idx_audit_modulo',        ['modulo']);
        $addIdx('recepciones', 'idx_rec_empresa_fecha',  ['empresa_id', 'created_at']);
        $addIdx('personal', 'idx_personal_documento',    ['documento']);
        $addIdx('personal', 'idx_personal_empresa_rol',  ['empresa_id', 'rol']);
        echo "  [DONE] Migración 023 completada.\n";
    },
    'down' => function () {},
];
