<?php

use Illuminate\Database\Capsule\Manager as DB;

return new class {
    public function up()
    {
        if (!DB::schema()->hasTable('sesion_icg_lineas')) {
            DB::schema()->create('sesion_icg_lineas', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sesion_id');
                $table->unsignedBigInteger('producto_id')->nullable();
                $table->string('codigo_referencia', 100);
                $table->string('nombre_referencia', 255)->nullable();
                $table->decimal('cantidad_icg', 12, 3)->default(0);
                $table->unsignedBigInteger('empresa_id')->nullable();
                $table->unsignedBigInteger('sucursal_id')->nullable();
                $table->timestamps();

                $table->foreign('sesion_id')->references('id')->on('sesiones_inventario')->onDelete('cascade');
                $table->index(['sesion_id', 'codigo_referencia']);
                $table->index(['sesion_id', 'producto_id']);
            });
        }
    }

    public function down()
    {
        DB::schema()->dropIfExists('sesion_icg_lineas');
    }
};
