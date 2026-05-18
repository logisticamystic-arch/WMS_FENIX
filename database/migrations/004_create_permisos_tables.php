<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        Capsule::schema()->create('permisos', function ($table) {
            $table->bigIncrements('id');
            $table->string('modulo', 50);
            $table->string('accion', 50);
            $table->string('descripcion', 200)->nullable();

            $table->unique(['modulo', 'accion']);
        });

        Capsule::schema()->create('rol_permisos', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('empresa_id');
            $table->enum('rol', ['Admin', 'SuperAdmin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista']);
            $table->unsignedBigInteger('permiso_id');
            $table->boolean('concedido')->default(true);
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->foreign('permiso_id')->references('id')->on('permisos')->onDelete('cascade');
            $table->unique(['empresa_id', 'rol', 'permiso_id']);
        });
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('rol_permisos');
        Capsule::schema()->dropIfExists('permisos');
    },
];
