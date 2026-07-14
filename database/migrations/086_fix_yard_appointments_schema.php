<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $col = fn(string $tbl, string $c): bool => (bool)$pdo->query(
            "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='$tbl' AND column_name='$c')"
        )->fetchColumn();

        // ── Renombrar columnas ────────────────────────────────────────────────
        if ($col('yard_appointments', 'fecha_hora_programada') && !$col('yard_appointments', 'fecha_cita')) {
            $pdo->exec("ALTER TABLE yard_appointments RENAME COLUMN fecha_hora_programada TO fecha_cita");
            echo "  [OK] fecha_hora_programada → fecha_cita\n";
        }
        if ($col('yard_appointments', 'fecha_hora_llegada') && !$col('yard_appointments', 'entrada_real')) {
            $pdo->exec("ALTER TABLE yard_appointments RENAME COLUMN fecha_hora_llegada TO entrada_real");
            echo "  [OK] fecha_hora_llegada → entrada_real\n";
        }
        if ($col('yard_appointments', 'fecha_hora_salida') && !$col('yard_appointments', 'salida_real')) {
            $pdo->exec("ALTER TABLE yard_appointments RENAME COLUMN fecha_hora_salida TO salida_real");
            echo "  [OK] fecha_hora_salida → salida_real\n";
        }
        if ($col('yard_appointments', 'muelle_asignado') && !$col('yard_appointments', 'muelle')) {
            $pdo->exec("ALTER TABLE yard_appointments RENAME COLUMN muelle_asignado TO muelle");
            echo "  [OK] muelle_asignado → muelle\n";
        }
        if ($col('yard_appointments', 'conductor_nombre') && !$col('yard_appointments', 'transportista')) {
            $pdo->exec("ALTER TABLE yard_appointments RENAME COLUMN conductor_nombre TO transportista");
            echo "  [OK] conductor_nombre → transportista\n";
        }
        if ($col('yard_appointments', 'tipo_operacion') && !$col('yard_appointments', 'tipo')) {
            $pdo->exec("ALTER TABLE yard_appointments RENAME COLUMN tipo_operacion TO tipo");
            echo "  [OK] tipo_operacion → tipo\n";
        }

        // ── Agregar columnas faltantes ────────────────────────────────────────
        if (!$col('yard_appointments', 'numero')) {
            $pdo->exec("ALTER TABLE yard_appointments ADD COLUMN numero VARCHAR(30)");
            echo "  [OK] +numero\n";
        }
        if (!$col('yard_appointments', 'llegada_est')) {
            $pdo->exec("ALTER TABLE yard_appointments ADD COLUMN llegada_est TIMESTAMP");
            echo "  [OK] +llegada_est\n";
        }
        if (!$col('yard_appointments', 'inicio_op_real')) {
            $pdo->exec("ALTER TABLE yard_appointments ADD COLUMN inicio_op_real TIMESTAMP");
            echo "  [OK] +inicio_op_real\n";
        }
        if (!$col('yard_appointments', 'turnaround_min')) {
            $pdo->exec("ALTER TABLE yard_appointments ADD COLUMN turnaround_min INTEGER");
            echo "  [OK] +turnaround_min\n";
        }
        if (!$col('yard_appointments', 'ocupacion_pct')) {
            $pdo->exec("ALTER TABLE yard_appointments ADD COLUMN ocupacion_pct DECIMAL(5,2) DEFAULT 0");
            echo "  [OK] +ocupacion_pct\n";
        }
    },
    'down' => function () { /* destructivo — no implementado */ },
];
