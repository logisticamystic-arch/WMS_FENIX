<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

if (!Capsule::schema()->hasTable('recepcion_calidad')) {
    Capsule::schema()->create('recepcion_calidad', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('recepcion_id')->unique();
        
        $table->string('factura', 50)->nullable();
        $table->string('trans_placa', 20)->nullable();
        
        // Producto C/NC
        $table->enum('prod_olor', ['C', 'NC'])->nullable();
        $table->enum('prod_color', ['C', 'NC'])->nullable();
        $table->enum('prod_textura', ['C', 'NC'])->nullable();
        $table->enum('prod_temperatura', ['C', 'NC'])->nullable();
        $table->enum('prod_empaque', ['C', 'NC'])->nullable();
        $table->enum('prod_rotulado', ['C', 'NC'])->nullable();
        
        // Transporte C/NC
        $table->enum('trans_temperatura', ['C', 'NC'])->nullable();
        $table->enum('trans_limpieza', ['C', 'NC'])->nullable();
        $table->enum('trans_concepto_sanitario', ['C', 'NC'])->nullable();
        $table->enum('trans_carnet_manipulacion', ['C', 'NC'])->nullable();
        
        $table->string('foto_evidencia', 255)->nullable();
        
        $table->timestamps();

        $table->foreign('recepcion_id', 'fk_rec_cal_rec')->references('id')->on('recepciones')->onDelete('cascade');
    });
}
