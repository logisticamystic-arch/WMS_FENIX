<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo = Capsule::connection()->getPdo();

        $stmt = $pdo->query("
            SELECT character_maximum_length
            FROM information_schema.columns
            WHERE table_name = 'ubicaciones' AND column_name = 'zona'
        ");
        $currentLen = $stmt->fetchColumn();

        if ($currentLen !== false && (int)$currentLen < 50) {
            $pdo->exec('ALTER TABLE ubicaciones ALTER COLUMN zona TYPE VARCHAR(50)');
            echo "  [OK] zona ampliada a VARCHAR(50) (era VARCHAR({$currentLen}))\n";
        } else {
            echo "  [--] zona ya es VARCHAR(50) o mayor\n";
        }

        $stmt2 = $pdo->query("
            SELECT character_maximum_length
            FROM information_schema.columns
            WHERE table_name = 'ubicaciones' AND column_name = 'codigo'
        ");
        $currentLenCodigo = $stmt2->fetchColumn();

        if ($currentLenCodigo !== false && (int)$currentLenCodigo < 80) {
            $pdo->exec('ALTER TABLE ubicaciones ALTER COLUMN codigo TYPE VARCHAR(80)');
            echo "  [OK] codigo ampliado a VARCHAR(80) (era VARCHAR({$currentLenCodigo}))\n";
        } else {
            echo "  [--] codigo ya es VARCHAR(80) o mayor\n";
        }
    },
    'down' => function () {
        // No revertir — reducir el tamaño podría truncar datos existentes
    },
];
