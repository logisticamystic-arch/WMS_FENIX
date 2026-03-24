<?php

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migración 019 — Tablas faltantes: rutas, clientes, ordenes_compra, orden_compra_detalles
 */
return [
    'up' => function () {
        // Tabla: rutas
        if (!Capsule::schema()->hasTable('rutas')) {
            Capsule::schema()->create('rutas', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->string('nombre', 150);
                $table->string('comercial', 150)->nullable();
                $table->string('frecuencia', 100)->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            });
        }

        // Tabla: clientes
        if (!Capsule::schema()->hasTable('clientes')) {
            Capsule::schema()->create('clientes', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('ruta_id')->nullable();
                $table->string('nit', 20)->nullable();
                $table->string('razon_social', 200);
                $table->string('direccion', 255)->nullable();
                $table->string('ciudad', 100)->nullable();
                $table->string('telefono', 30)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('contacto_nombre', 150)->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('ruta_id')->references('id')->on('rutas')->onDelete('set null');
            });
        }

        // Tabla: ordenes_compra
        if (!Capsule::schema()->hasTable('ordenes_compra')) {
            Capsule::schema()->create('ordenes_compra', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('proveedor_id')->nullable();
                $table->string('numero_odc', 50)->unique();
                $table->date('fecha');
                $table->enum('estado', ['Borrador', 'Confirmada', 'Cerrada', 'Cancelada'])->default('Borrador');
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('proveedor_id')->references('id')->on('proveedores')->onDelete('set null');
            });
        }

        // Tabla: orden_compra_detalles
        if (!Capsule::schema()->hasTable('orden_compra_detalles')) {
            Capsule::schema()->create('orden_compra_detalles', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('orden_compra_id');
                $table->unsignedBigInteger('producto_id');
                $table->integer('cantidad_solicitada')->default(0);
                $table->integer('cantidad_recibida')->default(0);
                $table->timestamps();

                $table->foreign('orden_compra_id')->references('id')->on('ordenes_compra')->onDelete('cascade');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('restrict');
            });
        }
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('orden_compra_detalles');
        Capsule::schema()->dropIfExists('ordenes_compra');
        Capsule::schema()->dropIfExists('clientes');
        Capsule::schema()->dropIfExists('rutas');
    },
];
