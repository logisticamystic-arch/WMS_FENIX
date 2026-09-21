<?php

use Illuminate\Database\Capsule\Manager as DB;

// Integración YMS (sistema separado de entrega en punto de venta, MySQL,
// hosting propio): el WMS empuja (push) el pedido certificado hacia el YMS
// vía scripts/yms_push_pedidos.php, y el YMS reporta de vuelta entrega/
// devolución vía TmsController::webhook() (eventos ENTREGA_CONFIRMADA /
// DEVOLUCION_TMS). yms_sync_status/yms_sync_at marcan qué pedidos ya se
// notificaron; entregas_ruta guarda lo que antes no existía en ningún lado:
// firma y tracking de tiempos por pedido individual en el punto de venta.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $tieneSyncStatus = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='orden_pickings' AND column_name='yms_sync_status'")->fetchColumn();
        if (!$tieneSyncStatus) {
            $pdo->exec("ALTER TABLE orden_pickings ADD COLUMN yms_sync_status VARCHAR(20)");
            $pdo->exec("ALTER TABLE orden_pickings ADD COLUMN yms_sync_at TIMESTAMP NULL");
            $pdo->exec("CREATE INDEX idx_orden_pickings_yms_sync ON orden_pickings (yms_sync_status) WHERE yms_sync_status IS DISTINCT FROM 'enviado'");
            echo "  [OK] orden_pickings.yms_sync_status/yms_sync_at agregadas.\n";
        }

        $existeEntregasRuta = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name='entregas_ruta'")->fetchColumn();
        if (!$existeEntregasRuta) {
            $pdo->exec("
                CREATE TABLE entregas_ruta (
                    id SERIAL PRIMARY KEY,
                    empresa_id BIGINT NOT NULL,
                    orden_picking_id BIGINT NOT NULL REFERENCES orden_pickings(id),
                    despacho_id BIGINT,
                    hora_llegada TIMESTAMP,
                    hora_inicio_certificacion TIMESTAMP,
                    hora_fin TIMESTAMP,
                    firma TEXT,
                    auxiliar_nombre_ruta VARCHAR(150),
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX idx_entregas_ruta_orden ON entregas_ruta (orden_picking_id)");
            $pdo->exec("CREATE INDEX idx_entregas_ruta_empresa ON entregas_ruta (empresa_id)");
            echo "  [OK] Tabla entregas_ruta creada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS entregas_ruta");
        $pdo->exec("ALTER TABLE orden_pickings DROP COLUMN IF EXISTS yms_sync_status");
        $pdo->exec("ALTER TABLE orden_pickings DROP COLUMN IF EXISTS yms_sync_at");
    },
];
