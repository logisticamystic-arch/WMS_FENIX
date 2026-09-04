<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-08-23): "que el administrador pueda dar acceso a
// los diferentes módulos tanto para escritorio como para móvil, y pueda
// impedir que un usuario en móvil tenga acceso a X pantalla puntual".
//
// El catálogo de permisos (tabla `permisos`) ya cubría 8 módulos de escritorio
// (almacenamiento, despacho, devoluciones, inventario, maestros, picking,
// recepcion, reportes) pero le faltaban el resto de módulos reales del sidebar,
// y NUNCA tuvo ninguna entrada para pantallas del MÓVIL — hoy en día el móvil
// no aplica ningún control de permisos (todas las pantallas del home se
// muestran a cualquier usuario autenticado, sin excepción).
//
// Esta migración solo AGREGA catálogo + lo concede por defecto a todos los
// roles reales (Admin/Supervisor/Auxiliar/Montacarguista/Analista) para NO
// cambiar el comportamiento actual de nadie — el admin decide después, desde
// "Permisos por Usuario", a quién restringírselo puntualmente.
// Excepción deliberada: 'calidad' (módulo nuevo, ver migración de Calidad) NO
// se concede a Auxiliar/Montacarguista/Analista por defecto — es un módulo de
// supervisión/inspección; el admin otorga acceso puntual a las personas de
// calidad vía override individual, sin importar su rol base.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $empresaId = (int)($pdo->query("SELECT id FROM empresas ORDER BY id LIMIT 1")->fetchColumn() ?: 1);

        $desktopAmplio = [
            ['aprobaciones',     'ver', 'Ver Centro de Aprobaciones'],
            ['rotulos',          'ver', 'Ver Rótulos'],
            ['inteligencia',     'ver', 'Ver Inteligencia ML'],
            ['logistica',        'ver', 'Ver Logística Pro'],
            ['consulta-rapida',  'ver', 'Ver Consulta Rápida'],
            ['trazabilidad',     'ver', 'Ver Trazabilidad'],
            ['chat-ia',          'ver', 'Ver Fénix IA'],
            ['preoperacional',   'ver', 'Ver módulo Preoperacional de Vehículos'],
        ];

        $calidad = [
            ['calidad', 'ver',          'Ver módulo de Calidad'],
            ['calidad', 'inspeccionar', 'Marcar registros de Calidad como inspeccionados'],
        ];

        $mobile = [
            ['mobile', 'ro', 'Móvil: Recepción ODC'],
            ['mobile', 'rs', 'Móvil: Recepción sin ODC'],
            ['mobile', 'ub', 'Móvil: Ubicar Mercancía'],
            ['mobile', 'tr', 'Móvil: Traslado'],
            ['mobile', 'pk', 'Móvil: Picking'],
            ['mobile', 'pa', 'Móvil: Packing'],
            ['mobile', 'ce', 'Móvil: Certificar'],
            ['mobile', 'iv', 'Móvil: Inventario'],
            ['mobile', 'au', 'Móvil: Ajuste x Ubicación'],
            ['mobile', 'pr', 'Móvil: Productos'],
            ['mobile', 'ci', 'Móvil: Consultar (IA)'],
            ['mobile', 'dv', 'Móvil: Devolución'],
            ['mobile', 'ms', 'Móvil: Misceláneos'],
            ['mobile', 'tp', 'Móvil: Traspaso'],
            ['mobile', 'pv', 'Móvil: Preoperacional'],
        ];

        $rolesAmplio = ['Admin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista'];
        $rolesCalidad = ['Admin', 'Supervisor'];

        $insertarYConceder = function (array $items, array $roles) use ($pdo, $empresaId) {
            foreach ($items as [$modulo, $accion, $descripcion]) {
                $id = $pdo->query("SELECT id FROM permisos WHERE modulo = " . $pdo->quote($modulo) . " AND accion = " . $pdo->quote($accion))->fetchColumn();
                if (!$id) {
                    $stmt = $pdo->prepare("INSERT INTO permisos (modulo, accion, descripcion) VALUES (?, ?, ?) RETURNING id");
                    $stmt->execute([$modulo, $accion, $descripcion]);
                    $id = $stmt->fetchColumn();
                    echo "  [OK] Permiso creado: {$modulo}.{$accion}\n";
                }
                foreach ($roles as $rol) {
                    $existe = $pdo->query("SELECT 1 FROM rol_permisos WHERE empresa_id = {$empresaId} AND rol = " . $pdo->quote($rol) . " AND permiso_id = {$id}")->fetchColumn();
                    if (!$existe) {
                        $stmt2 = $pdo->prepare("INSERT INTO rol_permisos (empresa_id, rol, permiso_id, concedido, created_at, updated_at) VALUES (?, ?, ?, 1, now(), now())");
                        $stmt2->execute([$empresaId, $rol, $id]);
                    }
                }
            }
        };

        $insertarYConceder($desktopAmplio, $rolesAmplio);
        $insertarYConceder($mobile, $rolesAmplio);
        $insertarYConceder($calidad, $rolesCalidad);

        echo "  [OK] Catálogo de permisos de escritorio/móvil/calidad sembrado y concedido por defecto.\n";
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $modulos = ['aprobaciones','rotulos','inteligencia','logistica','consulta-rapida','trazabilidad','chat-ia','preoperacional','calidad','mobile'];
        $in = "'" . implode("','", $modulos) . "'";
        $pdo->exec("DELETE FROM rol_permisos WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo IN ({$in}))");
        $pdo->exec("DELETE FROM personal_permisos WHERE modulo IN ({$in})");
        $pdo->exec("DELETE FROM permisos WHERE modulo IN ({$in})");
    },
];
