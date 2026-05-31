-- database/migrations/2026_05_30_create_aprobaciones_vencimiento.sql
CREATE TABLE IF NOT EXISTS aprobaciones_vencimiento (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id       INT UNSIGNED NOT NULL,
    sucursal_id      INT UNSIGNED NOT NULL,
    producto_id      INT UNSIGNED NOT NULL,
    lote             VARCHAR(100) NOT NULL,
    dias_restantes   INT NOT NULL,
    solicitado_por   INT UNSIGNED NOT NULL,
    aprobado_por     INT UNSIGNED NULL,
    estado           ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    valid_until      DATE NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX idx_aprobaciones_empresa (empresa_id, sucursal_id, estado),
    INDEX idx_aprobaciones_producto (producto_id, lote, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
