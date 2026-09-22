<?php
/**
 * Migration 155 — Planilla de Cargue: conecta conductor/vehículo del despacho
 * con los maestros reales (tablas `conductores`/`vehiculos`, migración 146).
 *
 * A pedido explícito de Camilo: hoy `despachos.placa`/`.conductor` son texto
 * libre (se tipean a mano cada vez). Se agregan conductor_id/vehiculo_id
 * siguiendo el MISMO patrón que ya usa `despachos.ruta_id` (agregado en la
 * migración 107): columna nullable sin FK dura (mismo criterio que ruta_id:
 * "sin FK — evita romper el despacho si el maestro se desactiva/borra"), y
 * se sigue guardando el valor de texto (placa/conductor) para no romper
 * ningún reporte/documento que ya lea esas columnas.
 */
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        $schema->table('despachos', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('despachos', 'conductor_id')) {
                $t->unsignedBigInteger('conductor_id')->nullable()->after('conductor');
            }
            if (!$schema->hasColumn('despachos', 'vehiculo_id')) {
                $t->unsignedBigInteger('vehiculo_id')->nullable()->after('placa');
            }
        });
        echo "  [OK] despachos.conductor_id / despachos.vehiculo_id agregadas.\n";
    },
    'down' => function () {
        Capsule::schema()->table('despachos', function (Blueprint $t) {
            $t->dropColumn(['conductor_id', 'vehiculo_id']);
        });
    },
];
