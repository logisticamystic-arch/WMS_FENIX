<?php

use Illuminate\Database\Capsule\Manager as DB;

// Movido desde bootstrap.php: corría en cada request en vez de una sola vez.
return new class {
    public function up()
    {
        $pdo = DB::connection()->getPdo();

        $check = $pdo->query("SELECT column_name, character_maximum_length FROM information_schema.columns WHERE table_name='ubicaciones' AND column_name IN ('zona','codigo')");
        foreach ($check->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            if ($col['column_name'] === 'zona' && (int)$col['character_maximum_length'] < 50) {
                $pdo->exec('ALTER TABLE ubicaciones ALTER COLUMN zona TYPE VARCHAR(50)');
            }
            if ($col['column_name'] === 'codigo' && (int)$col['character_maximum_length'] < 80) {
                $pdo->exec('ALTER TABLE ubicaciones ALTER COLUMN codigo TYPE VARCHAR(80)');
            }
        }

        if (DB::schema()->hasTable('ambientes')) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM ambientes")->fetchColumn();
            if ($count === 0) {
                $empresas = $pdo->query("SELECT id FROM empresas LIMIT 10")->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($empresas as $empId) {
                    $stmt = $pdo->prepare("INSERT INTO ambientes (empresa_id, codigo, descripcion, color, activo, created_at, updated_at) VALUES (?,?,?,?,true,NOW(),NOW())");
                    $stmt->execute([$empId, 'SECO', 'Productos temperatura ambiente', '#92400e']);
                    $stmt->execute([$empId, 'REFRIGERADO', 'Productos refrigerados 2-8°C', '#0369a1']);
                    $stmt->execute([$empId, 'CONGELADO', 'Productos congelados -18°C', '#7c3aed']);
                }
            }
        }
    }

    public function down()
    {
        // No-op: ensanchar columnas y sembrar datos por defecto no se revierte.
    }
};
