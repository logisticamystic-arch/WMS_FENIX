<?php
// database/migrations/147_fix_devoluciones_tipo_check_buen_mal_estado.php
//
// Bug encontrado 2026-09-07: el formulario "Nueva Devolución" se rediseñó para
// usar tipo=BuenEstado/MalEstado (reemplazando cliente/proveedor/interna), y se
// actualizó la validación de aplicación ($tiposValidos en DevolucionController::store())
// para aceptar esos dos valores — pero la restricción CHECK de la base de datos
// (chk_dev_tipo, creada en la migración 140) nunca se actualizó. Resultado: TODA
// devolución con tipo BuenEstado o MalEstado pasaba la validación de PHP y
// fallaba al hacer el INSERT con SQLSTATE[23514] (violación de CHECK), devuelto
// como error 500 — bloqueando las dos modalidades nuevas por completo.
// Se mantienen los valores legacy (cliente/proveedor/interna/AProveedorAveria/
// AProveedorVencido/ReingresoBuenEstado) para no romper devoluciones históricas
// ya guardadas con esos tipos.
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        try {
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_tipo");
        } catch (\Exception $e) {}

        try {
            Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_tipo CHECK (tipo IN ('AProveedorAveria', 'AProveedorVencido', 'ReingresoBuenEstado', 'cliente', 'proveedor', 'interna', 'BuenEstado', 'MalEstado'))");
        } catch (\Exception $e) {}
    },

    'down' => function () {}
];
