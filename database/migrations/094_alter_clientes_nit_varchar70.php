<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("ALTER TABLE clientes ALTER COLUMN nit TYPE VARCHAR(70)");
        echo "  [OK] clientes.nit ampliado a VARCHAR(70).\n";
    },
    'down' => function () {
        Capsule::connection()->getPdo()->exec(
            "ALTER TABLE clientes ALTER COLUMN nit TYPE VARCHAR(30)"
        );
    },
];
