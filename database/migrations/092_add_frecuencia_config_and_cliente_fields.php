<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        // ── RUTAS: frecuencia_tipo + frecuencia_config ───────────────────────
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='rutas'")->fetchAll(\PDO::FETCH_COLUMN);

        if (!in_array('frecuencia_tipo', $cols)) {
            $pdo->exec("ALTER TABLE rutas ADD COLUMN frecuencia_tipo VARCHAR(20) DEFAULT 'Diario'");
            echo "  [OK] rutas.frecuencia_tipo agregada.\n";
        } else { echo "  [SKIP] rutas.frecuencia_tipo ya existe.\n"; }

        if (!in_array('frecuencia_config', $cols)) {
            $pdo->exec("ALTER TABLE rutas ADD COLUMN frecuencia_config TEXT DEFAULT NULL");
            echo "  [OK] rutas.frecuencia_config agregada.\n";
        } else { echo "  [SKIP] rutas.frecuencia_config ya existe.\n"; }

        // ── CLIENTES: latitud, longitud, horario ─────────────────────────────
        $cols2 = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='clientes'")->fetchAll(\PDO::FETCH_COLUMN);

        if (!in_array('latitud', $cols2)) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN latitud DECIMAL(10,7) DEFAULT NULL");
            echo "  [OK] clientes.latitud agregada.\n";
        } else { echo "  [SKIP] clientes.latitud ya existe.\n"; }

        if (!in_array('longitud', $cols2)) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN longitud DECIMAL(10,7) DEFAULT NULL");
            echo "  [OK] clientes.longitud agregada.\n";
        } else { echo "  [SKIP] clientes.longitud ya existe.\n"; }

        if (!in_array('horario', $cols2)) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN horario VARCHAR(200) DEFAULT NULL");
            echo "  [OK] clientes.horario agregada.\n";
        } else { echo "  [SKIP] clientes.horario ya existe.\n"; }
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("ALTER TABLE rutas DROP COLUMN IF EXISTS frecuencia_tipo, DROP COLUMN IF EXISTS frecuencia_config");
        $pdo->exec("ALTER TABLE clientes DROP COLUMN IF EXISTS latitud, DROP COLUMN IF EXISTS longitud, DROP COLUMN IF EXISTS horario");
    },
];
