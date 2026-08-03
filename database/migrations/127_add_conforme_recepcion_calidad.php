<?php

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

// El formulario de Auditoría de Calidad (móvil) exige seleccionar el veredicto
// general de la recepción (Conforme/Inconforme), pero esa columna nunca existió.
return new class {
    public function up()
    {
        if (!DB::schema()->hasColumn('recepcion_calidad', 'conforme')) {
            DB::schema()->table('recepcion_calidad', function (Blueprint $t) {
                $t->string('conforme', 20)->nullable()->after('trans_carnet_manipulacion');
            });
        }
    }

    public function down()
    {
        if (DB::schema()->hasColumn('recepcion_calidad', 'conforme')) {
            DB::schema()->table('recepcion_calidad', function (Blueprint $t) {
                $t->dropColumn('conforme');
            });
        }
    }
};
