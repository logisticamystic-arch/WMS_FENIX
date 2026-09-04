<?php

use Illuminate\Database\Capsule\Manager as DB;

// Pedido urgente del dueño del proyecto (2026-08-17): visibilizar en el módulo
// de Faltantes las líneas que un auxiliar separó físicamente al 100% pero para
// las cuales el sistema no tenía inventario real que descontar (o solo parcial)
// — ver auditoría de descuento de inventario en picking del mismo día.
//
// area_responsable='Operaciones' y por lo tanto afecta_nivel_servicio=false
// (ver CausalesController::store()): el cliente SÍ recibió lo solicitado, esto
// es una discrepancia interna de inventario, no una falla de servicio.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();
        $empresas = $pdo->query("SELECT id FROM empresas")->fetchAll(\PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare(
            "SELECT 1 FROM causales_novedad WHERE empresa_id = ? AND nombre = ?"
        );
        $insert = $pdo->prepare(
            "INSERT INTO causales_novedad (empresa_id, nombre, area_responsable, afecta_nivel_servicio, activo, created_at, updated_at)
             VALUES (?, ?, 'Operaciones', false, true, NOW(), NOW())"
        );
        foreach ($empresas as $empresaId) {
            $stmt->execute([$empresaId, 'SEPARADO SIN UBICACION/INVENTARIO']);
            if (!$stmt->fetchColumn()) {
                $insert->execute([$empresaId, 'SEPARADO SIN UBICACION/INVENTARIO']);
                echo "  [OK] Causal 'SEPARADO SIN UBICACION/INVENTARIO' creada para empresa {$empresaId}.\n";
            }
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DELETE FROM causales_novedad WHERE nombre = 'SEPARADO SIN UBICACION/INVENTARIO'");
    },
];
