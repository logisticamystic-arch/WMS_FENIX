<?php

use Illuminate\Database\Capsule\Manager as DB;

// Mismo bug detectado en MIEL TRUFADA (migración 126): unidades_caja tenía el
// peso en gramos del nombre ("X 7000 GR") en vez de unidades reales por caja.
// Cada vez que se pickeaba en modo "cajas" este producto, el sistema multiplicaba
// por 7000 — confirmado en picking_detalles históricos (pedidos de 1-4 unidades
// pickeados como 7000/14000/21000/28000). Esto dejó una reserva de inventario
// (cantidad_reservada) acumulada que bloqueaba el módulo Ubicar para la partida
// en Patio (id 10941): cantidad=42000, cantidad_reservada=42000 → 0 disponible.
return new class {
    public function up()
    {
        DB::table('productos')
            ->where('codigo_interno', '101072') // I CHICKEN ENSALADA COLESLAW x 7000 GR
            ->update([
                'unidades_caja'    => 1,
                'factor_udm'       => 7000,
                'unidad_contenido' => 'GR',
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

        // Liberar la reserva errónea en la partida de Patio para que quede
        // disponible para ubicar. No se toca la cantidad total (42000): eso
        // requiere revisión aparte de si el total en sí también está inflado.
        DB::table('inventarios')
            ->where('id', 10941)
            ->update([
                'cantidad_reservada' => 0,
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
    }

    public function down()
    {
        // No reversible: no se conoce el valor original de unidades_caja antes
        // del bug, y restaurar la reserva liberada no es seguro sin saber a qué
        // pedido correspondía.
    }
};
