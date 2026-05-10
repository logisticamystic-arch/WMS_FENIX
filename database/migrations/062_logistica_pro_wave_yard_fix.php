<?php
/**
 * Migration 062 — Logística Pro: Wave Picking + Yard completo
 * ============================================================
 * 1. Crea tabla wave_picking (agrupación de planillas de picking en olas)
 * 2. Crea tabla wave_planillas (planillas incluidas en cada wave)
 * 3. Agrega columnas faltantes a yard_appointments:
 *    inicio_op_real, fin_op_real, conductor, telefono, notas,
 *    duracion_estimada_min, creado_por
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // ── 1. WAVE PICKING ──────────────────────────────────────────────────
        if (!$schema->hasTable('wave_picking')) {
            $schema->create('wave_picking', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('numero', 30);
                $table->string('nombre', 120)->nullable();
                // manual | auto | cliente | auxiliar | prioridad
                $table->string('criterio', 30)->default('manual');
                $table->unsignedTinyInteger('prioridad')->default(3);
                $table->unsignedInteger('planillas_count')->default(0);
                $table->unsignedInteger('lineas_count')->default(0);
                $table->timestamp('inicio_est')->nullable();
                $table->timestamp('inicio_real')->nullable();
                $table->timestamp('fin_real')->nullable();
                // calculado al completar: minutos entre inicio_real y fin_real
                $table->unsignedInteger('duracion_min')->nullable();
                // Preparando | En Proceso | Completado | Cancelado
                $table->string('estado', 30)->default('Preparando');
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->timestamps();

                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $table->index(['empresa_id', 'sucursal_id', 'estado']);
                $table->index(['empresa_id', 'created_at']);
                $table->unique(['empresa_id', 'numero']);
            });
        }

        // ── 2. WAVE PLANILLAS ────────────────────────────────────────────────
        if (!$schema->hasTable('wave_planillas')) {
            $schema->create('wave_planillas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('wave_id');
                $table->unsignedBigInteger('planilla_id');
                $table->unsignedSmallInteger('orden_picking')->default(1);

                $table->foreign('wave_id')->references('id')->on('wave_picking')->onDelete('cascade');
                $table->index('wave_id');
                $table->index('planilla_id');
            });
        }

        // ── 3. YARD_APPOINTMENTS — COLUMNAS FALTANTES ────────────────────────
        if ($schema->hasTable('yard_appointments')) {
            $schema->table('yard_appointments', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('yard_appointments', 'inicio_op_real')) {
                    $table->timestamp('inicio_op_real')->nullable()->after('entrada_real');
                }
                if (!$schema->hasColumn('yard_appointments', 'fin_op_real')) {
                    $table->timestamp('fin_op_real')->nullable()->after('inicio_op_real');
                }
                if (!$schema->hasColumn('yard_appointments', 'conductor')) {
                    $table->string('conductor', 100)->nullable()->after('placa_vehiculo');
                }
                if (!$schema->hasColumn('yard_appointments', 'telefono')) {
                    $table->string('telefono', 30)->nullable()->after('conductor');
                }
                if (!$schema->hasColumn('yard_appointments', 'duracion_estimada_min')) {
                    $table->unsignedSmallInteger('duracion_estimada_min')->default(60)->after('turnaround_min');
                }
                if (!$schema->hasColumn('yard_appointments', 'notas')) {
                    $table->text('notas')->nullable()->after('observaciones');
                }
                if (!$schema->hasColumn('yard_appointments', 'creado_por')) {
                    $table->unsignedBigInteger('creado_por')->nullable()->after('notas');
                }
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('wave_planillas');
        $schema->dropIfExists('wave_picking');
        if ($schema->hasTable('yard_appointments')) {
            $schema->table('yard_appointments', function (Blueprint $table) {
                $table->dropColumn([
                    'inicio_op_real', 'fin_op_real', 'conductor',
                    'telefono', 'duracion_estimada_min', 'notas', 'creado_por',
                ]);
            });
        }
    },
];
