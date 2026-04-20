<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. Update conteo_inventarios
        $schema->table('conteo_inventarios', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('conteo_inventarios', 'analista_id')) {
                $table->unsignedBigInteger('analista_id')->nullable()->after('sucursal_id');
            }
            if (!$schema->hasColumn('conteo_inventarios', 'ronda_actual')) {
                $table->integer('ronda_actual')->default(1)->after('tipo_conteo');
            }
            if (!$schema->hasColumn('conteo_inventarios', 'usa_bloqueo')) {
                $table->boolean('usa_bloqueo')->default(false)->after('ronda_actual');
            }
            if (!$schema->hasColumn('conteo_inventarios', 'tipo')) {
                // To avoid conflict with existing 'tipo_conteo' column names or handle mapping
                $table->string('tipo_interno', 20)->default('Ciclico')->after('tipo_conteo');
            }
        });

        // 2. Create conteo_personal (Many-to-Many assignments)
        if (!$schema->hasTable('conteo_personal')) {
            $schema->create('conteo_personal', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('conteo_id');
                $table->unsignedBigInteger('personal_id');
                $table->timestamps();

                $table->foreign('conteo_id')->references('id')->on('conteo_inventarios')->onDelete('cascade');
                $table->foreign('personal_id')->references('id')->on('personal')->onDelete('cascade');
                $table->unique(['conteo_id', 'personal_id']);
            });
        }

        // 3. Update conteo_detalles
        $schema->table('conteo_detalles', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('conteo_detalles', 'ronda')) {
                $table->integer('ronda')->default(1)->after('conteo_id');
            }
            if (!$schema->hasColumn('conteo_detalles', 'auxiliar_id')) {
                $table->unsignedBigInteger('auxiliar_id')->nullable()->after('ronda');
            }
            if (!$schema->hasColumn('conteo_detalles', 'cantidad_sistema_snapshot')) {
                $table->integer('cantidad_sistema_snapshot')->default(0)->after('cantidad_sistema');
            }
            if (!$schema->hasColumn('conteo_detalles', 'lote_leido')) {
                $table->string('lote_leido', 50)->nullable()->after('lote');
            }
            if (!$schema->hasColumn('conteo_detalles', 'fv_leida')) {
                $table->date('fv_leida')->nullable()->after('lote_leido');
            }
            if (!$schema->hasColumn('conteo_detalles', 'fecha_inicio')) {
                $table->timestamp('fecha_inicio')->nullable()->after('estado');
            }
            if (!$schema->hasColumn('conteo_detalles', 'fecha_fin')) {
                $table->timestamp('fecha_fin')->nullable()->after('fecha_inicio');
            }
        });
    },
    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('conteo_personal');
        $schema->table('conteo_inventarios', function (Blueprint $table) {
            $table->dropColumn(['analista_id', 'ronda_actual', 'usa_bloqueo', 'tipo_interno']);
        });
        $schema->table('conteo_detalles', function (Blueprint $table) {
            $table->dropColumn(['ronda', 'auxiliar_id', 'cantidad_sistema_snapshot', 'lote_leido', 'fv_leida', 'fecha_inicio', 'fecha_fin']);
        });
    }
];
