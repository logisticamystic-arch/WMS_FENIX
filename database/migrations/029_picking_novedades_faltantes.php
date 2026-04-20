<?php
use Illuminate\Database\Capsule\Manager as Capsule;
return [
    'up' => function () {
        try { Capsule::statement('ALTER TABLE picking_detalles DROP CONSTRAINT IF EXISTS picking_detalles_ubicacion_id_foreign'); } catch (\Exception $e) {}
        Capsule::statement('ALTER TABLE picking_detalles ALTER COLUMN ubicacion_id DROP NOT NULL');
        try { Capsule::statement('ALTER TABLE picking_detalles ADD CONSTRAINT picking_detalles_ubicacion_id_foreign FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id) ON DELETE SET NULL'); } catch (\Exception $e) {}
        if (!Capsule::schema()->hasTable('picking_novedades_stock')) {
            Capsule::schema()->create('picking_novedades_stock', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->unsignedBigInteger('archivo_id')->nullable();
                $table->unsignedBigInteger('orden_picking_id')->nullable();
                $table->string('numero_planilla', 100)->nullable();
                $table->string('cliente', 255)->nullable();
                $table->string('asesor', 255)->nullable();
                $table->unsignedBigInteger('producto_id')->nullable();
                $table->string('producto_nombre', 255)->nullable();
                $table->string('producto_codigo', 100)->nullable();
                $table->decimal('cantidad_solicitada', 12, 3)->default(0);
                $table->decimal('stock_disponible',    12, 3)->default(0);
                $table->decimal('cantidad_faltante',   12, 3)->default(0);
                $table->timestamps();
                $table->index(['empresa_id', 'sucursal_id', 'created_at'], 'idx_nov_empresa_fecha');
            });
            echo "  picking_novedades_stock creada.\n";
        }
        echo "  Migration 029 completada.\n";
    },
    'down' => function () { Capsule::schema()->dropIfExists('picking_novedades_stock'); },
];
