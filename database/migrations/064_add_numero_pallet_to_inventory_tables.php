<?php
/**
 * Migration 064 — Inventario: Soporte para Numero de Pallet
 * ========================================================
 * Agrega la columna numero_pallet a las tablas de inventario 
 * para permitir la trazabilidad por unidades de carga (LPN).
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. Tabla inventarios
        if ($schema->hasTable('inventarios')) {
            $schema->table('inventarios', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('inventarios', 'numero_pallet')) {
                    $table->unsignedBigInteger('numero_pallet')->nullable()->after('ubicacion_id');
                }
                
                // Actualizar índice único para incluir numero_pallet
                // Primero eliminamos el anterior si existe
                try {
                    $table->dropUnique('inv_prod_ubic_lote_unique');
                } catch (\Exception $e) {
                    // Si no existe o tiene otro nombre, ignorar
                }
                
                $table->unique(['empresa_id', 'producto_id', 'ubicacion_id', 'lote', 'numero_pallet'], 'inv_prod_ubic_lote_pallet_unique');
            });
        }

        // 2. Tabla movimiento_inventarios
        if ($schema->hasTable('movimiento_inventarios')) {
            $schema->table('movimiento_inventarios', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('movimiento_inventarios', 'numero_pallet')) {
                    $table->unsignedBigInteger('numero_pallet')->nullable()->after('ubicacion_destino_id');
                }
            });
        }
        
        // 3. Tabla recepcion_detalles (opcional pero recomendado para trazabilidad)
        if ($schema->hasTable('recepcion_detalles')) {
            $schema->table('recepcion_detalles', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('recepcion_detalles', 'numero_pallet')) {
                    $table->unsignedBigInteger('numero_pallet')->nullable()->after('lote');
                }
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        
        if ($schema->hasTable('inventarios')) {
            $schema->table('inventarios', function (Blueprint $table) {
                $table->dropUnique('inv_prod_ubic_lote_pallet_unique');
                $table->unique(['empresa_id', 'producto_id', 'ubicacion_id', 'lote'], 'inv_prod_ubic_lote_unique');
                $table->dropColumn('numero_pallet');
            });
        }
        
        if ($schema->hasTable('movimiento_inventarios')) {
            $schema->table('movimiento_inventarios', function (Blueprint $table) {
                $table->dropColumn('numero_pallet');
            });
        }
        
        if ($schema->hasTable('recepcion_detalles')) {
            $schema->table('recepcion_detalles', function (Blueprint $table) {
                $table->dropColumn('numero_pallet');
            });
        }
    },
];
