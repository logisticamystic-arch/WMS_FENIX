<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        if (!Capsule::schema()->hasColumn('ordenes_compra', 'auxiliar_id')) {
            Capsule::schema()->table('ordenes_compra', function (Blueprint $table) {
                $table->bigInteger('auxiliar_id')->unsigned()->nullable()->after('proveedor_id');
                $table->foreign('auxiliar_id')->references('id')->on('personal')->onDelete('set null');
                $table->index('auxiliar_id');
                $table->index('estado');
            });
        }
    },
    'down' => function () {
        Capsule::schema()->table('ordenes_compra', function (Blueprint $table) {
            $table->dropForeign(['auxiliar_id']);
            $table->dropColumn('auxiliar_id');
        });
    }
];
