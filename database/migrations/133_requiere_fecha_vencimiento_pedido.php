<?php

use Illuminate\Database\Capsule\Manager as DB;

// Pedido urgente del dueño del proyecto (2026-08-17): un Administrador debe
// poder, desde escritorio, forzar o desactivar por PEDIDO puntual la exigencia
// de fecha de vencimiento al separar — hasta ahora esa exigencia dependía
// únicamente de si el producto controla_vencimiento (ver 2026-08-15,
// confirmarConsolidado()/confirmLine()).
//
// Columna tri-estado, nullable a propósito:
//   NULL  = sin anulación — se usa el comportamiento por defecto (el producto
//           decide, igual que hasta ahora).
//   TRUE  = forzar SIEMPRE la fecha en este pedido, aunque el producto no la controle.
//   FALSE = NUNCA exigirla en este pedido, aunque el producto sí la controle.
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();
        $col = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='orden_pickings' AND column_name='requiere_fecha_vencimiento'")->fetchColumn();
        if (!$col) {
            $pdo->exec("ALTER TABLE orden_pickings ADD COLUMN requiere_fecha_vencimiento BOOLEAN DEFAULT NULL");
            echo "  [OK] Columna orden_pickings.requiere_fecha_vencimiento agregada.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE orden_pickings DROP COLUMN IF EXISTS requiere_fecha_vencimiento");
    },
];
