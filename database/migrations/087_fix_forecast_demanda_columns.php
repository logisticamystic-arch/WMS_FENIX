<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $col = fn(string $c): bool => (bool)$pdo->query(
            "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='forecast_demanda' AND column_name='$c')"
        )->fetchColumn();

        $adds = [
            'horizonte_dias'      => 'INTEGER NOT NULL DEFAULT 30',
            'demanda_pred'        => 'DECIMAL(12,2)',
            'demanda_std'         => 'DECIMAL(12,2)',
            'banda_inf_80'        => 'DECIMAL(12,2)',
            'banda_sup_80'        => 'DECIMAL(12,2)',
            'banda_inf_95'        => 'DECIMAL(12,2)',
            'banda_sup_95'        => 'DECIMAL(12,2)',
            'mape'                => 'DECIMAL(8,4)',
            'rmse'                => 'DECIMAL(12,4)',
            'score_confianza'     => 'DECIMAL(5,4)',
            'alerta_quiebre'      => 'BOOLEAN NOT NULL DEFAULT false',
            'dias_hasta_quiebre'  => 'INTEGER',
            'stock_seguridad_sug' => 'DECIMAL(12,2)',
            'punto_reorden_sug'   => 'DECIMAL(12,2)',
            'es_vigente'          => 'BOOLEAN NOT NULL DEFAULT true',
            'generado_at'         => 'TIMESTAMP',
        ];

        foreach ($adds as $column => $type) {
            if (!$col($column)) {
                $pdo->exec("ALTER TABLE forecast_demanda ADD COLUMN {$column} {$type}");
                echo "  [OK] +{$column}\n";
            } else {
                echo "  [SKIP] {$column} ya existe\n";
            }
        }

        // Poblar demanda_pred desde cantidad_esperada si existe y demanda_pred está vacía
        $hasCantidad = $col('cantidad_esperada');
        if ($hasCantidad) {
            $pdo->exec("UPDATE forecast_demanda SET demanda_pred = cantidad_esperada WHERE demanda_pred IS NULL AND cantidad_esperada IS NOT NULL");
            echo "  [OK] demanda_pred poblada desde cantidad_esperada\n";
        }
    },
    'down' => function () { /* destructivo — no implementado */ },
];
