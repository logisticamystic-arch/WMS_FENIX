<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito de Camilo (2026-09-28): el cierre de una visita en el
// TMS ahora cuantifica si hubo novedades reportadas (devoluciones_ruta) o no
// — se guarda también aquí (aunque ya es derivable via
// devoluciones.referencia_externa=orden_picking_id) para que el futuro
// dashboard de TMS en escritorio no tenga que recalcularlo por join cada vez.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();
        $cols = ['tiene_novedad' => 'BOOLEAN DEFAULT false', 'total_novedades' => 'INTEGER DEFAULT 0'];
        foreach ($cols as $col => $tipo) {
            $existe = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='entregas_ruta' AND column_name='{$col}'")->fetchColumn();
            if (!$existe) {
                $pdo->exec("ALTER TABLE entregas_ruta ADD COLUMN {$col} {$tipo}");
                echo "  [OK] entregas_ruta.{$col} agregada.\n";
            }
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE entregas_ruta DROP COLUMN IF EXISTS tiene_novedad");
        $pdo->exec("ALTER TABLE entregas_ruta DROP COLUMN IF EXISTS total_novedades");
    },
];
