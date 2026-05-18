<?php
/**
 * Migration 063 — Logística Pro: Estabilización de Esquema MySQL
 * ============================================================
 * 1. Crea la tabla faltante 'planillas_picking' requerida por WaveController.
 * 2. Implementa columnas calculadas (KPIs) usando MySQL GENERATED COLUMNS.
 * 3. Asegura consistencia de tipos para cálculos de tiempo (TIMESTAMPDIFF).
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // ── 1. CREAR PLANILLAS_PICKING ───────────────────────────────────────
        if (!$schema->hasTable('planillas_picking')) {
            $schema->create('planillas_picking', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('numero', 50);
                $table->string('cliente', 200)->nullable();
                $table->string('estado', 30)->default('Pendiente');
                $table->integer('total_lineas')->default(0);
                $table->integer('lineas_completadas')->default(0);
                $table->unsignedBigInteger('auxiliar_id')->nullable();
                $table->unsignedTinyInteger('prioridad')->default(3);
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->index(['empresa_id', 'sucursal_id', 'estado']);
                $table->unique(['empresa_id', 'numero']);
            });
        }

        // ── 2. YARD_APPOINTMENTS: TURNAROUND KPI ─────────────────────────────
        if ($schema->hasTable('yard_appointments')) {
            // Eliminar columna si existe para re-crearla como generada
            if ($schema->hasColumn('yard_appointments', 'turnaround_min')) {
                $schema->table('yard_appointments', function (Blueprint $table) {
                    $table->dropColumn('turnaround_min');
                });
            }
            // MySQL 5.7+ Generated Column
            Capsule::statement("ALTER TABLE yard_appointments ADD turnaround_min INT 
                GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, entrada_real, salida_real)) STORED");
        }

        // ── 3. CROSS_DOCK_ORDENES: TIEMPO EN SUELO KPI ────────────────────────
        if ($schema->hasTable('cross_dock_ordenes')) {
            if ($schema->hasColumn('cross_dock_ordenes', 'tiempo_suelo_min')) {
                $schema->table('cross_dock_ordenes', function (Blueprint $table) {
                    $table->dropColumn('tiempo_suelo_min');
                });
            }
            Capsule::statement("ALTER TABLE cross_dock_ordenes ADD tiempo_suelo_min INT 
                GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, entrada_real, salida_real)) STORED");
        }

        // ── 4. WAVE_PICKING: DURACION KPI ─────────────────────────────────────
        if ($schema->hasTable('wave_picking')) {
            if ($schema->hasColumn('wave_picking', 'duracion_min')) {
                $schema->table('wave_picking', function (Blueprint $table) {
                    $table->dropColumn('duracion_min');
                });
            }
            Capsule::statement("ALTER TABLE wave_picking ADD duracion_min INT 
                GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, inicio_real, fin_real)) STORED");
        }

        // ── 5. SEED INICIAL PARA PLANILLAS_PICKING (desde orden_pickings) ─────
        // Esto permite que el módulo de Waves tenga datos con los que trabajar de inmediato
        Capsule::statement("
            INSERT IGNORE INTO planillas_picking (empresa_id, sucursal_id, numero, cliente, estado, total_lineas, created_at, updated_at)
            SELECT empresa_id, sucursal_id, planilla_numero, MAX(cliente), 'Pendiente', COUNT(*), NOW(), NOW()
            FROM orden_pickings
            WHERE planilla_numero IS NOT NULL AND estado = 'Pendiente'
            GROUP BY empresa_id, sucursal_id, planilla_numero
        ");
    },

    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('planillas_picking');
        
        if ($schema->hasTable('yard_appointments')) {
            $schema->table('yard_appointments', function (Blueprint $table) {
                $table->dropColumn('turnaround_min');
            });
            $table->integer('turnaround_min')->nullable();
        }
        // ... otros revertir si es necesario
    }
];
