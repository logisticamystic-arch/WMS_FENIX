<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('orden_compra_detalles')) {
            $schema->table('orden_compra_detalles', function (Blueprint $t) use ($schema) {
                if (!$schema->hasColumn('orden_compra_detalles', 'aprobado_admin'))    $t->boolean('aprobado_admin')->default(false)->nullable();
                if (!$schema->hasColumn('orden_compra_detalles', 'novedad_motivo'))    $t->string('novedad_motivo', 100)->nullable();
                if (!$schema->hasColumn('orden_compra_detalles', 'novedad_observacion')) $t->text('novedad_observacion')->nullable();
                if (!$schema->hasColumn('orden_compra_detalles', 'cantidad_novedad'))  $t->decimal('cantidad_novedad', 12, 2)->nullable();
            });
            echo "  orden_compra_detalles OK\n";
        }
        if (!$schema->hasColumn('recepciones', 'odc_id'))
            $schema->table('recepciones', function (Blueprint $t) { $t->unsignedBigInteger('odc_id')->nullable(); });
        else echo "  recepciones.odc_id ya existe\n";
        if ($schema->hasTable('recepcion_detalles') && !$schema->hasColumn('recepcion_detalles', 'cantidad_recibida'))
            $schema->table('recepcion_detalles', function (Blueprint $t) { $t->decimal('cantidad_recibida', 12, 2)->nullable(); });
        if (!$schema->hasColumn('devoluciones', 'odc_id'))
            $schema->table('devoluciones', function (Blueprint $t) { $t->unsignedBigInteger('odc_id')->nullable(); });
        else echo "  devoluciones.odc_id ya existe\n";
        echo "  Migration 041 completada.\n";
    },
    'down' => function () {},
];
