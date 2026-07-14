-- Migración Picking v2: campos para empaque, certificación y agotados
-- Aplicada: 2026-07-01

-- picking_detalles: campos de empaque del archivo de cargue
ALTER TABLE picking_detalles
  ADD COLUMN IF NOT EXISTS unid_pedido_empaque NUMERIC(12,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS unid_pedido_total   NUMERIC(12,2) DEFAULT 0;

-- picking_detalles: campos de certificación (fix bugs en certConfirmar/certFinalizar)
ALTER TABLE picking_detalles
  ADD COLUMN IF NOT EXISTS cantidad_certificada NUMERIC(12,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS estado_certificacion VARCHAR(30)   DEFAULT 'Pendiente';

-- picking_detalles: campo de novedad (ya existía, esta línea es idempotente)
ALTER TABLE picking_detalles
  ADD COLUMN IF NOT EXISTS novedad TEXT;

-- orden_pickings: campos de certificación para certFinalizar
ALTER TABLE orden_pickings
  ADD COLUMN IF NOT EXISTS fecha_certificacion TIMESTAMP,
  ADD COLUMN IF NOT EXISTS certificador_id     BIGINT;
