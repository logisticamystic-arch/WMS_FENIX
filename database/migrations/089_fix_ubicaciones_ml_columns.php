<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $col = fn(string $c): bool => (bool)$pdo->query(
            "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='ubicaciones' AND column_name='$c')"
        )->fetchColumn();

        $adds = [
            'activa'           => 'BOOLEAN NOT NULL DEFAULT true',
            'ocupacion_pct'    => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
            'capacidad_kg'     => 'DECIMAL(10,2)',
            'capacidad_m3'     => 'DECIMAL(10,4)',
            'distancia_muelle' => 'INTEGER',
            'accesibilidad'    => 'SMALLINT DEFAULT 3',
            'estanteria'       => 'VARCHAR(20)',
        ];

        foreach ($adds as $column => $type) {
            if (!$col($column)) {
                $pdo->exec("ALTER TABLE ubicaciones ADD COLUMN {$column} {$type}");
                echo "  [OK] +{$column}\n";
            } else {
                echo "  [SKIP] {$column} ya existe\n";
            }
        }

        // Poblar activa desde activo (activo puede ser SMALLINT o BOOLEAN)
        if ($col('activo') && $col('activa')) {
            $pdo->exec("UPDATE ubicaciones SET activa = CASE WHEN activo::text IN ('1','true','t') THEN true ELSE false END");
            echo "  [OK] activa poblada desde activo\n";
        }

        // Poblar capacidad_m3 desde m3
        if ($col('m3') && $col('capacidad_m3')) {
            $pdo->exec("UPDATE ubicaciones SET capacidad_m3 = m3 WHERE capacidad_m3 IS NULL AND m3 IS NOT NULL");
            echo "  [OK] capacidad_m3 poblada desde m3\n";
        }

        // Poblar estanteria desde modulo
        if ($col('modulo') && $col('estanteria')) {
            $pdo->exec("UPDATE ubicaciones SET estanteria = modulo WHERE estanteria IS NULL AND modulo IS NOT NULL");
            echo "  [OK] estanteria poblada desde modulo\n";
        }
    },
    'down' => function () { /* destructivo — no implementado */ },
];
