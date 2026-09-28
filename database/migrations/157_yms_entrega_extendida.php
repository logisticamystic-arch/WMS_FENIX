<?php

use Illuminate\Database\Capsule\Manager as DB;

// Extiende la integración YMS (migración 148) a pedido explícito de Camilo
// (2026-09-27): entrega en punto de venta ahora se organiza por "visita a
// sucursal" (una parada = una fila en la nueva tabla `visitas_sucursal` del
// lado YMS/MySQL, que puede agrupar varios pedidos), con 5 tiempos de
// tracking (llegada/descargue/inicio/fin/salida), geolocalización por evento,
// tiempo de demora calculado, tipo de entrega (Detallada/Consolidada), fotos,
// observaciones y nombre de quien recibe. `entregas_ruta` (lado WMS) recibe
// todo eso vía el mismo webhook ENTREGA_CONFIRMADA, ahora una vez por visita
// en vez de una vez por pedido.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $cols = [
            'hora_descargue'        => 'TIMESTAMP',
            'hora_salida'           => 'TIMESTAMP',
            'tracking_geo'          => 'JSONB',
            'tiempo_demora_minutos' => 'INTEGER',
            'tipo_entrega'          => 'VARCHAR(20)',
            'observaciones'         => 'TEXT',
            'nombre_recibe'         => 'VARCHAR(150)',
            'fotos'                 => 'JSONB',
        ];
        foreach ($cols as $col => $tipo) {
            $existe = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='entregas_ruta' AND column_name='{$col}'")->fetchColumn();
            if (!$existe) {
                $pdo->exec("ALTER TABLE entregas_ruta ADD COLUMN {$col} {$tipo}");
                echo "  [OK] entregas_ruta.{$col} agregada.\n";
            }
        }

        // Causales que Camilo pidió explícitamente para el modo Entrega
        // Consolidada del YMS (rechazo/faltante) — mismo catálogo que ya usa
        // Devoluciones manual, para no duplicar la noción de "causal".
        $nuevasCausales = ['Rechazo en punto de venta', 'Faltante en entrega'];
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            foreach ($nuevasCausales as $causal) {
                $existe = DB::table('causales_devolucion')
                    ->where('empresa_id', $empresaId)->where('causal', $causal)->exists();
                if (!$existe) {
                    DB::table('causales_devolucion')->insert([
                        'empresa_id'  => $empresaId,
                        'causal'      => $causal,
                        'responsable' => 'Cliente',
                        'activo'      => true,
                    ]);
                }
            }
        }
        echo "  [OK] Causales Rechazo/Faltante sembradas para entrega en ruta.\n";
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        foreach (['hora_descargue','hora_salida','tracking_geo','tiempo_demora_minutos','tipo_entrega','observaciones','nombre_recibe','fotos'] as $col) {
            $pdo->exec("ALTER TABLE entregas_ruta DROP COLUMN IF EXISTS {$col}");
        }
    },
];
