<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        Capsule::schema()->create('zonas', function ($table) {
            $table->bigIncrements('id');
            $table->string('codigo', 10)->unique();
            $table->string('descripcion')->nullable();
            $table->unsignedBigInteger('empresa_id');
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas');
            $table->index(['empresa_id', 'codigo']);
        });
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('zonas');
    },
];