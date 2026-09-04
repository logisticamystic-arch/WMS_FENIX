<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

echo "Ejecutando actualización de restricciones CHECK en devoluciones..." . PHP_EOL;

try {
    Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_estado_check");
    echo "✔ Constraint devoluciones_estado_check eliminada." . PHP_EOL;
} catch (\Exception $e) { echo "Note: " . $e->getMessage() . PHP_EOL; }

try {
    Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_estado");
    echo "✔ Constraint chk_dev_estado eliminada." . PHP_EOL;
} catch (\Exception $e) { echo "Note: " . $e->getMessage() . PHP_EOL; }

try {
    Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_estado CHECK (estado IN ('Borrador', 'Pendiente', 'PendienteAprobacion', 'Aprobada', 'Procesada', 'Rechazada', 'Anulada'))");
    echo "✔ Restricción chk_dev_estado creada exitosamente." . PHP_EOL;
} catch (\Exception $e) { echo "Error: " . $e->getMessage() . PHP_EOL; }

try {
    Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_tipo_check");
    echo "✔ Constraint devoluciones_tipo_check eliminada." . PHP_EOL;
} catch (\Exception $e) { echo "Note: " . $e->getMessage() . PHP_EOL; }

try {
    Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_tipo");
    echo "✔ Constraint chk_dev_tipo eliminada." . PHP_EOL;
} catch (\Exception $e) { echo "Note: " . $e->getMessage() . PHP_EOL; }

try {
    Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_tipo CHECK (tipo IN ('AProveedorAveria', 'AProveedorVencido', 'ReingresoBuenEstado', 'cliente', 'proveedor', 'interna'))");
    echo "✔ Restricción chk_dev_tipo creada exitosamente." . PHP_EOL;
} catch (\Exception $e) { echo "Error: " . $e->getMessage() . PHP_EOL; }
