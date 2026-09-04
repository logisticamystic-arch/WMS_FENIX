<?php

use Illuminate\Database\Capsule\Manager as DB;

// Pedido explícito del dueño del proyecto (2026-08-18):
//
// 1. requiere_escaneo_ubicacion (tri-estado, por PEDIDO — el toggle de escritorio
//    aplica en bloque a todos los pedidos de una misma planilla, pero la columna
//    vive en orden_pickings, igual que requiere_fecha_vencimiento):
//      NULL  = sin anulación — aplica el comportamiento por DEFECTO, que es
//              EXIGIR escaneo (a diferencia de requiere_fecha_vencimiento, cuyo
//              default es "según el producto"). Decisión explícita del dueño.
//      TRUE  = forzar el escaneo (redundante con el default hoy, pero deja el
//              tri-estado listo si el default cambiara en el futuro).
//      FALSE = un supervisor lo desactivó puntualmente para esta planilla.
//
// 2. modo_rotacion: FIFO (por defecto, sale primero lo más próximo a vencer) o
//    LIFO (sale primero lo que le queda MÁS tiempo para vencer — para clientes
//    nacionales que requieren mercancía con fecha larga). Se define al importar/
//    asignar la planilla (PlanillaController::asignar()) y lo consume el motor
//    FEFO consolidado (FefoEngine).
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $colEscaneo = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='orden_pickings' AND column_name='requiere_escaneo_ubicacion'")->fetchColumn();
        if (!$colEscaneo) {
            $pdo->exec("ALTER TABLE orden_pickings ADD COLUMN requiere_escaneo_ubicacion BOOLEAN DEFAULT NULL");
            echo "  [OK] Columna orden_pickings.requiere_escaneo_ubicacion agregada.\n";
        }

        $colRotacion = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='orden_pickings' AND column_name='modo_rotacion'")->fetchColumn();
        if (!$colRotacion) {
            $pdo->exec("ALTER TABLE orden_pickings ADD COLUMN modo_rotacion VARCHAR(4) NOT NULL DEFAULT 'FIFO'");
            $pdo->exec("ALTER TABLE orden_pickings ADD CONSTRAINT chk_orden_pickings_modo_rotacion CHECK (modo_rotacion IN ('FIFO','LIFO'))");
            echo "  [OK] Columna orden_pickings.modo_rotacion agregada (default FIFO).\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE orden_pickings DROP COLUMN IF EXISTS requiere_escaneo_ubicacion");
        $pdo->exec("ALTER TABLE orden_pickings DROP CONSTRAINT IF EXISTS chk_orden_pickings_modo_rotacion");
        $pdo->exec("ALTER TABLE orden_pickings DROP COLUMN IF EXISTS modo_rotacion");
    },
];
