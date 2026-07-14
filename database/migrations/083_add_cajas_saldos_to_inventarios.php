<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $hasCantidadCajas = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='inventarios' AND column_name='cantidad_cajas')")->fetchColumn();
        if (!$hasCantidadCajas) {
            $pdo->exec("ALTER TABLE inventarios ADD COLUMN cantidad_cajas INTEGER NULL DEFAULT NULL");
            echo "  [OK] Columna 'cantidad_cajas' agregada a inventarios.\n";
        } else {
            echo "  [SKIP] Columna 'cantidad_cajas' ya existe en inventarios.\n";
        }

        $hasSaldos = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='inventarios' AND column_name='saldos')")->fetchColumn();
        if (!$hasSaldos) {
            $pdo->exec("ALTER TABLE inventarios ADD COLUMN saldos DECIMAL(10,2) NULL DEFAULT 0.00");
            echo "  [OK] Columna 'saldos' agregada a inventarios.\n";
        } else {
            echo "  [SKIP] Columna 'saldos' ya existe en inventarios.\n";
        }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("ALTER TABLE inventarios DROP COLUMN IF EXISTS cantidad_cajas");
        $pdo->exec("ALTER TABLE inventarios DROP COLUMN IF EXISTS saldos");
    },
];
