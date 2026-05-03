<?php
/**
 * Migration 045 — Advanced Picking Schema (Fénix Enterprise)
 * Adds financial/logistic fields and shortage audit table.
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = DB::schema();

        // 1. Expand orden_pickings
        if ($schema->hasTable('orden_pickings')) {
            $schema->table('orden_pickings', function (Blueprint $t) {
                // Add new fields if not exist
                if (!DB::schema()->hasColumn('orden_pickings', 'numero_factura')) {
                    $t->string('numero_factura', 100)->nullable()->after('numero_orden');
                }
                if (!DB::schema()->hasColumn('orden_pickings', 'direccion_cliente')) {
                    $t->string('direccion_cliente', 300)->nullable()->after('cliente');
                }
                if (!DB::schema()->hasColumn('orden_pickings', 'asesor_comercial')) {
                    $t->string('asesor_comercial', 150)->nullable()->after('direccion_cliente');
                }
                if (!DB::schema()->hasColumn('orden_pickings', 'area_comercial')) {
                    $t->string('area_comercial', 100)->nullable()->after('asesor_comercial');
                }
                if (!DB::schema()->hasColumn('orden_pickings', 'numero_pedido')) {
                    $t->string('numero_pedido', 100)->nullable()->after('area_comercial');
                }
                if (!DB::schema()->hasColumn('orden_pickings', 'planilla_lote')) {
                    $t->string('planilla_lote', 100)->nullable()->after('numero_pedido')->index();
                }
            });
        }

        // 2. Expand picking_detalles
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $t) {
                if (!DB::schema()->hasColumn('picking_detalles', 'costo_unitario')) {
                    $t->decimal('costo_unitario', 16, 4)->default(0)->after('cantidad_pickeada');
                }
                if (!DB::schema()->hasColumn('picking_detalles', 'descuento_porc')) {
                    $t->decimal('descuento_porc', 8, 2)->default(0)->after('costo_unitario');
                }
                if (!DB::schema()->hasColumn('picking_detalles', 'iva_porc')) {
                    $t->decimal('iva_porc', 8, 2)->default(0)->after('descuento_porc');
                }
                if (!DB::schema()->hasColumn('picking_detalles', 'valor_iva')) {
                    $t->decimal('valor_iva', 16, 4)->default(0)->after('iva_porc');
                }
                if (!DB::schema()->hasColumn('picking_detalles', 'total_linea')) {
                    $t->decimal('total_linea', 16, 4)->default(0)->after('valor_iva');
                }
                if (!DB::schema()->hasColumn('picking_detalles', 'devolucion_qty')) {
                    $t->decimal('devolucion_qty', 12, 3)->default(0)->after('total_linea');
                }
            });
        }

        // 3. New table: picking_faltantes
        if (!$schema->hasTable('picking_faltantes')) {
            $schema->create('picking_faltantes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sucursal_id');
                $t->unsignedBigInteger('orden_picking_id');
                $t->unsignedBigInteger('producto_id');
                $t->string('planilla_lote', 100)->nullable();
                $t->decimal('cantidad_solicitada', 12, 3);
                $t->decimal('cantidad_faltante', 12, 3);
                $t->string('causa', 150)->nullable(); // Ej: Stock agotado WH, Pasillo Obstruido
                $t->timestamps();
                
                $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $t->foreign('orden_picking_id')->references('id')->on('orden_pickings')->onDelete('cascade');
                $t->foreign('producto_id')->references('id')->on('productos')->onDelete('cascade');
            });
        }
    },
    'down' => function () {
        $schema = DB::schema();
        $schema->dropIfExists('picking_faltantes');
        // Column drops are usually skipped in development for safety, or implemented carefully
    },
];
