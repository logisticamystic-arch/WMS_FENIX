-- database/migrations/2026_05_30_add_fecha_vencimiento_picking_detalles.sql
ALTER TABLE picking_detalles
    ADD COLUMN IF NOT EXISTS fecha_vencimiento DATE NULL AFTER lote;
