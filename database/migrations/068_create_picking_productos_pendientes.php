<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('picking_productos_pendientes')) return;
        $schema->create('picking_productos_pendientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empresa_id');
            $table->unsignedInteger('sucursal_id');
            $table->string('ean_codigo', 100);
            $table->string('descripcion', 300)->nullable();
            $table->integer('cantidad')->default(1);
            $table->string('numero_factura', 100)->nullable();
            $table->string('sucursal_entrega', 200)->nullable();
            $table->unsignedInteger('importado_por')->nullable();
            $table->date('fecha_importacion');
            $table->string('observacion', 500)->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'sucursal_id', 'ean_codigo'], 'uq_pp_empresa_sucursal_ean');
            $table->index(['empresa_id', 'sucursal_id']);
        });
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('picking_productos_pendientes');
    },
];
