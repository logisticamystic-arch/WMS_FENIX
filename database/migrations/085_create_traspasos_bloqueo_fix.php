<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        // Columnas bloqueado / bloqueo_motivo en productos
        $hasBloqueado = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='productos' AND column_name='bloqueado')")->fetchColumn();
        if (!$hasBloqueado) {
            $pdo->exec("ALTER TABLE productos ADD COLUMN bloqueado BOOLEAN NOT NULL DEFAULT false");
            $pdo->exec("ALTER TABLE productos ADD COLUMN bloqueo_motivo VARCHAR(300)");
            echo "  [OK] Columnas 'bloqueado' y 'bloqueo_motivo' agregadas a productos.\n";
        } else {
            echo "  [SKIP] Columna 'bloqueado' ya existe en productos.\n";
        }

        // Tabla bloqueo_lotes
        $hasLotes = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='bloqueo_lotes')")->fetchColumn();
        if (!$hasLotes) {
            $pdo->exec("
                CREATE TABLE bloqueo_lotes (
                    id            BIGSERIAL PRIMARY KEY,
                    empresa_id    BIGINT NOT NULL,
                    producto_id   BIGINT NOT NULL,
                    lote          VARCHAR(100) NOT NULL,
                    motivo        VARCHAR(300),
                    bloqueado_por BIGINT,
                    created_at    TIMESTAMP,
                    updated_at    TIMESTAMP,
                    UNIQUE (empresa_id, producto_id, lote)
                )
            ");
            $pdo->exec("CREATE INDEX idx_bloqueo_lotes_emp ON bloqueo_lotes (empresa_id)");
            echo "  [OK] Tabla 'bloqueo_lotes' creada.\n";
        } else {
            echo "  [SKIP] Tabla 'bloqueo_lotes' ya existe.\n";
        }

        // Tabla traspasos
        $hasTraspasos = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='traspasos')")->fetchColumn();
        if (!$hasTraspasos) {
            $pdo->exec("
                CREATE TABLE traspasos (
                    id               BIGSERIAL PRIMARY KEY,
                    empresa_id       BIGINT NOT NULL,
                    sucursal_id      BIGINT NOT NULL,
                    numero_traspaso  VARCHAR(30) NOT NULL,
                    producto_id      BIGINT NOT NULL,
                    ubicacion_id     BIGINT NOT NULL,
                    lote             VARCHAR(100),
                    fecha_vencimiento DATE,
                    cantidad         DECIMAL(12,2) NOT NULL,
                    cliente_id       BIGINT,
                    cliente_nombre   VARCHAR(200),
                    motivo           VARCHAR(100) NOT NULL,
                    observaciones    TEXT,
                    auxiliar_id      BIGINT,
                    estado           VARCHAR(30) NOT NULL DEFAULT 'Completado',
                    created_at       TIMESTAMP,
                    updated_at       TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_traspasos_emp_suc ON traspasos (empresa_id, sucursal_id)");
            $pdo->exec("CREATE INDEX idx_traspasos_cli ON traspasos (cliente_id)");
            $pdo->exec("CREATE INDEX idx_traspasos_prod ON traspasos (producto_id)");
            echo "  [OK] Tabla 'traspasos' creada.\n";
        } else {
            echo "  [SKIP] Tabla 'traspasos' ya existe.\n";
        }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS traspasos");
        $pdo->exec("DROP TABLE IF EXISTS bloqueo_lotes");
        $pdo->exec("ALTER TABLE productos DROP COLUMN IF EXISTS bloqueado");
        $pdo->exec("ALTER TABLE productos DROP COLUMN IF EXISTS bloqueo_motivo");
    },
];
