<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = Capsule::schema();
        if (!$schema->hasTable('inventory_guard_log')) {
            $schema->create('inventory_guard_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sucursal_id')->nullable();
                $t->unsignedBigInteger('usuario_id')->nullable();
                $t->string('operacion', 60);
                $t->string('motivo_bloqueo', 120);
                $t->json('contexto')->nullable();
                $t->string('endpoint', 200)->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamps();
                $t->index(['empresa_id', 'operacion', 'created_at']);
            });
            echo "  inventory_guard_log OK\n";
        }
        if (!$schema->hasTable('anomaly_flags')) {
            $schema->create('anomaly_flags', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sucursal_id')->nullable();
                $t->string('tipo', 50);
                $t->string('severidad', 20)->default('media');
                $t->string('titulo', 200);
                $t->text('descripcion');
                $t->json('datos_anomalia')->nullable();
                $t->string('estado', 20)->default('pendiente');
                $t->unsignedBigInteger('revisado_por')->nullable();
                $t->timestamp('revisado_at')->nullable();
                $t->text('notas_revision')->nullable();
                $t->timestamps();
                $t->index(['empresa_id', 'estado', 'severidad', 'created_at']);
            });
            echo "  anomaly_flags OK\n";
        }
        if (!$schema->hasTable('expiry_predictions')) {
            $schema->create('expiry_predictions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sucursal_id');
                $t->unsignedBigInteger('producto_id');
                $t->string('lote', 100)->nullable();
                $t->date('fecha_vencimiento');
                $t->integer('dias_para_vencer');
                $t->decimal('stock_actual', 12, 2);
                $t->decimal('consumo_diario', 10, 4);
                $t->decimal('dias_agotamiento', 8, 2);
                $t->decimal('unidades_en_riesgo', 12, 2)->default(0);
                $t->string('nivel_riesgo', 20)->default('bajo');
                $t->decimal('confianza', 5, 4)->default(0.5);
                $t->json('recomendaciones')->nullable();
                $t->json('serie_consumo')->nullable();
                $t->timestamp('calculado_at')->nullable();
                $t->timestamps();
            });
            echo "  expiry_predictions OK\n";
        }
        if (!$schema->hasTable('performance_metrics')) {
            $schema->create('performance_metrics', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id')->nullable();
                $t->string('metodo', 10);
                $t->string('endpoint', 250);
                $t->string('endpoint_pattern', 200)->nullable();
                $t->integer('duracion_ms');
                $t->integer('status_code')->default(200);
                $t->integer('memoria_kb')->nullable();
                $t->string('ip', 45)->nullable();
                $t->unsignedBigInteger('usuario_id')->nullable();
                $t->text('slow_query_hint')->nullable();
                $t->timestamps();
            });
            echo "  performance_metrics OK\n";
        }
        echo "  Migration 050 completada.\n";
    },
    'down' => function () {},
];
