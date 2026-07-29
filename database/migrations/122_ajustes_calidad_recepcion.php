<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

if (!Capsule::schema()->hasTable('recepcion_detalle_calidad')) {
    Capsule::schema()->create('recepcion_detalle_calidad', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('recepcion_detalle_id')->unique();
        
        $table->enum('olor', ['C', 'NC'])->nullable();
        $table->enum('color', ['C', 'NC'])->nullable();
        $table->enum('textura', ['C', 'NC'])->nullable();
        $table->enum('temperatura', ['C', 'NC'])->nullable();
        $table->enum('empaque', ['C', 'NC'])->nullable();
        $table->enum('rotulado', ['C', 'NC'])->nullable();
        
        $table->timestamps();

        $table->foreign('recepcion_detalle_id', 'fk_recdetcal_recdet')
              ->references('id')->on('recepcion_detalles')
              ->onDelete('cascade');
    });
}

if (Capsule::schema()->hasTable('recepcion_calidad')) {
    Capsule::schema()->table('recepcion_calidad', function (Blueprint $table) {
        $columnsToDrop = [
            'prod_olor', 'prod_color', 'prod_textura', 
            'prod_temperatura', 'prod_empaque', 'prod_rotulado'
        ];
        
        foreach ($columnsToDrop as $col) {
            if (Capsule::schema()->hasColumn('recepcion_calidad', $col)) {
                $table->dropColumn($col);
            }
        }
        
        if (!Capsule::schema()->hasColumn('recepcion_calidad', 'firma_responsable')) {
            // Se usará para guardar la URL de la imagen de la firma o el base64
            $table->longText('firma_responsable')->nullable();
        }
    });
}
