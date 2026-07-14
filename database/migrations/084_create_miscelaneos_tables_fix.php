<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $hasMisc = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='miscelaneos')")->fetchColumn();
        if (!$hasMisc) {
            $pdo->exec("
                CREATE TABLE miscelaneos (
                    id                BIGSERIAL PRIMARY KEY,
                    empresa_id        BIGINT NOT NULL,
                    sucursal_id       BIGINT NOT NULL,
                    numero_recepcion  VARCHAR(30),
                    proveedor         VARCHAR(200) NOT NULL,
                    articulo          VARCHAR(300) NOT NULL,
                    cantidad          DECIMAL(12,2) NOT NULL,
                    unidad_medida     VARCHAR(30) NOT NULL DEFAULT 'UN',
                    observaciones     TEXT,
                    recibido_por      BIGINT,
                    cliente_id        BIGINT,
                    cliente_nombre    VARCHAR(200),
                    despacho_id       BIGINT,
                    estado            VARCHAR(30) NOT NULL DEFAULT 'Recibido',
                    created_at        TIMESTAMP,
                    updated_at        TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_misc_emp_suc_est ON miscelaneos (empresa_id, sucursal_id, estado)");
            $pdo->exec("CREATE INDEX idx_misc_cli_est ON miscelaneos (cliente_id, estado)");
            $pdo->exec("CREATE INDEX idx_misc_despacho ON miscelaneos (despacho_id)");
            echo "  [OK] Tabla 'miscelaneos' creada.\n";
        } else {
            echo "  [SKIP] Tabla 'miscelaneos' ya existe.\n";
        }

        $hasFotos = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='miscelaneo_fotos')")->fetchColumn();
        if (!$hasFotos) {
            $pdo->exec("
                CREATE TABLE miscelaneo_fotos (
                    id             BIGSERIAL PRIMARY KEY,
                    miscelaneo_id  BIGINT NOT NULL REFERENCES miscelaneos(id) ON DELETE CASCADE,
                    url            VARCHAR(500) NOT NULL,
                    created_at     TIMESTAMP,
                    updated_at     TIMESTAMP
                )
            ");
            echo "  [OK] Tabla 'miscelaneo_fotos' creada.\n";
        } else {
            echo "  [SKIP] Tabla 'miscelaneo_fotos' ya existe.\n";
        }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS miscelaneo_fotos");
        $pdo->exec("DROP TABLE IF EXISTS miscelaneos");
    },
];
