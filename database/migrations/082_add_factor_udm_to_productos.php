<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $hasFactorUdm = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='productos' AND column_name='factor_udm')")->fetchColumn();
        if (!$hasFactorUdm) {
            $pdo->exec("ALTER TABLE productos ADD COLUMN factor_udm DECIMAL(12,4) NULL");
            echo "  [OK] Columna 'factor_udm' agregada a productos.\n";
        }

        $hasUnidadContenido = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='productos' AND column_name='unidad_contenido')")->fetchColumn();
        if (!$hasUnidadContenido) {
            $pdo->exec("ALTER TABLE productos ADD COLUMN unidad_contenido VARCHAR(10) NULL");
            echo "  [OK] Columna 'unidad_contenido' agregada a productos.\n";
        }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("ALTER TABLE productos DROP COLUMN IF EXISTS factor_udm");
        $pdo->exec("ALTER TABLE productos DROP COLUMN IF EXISTS unidad_contenido");
    },
];
