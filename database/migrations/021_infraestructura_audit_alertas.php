<?php

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migración 021 — Infraestructura: audit_logs, alertas_stock, niveles_reposicion, parametros_inventario
 */
return [
    'up' => function () {

        // ── AUDIT LOG: registro de todas las operaciones ──────────────────────
        if (!Capsule::schema()->hasTable('audit_logs')) {
            Capsule::schema()->create('audit_logs', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('modulo', 50);          // recepcion, picking, despacho, inventario…
                $table->string('accion', 50);           // crear, editar, eliminar, confirmar, trasladar…
                $table->string('tabla_afectada', 100)->nullable();
                $table->unsignedBigInteger('registro_id')->nullable();
                $table->json('datos_anteriores')->nullable();
                $table->json('datos_nuevos')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('descripcion')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['empresa_id', 'modulo', 'created_at']);
                $table->index(['tabla_afectada', 'registro_id']);
                $table->index('usuario_id');
            });
        }

        // ── NIVELES DE REPOSICIÓN: mín/máx por producto/ubicación ─────────────
        if (!Capsule::schema()->hasTable('niveles_reposicion')) {
            Capsule::schema()->create('niveles_reposicion', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->unsignedBigInteger('producto_id');
                $table->integer('stock_minimo')->default(0);
                $table->integer('stock_maximo')->default(9999);
                $table->integer('punto_reorden')->default(0);
                $table->integer('cantidad_reorden')->default(0); // cuánto pedir
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('cascade');
                $table->unique(['empresa_id', 'sucursal_id', 'producto_id']);
            });
        }

        // ── ALERTAS DE STOCK: vencimiento, agotados, bajo mínimo ─────────────
        if (!Capsule::schema()->hasTable('alertas_stock')) {
            Capsule::schema()->create('alertas_stock', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->unsignedBigInteger('producto_id');
                $table->enum('tipo', [
                    'ProximoVencer',   // fecha_vencimiento <= hoy + 30 días
                    'Vencido',         // fecha_vencimiento < hoy
                    'BajoMinimo',      // stock < stock_minimo
                    'Agotado',         // stock = 0
                    'SobreMaximo',     // stock > stock_maximo
                ]);
                $table->integer('stock_actual')->default(0);
                $table->integer('stock_minimo')->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->integer('dias_para_vencer')->nullable();
                $table->enum('estado', ['Activa', 'Resuelta', 'Ignorada'])->default('Activa');
                $table->unsignedBigInteger('resuelta_por')->nullable();
                $table->timestamp('resuelta_at')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('cascade');
                $table->index(['empresa_id', 'tipo', 'estado']);
                $table->index(['empresa_id', 'sucursal_id', 'estado']);
            });
        }

        // ── NOTAS DE AJUSTE: respaldo documental de ajustes de inventario ─────
        if (!Capsule::schema()->hasTable('notas_ajuste')) {
            Capsule::schema()->create('notas_ajuste', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('numero_nota', 30)->unique();
                $table->enum('tipo', ['EntradaAjuste', 'SalidaAjuste', 'Traslado', 'Merma', 'Sobrante']);
                $table->string('motivo', 255);
                $table->unsignedBigInteger('creado_por');
                $table->unsignedBigInteger('aprobado_por')->nullable();
                $table->enum('estado', ['Borrador', 'Aprobada', 'Rechazada'])->default('Borrador');
                $table->timestamp('aprobado_at')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('creado_por')->references('id')->on('personal')->onDelete('restrict');
            });
        }

        if (!Capsule::schema()->hasTable('nota_ajuste_detalles')) {
            Capsule::schema()->create('nota_ajuste_detalles', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('nota_ajuste_id');
                $table->unsignedBigInteger('producto_id');
                $table->unsignedBigInteger('ubicacion_id');
                $table->string('lote', 50)->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->integer('cantidad_sistema');
                $table->integer('cantidad_fisica');
                $table->integer('diferencia');  // fisica - sistema
                $table->timestamps();

                $table->foreign('nota_ajuste_id')->references('id')->on('notas_ajuste')->onDelete('cascade');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('restrict');
                $table->foreign('ubicacion_id')->references('id')->on('ubicaciones')->onDelete('restrict');
            });
        }
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('nota_ajuste_detalles');
        Capsule::schema()->dropIfExists('notas_ajuste');
        Capsule::schema()->dropIfExists('alertas_stock');
        Capsule::schema()->dropIfExists('niveles_reposicion');
        Capsule::schema()->dropIfExists('audit_logs');
    },
];
