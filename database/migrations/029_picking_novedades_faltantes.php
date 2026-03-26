<?php
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migración 029 — Picking: novedades de faltantes de stock
 *
 * 1. Hace nullable `picking_detalles.ubicacion_id` (era NOT NULL con FK,
 *    lo que causaba error al crear detalles antes de generar ruta FEFO).
 * 2. Crea tabla `picking_novedades_stock` para persistir los faltantes
 *    detectados durante la generación de rutas FEFO.
 */
return [
    'up' => function () {

        // ── 1. Hacer ubicacion_id nullable en picking_detalles ─────────────────
        // Primero se elimina la FK, se modifica la columna y se vuelve a agregar.
        try {
            Capsule::statement('ALTER TABLE picking_detalles DROP FOREIGN KEY picking_detalles_ubicacion_id_foreign');
        } catch (\Exception $e) {
            // La FK puede tener un nombre diferente según el entorno — ignorar si no existe
        }

        Capsule::statement('ALTER TABLE picking_detalles MODIFY COLUMN ubicacion_id BIGINT UNSIGNED NULL');

        // Re-agregar FK como nullable (ON DELETE SET NULL)
        try {
            Capsule::statement('ALTER TABLE picking_detalles ADD CONSTRAINT picking_detalles_ubicacion_id_foreign FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id) ON DELETE SET NULL');
        } catch (\Exception $e) {
            // Si ya existe, ignorar
        }

        // ── 2. Crear tabla picking_novedades_stock ─────────────────────────────
        if (!Capsule::schema()->hasTable('picking_novedades_stock')) {
            Capsule::schema()->create('picking_novedades_stock', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->unsignedBigInteger('archivo_id')->nullable()->comment('Archivo planilla origen');
                $table->unsignedBigInteger('orden_picking_id')->nullable();
                $table->string('numero_planilla', 100)->nullable();
                $table->string('cliente', 255)->nullable();
                $table->string('asesor', 255)->nullable()->comment('Comercial / asesor de la planilla');
                $table->unsignedBigInteger('producto_id')->nullable();
                $table->string('producto_nombre', 255)->nullable();
                $table->string('producto_codigo', 100)->nullable();
                $table->decimal('cantidad_solicitada', 12, 3)->default(0);
                $table->decimal('stock_disponible',    12, 3)->default(0);
                $table->decimal('cantidad_faltante',   12, 3)->default(0);
                $table->timestamps();

                $table->index(['empresa_id', 'sucursal_id', 'created_at'],   'idx_nov_empresa_fecha');
                $table->index('archivo_id',                                   'idx_nov_archivo');
                $table->index('orden_picking_id',                             'idx_nov_orden');
            });
        }
    },

    'down' => function () {
        Capsule::schema()->dropIfExists('picking_novedades_stock');
        // Revertir ubicacion_id a NOT NULL es destructivo — se omite en rollback
    },
];
