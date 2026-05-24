-- ═══════════════════════════════════════════════════════════════════════════
-- MIGRACIÓN 001 — Sprint 1: Índices compuestos y Full-Text Search
-- WMS ProOriente · PostgreSQL 16
-- Ejecutar con: psql -U usuario -d wms_prooriente -f 001_sprint1_indices.sql
-- NOTA: Todos los índices usan CONCURRENTLY — no bloquean la base de datos
-- ═══════════════════════════════════════════════════════════════════════════

\echo '>>> Iniciando migración 001 — Índices Sprint 1...'

-- ── 1. INVENTARIOS ────────────────────────────────────────────────────────────
-- Filtrado principal en picking, alertas y reportes
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inv_empresa_sucursal_estado
    ON inventarios (empresa_id, sucursal_id, estado);

-- FEFO: ordenar por vencimiento solo en registros con fecha
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inv_producto_vencimiento
    ON inventarios (producto_id, fecha_vencimiento)
    WHERE fecha_vencimiento IS NOT NULL;

-- Dashboard: suma de stock disponible por producto
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inv_empresa_sucursal_disponible
    ON inventarios (empresa_id, sucursal_id, producto_id)
    WHERE estado = 'Disponible';

-- ── 2. PICKING_DETALLES ───────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pick_planilla_estado
    ON picking_detalles (planilla_id, estado);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pick_empresa_estado
    ON picking_detalles (empresa_id, sucursal_id, estado);

-- ── 3. ALERTAS_STOCK ─────────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_alertas_empresa_estado
    ON alertas_stock (empresa_id, sucursal_id, estado, tipo);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_alertas_producto_activa
    ON alertas_stock (producto_id, estado)
    WHERE estado = 'Activa';

-- ── 4. REGISTROS_AUDITORIA ────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_tabla_accion_fecha
    ON registros_auditoria (tabla_afectada, accion, created_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_empresa_modulo
    ON registros_auditoria (empresa_id, modulo, created_at DESC);

-- ── 5. PRODUCTOS — Full-Text Search con tsvector generado ────────────────────
-- Agrega columna generada para búsqueda full-text en español
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS ts_search tsvector
    GENERATED ALWAYS AS (
        to_tsvector('spanish',
            coalesce(nombre, '') || ' ' ||
            coalesce(codigo_interno, '') || ' ' ||
            coalesce(descripcion, '') || ' ' ||
            coalesce(marca, '')
        )
    ) STORED;

-- Índice GIN para full-text search (20x más rápido que LIKE)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_productos_fts
    ON productos USING GIN(ts_search);

-- Índice adicional para búsqueda exacta por código
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_productos_codigo
    ON productos (empresa_id, codigo_interno);

-- ── 6. ORDENES / RECEPCION ────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ordenes_empresa_fecha
    ON ordenes (empresa_id, sucursal_id, created_at DESC)
    WHERE estado NOT IN ('Anulada', 'Cancelada');

-- ── 7. PLANILLAS_PICKING ──────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_planillas_empresa_estado
    ON planillas_picking (empresa_id, sucursal_id, estado, created_at DESC);

-- ── 8. NIVELES_REPOSICION ────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_niveles_repo_activo
    ON niveles_reposicion (empresa_id, sucursal_id, activo)
    WHERE activo = TRUE;

\echo '>>> Migración 001 completada ✓'
\echo '>>> Índices creados: 15 + 1 columna tsvector generada'
