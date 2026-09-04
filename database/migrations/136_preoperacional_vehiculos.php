<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-08-21): nuevo módulo de registro preoperacional de
// vehículos (escritorio + móvil). Encabezado (fecha/vehículo/conductor/ruta +
// observaciones) en preoperacionales, e items de la lista de chequeo fija
// (Cabina, Termo King, etc., calificación C/NC) en preoperacional_items —
// tabla hija en vez de JSON para poder reportar por item (ej. "cuántos NC en
// Termo King este mes"), igual que el resto del sistema (picking_detalles,
// recepcion_detalles, devolucion_detalles).
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $existe = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='preoperacionales'")->fetchColumn();
        if (!$existe) {
            $pdo->exec("
                CREATE TABLE preoperacionales (
                    id SERIAL PRIMARY KEY,
                    empresa_id BIGINT NOT NULL,
                    sucursal_id BIGINT NOT NULL,
                    fecha DATE NOT NULL,
                    vehiculo VARCHAR(50) NOT NULL,
                    conductor VARCHAR(150) NOT NULL,
                    ruta VARCHAR(150),
                    observaciones TEXT,
                    creado_por BIGINT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_preoperacionales_empresa_fecha ON preoperacionales (empresa_id, sucursal_id, fecha)");
            $pdo->exec("CREATE INDEX idx_preoperacionales_vehiculo ON preoperacionales (vehiculo)");
            echo "  [OK] Tabla preoperacionales creada.\n";
        }

        $existeItems = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='preoperacional_items'")->fetchColumn();
        if (!$existeItems) {
            $pdo->exec("
                CREATE TABLE preoperacional_items (
                    id SERIAL PRIMARY KEY,
                    preoperacional_id INTEGER NOT NULL REFERENCES preoperacionales(id) ON DELETE CASCADE,
                    item_clave VARCHAR(40) NOT NULL,
                    item_nombre VARCHAR(100) NOT NULL,
                    calificacion VARCHAR(2) NOT NULL CHECK (calificacion IN ('C','NC')),
                    foto_url VARCHAR(255),
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_preoperacional_items_padre ON preoperacional_items (preoperacional_id)");
            echo "  [OK] Tabla preoperacional_items creada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS preoperacional_items");
        $pdo->exec("DROP TABLE IF EXISTS preoperacionales");
    },
];
