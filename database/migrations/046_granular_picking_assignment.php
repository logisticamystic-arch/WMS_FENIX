<?php
/**
 * Migration 046 — Granular Picking Assignment
 * Adds auxiliar_id to picking_detalles.
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = DB::schema();
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $t) {
                if (!DB::schema()->hasColumn('picking_detalles', 'auxiliar_id')) {
                    $t->unsignedBigInteger('auxiliar_id')->nullable()->after('ubicacion_id');
                    $t->foreign('auxiliar_id')->references('id')->on('personal')->onDelete('set null');
                }
            });
        }
    },
    'down' => function () {
        $schema = DB::schema();
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $t) {
                $t->dropForeign(['auxiliar_id']);
                $t->dropColumn('auxiliar_id');
            });
        }
    },
];
