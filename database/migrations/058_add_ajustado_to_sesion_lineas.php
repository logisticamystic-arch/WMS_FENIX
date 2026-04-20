<?php
/**
 * Migration 058 — Add 'ajustado' to sesion_lineas
 * =============================================
 * Repara el error 500 en el dashboard de inventarios.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('sesion_lineas')) {
            $schema->table('sesion_lineas', function (Blueprint $table) {
                if (!Capsule::schema()->hasColumn('sesion_lineas', 'ajustado')) {
                    $table->boolean('ajustado')->default(false)->after('estado');
                }
            });
        }
    },
    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('sesion_lineas')) {
            $schema->table('sesion_lineas', function (Blueprint $table) {
                if (Capsule::schema()->hasColumn('sesion_lineas', 'ajustado')) {
                    $table->dropColumn('ajustado');
                }
            });
        }
    }
];
