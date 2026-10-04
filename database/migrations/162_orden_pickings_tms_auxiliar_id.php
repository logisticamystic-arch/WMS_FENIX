<?php
/**
 * Migración 162 — auxiliar de entrega por PEDIDO (transferencia TMS).
 *
 * A pedido de Camilo (2026-10-01), módulo "Gestión de Pedidos" del Dashboard
 * TMS: un pedido puede transferirse a otro auxiliar distinto al de su Planilla
 * de Cargue. Se guarda aquí para que TmsPush lo respete en cualquier reenvío
 * posterior (si no, al editar la planilla volvería al auxiliar del despacho).
 * NULL = usa el auxiliar del despacho (comportamiento de siempre).
 */

use Illuminate\Database\Capsule\Manager as Capsule;

if (!Capsule::schema()->hasColumn('orden_pickings', 'tms_auxiliar_id')) {
    Capsule::schema()->table('orden_pickings', function ($t) {
        $t->unsignedBigInteger('tms_auxiliar_id')->nullable();
    });
}
