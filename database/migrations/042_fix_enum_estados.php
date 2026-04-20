<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function () {
        $schema = Capsule::schema();
        Capsule::statement('ALTER TABLE ordenes_compra DROP CONSTRAINT IF EXISTS ordenes_compra_estado_check');
        Capsule::statement("ALTER TABLE ordenes_compra ADD CONSTRAINT ordenes_compra_estado_check CHECK (estado IN ('Borrador','Confirmada','En Proceso','Cerrada','Cancelada'))");
        echo "  ordenes_compra.estado OK\n";
        Capsule::statement('ALTER TABLE citas DROP CONSTRAINT IF EXISTS citas_estado_check');
        Capsule::statement("ALTER TABLE citas ADD CONSTRAINT citas_estado_check CHECK (estado IN ('Programada','EnPatio','EnCurso','Completada','Cancelada'))");
        echo "  citas.estado OK\n";
        $schema->table('citas', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('citas', 'odc_id'))               $t->unsignedBigInteger('odc_id')->nullable();
            if (!$schema->hasColumn('citas', 'hora_llegada'))          $t->timestamp('hora_llegada')->nullable();
            if (!$schema->hasColumn('citas', 'hora_inicio_descargue')) $t->timestamp('hora_inicio_descargue')->nullable();
            if (!$schema->hasColumn('citas', 'hora_fin_descargue'))    $t->timestamp('hora_fin_descargue')->nullable();
            if (!$schema->hasColumn('citas', 'evaluacion_proveedor'))  $t->smallInteger('evaluacion_proveedor')->default(5);
            if (!$schema->hasColumn('citas', 'tipo_descargue'))        $t->string('tipo_descargue', 50)->default('Paletizado');
        });
        echo "  citas columnas YMS OK\n";
    },
    'down' => function () {},
];
