-- =====================================================================
-- WMS Prooriente — Migración: Devoluciones con Fotos y bandera ODC
-- Ejecutar en phpMyAdmin o MySQL CLI
-- =====================================================================

-- 1. Agregar columna fotos_json a la tabla devoluciones
ALTER TABLE `devoluciones`
  ADD COLUMN IF NOT EXISTS `fotos_json` TEXT NULL COMMENT 'JSON array con rutas de fotos de evidencia' AFTER `motivo_general`;

-- 2. Agregar columna para fecha y usuario que autorizó la devolución  
ALTER TABLE `devoluciones`
  ADD COLUMN IF NOT EXISTS `autorizado_por` INT NULL AFTER `fotos_json`,
  ADD COLUMN IF NOT EXISTS `fecha_autorizacion` DATETIME NULL AFTER `autorizado_por`,
  ADD COLUMN IF NOT EXISTS `fecha_devolucion` DATE NULL AFTER `fecha_autorizacion`,
  ADD COLUMN IF NOT EXISTS `observaciones` TEXT NULL AFTER `fecha_devolucion`;

-- 3. Agregar bandera tiene_devolucion a ordenes_compra
ALTER TABLE `ordenes_compra`
  ADD COLUMN IF NOT EXISTS `tiene_devolucion` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 si se registró una devolución para esta ODC' AFTER `estado`;

-- Verificar
SELECT 'fotos_json' AS columna, 
       IF(COUNT(*) > 0, 'OK', 'FALTA') AS estado
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'devoluciones'
  AND COLUMN_NAME = 'fotos_json'
UNION ALL
SELECT 'tiene_devolucion', 
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ordenes_compra'
  AND COLUMN_NAME = 'tiene_devolucion';
