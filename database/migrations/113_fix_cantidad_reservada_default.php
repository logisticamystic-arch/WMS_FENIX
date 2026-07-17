<?php
// database/migrations/113_fix_cantidad_reservada_default.php
//
// La migración 074_alter_cantidades_decimales.php cambió `cantidad_reservada` a
// decimal(12,2) con $table->decimal(...)->change() sin volver a declarar
// ->default(0) — Doctrine DBAL descarta el DEFAULT existente en un ALTER COLUMN
// cuando no se redeclara explícitamente. La columna quedó NOT NULL sin default,
// por lo que cualquier INSERT que omitiera 'cantidad_reservada' (varios puntos
// del código nunca la incluyeron, confiando en el default original) fallaba con
// SQLSTATE[23502]. Esta migración restaura el DEFAULT 0 a nivel de esquema.
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $isPg = Capsule::connection()->getDriverName() === 'pgsql';
        if ($isPg) {
            Capsule::statement("ALTER TABLE inventarios ALTER COLUMN cantidad_reservada SET DEFAULT 0");
            Capsule::statement("UPDATE inventarios SET cantidad_reservada = 0 WHERE cantidad_reservada IS NULL");
        } else {
            Capsule::statement("ALTER TABLE inventarios MODIFY cantidad_reservada DECIMAL(12,2) NOT NULL DEFAULT 0");
        }
    },
    'down' => function () {
        $isPg = Capsule::connection()->getDriverName() === 'pgsql';
        if ($isPg) {
            Capsule::statement("ALTER TABLE inventarios ALTER COLUMN cantidad_reservada DROP DEFAULT");
        }
    },
];
