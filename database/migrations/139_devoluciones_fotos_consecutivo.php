<?php
// database/migrations/139_devoluciones_fotos_consecutivo.php
// Agrega columna consecutivo_devolucion (numérico simple: 1, 2, 3...)
// y asegura que fotos_json y sucursal_origen_id existan.

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        $schema->table('devoluciones', function (Blueprint $t) use ($schema) {
            // Consecutivo numérico simple para marcado físico de producto (1, 2, 3...)
            if (!$schema->hasColumn('devoluciones', 'consecutivo_devolucion')) {
                $t->unsignedInteger('consecutivo_devolucion')->nullable()->after('numero_devolucion');
            }

            // JSON de rutas de fotos
            if (!$schema->hasColumn('devoluciones', 'fotos_json')) {
                $t->text('fotos_json')->nullable()->after('motivo_general');
            }

            // Sucursal que devuelve
            if (!$schema->hasColumn('devoluciones', 'sucursal_origen_id')) {
                $t->unsignedBigInteger('sucursal_origen_id')->nullable()->after('cliente_origen_id');
            }
        });

        // Índice para búsqueda rápida por consecutivo
        try {
            Capsule::statement('CREATE INDEX idx_dev_consecutivo ON devoluciones (empresa_id, consecutivo_devolucion)');
        } catch (\Exception $e) {
            // Ignorar si ya existe
        }

        // Backfill: asignar consecutivos a devoluciones existentes que no tengan
        try {
            $empresas = Capsule::table('devoluciones')
                ->whereNull('consecutivo_devolucion')
                ->distinct()
                ->pluck('empresa_id');

            foreach ($empresas as $empresaId) {
                $devs = Capsule::table('devoluciones')
                    ->where('empresa_id', $empresaId)
                    ->whereNull('consecutivo_devolucion')
                    ->orderBy('id')
                    ->pluck('id');

                $maxActual = (int) Capsule::table('devoluciones')
                    ->where('empresa_id', $empresaId)
                    ->max('consecutivo_devolucion');

                foreach ($devs as $i => $devId) {
                    Capsule::table('devoluciones')
                        ->where('id', $devId)
                        ->update(['consecutivo_devolucion' => $maxActual + $i + 1]);
                }
            }
        } catch (\Exception $e) {
            error_log('139_devoluciones_fotos_consecutivo backfill error: ' . $e->getMessage());
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        $schema->table('devoluciones', function (Blueprint $t) use ($schema) {
            if ($schema->hasColumn('devoluciones', 'consecutivo_devolucion')) {
                $t->dropColumn('consecutivo_devolucion');
            }
        });
        try {
            Capsule::statement('DROP INDEX idx_dev_consecutivo ON devoluciones');
        } catch (\Exception $e) {}
    },
];
