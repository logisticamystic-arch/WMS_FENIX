<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $exists = $pdo->query("SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='cargue_inicial_lineas')")->fetchColumn();
        if ($exists) { echo "  [SKIP] cargue_inicial_lineas ya existe.\n"; return; }

        $pdo->exec("
            CREATE TABLE cargue_inicial_lineas (
                id               BIGSERIAL PRIMARY KEY,
                empresa_id       BIGINT NOT NULL,
                sucursal_id      BIGINT NOT NULL,
                producto_id      BIGINT NOT NULL,
                ubicacion_id     BIGINT,
                ubicacion_codigo VARCHAR(100),
                lote             VARCHAR(100),
                fecha_vencimiento DATE,
                cantidad_cajas   INTEGER NOT NULL DEFAULT 0,
                saldos           DECIMAL(10,3) NOT NULL DEFAULT 0,
                und_total        DECIMAL(12,3) NOT NULL,
                estado           VARCHAR(20)  NOT NULL DEFAULT 'Pendiente',
                creado_por       BIGINT,
                aprobado_por     BIGINT,
                aprobado_at      TIMESTAMP,
                motivo_rechazo   VARCHAR(300),
                created_at       TIMESTAMP,
                updated_at       TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX idx_cil_emp_suc_estado ON cargue_inicial_lineas (empresa_id, sucursal_id, estado)");
        $pdo->exec("CREATE INDEX idx_cil_prod ON cargue_inicial_lineas (producto_id)");
        echo "  [OK] Tabla 'cargue_inicial_lineas' creada.\n";
    },
    'down' => function () {
        Capsule::connection()->getPdo()->exec("DROP TABLE IF EXISTS cargue_inicial_lineas");
    },
];
