<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        Capsule::schema()->table('proveedores', function ($table) {
            // Campos de evaluación y desempeño
            $table->float('evaluacion_promedio', 5, 2)->nullable()->comment('Promedio de evaluación 1-10 de todas las citas completadas');
            $table->float('cumplimiento_entregas_pct', 5, 2)->default(0)->comment('% de ODCs completadas vs totales');
            $table->float('cumplimiento_citas_pct', 5, 2)->default(0)->comment('% de citas completadas vs totales');
            $table->float('calidad_aceptacion_pct', 5, 2)->default(0)->comment('% de líneas en buen estado vs totales recibidas');
            $table->float('indice_desempeno_pct', 5, 2)->default(0)->comment('Índice combinado ponderado (0-100)');
            $table->enum('clasificacion', ['A', 'B', 'C'])->nullable()->comment('A=Excelente(95+), B=Bueno(80-95), C=Riesgo(<80)');
            $table->datetime('ultima_evaluacion')->nullable()->comment('Fecha de última actualización de evaluación');
            $table->integer('total_citas_completadas')->default(0)->comment('Total de citas completadas');
            $table->integer('total_odc_completadas')->default(0)->comment('Total de ODCs completadas');
        });
    },
    'down' => function () {
        Capsule::schema()->table('proveedores', function ($table) {
            $table->dropColumn([
                'evaluacion_promedio',
                'cumplimiento_entregas_pct',
                'cumplimiento_citas_pct',
                'calidad_aceptacion_pct',
                'indice_desempeno_pct',
                'clasificacion',
                'ultima_evaluacion',
                'total_citas_completadas',
                'total_odc_completadas'
            ]);
        });
    },
];
