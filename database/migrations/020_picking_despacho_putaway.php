<?php

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migración 020 — Tablas faltantes: certificaciones, certificacion_detalles
 */
return [
    'up' => function () {
        // Tabla: certificaciones (OutboundController / Certificacion model)
        if (!Capsule::schema()->hasTable('certificaciones')) {
            Capsule::schema()->create('certificaciones', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('usuario_id');
                $table->enum('tipo', ['Consolidado', 'Detalle'])->default('Consolidado');
                $table->dateTime('fecha_inicio');
                $table->dateTime('fecha_fin')->nullable();
                $table->boolean('diferencias')->default(false);
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('usuario_id')->references('id')->on('personal')->onDelete('restrict');
            });
        }

        // Tabla: certificacion_detalles (CertificacionDetalle model)
        if (!Capsule::schema()->hasTable('certificacion_detalles')) {
            Capsule::schema()->create('certificacion_detalles', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('certificacion_id');
                $table->unsignedBigInteger('producto_id');
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->integer('cantidad_esperada')->default(0);
                $table->integer('cantidad_contada')->default(0);
                $table->timestamps();

                $table->foreign('certificacion_id')->references('id')->on('certificaciones')->onDelete('cascade');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('restrict');
                $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('set null');
            });
        }
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('certificacion_detalles');
        Capsule::schema()->dropIfExists('certificaciones');
    },
];
