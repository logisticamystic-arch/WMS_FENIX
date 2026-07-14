<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $tbl = fn(string $t): bool => (bool)$pdo->query(
            "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='$t')"
        )->fetchColumn();

        // ── clasificaciones_abc_xyz ───────────────────────────────────────────
        if (!$tbl('clasificaciones_abc_xyz')) {
            $pdo->exec("
                CREATE TABLE clasificaciones_abc_xyz (
                    id              BIGSERIAL PRIMARY KEY,
                    empresa_id      BIGINT NOT NULL,
                    sucursal_id     BIGINT NOT NULL,
                    producto_id     BIGINT NOT NULL,
                    clase_abc       VARCHAR(1),
                    clase_xyz       VARCHAR(1),
                    segmento        VARCHAR(3),
                    total_valor     DECIMAL(14,2),
                    total_unidades  DECIMAL(14,2),
                    pct_valor       DECIMAL(7,4),
                    pct_unidades    DECIMAL(7,4),
                    cv_demanda      DECIMAL(7,4),
                    periodos        INTEGER,
                    vigente         BOOLEAN NOT NULL DEFAULT true,
                    calculado_at    TIMESTAMP,
                    created_at      TIMESTAMP,
                    updated_at      TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_abc_xyz_emp_suc ON clasificaciones_abc_xyz (empresa_id, sucursal_id)");
            $pdo->exec("CREATE INDEX idx_abc_xyz_prod ON clasificaciones_abc_xyz (producto_id)");
            $pdo->exec("CREATE INDEX idx_abc_xyz_vigente ON clasificaciones_abc_xyz (empresa_id, sucursal_id, vigente)");
            echo "  [OK] Tabla 'clasificaciones_abc_xyz' creada.\n";
        } else {
            echo "  [SKIP] 'clasificaciones_abc_xyz' ya existe.\n";
        }

        // ── ventas_agregadas_ml ───────────────────────────────────────────────
        if (!$tbl('ventas_agregadas_ml')) {
            $pdo->exec("
                CREATE TABLE ventas_agregadas_ml (
                    id               BIGSERIAL PRIMARY KEY,
                    empresa_id       BIGINT NOT NULL,
                    sucursal_id      BIGINT NOT NULL,
                    producto_id      BIGINT NOT NULL,
                    periodo          DATE NOT NULL,
                    unidades_vendidas DECIMAL(12,2) NOT NULL DEFAULT 0,
                    valor_vendido    DECIMAL(14,2) NOT NULL DEFAULT 0,
                    stock_medio      DECIMAL(12,2),
                    num_pedidos      INTEGER,
                    created_at       TIMESTAMP,
                    updated_at       TIMESTAMP,
                    UNIQUE (empresa_id, sucursal_id, producto_id, periodo)
                )
            ");
            $pdo->exec("CREATE INDEX idx_ventas_ml_emp_suc_prod ON ventas_agregadas_ml (empresa_id, sucursal_id, producto_id)");
            $pdo->exec("CREATE INDEX idx_ventas_ml_periodo ON ventas_agregadas_ml (periodo)");
            echo "  [OK] Tabla 'ventas_agregadas_ml' creada.\n";
        } else {
            echo "  [SKIP] 'ventas_agregadas_ml' ya existe.\n";
        }

        // ── mv_rotacion_productos (vista materializada) ───────────────────────
        try {
            if (!$tbl('mv_rotacion_productos')) {
                $pdo->exec("
                    CREATE MATERIALIZED VIEW mv_rotacion_productos AS
                    SELECT
                        i.empresa_id,
                        i.sucursal_id,
                        i.producto_id,
                        p.nombre  AS producto_nombre,
                        p.codigo_interno AS codigo,
                        c.clase_abc,
                        c.clase_xyz,
                        c.segmento,
                        SUM(i.cantidad) AS stock_actual,
                        NULL::DECIMAL   AS forecast_30d,
                        false::BOOLEAN  AS alerta_quiebre,
                        NULL::INTEGER   AS dias_hasta_quiebre,
                        NULL::DECIMAL   AS stock_seguridad_sug,
                        NULL::DECIMAL   AS punto_reorden_sug,
                        NULL::DECIMAL   AS score_riesgo,
                        NULL::INTEGER   AS dias_cobertura
                    FROM inventarios i
                    JOIN productos p ON p.id = i.producto_id
                    LEFT JOIN clasificaciones_abc_xyz c
                        ON c.producto_id = i.producto_id
                        AND c.empresa_id = i.empresa_id
                        AND c.sucursal_id = i.sucursal_id
                        AND c.vigente = true
                    WHERE i.estado = 'Disponible'
                    GROUP BY i.empresa_id, i.sucursal_id, i.producto_id,
                             p.nombre, p.codigo_interno, c.clase_abc, c.clase_xyz, c.segmento
                ");
                $pdo->exec("CREATE UNIQUE INDEX ON mv_rotacion_productos (empresa_id, sucursal_id, producto_id)");
                echo "  [OK] Vista materializada 'mv_rotacion_productos' creada.\n";
            } else {
                echo "  [SKIP] 'mv_rotacion_productos' ya existe.\n";
            }
        } catch (\Exception $e) {
            echo "  [WARN] Vista materializada no creada: " . $e->getMessage() . "\n";
        }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("DROP MATERIALIZED VIEW IF EXISTS mv_rotacion_productos");
        $pdo->exec("DROP TABLE IF EXISTS ventas_agregadas_ml");
        $pdo->exec("DROP TABLE IF EXISTS clasificaciones_abc_xyz");
    },
];
