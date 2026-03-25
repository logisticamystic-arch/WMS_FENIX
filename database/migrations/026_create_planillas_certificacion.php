<?php
/**
 * Migration 026 — Planillas de certificación por cliente.
 * Permite importar archivos planos de picking y certificar por planilla.
 *
 * archivos_planilla  → Cabecera del archivo importado
 * lineas_planilla    → Cada línea del archivo (producto × planilla)
 * cert_planillas     → Proceso de certificación de una planilla específica
 * cert_planilla_det  → Detalle de lo que el auxiliar contó por producto
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

$schema = DB::schema();

// 1. archivos_planilla
if (!$schema->hasTable('archivos_planilla')) {
    $schema->create('archivos_planilla', function (Blueprint $t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('empresa_id');
        $t->unsignedBigInteger('sucursal_id');
        $t->string('nombre_archivo', 200);
        $t->integer('total_lineas')->default(0);
        $t->integer('total_planillas')->default(0);  // distinct planilla values
        $t->enum('estado', ['Importada', 'EnCertificacion', 'Certificada', 'Anulada'])->default('Importada');
        $t->unsignedBigInteger('importado_por');
        $t->timestamps();
        $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
        $t->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
        $t->foreign('importado_por')->references('id')->on('personal')->onDelete('restrict');
    });
}

// 2. lineas_planilla
if (!$schema->hasTable('lineas_planilla')) {
    $schema->create('lineas_planilla', function (Blueprint $t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('archivo_id');
        $t->unsignedBigInteger('empresa_id');
        $t->unsignedBigInteger('sucursal_id');
        $t->string('numero_factura', 100)->nullable();
        $t->string('documento', 100)->nullable();
        $t->string('numero_planilla', 100);   // Campo "Planilla" del CSV
        $t->string('asesor', 150)->nullable();
        $t->string('producto_codigo', 100)->nullable();
        $t->string('producto_nombre', 300);
        $t->decimal('cantidad', 12, 3)->default(0);
        $t->decimal('costo', 14, 4)->default(0);
        $t->decimal('descuento', 14, 4)->default(0);
        $t->decimal('valor_producto', 14, 4)->default(0);
        $t->string('pedido', 100)->nullable();
        $t->timestamps();
        $t->foreign('archivo_id')->references('id')->on('archivos_planilla')->onDelete('cascade');
        $t->index(['archivo_id', 'numero_planilla'], 'idx_lp_archivo_planilla');
        $t->index(['empresa_id', 'numero_planilla'], 'idx_lp_emp_planilla');
    });
}

// 3. cert_planillas (cabecera de certificación de una planilla)
if (!$schema->hasTable('cert_planillas')) {
    $schema->create('cert_planillas', function (Blueprint $t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('empresa_id');
        $t->unsignedBigInteger('sucursal_id');
        $t->unsignedBigInteger('archivo_id');
        $t->string('numero_planilla', 100);
        $t->unsignedBigInteger('auxiliar_id');
        $t->enum('estado', ['EnProceso', 'Completada', 'ConNovedad'])->default('EnProceso');
        $t->date('fecha');
        $t->time('hora_inicio');
        $t->time('hora_fin')->nullable();
        $t->text('observaciones')->nullable();
        $t->timestamps();
        $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
        $t->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
        $t->foreign('archivo_id')->references('id')->on('archivos_planilla')->onDelete('restrict');
        $t->foreign('auxiliar_id')->references('id')->on('personal')->onDelete('restrict');
        $t->unique(['archivo_id', 'numero_planilla', 'auxiliar_id'], 'uq_cert_planilla_aux');
    });
}

// 4. cert_planilla_det (detalle por producto)
if (!$schema->hasTable('cert_planilla_det')) {
    $schema->create('cert_planilla_det', function (Blueprint $t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('cert_id');
        $t->string('producto_codigo', 100)->nullable();
        $t->string('producto_nombre', 300);
        $t->decimal('cantidad_esperada', 12, 3)->default(0);  // visible solo admin/sup
        $t->decimal('cantidad_certificada', 12, 3)->default(0);
        $t->boolean('es_correcto')->default(false);
        $t->text('observaciones')->nullable();
        $t->timestamps();
        $t->foreign('cert_id')->references('id')->on('cert_planillas')->onDelete('cascade');
    });
}

// Nullable conteo_detalles.ubicacion_id (fix for conteo sin ubicacion especificada)
if ($schema->hasTable('conteo_detalles')) {
    try {
        DB::connection()->statement('ALTER TABLE conteo_detalles MODIFY ubicacion_id BIGINT UNSIGNED NULL');
    } catch (\Exception $e) { /* already nullable */ }
}

// Agregar columna observaciones a conteo_inventarios para guardar filtro de ubicaciones
if ($schema->hasTable('conteo_inventarios') && !$schema->hasColumn('conteo_inventarios', 'observaciones')) {
    $schema->table('conteo_inventarios', function ($t) {
        $t->text('observaciones')->nullable()->after('hora_fin');
    });
}

return ['up' => fn() => null, 'down' => fn() => null];
