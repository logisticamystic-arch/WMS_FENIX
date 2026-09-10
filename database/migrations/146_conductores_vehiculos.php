<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-09-04): parametrizar conductores y vehículos como
// maestros propios (antes eran texto libre en preoperacionales.conductor /
// preoperacionales.vehiculo) para poder seleccionarlos desde una lista en vez
// de escribirlos a mano, empezando por el módulo Preoperacional móvil.
// Mismo patrón que `rutas` (empresa_id, nombre, activo) — no se scoping por
// sucursal porque el mismo conductor/vehículo puede circular entre sucursales
// de una misma empresa. `preoperacionales.conductor`/`.vehiculo` siguen siendo
// VARCHAR (no FK): se guarda el valor elegido, sin forzar migración de datos
// históricos ni romper si luego se desactiva/borra el registro del maestro.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $existeConductores = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='conductores'")->fetchColumn();
        if (!$existeConductores) {
            $pdo->exec("
                CREATE TABLE conductores (
                    id SERIAL PRIMARY KEY,
                    empresa_id BIGINT NOT NULL,
                    nombre VARCHAR(150) NOT NULL,
                    documento VARCHAR(30),
                    telefono VARCHAR(30),
                    activo BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_conductores_empresa ON conductores (empresa_id)");
            echo "  [OK] Tabla conductores creada.\n";
        }

        $existeVehiculos = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='vehiculos'")->fetchColumn();
        if (!$existeVehiculos) {
            $pdo->exec("
                CREATE TABLE vehiculos (
                    id SERIAL PRIMARY KEY,
                    empresa_id BIGINT NOT NULL,
                    placa VARCHAR(20) NOT NULL,
                    tipo VARCHAR(50),
                    activo BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_vehiculos_empresa ON vehiculos (empresa_id)");
            $pdo->exec("CREATE UNIQUE INDEX idx_vehiculos_empresa_placa ON vehiculos (empresa_id, placa)");
            echo "  [OK] Tabla vehiculos creada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS vehiculos");
        $pdo->exec("DROP TABLE IF EXISTS conductores");
    },
];
