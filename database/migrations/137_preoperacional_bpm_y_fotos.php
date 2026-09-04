<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-08-22): se agrega "BPM Conductor" a la lista fija de
// chequeo (ver PreoperacionalController::ITEMS — el ítem en sí no requiere
// cambio de esquema, es solo una fila más en preoperacional_items) y se agrega
// soporte para VARIAS fotos generales de evidencia de la inspección completa
// (además de la foto puntual del ítem Temperatura, que ya vivía en
// preoperacional_items.foto_url) — tabla hija nueva, mismo patrón que
// miscelaneos_fotos/producto_fotos.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $existe = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='preoperacional_fotos'")->fetchColumn();
        if (!$existe) {
            $pdo->exec("
                CREATE TABLE preoperacional_fotos (
                    id SERIAL PRIMARY KEY,
                    preoperacional_id INTEGER NOT NULL REFERENCES preoperacionales(id) ON DELETE CASCADE,
                    url VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_preoperacional_fotos_padre ON preoperacional_fotos (preoperacional_id)");
            echo "  [OK] Tabla preoperacional_fotos creada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS preoperacional_fotos");
    },
];
