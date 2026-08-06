<?php

use Illuminate\Database\Capsule\Manager as DB;

// Garantía estructural pedida por el dueño del proyecto (2026-08-06): el sistema
// debe poder multiplicar SIEMPRE unidad × (unidades_caja + saldo) sin perder
// cantidad ni romperse, incluso si un producto queda sin parametrizar. La
// columna ya es NOT NULL DEFAULT 1 (nunca puede quedar vacía), pero nada
// impedía que quedara en 0 vía un UPDATE directo o una futura importación —
// y varias consultas SQL usan COALESCE(unidades_caja, 1), que protege contra
// NULL pero no contra un 0 explícito (multiplicar por 0 = pérdida silenciosa
// de cantidad; dividir por 0 = error de base de datos). Verificado 2026-08-06:
// 0 de 809 productos activos tienen hoy 0 o NULL, así que este CHECK no
// afecta ningún dato existente — solo cierra la puerta hacia adelante.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();
        $exists = $pdo->query("SELECT 1 FROM pg_constraint WHERE conname = 'chk_productos_unidades_caja_positivo'")->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE productos ADD CONSTRAINT chk_productos_unidades_caja_positivo CHECK (unidades_caja > 0)");
            echo "  [OK] CHECK unidades_caja > 0 agregado a productos.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE productos DROP CONSTRAINT IF EXISTS chk_productos_unidades_caja_positivo");
    },
];
