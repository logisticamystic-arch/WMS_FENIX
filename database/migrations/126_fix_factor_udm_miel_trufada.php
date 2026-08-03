<?php

use Illuminate\Database\Capsule\Manager as DB;

// El campo factor_udm (U/E: cantidad de contenido por unidad, ej. gramos) nunca
// se pudo cargar vía importación masiva (ver ImportExportController) — quedó
// vacío en los 822 productos activos. Este producto puntual fue reportado:
// el maestro tiene peso_unitario=1100 ("X 1100 GRAMOS") pero factor_udm estaba
// vacío, así que la conversión U/E en recepción/picking se saltaba por completo
// y los procesos terminaban usando unidades_caja=1450 (un campo no relacionado,
// unidades por caja) donde se esperaba el contenido por unidad.
return new class {
    public function up()
    {
        DB::table('productos')
            ->where('codigo_interno', '101083')
            ->whereNull('factor_udm')
            ->update([
                'factor_udm'       => 1100,
                'unidad_contenido' => 'GR',
                'updated_at'       => now(),
            ]);
    }

    public function down()
    {
        DB::table('productos')
            ->where('codigo_interno', '101083')
            ->update([
                'factor_udm'       => null,
                'unidad_contenido' => null,
            ]);
    }
};
