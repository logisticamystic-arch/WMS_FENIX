<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\{Empresa, Sucursal, Personal, Permiso, RolPermiso, Ubicacion, Parametro};

class DatabaseSeeder
{
    public function run(): void
    {
        echo "  → Seeding empresa...\n";
        $empresa = Empresa::firstOrCreate(['nit' => '900000001'], [
            'razon_social' => 'WMS Fénix',
            'direccion' => 'Calle Principal #1',
            'telefono' => '3001234567',
            'email' => 'admin@wmsfenix.com',
            'activo' => true,
        ]);

        echo "  → Seeding sucursal...\n";
        $sucursal = Sucursal::firstOrCreate([
            'empresa_id' => $empresa->id,
            'codigo' => 'CEDI-01',
        ], [
            'nombre' => 'CEDI Principal',
            'direccion' => 'Zona Industrial',
            'ciudad' => 'Medellin',
            'tipo' => 'CEDI',
            'activo' => true,
        ]);

        echo "  → Seeding parametros...\n";
        $params = [
            ['clave' => 'recibo_ciego', 'valor' => 'false', 'descripcion' => 'Activar recibo a ciegas por defecto'],
            ['clave' => 'fefo_estricto', 'valor' => 'true', 'descripcion' => 'Validación FEFO estricta'],
            ['clave' => 'dias_alerta_vencimiento', 'valor' => '30', 'descripcion' => 'Días para alerta de vencimiento'],
            ['clave' => 'auto_reabastecimiento', 'valor' => 'true', 'descripcion' => 'Generar tareas de reabastecimiento automático'],
        ];
        foreach ($params as $p) {
            Parametro::firstOrCreate([
                'sucursal_id' => $sucursal->id,
                'clave' => $p['clave'],
            ], $p);
        }

        echo "  → Seeding virtual locations (PATIO, OBSOLETO, MUELLE-01)...\n";
        $virtualLocations = [
            [
                'codigo' => 'PATIO',
                'zona' => 'VRT',
                'pasillo' => '00',
                'nivel' => '00',
                'tipo_ubicacion' => 'Patio',
            ],
            [
                'codigo' => 'OBSOLETO',
                'zona' => 'VRT',
                'pasillo' => '00',
                'nivel' => '00',
                'tipo_ubicacion' => 'Patio',
            ],
            [
                'codigo' => 'MUELLE-01',
                'zona' => 'MUE',
                'pasillo' => '01',
                'nivel' => '00',
                'tipo_ubicacion' => 'Muelle',
            ],
        ];
        foreach ($virtualLocations as $loc) {
            Ubicacion::firstOrCreate([
                'sucursal_id' => $sucursal->id,
                'codigo' => $loc['codigo'],
            ], $loc);
        }

        echo "  → Seeding sample locations (P01-01-01 to P01-03-03)...\n";
        $tipos = ['Picking', 'Almacenamiento'];
        for ($pasillo = 1; $pasillo <= 3; $pasillo++) {
            for ($nivel = 1; $nivel <= 3; $nivel++) {
                $tipo = $nivel <= 1 ? 'Picking' : 'Almacenamiento';
                $codigo = sprintf('P%02d-%02d-%02d', $pasillo, $nivel, 1);
                Ubicacion::firstOrCreate([
                    'sucursal_id' => $sucursal->id,
                    'codigo' => $codigo,
                ], [
                    'zona' => 'A',
                    'pasillo' => sprintf('%02d', $pasillo),
                    'nivel' => sprintf('%02d', $nivel),
                    'posicion' => '01',
                    'tipo_ubicacion' => $tipo,
                    'capacidad_maxima' => $tipo === 'Picking' ? 100 : 500,
                ]);
            }
        }

        echo "  → Seeding admin user (PIN: 1234)...\n";
        Personal::firstOrCreate([
            'empresa_id' => $empresa->id,
            'documento' => 'ADMIN001',
        ], [
            'sucursal_id' => $sucursal->id,
            'nombre' => 'Administrador',
            'pin' => Personal::hashPin('1234'),
            'rol' => 'Admin',
            'activo' => true,
        ]);

        echo "  → Seeding SuperAdmin user (PIN: 0000)...\n";
        Personal::firstOrCreate([
            'empresa_id' => $empresa->id,
            'documento' => 'SUPERADMIN',
        ], [
            'sucursal_id' => $sucursal->id,
            'nombre' => 'SuperAdmin Global',
            'pin' => Personal::hashPin('0000'),
            'rol' => 'SuperAdmin',
            'activo' => true,
        ]);

        // Seed sample operators
        $operators = [
            ['documento' => 'AUX001', 'nombre' => 'Auxiliar Demo', 'rol' => 'Auxiliar', 'pin' => '0001'],
            ['documento' => 'SUP001', 'nombre' => 'Supervisor Demo', 'rol' => 'Supervisor', 'pin' => '0002'],
            ['documento' => 'MON001', 'nombre' => 'Montacarguista Demo', 'rol' => 'Montacarguista', 'pin' => '0003'],
            ['documento' => 'ANA001', 'nombre' => 'Analista Demo', 'rol' => 'Analista', 'pin' => '0004'],
        ];
        foreach ($operators as $op) {
            Personal::firstOrCreate([
                'empresa_id' => $empresa->id,
                'documento' => $op['documento'],
            ], [
                'sucursal_id' => $sucursal->id,
                'nombre' => $op['nombre'],
                'pin' => Personal::hashPin($op['pin']),
                'rol' => $op['rol'],
                'activo' => true,
            ]);
        }

        echo "  → Seeding permissions catalog...\n";
        $modules = [
            'maestros' => ['ver', 'crear', 'editar', 'eliminar', 'importar', 'exportar'],
            'recepcion' => ['ver', 'crear', 'editar', 'cerrar', 'recibo_ciego'],
            'almacenamiento' => ['ver', 'ubicar', 'trasladar'],
            'inventario' => ['ver', 'conteo_crear', 'conteo_ejecutar', 'conteo_aprobar', 'ajustar'],
            'picking' => ['ver', 'crear', 'ejecutar', 'reabastecer'],
            'despacho' => ['ver', 'crear', 'certificar', 'despachar'],
            'devoluciones' => ['ver', 'crear', 'aprobar', 'procesar'],
            'reportes' => ['ver', 'exportar'],
            'permisos' => ['ver', 'editar'],
        ];

        foreach ($modules as $modulo => $acciones) {
            foreach ($acciones as $accion) {
                Permiso::firstOrCreate(
                    ['modulo' => $modulo, 'accion' => $accion],
                    ['descripcion' => "Permiso: {$modulo} → {$accion}"]
                );
            }
        }

        // Grant all permissions to Admin and SuperAdmin roles
        echo "  → Granting all permissions to Admin and SuperAdmin roles...\n";
        $allPermisos = Permiso::all();
        foreach (['Admin', 'SuperAdmin'] as $role) {
            foreach ($allPermisos as $permiso) {
                RolPermiso::firstOrCreate([
                    'empresa_id' => $empresa->id,
                    'rol' => $role,
                    'permiso_id' => $permiso->id,
                ], [
                    'concedido' => true,
                ]);
            }
        }

        // Grant basic permissions to other roles
        $rolePermisos = [
            'Supervisor' => ['maestros.ver', 'recepcion.*', 'almacenamiento.*', 'inventario.*', 'picking.*', 'despacho.*', 'devoluciones.*', 'reportes.*'],
            'Auxiliar' => ['recepcion.ver', 'recepcion.crear', 'almacenamiento.ver', 'almacenamiento.ubicar', 'almacenamiento.trasladar', 'inventario.ver', 'inventario.conteo_ejecutar', 'picking.ver', 'picking.ejecutar', 'despacho.ver', 'despacho.certificar'],
            'Montacarguista' => ['almacenamiento.ver', 'almacenamiento.ubicar', 'almacenamiento.trasladar', 'picking.ver', 'picking.reabastecer'],
            'Analista' => ['maestros.ver', 'inventario.*', 'reportes.*', 'devoluciones.ver', 'devoluciones.aprobar'],
        ];

        foreach ($rolePermisos as $rol => $permisoKeys) {
            foreach ($permisoKeys as $key) {
                if (str_contains($key, '.*')) {
                    // Wildcard — grant all actions for module
                    $modulo = str_replace('.*', '', $key);
                    $permisos = Permiso::where('modulo', $modulo)->get();
                    foreach ($permisos as $permiso) {
                        RolPermiso::firstOrCreate([
                            'empresa_id' => $empresa->id,
                            'rol' => $rol,
                            'permiso_id' => $permiso->id,
                        ], ['concedido' => true]);
                    }
                } else {
                    [$modulo, $accion] = explode('.', $key);
                    $permiso = Permiso::where('modulo', $modulo)->where('accion', $accion)->first();
                    if ($permiso) {
                        RolPermiso::firstOrCreate([
                            'empresa_id' => $empresa->id,
                            'rol' => $rol,
                            'permiso_id' => $permiso->id,
                        ], ['concedido' => true]);
                    }
                }
            }
        }

        echo "  ✓ Seeding complete!\n";
    }
}
