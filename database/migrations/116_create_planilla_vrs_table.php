<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        if (!$schema->hasTable('planilla_vrs')) {
            $schema->create('planilla_vrs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id')->index();
                $table->string('planilla_numero', 100)->index();
                $table->string('vr', 100);
                $table->timestamps();
            });
        }
    },
    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('planilla_vrs')) {
            $schema->dropIfExists('planilla_vrs');
        }
    },
];
