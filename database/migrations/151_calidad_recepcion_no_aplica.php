<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-09-18): la inspección de calidad de Recepción
// (transporte y por producto, Con ODC y Sin ODC) admite un tercer estado
// "No Aplica" (NA) además de Cumple (C) / No Cumple (NC). Las columnas ya
// eran VARCHAR(255) — el obstáculo real eran los CHECK constraints que solo
// permitían C/NC, descubiertos en pruebas reales (SQLSTATE 23514) al guardar
// NA desde el nuevo botón. Se recrean con NA incluido.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $checks = [
            'recepcion_calidad' => ['trans_temperatura', 'trans_limpieza', 'trans_concepto_sanitario', 'trans_carnet_manipulacion'],
            'recepcion_detalle_calidad' => ['olor', 'color', 'textura', 'temperatura', 'empaque', 'rotulado'],
        ];

        foreach ($checks as $table => $cols) {
            foreach ($cols as $col) {
                $constraint = "{$table}_{$col}_check";
                $pdo->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS \"{$constraint}\"");
                $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT \"{$constraint}\" CHECK ({$col} IN ('C', 'NC', 'NA'))");
                echo "  [OK] {$constraint} actualizado con NA.\n";
            }
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();

        $checks = [
            'recepcion_calidad' => ['trans_temperatura', 'trans_limpieza', 'trans_concepto_sanitario', 'trans_carnet_manipulacion'],
            'recepcion_detalle_calidad' => ['olor', 'color', 'textura', 'temperatura', 'empaque', 'rotulado'],
        ];

        foreach ($checks as $table => $cols) {
            foreach ($cols as $col) {
                $constraint = "{$table}_{$col}_check";
                $pdo->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS \"{$constraint}\"");
                $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT \"{$constraint}\" CHECK ({$col} IN ('C', 'NC'))");
            }
        }
    },
];
