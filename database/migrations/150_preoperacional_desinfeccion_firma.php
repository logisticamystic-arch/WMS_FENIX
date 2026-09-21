<?php

use Illuminate\Database\Capsule\Manager as DB;

// A pedido explícito (2026-09-17): nuevo ítem "Desinfección Vehículo" en la
// lista de chequeo — si se califica "Cumple", debe registrar el producto
// usado (Ácido Peracético o Amonio Cuaternario). Además, el formulario debe
// cerrar con la firma del conductor (imagen capturada en un canvas, mismo
// patrón de archivo que el resto de fotos del módulo).
return [
    'up' => function () {
        $pdo = DB::connection()->getPdo();

        $colsItems = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='preoperacional_items'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array('producto_desinfeccion', $colsItems, true)) {
            $pdo->exec("ALTER TABLE preoperacional_items ADD COLUMN producto_desinfeccion VARCHAR(50) NULL");
            echo "  [OK] Columna producto_desinfeccion agregada a preoperacional_items.\n";
        }

        $colsPreop = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='preoperacionales'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array('firma_url', $colsPreop, true)) {
            $pdo->exec("ALTER TABLE preoperacionales ADD COLUMN firma_url VARCHAR(255) NULL");
            echo "  [OK] Columna firma_url agregada a preoperacionales.\n";
        }
    },
    'down' => function () {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE preoperacional_items DROP COLUMN IF EXISTS producto_desinfeccion");
        $pdo->exec("ALTER TABLE preoperacionales DROP COLUMN IF EXISTS firma_url");
    },
];
