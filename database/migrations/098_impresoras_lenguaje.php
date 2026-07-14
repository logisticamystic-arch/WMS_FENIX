<?php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $pdo   = Capsule::connection()->getPdo();
        $isPg  = Capsule::connection()->getDriverName() === 'pgsql';

        if ($isPg) {
            $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='impresoras'")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('lenguaje', $cols)) {
                $pdo->exec("ALTER TABLE impresoras ADD COLUMN lenguaje VARCHAR(20) NOT NULL DEFAULT 'ZPL'");
            }
            // Migrar registros que tenían tipo='TSC' al nuevo campo
            $pdo->exec("UPDATE impresoras SET lenguaje='TSC', tipo='General' WHERE tipo='TSC'");
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM impresoras")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('lenguaje', $cols)) {
                $pdo->exec("ALTER TABLE impresoras ADD COLUMN lenguaje VARCHAR(20) NOT NULL DEFAULT 'ZPL'");
            }
            $pdo->exec("UPDATE impresoras SET lenguaje='TSC', tipo='General' WHERE tipo='TSC'");
        }
    },
    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasColumn('impresoras', 'lenguaje')) {
            $schema->table('impresoras', fn($t) => $t->dropColumn('lenguaje'));
        }
    },
];
