<?php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        if (Capsule::connection()->getDriverName() !== 'pgsql') return;
        Capsule::statement("ALTER TABLE packing_sesiones DROP CONSTRAINT IF EXISTS packing_sesiones_estado_check");
        Capsule::statement("ALTER TABLE packing_sesiones ADD CONSTRAINT packing_sesiones_estado_check
            CHECK (estado IN ('EnProceso','Completada','Cancelada'))");
    },
    'down' => function () {
        if (Capsule::connection()->getDriverName() !== 'pgsql') return;
        Capsule::statement("ALTER TABLE packing_sesiones DROP CONSTRAINT IF EXISTS packing_sesiones_estado_check");
        Capsule::statement("ALTER TABLE packing_sesiones ADD CONSTRAINT packing_sesiones_estado_check
            CHECK (estado IN ('EnProceso','Completada'))");
    },
];
