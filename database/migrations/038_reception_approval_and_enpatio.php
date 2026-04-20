<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = Capsule::schema();
        $schema->table('recepciones', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('recepciones', 'aprobado_admin')) {
                $t->boolean('aprobado_admin')->default(false);
                $t->integer('aprobado_por')->nullable();
                $t->timestamp('fecha_aprobacion')->nullable();
            }
        });
        $schema->table('recepcion_detalles', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('recepcion_detalles', 'aprobado_admin'))
                $t->boolean('aprobado_admin')->default(false);
        });
        echo "  Migration 038 completada.\n";
    },
    'down' => function () {},
];
