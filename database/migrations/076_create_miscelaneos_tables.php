<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as DB;

return new class {
    public function up(): void
    {
        $schema = DB::schema();

        if (!$schema->hasTable('miscelaneos')) {
            $schema->create('miscelaneos', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('numero_recepcion', 30)->nullable();
                $table->string('proveedor', 200);
                $table->string('articulo', 300);
                $table->decimal('cantidad', 12, 2);
                $table->string('unidad_medida', 30)->default('UN');
                $table->text('observaciones')->nullable();
                $table->unsignedBigInteger('recibido_por')->nullable();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->string('cliente_nombre', 200)->nullable();
                $table->unsignedBigInteger('despacho_id')->nullable();
                $table->string('estado', 30)->default('Recibido');
                $table->timestamps();

                $table->index(['empresa_id', 'sucursal_id', 'estado']);
                $table->index(['cliente_id', 'estado']);
                $table->index('despacho_id');
            });
        }

        if (!$schema->hasTable('miscelaneo_fotos')) {
            $schema->create('miscelaneo_fotos', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('miscelaneo_id');
                $table->string('url', 500);
                $table->timestamps();

                $table->foreign('miscelaneo_id')->references('id')->on('miscelaneos')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        $schema = DB::schema();
        $schema->dropIfExists('miscelaneo_fotos');
        $schema->dropIfExists('miscelaneos');
    }
};
