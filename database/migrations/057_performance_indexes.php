<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = Capsule::schema();
        $idx = function(string $table, string $name) {
            $driver = Capsule::getDriverName();
            if ($driver === 'pgsql') {
                $r = Capsule::select("SELECT COUNT(*) as cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $name]);
                return $r[0]->cnt > 0;
            } else {
                $r = Capsule::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$name]);
                return count($r) > 0;
            }
        };
        if (!$idx('inventarios', 'idx_inv_empresa_sucursal'))
            Capsule::statement('CREATE INDEX idx_inv_empresa_sucursal ON inventarios (empresa_id, sucursal_id)');
        if (!$idx('inventarios', 'idx_inv_empresa_ubicacion'))
            Capsule::statement('CREATE INDEX idx_inv_empresa_ubicacion ON inventarios (empresa_id, sucursal_id, ubicacion_id)');
        if (!$idx('inventarios', 'idx_inv_empresa_producto'))
            Capsule::statement('CREATE INDEX idx_inv_empresa_producto ON inventarios (empresa_id, sucursal_id, producto_id)');
        if (!$idx('inventarios', 'idx_inv_vencimiento'))
            Capsule::statement('CREATE INDEX idx_inv_vencimiento ON inventarios (empresa_id, fecha_vencimiento)');
        if (!$idx('movimiento_inventarios', 'idx_mov_empresa_producto'))
            Capsule::statement('CREATE INDEX idx_mov_empresa_producto ON movimiento_inventarios (empresa_id, producto_id, created_at)');
        if (!$idx('movimiento_inventarios', 'idx_mov_ubic_destino_fecha'))
            Capsule::statement('CREATE INDEX idx_mov_ubic_destino_fecha ON movimiento_inventarios (ubicacion_destino_id, created_at)');
        if (!$idx('movimiento_inventarios', 'idx_mov_prod_origen'))
            Capsule::statement('CREATE INDEX idx_mov_prod_origen ON movimiento_inventarios (producto_id, ubicacion_origen_id)');
        if (!$idx('movimiento_inventarios', 'idx_mov_tipo_fecha'))
            Capsule::statement('CREATE INDEX idx_mov_tipo_fecha ON movimiento_inventarios (tipo_movimiento, created_at)');
        if ($schema->hasTable('ajustes_inventario')) {
            if (!$idx('ajustes_inventario', 'idx_ajuste_empresa_fecha'))
                Capsule::statement('CREATE INDEX idx_ajuste_empresa_fecha ON ajustes_inventario (empresa_id, created_at)');
            if (!$idx('ajustes_inventario', 'idx_ajuste_sesion') && $schema->hasColumn('ajustes_inventario', 'sesion_id'))
                Capsule::statement('CREATE INDEX idx_ajuste_sesion ON ajustes_inventario (sesion_id)');
        }
        if ($schema->hasTable('sesion_lineas')) {
            if (!$idx('sesion_lineas', 'idx_slinea_sesion_ronda'))
                Capsule::statement('CREATE INDEX idx_slinea_sesion_ronda ON sesion_lineas (sesion_id)');
            if (!$idx('sesion_lineas', 'idx_slinea_sesion_producto_ubic'))
                Capsule::statement('CREATE INDEX idx_slinea_sesion_producto_ubic ON sesion_lineas (sesion_id, producto_id, ubicacion_id)');
        }
        if ($schema->hasTable('sesion_asignaciones') && !$idx('sesion_asignaciones', 'idx_sasig_sesion_estado'))
            Capsule::statement('CREATE INDEX idx_sasig_sesion_estado ON sesion_asignaciones (sesion_id, estado)');
        if (!$idx('productos', 'idx_prod_nombre_busqueda')) {
            if (Capsule::getDriverName() === 'pgsql') {
                try {
                    Capsule::statement("CREATE INDEX idx_prod_nombre_busqueda ON productos USING GIN (to_tsvector('simple', nombre))");
                } catch (\Exception $e) {
                    Capsule::statement('CREATE INDEX idx_prod_nombre_busqueda ON productos (nombre)');
                }
            } else {
                Capsule::statement('CREATE INDEX idx_prod_nombre_busqueda ON productos (nombre)');
            }
        }
        if ($schema->hasColumn('productos', 'ean') && !$idx('productos', 'idx_prod_ean_trgm'))
            Capsule::statement('CREATE INDEX idx_prod_ean_trgm ON productos (ean)');
        echo "  Migration 057 completada.\n";
    },
    'down' => function () {},
];
