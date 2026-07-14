<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo  = Capsule::connection()->getPdo();
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='clientes'")->fetchAll(\PDO::FETCH_COLUMN);

        if (!in_array('frecuencia_tipo', $cols)) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN frecuencia_tipo VARCHAR(20) DEFAULT 'Diario'");
            echo "  [OK] clientes.frecuencia_tipo agregada.\n";
        } else { echo "  [SKIP] clientes.frecuencia_tipo ya existe.\n"; }

        if (!in_array('frecuencia_config', $cols)) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN frecuencia_config TEXT DEFAULT NULL");
            echo "  [OK] clientes.frecuencia_config agregada.\n";
        } else { echo "  [SKIP] clientes.frecuencia_config ya existe.\n"; }

        if (!in_array('frecuencia', $cols)) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN frecuencia VARCHAR(120) DEFAULT NULL");
            echo "  [OK] clientes.frecuencia agregada.\n";
        } else { echo "  [SKIP] clientes.frecuencia ya existe.\n"; }
    },
    'down' => function () {
        Capsule::connection()->getPdo()->exec(
            "ALTER TABLE clientes DROP COLUMN IF EXISTS frecuencia_tipo, DROP COLUMN IF EXISTS frecuencia_config, DROP COLUMN IF EXISTS frecuencia"
        );
    },
];
