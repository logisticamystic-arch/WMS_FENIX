<?php
use Illuminate\Database\Capsule\Manager as DB;
return [
    'up' => function () {
        if (!DB::schema()->hasTable('odc_auxiliares')) {
            DB::schema()->create('odc_auxiliares', function ($table) {
                $table->id();
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id')->nullable();
                $table->unsignedBigInteger('orden_compra_id');
                $table->unsignedBigInteger('auxiliar_id');
                $table->timestamp('assigned_at')->useCurrent();
                $table->index(['empresa_id', 'orden_compra_id']);
                $table->index('auxiliar_id');
            });
            echo "  odc_auxiliares creada.\n";
        } else { echo "  odc_auxiliares ya existe.\n"; }
        if (!DB::schema()->hasColumn('productos', 'activo')) {
            DB::schema()->table('productos', function ($t) { $t->boolean('activo')->default(1); });
            echo "  activo añadida a productos.\n";
        } else { echo "  activo ya existe en productos.\n"; }
    },
    'down' => function () {},
];
