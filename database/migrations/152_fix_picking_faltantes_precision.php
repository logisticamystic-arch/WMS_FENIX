<?php

use Illuminate\Database\Capsule\Manager as DB;

// Bug real reportado 2026-09-17: referencia "I YOGURT GRIEGO NATURAL X 4000 GR"
// (unidades_caja = factor_udm = 4000) — se solicitó 1 caja (4000 und), se
// separaron 3500 und, el faltante real es 500 und, pero Agotados mostraba 480.
//
// Causa raíz (PickingController::confirmarConsolidado, ~línea 5517-5535):
// el faltante se calcula en UNIDADES y se convierte a CAJAS para guardarlo
// (`$faltanteCajas = $faltanteFisicoUnd / $upcConf`), pero la columna
// picking_faltantes.cantidad_faltante era NUMERIC(12,2) — solo 2 decimales.
// Para productos con upc alto, 0.01 caja ya representa decenas o cientos de
// unidades: con upc=4000, 0.125 cajas (=500 und exactas) se guardaba
// truncado a 0.12 (=480 und), perdiendo 20 unidades reales en el redondeo.
// Se amplía a NUMERIC(12,4), igual que productos.factor_udm (la otra columna
// de este mismo tipo de valor — fracción de caja — en el esquema), lo que
// deja el peor caso de redondeo en ~1 unidad incluso para el upc más alto
// del catálogo (20000).
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $col = $pdo->query("SELECT numeric_scale FROM information_schema.columns WHERE table_name='picking_faltantes' AND column_name='cantidad_faltante'")->fetchColumn();
        if ((int)$col !== 4) {
            $pdo->exec("ALTER TABLE picking_faltantes ALTER COLUMN cantidad_faltante TYPE NUMERIC(12,4)");
            echo "  [OK] picking_faltantes.cantidad_faltante ampliada a NUMERIC(12,4).\n";
        }

        // Corrige el registro real afectado (no se hace backfill masivo de
        // histórico — solo el caso puntual reportado y verificado).
        $fix = $pdo->prepare("
            UPDATE picking_faltantes
            SET cantidad_faltante = 0.1250, updated_at = NOW()
            WHERE id = 5570 AND producto_id = 5936 AND cantidad_faltante = 0.12
        ");
        $fix->execute();
        if ($fix->rowCount() > 0) {
            echo "  [OK] Corregido picking_faltantes #5570 (I YOGURT GRIEGO): 0.12 -> 0.1250 cajas (480 -> 500 und).\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE picking_faltantes ALTER COLUMN cantidad_faltante TYPE NUMERIC(12,2)");
    },
];
