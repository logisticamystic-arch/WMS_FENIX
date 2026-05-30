<?php
// database/migrations/069_packing_certificacion.php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        $isPg   = Capsule::connection()->getDriverName() === 'pgsql';

        if (!$schema->hasTable('packing_sesiones')) {
            $schema->create('packing_sesiones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('sucursal_entrega', 200);
                $table->enum('tipo_empaque', ['canasta', 'caja', 'paquete']);
                $table->unsignedBigInteger('certificador_id');
                $table->unsignedBigInteger('impresora_sticker_id')->nullable();
                $table->unsignedBigInteger('impresora_doc_id')->nullable();
                $table->enum('estado', ['EnProceso', 'Completada'])->default('EnProceso');
                $table->timestamps();
                $table->index(
                    ['empresa_id', 'sucursal_id', 'sucursal_entrega', 'estado'],
                    'idx_pk_ses_scope'
                );
            });
        }

        if (!$schema->hasTable('packing_unidades')) {
            $schema->create('packing_unidades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sesion_id');
                $table->unsignedSmallInteger('consecutivo');
                $table->enum('estado', ['Abierta', 'Cerrada'])->default('Abierta');
                $table->decimal('total_unidades', 12, 3)->default(0);
                $table->boolean('sticker_impreso')->default(false);
                $table->timestamp('closed_at')->nullable();
                $table->unique(['sesion_id', 'consecutivo'], 'uq_pk_unidad');
            });
        }

        if (!$schema->hasTable('packing_items')) {
            $schema->create('packing_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('unidad_id');
                $table->unsignedBigInteger('picking_detalle_id')->nullable();
                $table->unsignedBigInteger('producto_id');
                $table->string('lote', 100)->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->unsignedBigInteger('separador_id')->nullable();
                $table->decimal('cantidad', 12, 3);
                $table->timestamp('created_at')->useCurrent();
                $table->index(['unidad_id', 'producto_id'], 'idx_pk_item_unidad');
            });
        }

        if ($schema->hasTable('impresoras') && !$schema->hasColumn('impresoras', 'tipos_trabajo')) {
            $schema->table('impresoras', function (Blueprint $table) {
                $table->json('tipos_trabajo')->nullable()->after('modulos');
            });
            if ($isPg) {
                Capsule::statement("UPDATE impresoras SET tipos_trabajo = '[]'::jsonb WHERE tipos_trabajo IS NULL");
                Capsule::statement("ALTER TABLE impresoras ALTER COLUMN tipos_trabajo SET DEFAULT '[]'::jsonb");
                Capsule::statement("ALTER TABLE impresoras ALTER COLUMN tipos_trabajo SET NOT NULL");
            } else {
                Capsule::statement("UPDATE impresoras SET tipos_trabajo = '[]' WHERE tipos_trabajo IS NULL");
                // MySQL 8: JSON columns cannot carry a literal DEFAULT — application layer must always supply the value
                Capsule::statement("ALTER TABLE impresoras MODIFY COLUMN tipos_trabajo JSON NOT NULL");
            }
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('packing_items');
        $schema->dropIfExists('packing_unidades');
        $schema->dropIfExists('packing_sesiones');
        if ($schema->hasTable('impresoras') && $schema->hasColumn('impresoras', 'tipos_trabajo')) {
            $schema->table('impresoras', function (Blueprint $t) {
                $t->dropColumn('tipos_trabajo');
            });
        }
    },
];
