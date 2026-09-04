<?php

use Illuminate\Database\Capsule\Manager as DB;

// Pedido del dueño del proyecto (2026-08-15): el sistema debe respetar SIEMPRE
// la rotación FIFO/FEFO al separar. Hoy el auxiliar puede elegir cualquier
// ubicación alternativa a la sugerida (la de fecha de vencimiento más próxima)
// sin dar ninguna explicación. En vez de bloquear por completo (podría trabar
// el picking si la ubicación FEFO tiene un problema físico puntual), se exige
// una causal estructurada — parametrizable, no texto libre — cuando el
// auxiliar no usa la ubicación sugerida.
//
// Tabla separada de `causales_novedad` a propósito: esa tabla alimenta
// `afecta_nivel_servicio` y varios reportes/KPIs de "por qué no se entregó lo
// pedido" (Dashboard TV, picking_faltantes) — mezclar ahí motivos de rotación
// de ubicación (que no son faltantes de mercancía) contaminaría esas métricas.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        if (!DB::schema()->hasTable('causales_fifo')) {
            $pdo->exec("
                CREATE TABLE causales_fifo (
                    id          SERIAL PRIMARY KEY,
                    empresa_id  BIGINT NOT NULL,
                    nombre      VARCHAR(150) NOT NULL,
                    activo      BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ");
            $pdo->exec("CREATE INDEX idx_causales_fifo_empresa ON causales_fifo (empresa_id)");
            echo "  [OK] Tabla causales_fifo creada.\n";
        }

        $nombres = [
            'Mala Rotación',
            'Error en Ubicación',
            'Mercancía Dañada',
            'Mercancía Vencida',
            'Ubicación no Existe',
            'Ubicación sin Inventario',
        ];

        $empresas = $pdo->query("SELECT id FROM empresas")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($empresas as $empresaId) {
            foreach ($nombres as $nombre) {
                $stmt = $pdo->prepare("SELECT 1 FROM causales_fifo WHERE empresa_id = ? AND nombre = ?");
                $stmt->execute([$empresaId, $nombre]);
                if (!$stmt->fetchColumn()) {
                    $ins = $pdo->prepare("INSERT INTO causales_fifo (empresa_id, nombre) VALUES (?, ?)");
                    $ins->execute([$empresaId, $nombre]);
                }
            }
        }
        echo "  [OK] Causales FIFO sembradas.\n";

        $col = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='picking_detalles' AND column_name='causal_fifo_id'")->fetchColumn();
        if (!$col) {
            $pdo->exec("ALTER TABLE picking_detalles ADD COLUMN causal_fifo_id INTEGER REFERENCES causales_fifo(id) ON DELETE SET NULL");
            $pdo->exec("CREATE INDEX idx_picking_detalles_causal_fifo ON picking_detalles (causal_fifo_id)");
            echo "  [OK] Columna picking_detalles.causal_fifo_id agregada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE picking_detalles DROP COLUMN IF EXISTS causal_fifo_id");
        $pdo->exec("DROP TABLE IF EXISTS causales_fifo");
    },
];
