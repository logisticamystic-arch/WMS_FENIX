<?php
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = DB::schema();
        $addIdx = function($table, $indexName, $cols) use ($schema) {
            if (!$schema->hasTable($table)) return;
            foreach ($cols as $col) { if (!$schema->hasColumn($table, $col)) return; }
            $exists = DB::select("SELECT COUNT(*) as cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            if ($exists[0]->cnt > 0) return;
            $schema->table($table, function (Blueprint $t) use ($cols, $indexName) { $t->index($cols, $indexName); });
            echo "  [OK] $indexName añadido.\n";
        };
        $addIdx('ubicaciones', 'idx_ubic_zona_pasillo',   ['zona', 'pasillo']);
        $addIdx('ubicaciones', 'idx_ubic_tipo',           ['tipo_ubicacion']);
        $addIdx('inventarios', 'idx_inv_sucursal_estado', ['sucursal_id', 'estado']);
        $addIdx('movimiento_inventarios', 'idx_mov_auxiliar',  ['auxiliar_id']);
        $addIdx('movimiento_inventarios', 'idx_mov_fecha_mov', ['fecha_movimiento']);
        echo "  [DONE] Migración 031 optimización completada.\n";
    },
    'down' => function () {},
];
