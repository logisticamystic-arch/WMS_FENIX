<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as DB;

// Auditoría 2026-07-22: BloqueoController::desbloquearLote() hacía delete() físico,
// sin dejar rastro de que el bloqueo existió, quién lo puso ni quién lo levantó.
// Este cambio agrega soft-delete para que el historial de bloqueos de calidad quede
// completo ante un recall o auditoría regulatoria.
return new class {
    public function up(): void
    {
        $schema = DB::schema();

        if (!$schema->hasColumn('bloqueo_lotes', 'activo')) {
            $schema->table('bloqueo_lotes', function (Blueprint $table) {
                $table->boolean('activo')->default(true)->after('bloqueado_por');
                $table->unsignedBigInteger('desbloqueado_por')->nullable()->after('activo');
                $table->timestamp('desbloqueado_at')->nullable()->after('desbloqueado_por');
                $table->string('motivo_desbloqueo', 300)->nullable()->after('desbloqueado_at');
            });
        }

        // La unicidad (empresa_id, producto_id, lote) ya no puede ser estricta a nivel
        // de motor: con soft-delete, re-bloquear el mismo lote tras desbloquearlo
        // chocaría contra la fila inactiva. Se controla en la aplicación
        // (BloqueoController::bloquearLote filtra por activo=true antes de crear).
        try {
            $schema->table('bloqueo_lotes', function (Blueprint $table) {
                $table->dropUnique(['empresa_id', 'producto_id', 'lote']);
            });
        } catch (\Exception $e) {
            // El índice ya no existía o el nombre difiere — no bloquea la migración.
        }

        // Backfill: todas las filas existentes son bloqueos activos hoy (nunca hubo
        // soft-delete antes de esta migración).
        DB::table('bloqueo_lotes')->whereNull('activo')->update(['activo' => true]);
    }

    public function down(): void
    {
        $schema = DB::schema();
        if ($schema->hasColumn('bloqueo_lotes', 'activo')) {
            $schema->table('bloqueo_lotes', function (Blueprint $table) {
                $table->dropColumn(['activo', 'desbloqueado_por', 'desbloqueado_at', 'motivo_desbloqueo']);
            });
        }
    }
};
