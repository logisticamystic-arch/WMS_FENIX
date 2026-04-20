<?php
/**
 * Migration 056 — Módulo de Inventarios V2
 * =========================================
 * Crea la infraestructura completa para:
 *  - Sesiones de inventario (Cíclico / General)
 *  - Asignaciones de conteo a auxiliares (por Pasillo, Módulo, Referencia, Libre)
 *  - Líneas de conteo por ronda (hasta 3 rondas en General)
 *  - Tabla de ajustes de inventario (inmutable, auditable)
 *
 * Compatible con PostgreSQL en producción.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // ── 1. SESIONES DE INVENTARIO ────────────────────────────────────────
        // Reemplaza el concepto de conteo_inventarios para el módulo V2.
        // Una sesión puede ser Cíclica (1 ronda, 1+ auxiliares) o
        // General (1-3 rondas, con comparación opcional contra sistema).
        if (!$schema->hasTable('sesiones_inventario')) {
            $schema->create('sesiones_inventario', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('nombre', 120);
                $table->text('descripcion')->nullable();

                // Tipo de sesión
                $table->string('tipo', 20)->default('Ciclico'); // Ciclico | General

                // Sólo aplica para General: cuántos conteos se realizarán (1, 2 o 3)
                $table->smallInteger('num_conteos')->default(1);

                // Si es General con 2+ conteos: ¿comparar contra sistema o entre conteos?
                $table->boolean('comparar_sistema')->default(true);

                // Estado del flujo
                // Borrador → EnCurso → PendienteAjuste → Ajustado → Cerrado
                $table->string('estado', 30)->default('Borrador');

                $table->unsignedBigInteger('creado_por');
                $table->unsignedBigInteger('ajustado_por')->nullable();
                $table->date('fecha_inicio')->nullable();
                $table->date('fecha_cierre')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->foreign('creado_por')->references('id')->on('personal')->onDelete('restrict');
                $table->foreign('ajustado_por')->references('id')->on('personal')->onDelete('set null');
                $table->index(['empresa_id', 'sucursal_id', 'estado']);
            });
        }

        // ── 2. ASIGNACIONES DE CONTEO ────────────────────────────────────────
        // Cada auxiliar recibe una instrucción de conteo por ronda.
        // El tipo de instrucción define el alcance (Pasillo, Módulo, Referencia, Libre).
        if (!$schema->hasTable('sesion_asignaciones')) {
            $schema->create('sesion_asignaciones', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sesion_id');
                $table->unsignedBigInteger('auxiliar_id');
                $table->smallInteger('ronda')->default(1); // 1, 2 o 3

                // Tipo de instrucción de conteo
                $table->string('tipo_instruccion', 20)->default('Libre');
                // Pasillo | Modulo | Referencia | Libre

                // Filtros opcionales según tipo de instrucción
                $table->string('pasillo', 60)->nullable();
                $table->string('modulo', 60)->nullable();
                $table->unsignedBigInteger('producto_id')->nullable(); // para tipo Referencia

                $table->text('instruccion_libre')->nullable(); // Notas adicionales al auxiliar

                // Estado de la asignación
                $table->string('estado', 20)->default('Pendiente');
                // Pendiente | Notificado | EnConteo | Finalizado

                $table->timestamp('notificado_at')->nullable();
                $table->timestamp('iniciado_at')->nullable();
                $table->timestamp('finalizado_at')->nullable();
                $table->timestamps();

                $table->foreign('sesion_id')->references('id')->on('sesiones_inventario')->onDelete('cascade');
                $table->foreign('auxiliar_id')->references('id')->on('personal')->onDelete('restrict');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('set null');
                $table->index(['sesion_id', 'ronda', 'auxiliar_id']);
                $table->index(['auxiliar_id', 'estado']); // Para consulta en móvil del auxiliar
            });
        }

        // ── 3. LÍNEAS DE CONTEO ──────────────────────────────────────────────
        // Cada línea representa una referencia contada por un auxiliar en una ronda.
        // Una línea puede ser editada por el admin pero queda registro.
        // Para eliminar se usa soft-delete (estado = Eliminado).
        if (!$schema->hasTable('sesion_lineas')) {
            $schema->create('sesion_lineas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sesion_id');
                $table->unsignedBigInteger('asignacion_id')->nullable(); // Asignación del auxiliar
                $table->unsignedBigInteger('auxiliar_id');
                $table->smallInteger('ronda')->default(1);

                // Producto contado
                $table->unsignedBigInteger('producto_id');
                $table->unsignedBigInteger('ubicacion_id');
                $table->string('lote', 80)->nullable();
                $table->date('fecha_vencimiento')->nullable();

                // Cantidades
                $table->integer('cantidad_contada')->default(0);
                $table->integer('cantidad_sistema')->default(0); // Snapshot al momento del conteo
                $table->integer('diferencia')->default(0);       // cantidad_contada - cantidad_sistema

                // Trazabilidad temporal
                $table->timestamp('hora_conteo')->nullable();

                // Auditoría de ediciones
                $table->integer('cantidad_original')->nullable();  // Valor antes de edición admin
                $table->unsignedBigInteger('editado_por')->nullable();
                $table->timestamp('editado_at')->nullable();
                $table->text('motivo_edicion')->nullable();

                // Soft-delete (nunca se borra físicamente)
                $table->string('estado', 20)->default('Activo'); // Activo | Eliminado
                $table->unsignedBigInteger('eliminado_por')->nullable();
                $table->timestamp('eliminado_at')->nullable();

                $table->timestamps();

                $table->foreign('sesion_id')->references('id')->on('sesiones_inventario')->onDelete('cascade');
                $table->foreign('asignacion_id')->references('id')->on('sesion_asignaciones')->onDelete('set null');
                $table->foreign('auxiliar_id')->references('id')->on('personal')->onDelete('restrict');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('restrict');
                $table->foreign('ubicacion_id')->references('id')->on('ubicaciones')->onDelete('restrict');
                $table->foreign('editado_por')->references('id')->on('personal')->onDelete('set null');
                $table->foreign('eliminado_por')->references('id')->on('personal')->onDelete('set null');

                $table->index(['sesion_id', 'ronda', 'estado']);
                $table->index(['sesion_id', 'producto_id', 'ubicacion_id', 'ronda']);
            });
        }

        // ── 4. TABLA DE AJUSTES DE INVENTARIO ───────────────────────────────
        // Registro inmutable de todos los ajustes (positivos y negativos).
        // Los ajustes provienen de:
        //   a) Aprobación de un conteo (cíclico o general)
        //   b) Corrección manual desde el sub-módulo de ajustes
        // REGLA: Un registro de ajuste NO puede eliminarse ni modificarse.
        if (!$schema->hasTable('ajustes_inventario')) {
            $schema->create('ajustes_inventario', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');

                // Origen del ajuste
                $table->string('origen', 30)->default('Manual');
                // Manual | ConteoLinea | ConteoTotal | CorreccionAdmin

                $table->unsignedBigInteger('sesion_id')->nullable();   // Si viene de conteo
                $table->unsignedBigInteger('linea_id')->nullable();    // Línea específica del conteo
                $table->unsignedBigInteger('movimiento_id')->nullable(); // MovimientoInventario creado

                // Datos del producto y ubicación
                $table->unsignedBigInteger('producto_id');
                $table->unsignedBigInteger('ubicacion_id');
                $table->string('lote', 80)->nullable();
                $table->date('fecha_vencimiento')->nullable();

                // Cantidades — campo clave para el reporte
                $table->integer('cantidad_fisica');   // Lo que se contó / cantidad nueva
                $table->integer('cantidad_sistema');  // Stock antes del ajuste
                $table->integer('diferencia');        // fisica - sistema (+ o -)

                // Tipo de ajuste
                $table->string('tipo_ajuste', 20);   // Entrada | Salida

                $table->text('motivo');

                // Quién hizo el conteo y quién aprobó
                $table->unsignedBigInteger('auxiliar_id')->nullable();  // Quien contó
                $table->unsignedBigInteger('ajustado_por');             // Admin que aprobó

                // Fecha y hora exactas
                $table->date('fecha');
                $table->time('hora');

                // Solo created_at (inmutable, sin updated_at)
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->foreign('sesion_id')->references('id')->on('sesiones_inventario')->onDelete('set null');
                $table->foreign('producto_id')->references('id')->on('productos')->onDelete('restrict');
                $table->foreign('ubicacion_id')->references('id')->on('ubicaciones')->onDelete('restrict');
                $table->foreign('auxiliar_id')->references('id')->on('personal')->onDelete('set null');
                $table->foreign('ajustado_por')->references('id')->on('personal')->onDelete('restrict');

                $table->index(['empresa_id', 'sucursal_id', 'fecha']);
                $table->index(['producto_id', 'fecha']);
                $table->index(['sesion_id']);
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        // Orden inverso respetando FK
        $schema->dropIfExists('ajustes_inventario');
        $schema->dropIfExists('sesion_lineas');
        $schema->dropIfExists('sesion_asignaciones');
        $schema->dropIfExists('sesiones_inventario');
    },
];
