<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        $schema->table('recepcion_detalles', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('recepcion_detalles', 'proveedor')) {
                $table->string('proveedor', 200)->nullable();
            }
            if (!$schema->hasColumn('recepcion_detalles', 'numero_documento')) {
                $table->string('numero_documento', 100)->nullable();
            }
            if (!$schema->hasColumn('recepcion_detalles', 'origen_captura')) {
                $table->string('origen_captura', 20)->default('Manual');
            }
            if (!$schema->hasColumn('recepcion_detalles', 'requiere_autorizacion_vencimiento')) {
                $table->boolean('requiere_autorizacion_vencimiento')->default(false);
            }
            if (!$schema->hasColumn('recepcion_detalles', 'fecha_existente_bodega')) {
                $table->date('fecha_existente_bodega')->nullable();
            }
            if (!$schema->hasColumn('recepcion_detalles', 'autorizado_vencimiento_por')) {
                $table->unsignedBigInteger('autorizado_vencimiento_por')->nullable();
            }
            if (!$schema->hasColumn('recepcion_detalles', 'autorizado_vencimiento_at')) {
                $table->timestamp('autorizado_vencimiento_at')->nullable();
            }
        });
    },
    'down' => function () {
        $schema = Capsule::schema();
        $schema->table('recepcion_detalles', function (Blueprint $table) {
            foreach ([
                'proveedor', 'numero_documento', 'origen_captura',
                'requiere_autorizacion_vencimiento', 'fecha_existente_bodega',
                'autorizado_vencimiento_por', 'autorizado_vencimiento_at',
            ] as $col) {
                if (Capsule::schema()->hasColumn('recepcion_detalles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    },
];
