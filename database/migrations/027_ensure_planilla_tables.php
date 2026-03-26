<?php
/**
 * Migration 027 — Ensure planilla tables exist.
 * Migration 026 had a bug where table creation ran at top-level scope,
 * so tables were never created on fresh installs after 026 was recorded.
 * This migration idempotently creates them if missing.
 */
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = DB::schema();

        if (!$schema->hasTable('archivos_planilla')) {
            $schema->create('archivos_planilla', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sucursal_id');
                $t->string('nombre_archivo', 200);
                $t->integer('total_lineas')->default(0);
                $t->integer('total_planillas')->default(0);
                $t->enum('estado', ['Importada', 'EnCertificacion', 'Certificada', 'Anulada'])->default('Importada');
                $t->unsignedBigInteger('importado_por');
                $t->timestamps();
                $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $t->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
                $t->foreign('importado_por')->references('id')->on('personal')->onDelete('restrict');
            });
            echo "  Created: archivos_planilla\n";
        }

        if (!$schema->hasTable('lineas_planilla')) {
            $schema->create('lineas_planilla', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('archivo_id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sucursal_id');
                $t->string('numero_factura', 100)->nullable();
                $t->string('documento', 100)->nullable();
                $t->string('numero_planilla', 100);
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
            echo "  Created: lineas_planilla\n";
        }

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
            echo "  Created: cert_planillas\n";
        }

        if (!$schema->hasTable('cert_planilla_det')) {
            $schema->create('cert_planilla_det', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('cert_id');
                $t->string('producto_codigo', 100)->nullable();
                $t->string('producto_nombre', 300);
                $t->decimal('cantidad_esperada', 12, 3)->default(0);
                $t->decimal('cantidad_certificada', 12, 3)->default(0);
                $t->boolean('es_correcto')->default(false);
                $t->text('observaciones')->nullable();
                $t->timestamps();
                $t->foreign('cert_id')->references('id')->on('cert_planillas')->onDelete('cascade');
            });
            echo "  Created: cert_planilla_det\n";
        }

        // Also ensure categoria_productos exists (migration 024 had same bug risk)
        if (!$schema->hasTable('categoria_productos')) {
            $schema->create('categoria_productos', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('nombre', 100);
                $t->text('descripcion')->nullable();
                $t->boolean('requiere_foto_vencimiento')->default(false);
                $t->timestamps();
                $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            });
            echo "  Created: categoria_productos\n";
        }

        if ($schema->hasTable('productos') && !$schema->hasColumn('productos', 'categoria_id')) {
            $schema->table('productos', function (Blueprint $t) {
                $t->unsignedBigInteger('categoria_id')->nullable()->after('marca_id');
                $t->foreign('categoria_id')->references('id')->on('categoria_productos')->onDelete('set null');
            });
            echo "  Added: productos.categoria_id\n";
        }
    },

    'down' => function () {
        // No-op: handled by 026 down
    },
];
