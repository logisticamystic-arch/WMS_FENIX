<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * AuditLogger — Registra cada operación en audit_logs.
 * Se llama desde todos los controladores en acciones CREATE / UPDATE / DELETE.
 */
class AuditLogger
{
    /**
     * Registrar una acción en el log de auditoría.
     *
     * @param int         $empresaId
     * @param int|null    $usuarioId
     * @param string      $modulo        'recepcion' | 'picking' | 'despacho' | 'inventario' | 'maestros' | ...
     * @param string      $accion        'crear' | 'editar' | 'eliminar' | 'confirmar' | 'trasladar' | ...
     * @param string|null $tablaAfectada nombre de la tabla BD
     * @param int|null    $registroId    PK del registro afectado
     * @param array|null  $datosAnteriores snapshot antes del cambio
     * @param array|null  $datosNuevos    snapshot después del cambio
     * @param string|null $descripcion   texto libre legible por humanos
     * @param string|null $ip
     */
    public static function log(
        int     $empresaId,
        ?int    $usuarioId,
        string  $modulo,
        string  $accion,
        ?string $tablaAfectada = null,
        ?int    $registroId    = null,
        ?array  $datosAnteriores = null,
        ?array  $datosNuevos    = null,
        ?string $descripcion    = null,
        ?string $ip             = null
    ): void {
        try {
            Capsule::table('audit_logs')->insert([
                'empresa_id'       => $empresaId,
                'usuario_id'       => $usuarioId,
                'modulo'           => $modulo,
                'accion'           => $accion,
                'tabla_afectada'   => $tablaAfectada,
                'registro_id'      => $registroId,
                'datos_anteriores' => $datosAnteriores ? json_encode($datosAnteriores, JSON_UNESCAPED_UNICODE) : null,
                'datos_nuevos'     => $datosNuevos    ? json_encode($datosNuevos,    JSON_UNESCAPED_UNICODE) : null,
                'descripcion'      => $descripcion,
                'ip_address'       => $ip,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // El audit log nunca debe bloquear la operación principal
            error_log('[AuditLogger] Error: ' . $e->getMessage());
        }
    }

    /**
     * Shorthand para operaciones de inventario (muy frecuentes).
     */
    public static function movimiento(
        int    $empresaId,
        int    $usuarioId,
        string $tipoMovimiento,
        int    $productoId,
        int    $cantidad,
        string $descripcion
    ): void {
        self::log(
            $empresaId,
            $usuarioId,
            'inventario',
            $tipoMovimiento,
            'inventarios',
            $productoId,
            null,
            ['cantidad' => $cantidad],
            $descripcion
        );
    }
}
