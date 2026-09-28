<?php
/**
 * Migration 159 — Permiso de menú para el módulo "TMS Entregas" (dashboard
 * de escritorio agregado en esta sesión, 2026-09-29).
 *
 * Causa raíz de "no veo el módulo en escritorio": _filtrarModulosNav() oculta
 * cualquier nm-item[data-module] cuyo hasPermiso(modulo,'ver') sea falso.
 * hasPermiso() solo bypasea para rol literal 'Admin' — SuperAdmin depende de
 * que el catálogo `permisos` tenga la fila (su lista se arma con
 * Permiso::all() en el login, ver AuthController) y cualquier otro rol
 * depende además de un rol_permisos concedido=true. Como el módulo se agregó
 * sin sembrar ninguna fila, quedaba invisible para todo el que no fuera
 * literalmente 'Admin'. Mismo patrón que la migración 156 (canastas).
 */
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();
        $roles = ['Admin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista'];

        $id = $pdo->query("SELECT id FROM permisos WHERE modulo = 'tms' AND accion = 'ver'")->fetchColumn();
        if (!$id) {
            $stmt = $pdo->prepare("INSERT INTO permisos (modulo, accion, descripcion) VALUES ('tms', 'ver', 'TMS Entregas: Dashboard') RETURNING id");
            $stmt->execute();
            $id = $stmt->fetchColumn();
            echo "  [OK] Permiso creado: tms.ver\n";
        }

        $empresas = $pdo->query("SELECT id FROM empresas")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($empresas as $empresaId) {
            foreach ($roles as $rol) {
                $existe = $pdo->query("SELECT 1 FROM rol_permisos WHERE empresa_id = {$empresaId} AND rol = " . $pdo->quote($rol) . " AND permiso_id = {$id}")->fetchColumn();
                if (!$existe) {
                    $stmt2 = $pdo->prepare("INSERT INTO rol_permisos (empresa_id, rol, permiso_id, concedido, created_at, updated_at) VALUES (?, ?, ?, 1, now(), now())");
                    $stmt2->execute([$empresaId, $rol, $id]);
                }
            }
        }
        echo "  [OK] tms.ver concedido para " . count($empresas) . " empresa(s).\n";
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("DELETE FROM rol_permisos WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo = 'tms' AND accion = 'ver')");
        $pdo->exec("DELETE FROM personal_permisos WHERE modulo = 'tms' AND accion = 'ver'");
        $pdo->exec("DELETE FROM permisos WHERE modulo = 'tms' AND accion = 'ver'");
    },
];
