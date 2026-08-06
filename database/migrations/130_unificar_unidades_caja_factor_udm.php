<?php

use Illuminate\Database\Capsule\Manager as DB;

// Regla de negocio confirmada por el dueño del proyecto (2026-08-06): para
// productos vendidos por contenido/peso (ej. "MIEL TRUFADA X 1100 GRAMOS"),
// "1 caja" en este WMS significa "1 unidad de venta" (1 jarra/frasco/paquete),
// y esa unidad debe ingresar al inventario tantas "unidades" (gramos) como
// indique su contenido — es decir, UND/CAJA (unidades_caja) debe ser igual a
// FACTOR U/E (factor_udm) para que TODAS las pantallas (Recepción, Putaway,
// Picking normal, además de confirmarConsolidado()/planillaDetalles(), que ya
// priorizaban factor_udm) calculen la misma conversión caja→unidades.
//
// Las migraciones 126/128/129 ya habían cargado factor_udm correctamente para
// estos productos, pero dejaron unidades_caja=1 (filosofía distinta: "1 caja
// = 1 unidad física, el peso es solo metadata"), lo que contradice la regla
// anterior y hace que el sistema calcule cantidades distintas según la
// pantalla usada. Este fix unifica ambos campos.
//
// Verificado 2026-08-06: los 175 productos listados abajo tienen hoy
// unidades_caja=1 y factor_udm>0 con valores distintos; el down() los
// restaura a unidades_caja=1 (único valor previo confirmado para este lote).
return new class {
    private function ids(): array
    {
        return [
            5776, 6224, 6291, 6397, 6666, 5752, 5789, 5790, 6612, 6782, 5791, 5792, 5793, 5796,
            5754, 5755, 5760, 5767, 5775, 5779, 5780, 5781, 5813, 6339, 5820, 6853, 5952, 5937,
            5960, 5931, 5935, 5959, 5961, 5962, 5936, 5964, 5946, 5951, 5967, 5969, 5970, 5972,
            5953, 5888, 5893, 5902, 6015, 5990, 6496, 5996, 5998, 6001, 6003, 6004, 6007, 6008,
            6010, 6014, 6024, 6025, 6029, 6036, 6037, 6040, 6041, 6042, 5907, 6452, 5919, 5929,
            5963, 5978, 6269, 6286, 6307, 6317, 6318, 6321, 5989, 6002, 6005, 6006, 6017, 6018,
            6019, 6020, 6323, 6324, 6420, 6429, 6021, 6023, 6031, 6032, 6038, 6046, 6437, 6448,
            6453, 6460, 6477, 6490, 6498, 6502, 6507, 6513, 6514, 6532, 6535, 6546, 6053, 6054,
            6055, 6056, 6493, 6548, 6552, 6576, 6653, 6655, 6652, 6660, 6113, 6764, 6805, 6811,
            6804, 6810, 6821, 6773, 6775, 6776, 6777, 6841, 6828, 6144, 6225, 6251, 6290, 6308,
            6322, 6329, 6330, 6335, 6349, 6365, 6366, 6395, 6408, 6447, 6449, 6455, 6456, 6459,
            6464, 6465, 6466, 6470, 6471, 6472, 6488, 6654, 6662, 6778, 6787, 6781, 6785, 6788,
            6789, 6820, 6815, 6834, 6838, 6842, 6808,
        ];
    }

    public function up()
    {
        // factor_udm ya está cargado y validado para estos ids (migraciones 126/128/129);
        // solo se sincroniza unidades_caja para que coincida.
        DB::table('productos')
            ->whereIn('id', $this->ids())
            ->whereNotNull('factor_udm')
            ->where('factor_udm', '>', 0)
            ->update([
                'unidades_caja' => DB::raw('factor_udm'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
    }

    public function down()
    {
        DB::table('productos')
            ->whereIn('id', $this->ids())
            ->update([
                'unidades_caja' => 1,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
    }
};
