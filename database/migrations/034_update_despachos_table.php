<?php
/**
 * Migration 034 — Update despachos table with operational fields.
 */
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        Capsule::schema()->table('despachos', function (Blueprint $t) {
            if (!Capsule::schema()->hasColumn('despachos', 'conductor')) {
                $t->string('conductor', 150)->nullable()->after('ruta');
            }
            if (!Capsule::schema()->hasColumn('despachos', 'placa')) {
                $t->string('placa', 20)->nullable()->after('conductor');
            }
            if (!Capsule::schema()->hasColumn('despachos', 'planilla_id')) {
                $t->unsignedBigInteger('planilla_id')->nullable()->after('placa');
                $t->foreign('planilla_id')->references('id')->on('archivos_planilla')->onDelete('set null');
            }
            if (!Capsule::schema()->hasColumn('despachos', 'auxiliares_json')) {
                $t->text('auxiliares_json')->nullable()->after('auxiliar_id');
            }
        });
    },
    'down' => function () {
        Capsule::schema()->table('despachos', function (Blueprint $t) {
            $t->dropForeign(['planilla_id']);
            $t->dropColumn(['conductor', 'placa', 'planilla_id', 'auxiliares_json']);
        });
    },
];
