<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // 1. Insertar nuevos permisos granulares para Picking
        $permisos = [
            ['modulo' => 'picking', 'accion' => 'gestionar', 'descripcion' => 'Permiso para ver y gestionar la lista de picking'],
            ['modulo' => 'picking', 'accion' => 'asignaciones', 'descripcion' => 'Permiso para realizar asignaciones de auxiliares'],
            ['modulo' => 'picking', 'accion' => 'importar_planillas', 'descripcion' => 'Permiso para importar planillas de Excel'],
        ];

        foreach ($permisos as $p) {
            // Usar updateOrInsert para evitar duplicados si se corre dos veces
            Capsule::table('permisos')->updateOrInsert(
                ['modulo' => $p['modulo'], 'accion' => $p['accion']],
                ['descripcion' => $p['descripcion']]
            );
        }

        // 2. Conceder automáticamente estos permisos a los roles Admin y Supervisor en todas las empresas
        $newPermisoIds = Capsule::table('permisos')
            ->where('modulo', 'picking')
            ->whereIn('accion', ['gestionar', 'asignaciones', 'importar_planillas'])
            ->pluck('id');

        $empresaIds = Capsule::table('empresas')->pluck('id');

        foreach ($empresaIds as $empresaId) {
            foreach (['Admin', 'Supervisor'] as $rol) {
                foreach ($newPermisoIds as $permisoId) {
                    Capsule::table('rol_permisos')->updateOrInsert(
                        [
                            'empresa_id' => $empresaId,
                            'rol'        => $rol,
                            'permiso_id' => $permisoId
                        ],
                        ['concedido' => true, 'updated_at' => date('Y-m-d H:i:s')]
                    );
                }
            }
        }
    },
    'down' => function () {
        $permisoIds = Capsule::table('permisos')
            ->where('modulo', 'picking')
            ->whereIn('accion', ['gestionar', 'asignaciones', 'importar_planillas'])
            ->pluck('id');

        Capsule::table('rol_permisos')->whereIn('permiso_id', $permisoIds)->delete();
        Capsule::table('permisos')->whereIn('id', $permisoIds)->delete();
    },
];
