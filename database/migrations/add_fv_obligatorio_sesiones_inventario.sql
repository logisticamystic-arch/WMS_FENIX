-- Migración: agregar columna fv_obligatorio a sesiones_inventario
-- Ejecutar en pgAdmin o psql antes de usar el módulo actualizado
ALTER TABLE sesiones_inventario
    ADD COLUMN IF NOT EXISTS fv_obligatorio BOOLEAN NOT NULL DEFAULT TRUE;

-- Actualizar sesiones tipo CargueInicial por consistencia
UPDATE sesiones_inventario SET fv_obligatorio = TRUE WHERE tipo = 'CargueInicial';
