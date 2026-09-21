<?php

use Illuminate\Database\Capsule\Manager as DB;

// Catálogo de personal responsable de los movimientos/estados del CRM de
// devoluciones (Camilo, 2026-09-17: "codificar tabla de Auxiliares de calidad
// para que permita seleccionar el personal responsable de los movimientos de
// los estados del CRM"). Se modela como catálogo propio del módulo — igual
// que causales_devolucion y crm_estados_devolucion — porque el responsable de
// calidad no siempre es un usuario con login en `personal` (usuarios del WMS).
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auxiliares_calidad (
                id SERIAL PRIMARY KEY,
                empresa_id INTEGER NOT NULL,
                nombre VARCHAR(150) NOT NULL,
                cargo VARCHAR(100),
                activo BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auxiliares_calidad_empresa ON auxiliares_calidad(empresa_id)");
    },
    'down' => function () {
        DB::connection()->getPdo()->exec("DROP TABLE IF EXISTS auxiliares_calidad");
    },
];
