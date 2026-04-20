<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // notificaciones table (main notification storage)
        if (!$schema->hasTable('notificaciones')) {
            $schema->create('notificaciones', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('personal_id');  // recipient
                $table->unsignedBigInteger('emisor_id')->nullable(); // sender
                $table->string('tipo', 30)->default('info'); // info, tarea, alerta, recibo, picking, inventario
                $table->string('titulo', 200);
                $table->text('mensaje');
                $table->string('link_accion', 255)->nullable(); // deeplink inside app
                $table->string('modulo', 50)->nullable();       // which module triggered
                $table->string('referencia_tipo', 50)->nullable(); // ODC, Recepcion, Picking, etc
                $table->unsignedBigInteger('referencia_id')->nullable();
                $table->boolean('leida')->default(false);
                $table->boolean('completada')->default(false);
                $table->boolean('sonido')->default(true);
                $table->timestamp('leida_en')->nullable();
                $table->timestamps();

                $table->index(['empresa_id', 'personal_id', 'leida']);
                $table->index(['personal_id', 'created_at']);
            });
        } else {
            // Ensure all columns exist
            $schema->table('notificaciones', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('notificaciones', 'titulo')) {
                    $table->string('titulo', 200)->after('tipo');
                }
                if (!$schema->hasColumn('notificaciones', 'modulo')) {
                    $table->string('modulo', 50)->nullable()->after('link_accion');
                }
                if (!$schema->hasColumn('notificaciones', 'referencia_tipo')) {
                    $table->string('referencia_tipo', 50)->nullable()->after('modulo');
                }
                if (!$schema->hasColumn('notificaciones', 'referencia_id')) {
                    $table->unsignedBigInteger('referencia_id')->nullable()->after('referencia_tipo');
                }
                if (!$schema->hasColumn('notificaciones', 'sonido')) {
                    $table->boolean('sonido')->default(true)->after('completada');
                }
                if (!$schema->hasColumn('notificaciones', 'leida_en')) {
                    $table->timestamp('leida_en')->nullable()->after('sonido');
                }
            });
        }

        // odc_personal: many-to-many ODC <-> Personal assignment
        if (!$schema->hasTable('odc_personal')) {
            $schema->create('odc_personal', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedBigInteger('odc_id');
                $table->unsignedBigInteger('personal_id');
                $table->unsignedBigInteger('asignado_por');
                $table->timestamps();
                $table->unique(['odc_id', 'personal_id']);
                $table->index('odc_id');
            });
        }

        // Ensure personal_permisos exists (from migration 036 if not ran)
        if (!$schema->hasTable('personal_permisos')) {
            $schema->create('personal_permisos', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('personal_id');
                $table->string('modulo', 50);
                $table->string('submodulo', 50)->default('');
                $table->string('accion', 50)->default('ver');
                $table->boolean('concedido')->default(true);
                $table->timestamps();
                $table->index(['empresa_id', 'personal_id']);
                $table->index(['personal_id', 'modulo']);
            });
        }
    },
    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('odc_personal');
    }
];
