<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // Tabla de categorías
        if (!Capsule::schema()->hasTable('categoria_productos')) {
            Capsule::schema()->create('categoria_productos', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->string('nombre', 100);
                $table->text('descripcion')->nullable();
                $table->boolean('requiere_foto_vencimiento')->default(false);
                $table->timestamps();
                $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            });
        }

        // Agregar categoria_id a productos
        if (!Capsule::schema()->hasColumn('productos', 'categoria_id')) {
            Capsule::schema()->table('productos', function ($table) {
                $table->unsignedBigInteger('categoria_id')->nullable()->after('marca_id');
                $table->foreign('categoria_id')->references('id')->on('categoria_productos')->onDelete('set null');
            });
        }
    },
    'down' => function () {
        if (Capsule::schema()->hasColumn('productos', 'categoria_id')) {
            Capsule::schema()->table('productos', function ($table) {
                $table->dropForeign(['categoria_id']);
                $table->dropColumn('categoria_id');
            });
        }
        Capsule::schema()->dropIfExists('categoria_productos');
    },
];
