<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

if (!Capsule::schema()->hasTable('traspaso_documentos')) {
    Capsule::schema()->create('traspaso_documentos', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('empresa_id');
        $table->unsignedBigInteger('sucursal_id');
        $table->string('numero_documento', 50);
        $table->date('fecha_movimiento')->nullable();
        
        $table->unsignedBigInteger('cliente_id')->nullable();
        $table->string('cliente_nombre')->nullable();
        $table->string('quien_recibe')->nullable();
        $table->string('firma_path')->nullable();
        
        $table->string('motivo')->nullable();
        $table->text('observaciones')->nullable();
        
        $table->unsignedBigInteger('auxiliar_id')->nullable();
        $table->string('estado', 50)->default('Completado');
        
        $table->timestamps();

        $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
        $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
    });
}

if (!Capsule::schema()->hasTable('traspaso_documento_detalles')) {
    Capsule::schema()->create('traspaso_documento_detalles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('traspaso_documento_id');
        
        $table->unsignedBigInteger('producto_id');
        $table->unsignedBigInteger('ubicacion_id');
        
        $table->string('lote')->nullable();
        $table->date('fecha_vencimiento')->nullable();
        
        $table->decimal('cantidad', 10, 2);
        
        $table->timestamps();

        $table->foreign('traspaso_documento_id', 'fk_trpdoc_det_doc')->references('id')->on('traspaso_documentos')->onDelete('cascade');
        $table->foreign('producto_id', 'fk_trpdoc_det_prod')->references('id')->on('productos')->onDelete('cascade');
        $table->foreign('ubicacion_id', 'fk_trpdoc_det_ubi')->references('id')->on('ubicaciones')->onDelete('cascade');
    });
}
