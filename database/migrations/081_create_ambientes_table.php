<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $exists = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name='ambientes')")->fetchColumn();
        if (!$exists) {
            $pdo->exec("
                CREATE TABLE ambientes (
                    id BIGSERIAL PRIMARY KEY,
                    empresa_id BIGINT NOT NULL,
                    codigo VARCHAR(30) NOT NULL,
                    descripcion VARCHAR(100),
                    icono VARCHAR(30),
                    color VARCHAR(20),
                    activo BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL,
                    UNIQUE(empresa_id, codigo)
                )
            ");
            echo "  [OK] Tabla 'ambientes' creada.\n";
        }

        $hasCol = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='productos' AND column_name='ambiente_id')")->fetchColumn();
        if (!$hasCol) {
            $pdo->exec("ALTER TABLE productos ADD COLUMN ambiente_id BIGINT NULL");
            echo "  [OK] Columna 'ambiente_id' agregada a productos.\n";
        }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("ALTER TABLE productos DROP COLUMN IF EXISTS ambiente_id");
        $pdo->exec("DROP TABLE IF EXISTS ambientes");
    },
];
