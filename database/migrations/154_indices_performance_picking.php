<?php
use Illuminate\Database\Capsule\Manager as Capsule;

// Auditoría de performance 2026-09-21 (a pedido de Camilo, enfocada en el
// hot path de picking móvil): orden_pickings y picking_detalles quedaron
// sin índices propios en la migración 015 (solo las FKs). Postgres, a
// diferencia de MySQL/InnoDB, NO indexa automáticamente las columnas FK.
// El dashboard de picking (PickingController::dashboard, ~línea 3275) y el
// flujo compartido de confirmar línea (_confirmarLineaCore, móvil + manual)
// filtran constantemente por estas columnas en cada request.
return [
    'up' => function () {
        $idx = function (string $table, string $name) {
            $driver = Capsule::getDriverName();
            if ($driver === 'pgsql') {
                $r = Capsule::select("SELECT COUNT(*) as cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $name]);
                return $r[0]->cnt > 0;
            }
            $r = Capsule::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$name]);
            return count($r) > 0;
        };

        if (!$idx('picking_detalles', 'idx_pd_orden_estado'))
            Capsule::statement('CREATE INDEX idx_pd_orden_estado ON picking_detalles (orden_picking_id, estado)');
        if (!$idx('picking_detalles', 'idx_pd_producto_ubic'))
            Capsule::statement('CREATE INDEX idx_pd_producto_ubic ON picking_detalles (producto_id, ubicacion_id)');
        if (!$idx('orden_pickings', 'idx_op_empresa_suc_estado_fecha'))
            Capsule::statement('CREATE INDEX idx_op_empresa_suc_estado_fecha ON orden_pickings (empresa_id, sucursal_id, estado, created_at)');

        echo "  [OK] Indices de picking (orden_pickings/picking_detalles) creados.\n";
    },
    'down' => function () {
        $driver = Capsule::getDriverName();
        $drop = fn(string $table, string $name) => Capsule::statement(
            $driver === 'pgsql' ? "DROP INDEX IF EXISTS {$name}" : "ALTER TABLE {$table} DROP INDEX {$name}"
        );
        $drop('picking_detalles', 'idx_pd_orden_estado');
        $drop('picking_detalles', 'idx_pd_producto_ubic');
        $drop('orden_pickings', 'idx_op_empresa_suc_estado_fecha');
    },
];
