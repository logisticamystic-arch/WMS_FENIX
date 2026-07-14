<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as DB;

return new class {
    public function up(): void
    {
        $schema = DB::schema();

        // Bloqueo de producto completo
        if (!$schema->hasColumn('productos', 'bloqueado')) {
            $schema->table('productos', function (Blueprint $table) {
                $table->boolean('bloqueado')->default(false)->after('activo');
                $table->string('bloqueo_motivo', 300)->nullable()->after('bloqueado');
            });
        }

        // Bloqueo por lote específico
        if (!$schema->hasTable('bloqueo_lotes')) {
            $schema->create('bloqueo_lotes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('producto_id');
                $table->string('lote', 100);
                $table->string('motivo', 300)->nullable();
                $table->unsignedBigInteger('bloqueado_por')->nullable();
                $table->timestamps();

                $table->unique(['empresa_id', 'producto_id', 'lote']);
                $table->index('empresa_id');
            });
        }

        // Traspasos (salida de inventario a cliente)
        if (!$schema->hasTable('traspasos')) {
            $schema->create('traspasos', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('numero_traspaso', 30);
                $table->unsignedBigInteger('producto_id');
                $table->unsignedBigInteger('ubicacion_id');
                $table->string('lote', 100)->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->decimal('cantidad', 12, 2);
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->string('cliente_nombre', 200)->nullable();
                $table->string('motivo', 100);
                $table->text('observaciones')->nullable();
                $table->unsignedBigInteger('auxiliar_id')->nullable();
                $table->string('estado', 30)->default('Completado');
                $table->timestamps();

                $table->index(['empresa_id', 'sucursal_id']);
                $table->index('cliente_id');
                $table->index('producto_id');
            });
        }
    }

    public function down(): void
    {
        $schema = DB::schema();
        $schema->dropIfExists('traspasos');
        $schema->dropIfExists('bloqueo_lotes');
        if ($schema->hasColumn('productos', 'bloqueado')) {
            $schema->table('productos', function (Blueprint $table) {
                $table->dropColumn(['bloqueado', 'bloqueo_motivo']);
            });
        }
    }
};
