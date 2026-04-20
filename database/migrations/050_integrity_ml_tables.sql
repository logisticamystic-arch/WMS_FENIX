-- =============================================================================
-- Migración 050 — Tablas de Integridad, ML y Rendimiento
-- WMS Prooriente
--
-- Ejecutar en phpMyAdmin o MySQL Workbench sobre la base de datos del WMS.
-- Es segura: usa CREATE TABLE IF NOT EXISTS — no destruye nada si ya existe.
-- =============================================================================

-- ── 1. inventory_guard_log ────────────────────────────────────────────────────
-- Registra cada operación bloqueada por las reglas de integridad (InventoryGuard).
-- Permite auditar quién intentó hacer qué y por qué fue bloqueado.
CREATE TABLE IF NOT EXISTS `inventory_guard_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`     BIGINT UNSIGNED NOT NULL,
  `sucursal_id`    BIGINT UNSIGNED NULL,
  `usuario_id`     BIGINT UNSIGNED NULL,           -- personal.id
  `operacion`      VARCHAR(60)     NOT NULL,        -- picking|traslado|ajuste|recepcion|despacho
  `motivo_bloqueo` VARCHAR(120)    NOT NULL,        -- descripción corta de la regla violada
  `contexto`       JSON            NULL,            -- {producto_id, cantidad, stock_actual, …}
  `endpoint`       VARCHAR(200)    NULL,            -- /api/picking/confirmar-linea
  `ip`             VARCHAR(45)     NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_guard_empresa_op`   (`empresa_id`, `operacion`, `created_at`),
  INDEX `idx_guard_usuario`      (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 2. anomaly_flags ──────────────────────────────────────────────────────────
-- Anomalías detectadas por el motor ML (Z-score + IQR + análisis de frecuencia).
-- Cada registro representa un evento estadísticamente inusual para revisión.
CREATE TABLE IF NOT EXISTS `anomaly_flags` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`      BIGINT UNSIGNED NOT NULL,
  `sucursal_id`     BIGINT UNSIGNED NULL,
  `tipo`            VARCHAR(50)     NOT NULL,               -- inventario|picking|ajuste|traslado
  `severidad`       VARCHAR(20)     NOT NULL DEFAULT 'media', -- baja|media|alta|critica
  `titulo`          VARCHAR(200)    NOT NULL,
  `descripcion`     TEXT            NOT NULL,
  `datos_anomalia`  JSON            NULL,                   -- {producto_id, valor_detectado, z_score, …}
  `estado`          VARCHAR(20)     NOT NULL DEFAULT 'pendiente', -- pendiente|revisado|descartado|confirmado
  `revisado_por`    BIGINT UNSIGNED NULL,
  `revisado_at`     TIMESTAMP       NULL,
  `notas_revision`  TEXT            NULL,
  `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_anomaly_estado`   (`empresa_id`, `estado`, `severidad`, `created_at`),
  INDEX `idx_anomaly_tipo`     (`empresa_id`, `tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 3. expiry_predictions ─────────────────────────────────────────────────────
-- Predicciones de vencimiento por producto+lote generadas por el ML.
-- Se recalcula cada noche. El UNIQUE garantiza UPSERT sin duplicados.
CREATE TABLE IF NOT EXISTS `expiry_predictions` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `empresa_id`        BIGINT UNSIGNED  NOT NULL,
  `sucursal_id`       BIGINT UNSIGNED  NOT NULL,
  `producto_id`       BIGINT UNSIGNED  NOT NULL,
  `lote`              VARCHAR(100)     NULL,
  `fecha_vencimiento` DATE             NOT NULL,
  `dias_para_vencer`  INT              NOT NULL,          -- días desde hoy hasta vencimiento
  `stock_actual`      DECIMAL(12,2)    NOT NULL,
  `consumo_diario`    DECIMAL(10,4)    NOT NULL,          -- promedio EMA 30 días
  `dias_agotamiento`  DECIMAL(8,2)     NOT NULL,          -- stock_actual / consumo_diario
  `unidades_en_riesgo` DECIMAL(12,2)   NOT NULL DEFAULT 0, -- stock que vencerá sin venderse
  `nivel_riesgo`      VARCHAR(20)      NOT NULL DEFAULT 'bajo', -- bajo|medio|alto|critico
  `confianza`         DECIMAL(5,4)     NOT NULL DEFAULT 0.5000, -- 0.0–1.0
  `recomendaciones`   JSON             NULL,              -- array de strings con acciones sugeridas
  `serie_consumo`     JSON             NULL,              -- últimos 30 días (para sparklines)
  `calculado_at`      TIMESTAMP        NULL,
  `created_at`        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exp_pred_unique`    (`empresa_id`, `sucursal_id`, `producto_id`, `lote`, `fecha_vencimiento`),
  INDEX `idx_exp_riesgo`          (`empresa_id`, `nivel_riesgo`, `dias_para_vencer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 4. performance_metrics ────────────────────────────────────────────────────
-- Registra requests que superan el umbral de tiempo (por defecto 1500ms).
-- Permite identificar endpoints lentos y correlacionar con carga de usuarios.
CREATE TABLE IF NOT EXISTS `performance_metrics` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`       BIGINT UNSIGNED NULL,
  `metodo`           VARCHAR(10)     NOT NULL,    -- GET|POST|PUT|DELETE
  `endpoint`         VARCHAR(250)    NOT NULL,    -- /api/inventario/stock?sucursal_id=2
  `endpoint_pattern` VARCHAR(200)    NULL,        -- /api/inventario/{tipo}
  `duracion_ms`      INT             NOT NULL,    -- milisegundos totales del request
  `status_code`      INT             NOT NULL DEFAULT 200,
  `memoria_kb`       INT             NULL,        -- memoria pico PHP (memory_get_peak_usage)
  `ip`               VARCHAR(45)     NULL,
  `usuario_id`       BIGINT UNSIGNED NULL,
  `slow_query_hint`  TEXT            NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_perf_pattern`  (`endpoint_pattern`, `created_at`),
  INDEX `idx_perf_duracion` (`duracion_ms`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- FIN — 4 tablas creadas correctamente
-- =============================================================================
SELECT 'Migración 050 completada — 4 tablas listas.' AS resultado;
