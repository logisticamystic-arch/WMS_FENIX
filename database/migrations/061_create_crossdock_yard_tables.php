<?php
/**
 * Migration 061 — Cross-Dock y Yard Management
 * =============================================
 * Crea tablas para el módulo de Logística Pro:
 *  - cross_dock_ordenes: órdenes de transferencia directa sin almacenamiento
 *  - cross_dock_detalles: líneas de cada orden
 *  - yard_appointments: programación de muelles del patio
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // ── CROSS DOCK ORDENES ────────────────────────────────────────────────
        if (!$schema->hasTable('cross_dock_ordenes')) {
            $schema->create('cross_dock_ordenes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('numero', 30)->unique();
                $table->string('muelle_entrada', 20)->nullable();
                $table->string('muelle_salida', 20)->nullable();
                $table->string('transportista', 120)->nullable();
                $table->string('placa_entrada', 20)->nullable();
                $table->string('placa_salida', 20)->nullable();
                $table->string('estado', 30)->default('Programado');
                // Estados: Programado | Recibiendo | Clasificando | Despachando | Completado | Cancelado
                $table->dateTime('entrada_programada')->nullable();
                $table->dateTime('salida_programada')->nullable();
                $table->dateTime('entrada_real')->nullable();
                $table->dateTime('salida_real')->nullable();
                $table->integer('tiempo_suelo_min')->nullable(); // calculado al completar
                $table->text('observaciones')->nullable();
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->index(['empresa_id', 'sucursal_id', 'estado']);
                $table->index(['empresa_id', 'created_at']);
            });
        }

        // ── CROSS DOCK DETALLES ───────────────────────────────────────────────
        if (!$schema->hasTable('cross_dock_detalles')) {
            $schema->create('cross_dock_detalles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('cross_dock_id');
                $table->unsignedBigInteger('producto_id');
                $table->string('ean', 30)->nullable();
                $table->string('lote', 60)->nullable();
                $table->decimal('cantidad_esperada', 12, 2)->default(0);
                $table->decimal('cantidad_recibida', 12, 2)->default(0);
                $table->decimal('cantidad_transferida', 12, 2)->default(0);
                $table->string('estado', 20)->default('Pendiente');
                // Pendiente | Recibido | Diferencia | Transferido
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->foreign('cross_dock_id')->references('id')->on('cross_dock_ordenes')->onDelete('cascade');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('restrict');
                $table->index('cross_dock_id');
            });
        }

        // ── YARD APPOINTMENTS ─────────────────────────────────────────────────
        if (!$schema->hasTable('yard_appointments')) {
            $schema->create('yard_appointments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('muelle', 20);
                $table->string('numero', 30)->nullable(); // número de cita
                $table->string('transportista', 120)->nullable();
                $table->string('placa_vehiculo', 20)->nullable();
                $table->string('tipo', 30)->default('Entrada'); // Entrada | Salida | Cross-Dock
                $table->string('estado', 30)->default('Programado');
                // Programado | En Patio | Operando | Completado | Cancelado
                $table->dateTime('fecha_cita');
                $table->dateTime('entrada_real')->nullable();
                $table->dateTime('salida_real')->nullable();
                $table->integer('turnaround_min')->nullable();
                $table->unsignedBigInteger('recepcion_id')->nullable();
                $table->unsignedBigInteger('despacho_id')->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->index(['empresa_id', 'sucursal_id', 'fecha_cita']);
                $table->index(['empresa_id', 'muelle', 'estado']);
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('yard_appointments');
        $schema->dropIfExists('cross_dock_detalles');
        $schema->dropIfExists('cross_dock_ordenes');
    },
];
