<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. UBAC Permissions
        if (!$schema->hasTable('personal_permisos')) {
            $schema->create('personal_permisos', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('empresa_id')->unsigned();
                $table->integer('personal_id')->unsigned(); 
                $table->string('modulo', 50);
                $table->string('submodulo', 50);
                $table->string('accion', 50);
                $table->boolean('concedido')->default(true);
                $table->timestamps();

                $table->index(['empresa_id', 'personal_id']);
                $table->index(['personal_id', 'modulo', 'submodulo']);
            });
        }

        // 2. Extending Notificaciones
        if ($schema->hasTable('notificaciones')) {
            $schema->table('notificaciones', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('notificaciones', 'emisor_id')) {
                    $table->integer('emisor_id')->unsigned()->nullable()->after('personal_id');
                }
                if (!$schema->hasColumn('notificaciones', 'link_accion')) {
                    $table->string('link_accion', 255)->nullable()->after('tipo');
                }
                if (!$schema->hasColumn('notificaciones', 'completada')) {
                    $table->boolean('completada')->default(false)->after('leida');
                }
                $table->index(['empresa_id', 'personal_id', 'leida']);
            });
        }

        // 3. General Physical Inventory Module
        if (!$schema->hasTable('inv_general_eventos')) {
            $schema->create('inv_general_eventos', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('empresa_id')->unsigned();
                $table->integer('sucursal_id')->unsigned();
                $table->string('nombre', 100);
                $table->enum('tipo', ['CargueInicial', 'Comparacion'])->default('Comparacion');
                $table->enum('estado', ['Abierto', 'Validando', 'Cerrado', 'Anulado'])->default('Abierto');
                $table->date('fecha_programada');
                $table->text('notas')->nullable();
                $table->integer('creado_por')->unsigned();
                $table->timestamps();

                $table->index(['empresa_id', 'sucursal_id', 'estado']);
            });
        }

        if (!$schema->hasTable('inv_general_asignaciones')) {
            $schema->create('inv_general_asignaciones', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('evento_id')->unsigned();
                $table->integer('personal_id')->unsigned();
                $table->enum('rango_tipo', ['Todo', 'Pasillo', 'Ubicacion', 'Categoria', 'Libre'])->default('Libre');
                $table->string('rango_valor', 255)->nullable();
                $table->enum('estado', ['Pendiente', 'EnProgreso', 'Completada'])->default('Pendiente');
                $table->integer('asignado_por')->unsigned();
                $table->timestamps();

                $table->index(['evento_id', 'personal_id']);
            });
        }

        if (!$schema->hasTable('inv_general_conteos')) {
            $schema->create('inv_general_conteos', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('evento_id')->unsigned();
                $table->integer('personal_id')->unsigned();
                $table->integer('ubicacion_id')->unsigned();
                $table->integer('producto_id')->unsigned();
                $table->string('lote', 50)->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->decimal('cantidad', 12, 2)->default(0);
                $table->integer('ciclo')->default(1);
                $table->timestamps();

                $table->index(['evento_id', 'ubicacion_id']);
                $table->index(['evento_id', 'producto_id']);
                // unique requires name to be short enough
                $table->unique(['evento_id', 'ubicacion_id', 'producto_id', 'lote', 'ciclo'], 'inv_gen_conteo_unico_idx');
            });
        }

        if (!$schema->hasTable('inv_general_diferencias')) {
            $schema->create('inv_general_diferencias', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('evento_id')->unsigned();
                $table->integer('ubicacion_id')->unsigned();
                $table->integer('producto_id')->unsigned();
                $table->string('lote', 50)->nullable();
                $table->date('vencimiento_esperado')->nullable();
                $table->decimal('cantidad_sistema', 12, 2)->default(0);
                $table->decimal('conteo_1', 12, 2)->nullable();
                $table->decimal('conteo_2', 12, 2)->nullable();
                $table->decimal('conteo_3', 12, 2)->nullable();
                $table->decimal('cantidad_final_aprobada', 12, 2)->nullable();
                $table->enum('estado', ['Pendiente', 'RequiereRecorteo', 'Aprobada', 'Descartada'])->default('Pendiente');
                $table->integer('resuelto_por')->unsigned()->nullable();
                $table->timestamps();

                $table->index(['evento_id', 'estado']);
                $table->unique(['evento_id', 'ubicacion_id', 'producto_id', 'lote'], 'dif_unica_idx');
            });
        }
    },
    
    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('inv_general_diferencias');
        $schema->dropIfExists('inv_general_conteos');
        $schema->dropIfExists('inv_general_asignaciones');
        $schema->dropIfExists('inv_general_eventos');
        $schema->dropIfExists('personal_permisos');
        
        // Rolling back column addition in notificaciones can be trickier, skipping for safety 
        // as other scripts might depend on it now.
    }
];
