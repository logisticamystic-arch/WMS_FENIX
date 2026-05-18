<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        Capsule::schema()->create('personal', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('nombre', 150);
            $table->string('documento', 20);
            $table->string('pin', 255); // hashed
            $table->enum('rol', ['Admin', 'SuperAdmin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista']);
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_login')->nullable();
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('set null');
            $table->unique(['empresa_id', 'documento']);
        });
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('personal');
    },
];
