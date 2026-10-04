<?php
/**
 * Migración 163 — bloqueo temporal por PIN fallido.
 *
 * 2026-10-01: el login móvil (WMS y TMS) pasa a "seleccionar nombre + PIN"
 * a pedido de Camilo. Con la lista de nombres visible, lo único que protege
 * la cuenta es el PIN de 4 dígitos — sin límite de intentos se podría probar
 * por fuerza bruta. 5 fallos seguidos bloquean 5 minutos (Personal::intentarPin).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();
if (!$schema->hasColumn('personal', 'pin_intentos')) {
    $schema->table('personal', function ($t) {
        $t->smallInteger('pin_intentos')->default(0);
        $t->timestamp('pin_bloqueado_hasta')->nullable();
    });
}
