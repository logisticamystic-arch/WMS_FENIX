<?php
require 'bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

try {
    // 1. Update 'citas' table
    if (!Capsule::schema()->hasColumn('citas', 'tipo_carro')) {
        Capsule::schema()->table('citas', function ($table) {
            $table->string('tipo_carro')->nullable();
            $table->decimal('peso', 10, 2)->nullable();
        });
        echo "Tabla 'citas' actualizada con tipo_carro y peso.\n";
    }

    // 2. Create 'ordenes_compra' table
    if (!Capsule::schema()->hasTable('ordenes_compra')) {
        Capsule::schema()->create('ordenes_compra', function ($table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('proveedor_id');
            $table->string('numero_odc')->unique();
            $table->date('fecha');
            $table->enum('estado', ['Pendiente', 'Parcial', 'Cerrado', 'Anulado'])->default('Pendiente');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas');
            $table->foreign('proveedor_id')->references('id')->on('proveedores');
        });
        echo "Tabla 'ordenes_compra' creada.\n";
    }

    // 3. Create 'orden_compra_detalles' table
    if (!Capsule::schema()->hasTable('orden_compra_detalles')) {
        Capsule::schema()->create('orden_compra_detalles', function ($table) {
            $table->id();
            $table->unsignedBigInteger('orden_compra_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('cantidad_solicitada', 10, 2);
            $table->decimal('cantidad_recibida', 10, 2)->default(0);
            $table->timestamps();

            $table->foreign('orden_compra_id')->references('id')->on('ordenes_compra')->onDelete('cascade');
            $table->foreign('producto_id')->references('id')->on('productos');
        });
        echo "Tabla 'orden_compra_detalles' creada.\n";
    }

    // 4. Create 'certificaciones' table
    if (!Capsule::schema()->hasTable('certificaciones')) {
        Capsule::schema()->create('certificaciones', function ($table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('usuario_id');
            $table->enum('tipo', ['Consolidado', 'Detalle']);
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin')->nullable();
            $table->boolean('diferencias')->default(false);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas');
            $table->foreign('usuario_id')->references('id')->on('personal');
        });
        echo "Tabla 'certificaciones' creada.\n";
    }

    // 5. Create 'certificacion_detalles' table
    if (!Capsule::schema()->hasTable('certificacion_detalles')) {
        Capsule::schema()->create('certificacion_detalles', function ($table) {
            $table->id();
            $table->unsignedBigInteger('certificacion_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->decimal('cantidad_esperada', 10, 2);
            $table->decimal('cantidad_contada', 10, 2);
            $table->timestamps();

            $table->foreign('certificacion_id')->references('id')->on('certificaciones')->onDelete('cascade');
            $table->foreign('producto_id')->references('id')->on('productos');
            $table->foreign('cliente_id')->references('id')->on('clientes');
        });
        echo "Tabla 'certificacion_detalles' creada.\n";
    }

    echo "Migración V3 completada exitosamente.\n";

} catch (\Exception $e) {
    echo "Error en la migración: " . $e->getMessage() . "\n";
}
