<?php
/**
 * Migración 161 — Recogidas de devolución desde Punto de Venta (PDV).
 *
 * A pedido de Camilo (2026-09-30), según la "Política Interna de Gestión de
 * Logística Inversa y Devoluciones": el PDV monta la solicitud en la plataforma
 * BEE y Logística genera el documento de RECOGIDA (media carta, firmas de quien
 * entrega y quien recibe + sello) con el consecutivo BEE digitado.
 *
 * devolucion_recogidas        → cabecera (PDV, consecutivo BEE, fecha, observaciones)
 * devolucion_recogida_lineas  → referencia, cantidad (cajas + saldos), causal, disposición
 */

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();

if (!$schema->hasTable('devolucion_recogidas')) {
    $schema->create('devolucion_recogidas', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('empresa_id');
        $t->unsignedBigInteger('sucursal_id')->nullable();   // sucursal WMS (CEDI) que recoge
        $t->string('numero', 30);                             // consecutivo interno RG-000001
        $t->string('consecutivo_bee', 60);                    // digitado: número de la solicitud en BEE
        $t->unsignedBigInteger('cliente_id')->nullable();     // PDV del maestro de clientes, si aplica
        $t->string('punto_venta', 150);                       // nombre del PDV / sucursal que devuelve
        $t->date('fecha_solicitud');
        $t->date('fecha_recogida')->nullable();
        $t->string('autorizado_por', 150)->nullable();        // Calidad / Líder CDP (causales que lo exigen)
        $t->text('observaciones')->nullable();
        $t->string('estado', 20)->default('Programada');      // Programada | Anulada
        $t->unsignedBigInteger('creado_por')->nullable();
        $t->timestamps();
        $t->unique(['empresa_id', 'numero']);
        $t->unique(['empresa_id', 'consecutivo_bee']);
        $t->index(['empresa_id', 'sucursal_id', 'fecha_solicitud']);
    });
}

if (!$schema->hasTable('devolucion_recogida_lineas')) {
    $schema->create('devolucion_recogida_lineas', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('recogida_id');
        $t->unsignedBigInteger('producto_id');
        $t->decimal('cantidad_cajas', 12, 2)->default(0);
        $t->decimal('saldos', 12, 3)->default(0);
        $t->decimal('cantidad', 14, 3);                       // UND/TOTAL = cajas × U/E + saldos
        $t->string('lote', 60)->nullable();
        $t->date('fecha_vencimiento')->nullable();
        $t->string('causal', 60);
        $t->string('disposicion', 30)->default('Recoger');    // Recoger | Destruir en PDV
        $t->string('observacion', 255)->nullable();
        $t->timestamps();
        $t->foreign('recogida_id')->references('id')->on('devolucion_recogidas')->onDelete('cascade');
        $t->index('recogida_id');
    });
}

echo "Migración 161 completada: devolucion_recogidas y devolucion_recogida_lineas.\n";
