<?php
// database/migrations/140_fix_devoluciones_estado_check.php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // 1. Eliminar constraints de check antiguas para estado
        try {
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_estado_check");
        } catch (\Exception $e) {}

        try {
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_estado");
        } catch (\Exception $e) {}

        // Re-crear la restricción CHECK permitiendo todos los estados válidos
        try {
            Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_estado CHECK (estado IN ('Borrador', 'Pendiente', 'PendienteAprobacion', 'Aprobada', 'Procesada', 'Rechazada', 'Anulada'))");
        } catch (\Exception $e) {}

        // 2. Eliminar constraints de check antiguas para tipo
        try {
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_tipo_check");
        } catch (\Exception $e) {}

        try {
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_tipo");
        } catch (\Exception $e) {}

        // Re-crear la restricción CHECK permitiendo todos los tipos válidos
        try {
            Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_tipo CHECK (tipo IN ('AProveedorAveria', 'AProveedorVencido', 'ReingresoBuenEstado', 'cliente', 'proveedor', 'interna'))");
        } catch (\Exception $e) {}
    },

    'down' => function () {}
];
