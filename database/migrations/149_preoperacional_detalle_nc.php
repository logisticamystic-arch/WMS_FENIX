<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-09-17): cuando un ítem del preoperacional se
// califica "No Cumple", el sistema debe pedir obligatoriamente el detalle de
// la no conformidad y la acción correctiva, y admitir VARIAS fotografías de
// esa no conformidad puntual (no solo las fotos generales de la inspección
// en preoperacional_fotos, ni la única foto del ítem Temperatura en
// preoperacional_items.foto_url). Se agregan dos columnas de texto al ítem y
// una tabla hija de fotos por ítem, mismo patrón que preoperacional_fotos /
// miscelaneos_fotos (id, *_id FK ON DELETE CASCADE, url, created_at).
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='preoperacional_items'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        if (!in_array('detalle_no_conformidad', $cols, true)) {
            $pdo->exec("ALTER TABLE preoperacional_items ADD COLUMN detalle_no_conformidad TEXT NULL");
            echo "  [OK] Columna detalle_no_conformidad agregada a preoperacional_items.\n";
        }
        if (!in_array('accion_correctiva', $cols, true)) {
            $pdo->exec("ALTER TABLE preoperacional_items ADD COLUMN accion_correctiva TEXT NULL");
            echo "  [OK] Columna accion_correctiva agregada a preoperacional_items.\n";
        }

        $existeTabla = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='preoperacional_item_fotos'")->fetchColumn();
        if (!$existeTabla) {
            $pdo->exec("
                CREATE TABLE preoperacional_item_fotos (
                    id SERIAL PRIMARY KEY,
                    preoperacional_item_id INTEGER NOT NULL REFERENCES preoperacional_items(id) ON DELETE CASCADE,
                    url VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_preop_item_fotos_item ON preoperacional_item_fotos (preoperacional_item_id)");
            echo "  [OK] Tabla preoperacional_item_fotos creada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS preoperacional_item_fotos");
        $pdo->exec("ALTER TABLE preoperacional_items DROP COLUMN IF EXISTS detalle_no_conformidad");
        $pdo->exec("ALTER TABLE preoperacional_items DROP COLUMN IF EXISTS accion_correctiva");
    },
];
