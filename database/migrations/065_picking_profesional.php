<?php
/**
 * Migration 065 — Picking Profesional: schema extensión
 * =====================================================
 * 1. Agrega sucursal_entrega, ruta, orden_logico a orden_pickings
 * 2. Agrega ambiente a picking_detalles
 * 3. Crea tabla picking_asignaciones_log
 * 4. Agrega índices de rendimiento idx_pick_ruta, idx_pick_suc, idx_pick_fecha_est
 */
// database/migrations/065_picking_profesional.php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. Columnas nuevas en orden_pickings
        if ($schema->hasTable('orden_pickings')) {
            $schema->table('orden_pickings', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('orden_pickings', 'sucursal_entrega')) {
                    $table->string('sucursal_entrega', 200)->nullable()->after('cliente');
                }
                if (!$schema->hasColumn('orden_pickings', 'ruta')) {
                    $table->string('ruta', 100)->nullable()->after('sucursal_entrega');
                }
                if (!$schema->hasColumn('orden_pickings', 'orden_logico')) {
                    $table->integer('orden_logico')->nullable()->after('ruta');
                }
            });
            // Índices via raw para evitar conflictos con Blueprint
            try { Capsule::statement('ALTER TABLE orden_pickings ADD INDEX idx_pick_ruta (empresa_id,sucursal_id,ruta(50))'); } catch (\Exception $e) {}
            try { Capsule::statement('ALTER TABLE orden_pickings ADD INDEX idx_pick_suc (empresa_id,sucursal_id,sucursal_entrega(100))'); } catch (\Exception $e) {}
            try { Capsule::statement('ALTER TABLE orden_pickings ADD INDEX idx_pick_fecha_est (empresa_id,sucursal_id,fecha_movimiento,estado)'); } catch (\Exception $e) {}
        }

        // 2. Columna ambiente en picking_detalles
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('picking_detalles', 'ambiente')) {
                    $table->string('ambiente', 30)->nullable()->after('auxiliar_id');
                }
            });
        }

        // 3. Tabla de auditoría de asignaciones
        if (!$schema->hasTable('picking_asignaciones_log')) {
            $schema->create('picking_asignaciones_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->text('ordenes_json');
                $table->enum('modo', ['ambiente', 'pasillo']);
                $table->text('config_json');
                $table->integer('lineas_total');
                $table->string('ruta', 100)->nullable();
                $table->unsignedBigInteger('asignado_por');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['empresa_id', 'sucursal_id', 'created_at'], 'idx_log_empresa');
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('picking_asignaciones_log')) {
            $schema->drop('picking_asignaciones_log');
        }
        if ($schema->hasTable('picking_detalles') && $schema->hasColumn('picking_detalles', 'ambiente')) {
            $schema->table('picking_detalles', fn(Blueprint $t) => $t->dropColumn('ambiente'));
        }
        try { Capsule::statement('ALTER TABLE orden_pickings DROP INDEX idx_pick_ruta'); } catch (\Exception $e) {}
        try { Capsule::statement('ALTER TABLE orden_pickings DROP INDEX idx_pick_suc'); } catch (\Exception $e) {}
        try { Capsule::statement('ALTER TABLE orden_pickings DROP INDEX idx_pick_fecha_est'); } catch (\Exception $e) {}
        if ($schema->hasTable('orden_pickings')) {
            $schema->table('orden_pickings', function (Blueprint $table) use ($schema) {
                foreach (['orden_logico', 'ruta', 'sucursal_entrega'] as $col) {
                    if ($schema->hasColumn('orden_pickings', $col)) $table->dropColumn($col);
                }
            });
        }
    },
];
