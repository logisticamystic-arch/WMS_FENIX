<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * NotificationService — Servicio centralizado de notificaciones WMS
 *
 * Inserta registros en la tabla `notificaciones` y los entrega a los
 * destinatarios correctos según su rol dentro de la empresa/sucursal.
 *
 * Uso típico:
 *   NotificationService::toSupervisors($empresaId, $sucursalId, [
 *       'tipo'        => 'alerta',
 *       'titulo'      => 'Producto en riesgo crítico de vencimiento',
 *       'mensaje'     => '3 productos vencen en menos de 7 días...',
 *       'link_accion' => "inteligencia/vencimientos",
 *       'modulo'      => 'inteligencia',
 *   ]);
 */
class NotificationService
{
    // ── Tipos de notificación ─────────────────────────────────────────────────
    const TIPO_INFO      = 'info';
    const TIPO_ALERTA    = 'alerta';
    const TIPO_TAREA     = 'tarea';
    const TIPO_PICKING   = 'picking';
    const TIPO_INVENTARIO = 'inventario';

    // ── Roles que reciben alertas de supervisión ──────────────────────────────
    const ROLES_SUPERVISOR = ['admin', 'supervisor', 'gerente'];

    /**
     * Envía notificación a todos los supervisores/admins de la empresa.
     * Si se pasa $sucursalId filtra solo los de esa sucursal; null = todos.
     *
     * @param int        $empresaId
     * @param int|null   $sucursalId
     * @param array      $payload  [tipo, titulo, mensaje, link_accion, modulo, referencia_tipo, referencia_id, sonido]
     * @param int|null   $emisorId personal.id del emisor (null = sistema)
     * @return int        Cantidad de notificaciones insertadas
     */
    public static function toSupervisors(
        int $empresaId,
        ?int $sucursalId,
        array $payload,
        ?int $emisorId = null
    ): int {
        $query = Capsule::table('personal')
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->whereIn('rol', self::ROLES_SUPERVISOR)
            ->select('id');

        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }

        $recipients = $query->pluck('id');

        return self::_insertBulk($empresaId, $sucursalId, $recipients, $payload, $emisorId);
    }

    /**
     * Envía notificación a un usuario específico.
     */
    public static function toUser(
        int $empresaId,
        int $personalId,
        array $payload,
        ?int $emisorId = null
    ): int {
        // Intentar obtener sucursal_id del destinatario para mayor precisión
        $sucursalId = Capsule::table('personal')->where('id', $personalId)->value('sucursal_id');
        return self::_insertBulk($empresaId, $sucursalId, collect([$personalId]), $payload, $emisorId);
    }

    /**
     * Envía notificación a todos los usuarios de un rol específico.
     */
    public static function toRole(
        int $empresaId,
        ?int $sucursalId,
        string $rol,
        array $payload,
        ?int $emisorId = null
    ): int {
        $query = Capsule::table('personal')
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->where('rol', $rol)
            ->select('id');

        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }

        $recipients = $query->pluck('id');

        return self::_insertBulk($empresaId, $sucursalId, $recipients, $payload, $emisorId);
    }

    /**
     * Verifica si ya existe una notificación no leída del mismo tipo+módulo
     * para evitar duplicados en intervalos cortos (ventana: $ventanaMinutos).
     */
    public static function existeReciente(
        int $empresaId,
        string $modulo,
        string $tituloLike,
        int $ventanaMinutos = 120
    ): bool {
        return Capsule::table('notificaciones')
            ->where('empresa_id', $empresaId)
            ->where('modulo', $modulo)
            ->where('titulo', 'like', "%{$tituloLike}%")
            ->where('leida', false)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $ventanaMinutos * 60))
            ->exists();
    }

    // ── Métodos especializados para ML / Inteligencia ─────────────────────────

    /**
     * Genera notificaciones de vencimiento crítico para supervisores.
     * Agrupa todos los productos en un solo mensaje para no saturar.
     *
     * @param int   $empresaId
     * @param int   $sucursalId
     * @param array $productosCriticos  Array de predicciones con nivel_riesgo='critico'|'alto'
     * @return int  notificaciones insertadas
     */
    public static function alertarVencimientos(
        int $empresaId,
        int $sucursalId,
        array $productosCriticos
    ): int {
        if (empty($productosCriticos)) {
            return 0;
        }

        // Evitar duplicados: no enviar si ya hay alerta de vencimiento en las últimas 2h
        if (self::existeReciente($empresaId, 'inteligencia', 'vencimiento', 120)) {
            return 0;
        }

        $criticos = array_filter($productosCriticos, fn($p) => ($p['nivel_riesgo'] ?? '') === 'critico');
        $altos    = array_filter($productosCriticos, fn($p) => ($p['nivel_riesgo'] ?? '') === 'alto');

        $nCrit = count($criticos);
        $nAlto = count($altos);

        if ($nCrit === 0 && $nAlto === 0) {
            return 0;
        }

        // Título con urgencia
        $titulo = $nCrit > 0
            ? "⚠️ {$nCrit} producto(s) en riesgo CRÍTICO de vencimiento"
            : "🔶 {$nAlto} producto(s) en riesgo ALTO de vencimiento";

        // Mensaje detallado
        $lines = [];
        foreach (array_merge(array_values($criticos), array_values($altos)) as $p) {
            $nombre = $p['nombre'] ?? "Producto #{$p['producto_id']}";
            $dias   = $p['dias_para_vencer'] ?? '?';
            $stock  = $p['unidades_en_riesgo'] ?? $p['stock_actual'] ?? '?';
            $lote   = !empty($p['lote']) ? " (lote {$p['lote']})" : '';
            $lines[] = "• {$nombre}{$lote}: {$dias} días, {$stock} uds en riesgo";
        }

        $mensaje = implode("\n", array_slice($lines, 0, 10));
        if (count($lines) > 10) {
            $mensaje .= "\n… y " . (count($lines) - 10) . " más.";
        }
        $mensaje .= "\n\nRevisa el módulo Inteligencia → Vencimientos para ver todas las recomendaciones.";

        return self::toSupervisors($empresaId, $sucursalId, [
            'tipo'             => self::TIPO_ALERTA,
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'link_accion'      => 'inteligencia/vencimientos',
            'modulo'           => 'inteligencia',
            'referencia_tipo'  => 'expiry_predictions',
            'sonido'           => true,
        ]);
    }

    /**
     * Genera notificaciones cuando el ML detecta anomalías de severidad alta/crítica.
     */
    public static function alertarAnomalias(
        int $empresaId,
        int $sucursalId,
        array $anomalias
    ): int {
        if (empty($anomalias)) {
            return 0;
        }

        if (self::existeReciente($empresaId, 'inteligencia', 'anomal', 60)) {
            return 0;
        }

        $graves = array_filter($anomalias, fn($a) => in_array($a['severidad'] ?? '', ['alta', 'critica']));

        if (empty($graves)) {
            return 0;
        }

        $n      = count($graves);
        $titulo = "🔍 {$n} anomalía(s) de inventario detectada(s)";

        $lines = [];
        foreach (array_values($graves) as $a) {
            $sev     = strtoupper($a['severidad'] ?? '');
            $lines[] = "• [{$sev}] " . ($a['titulo'] ?? 'Anomalía sin título');
        }

        $mensaje  = implode("\n", array_slice($lines, 0, 8));
        $mensaje .= "\n\nRevisa el módulo Inteligencia → Anomalías para revisar o descartar cada una.";

        return self::toSupervisors($empresaId, $sucursalId, [
            'tipo'            => self::TIPO_ALERTA,
            'titulo'          => $titulo,
            'mensaje'         => $mensaje,
            'link_accion'     => 'inteligencia/anomalias',
            'modulo'          => 'inteligencia',
            'referencia_tipo' => 'anomaly_flags',
            'sonido'          => true,
        ]);
    }

    // ── Privado ───────────────────────────────────────────────────────────────

    private static function _insertBulk(
        int $empresaId,
        ?int $sucursalId,
        $recipients,
        array $payload,
        ?int $emisorId
    ): int {
        if ($recipients->isEmpty()) {
            return 0;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($recipients as $uid) {
            $rows[] = [
                'empresa_id'      => $empresaId,
                'sucursal_id'     => $sucursalId,
                'personal_id'     => $uid,
                'emisor_id'       => $emisorId,
                'tipo'            => $payload['tipo']            ?? self::TIPO_INFO,
                'titulo'          => $payload['titulo']          ?? 'Notificación WMS',
                'mensaje'         => $payload['mensaje']         ?? '',
                'link_accion'     => $payload['link_accion']     ?? null,
                'modulo'          => $payload['modulo']          ?? null,
                'referencia_tipo' => $payload['referencia_tipo'] ?? null,
                'referencia_id'   => $payload['referencia_id']   ?? null,
                'leida'           => false,
                'completada'      => false,
                'sonido'          => $payload['sonido']          ?? true,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        // Insertar en lotes de 50 para no saturar el bind
        foreach (array_chunk($rows, 50) as $chunk) {
            Capsule::table('notificaciones')->insert($chunk);
        }

        return count($rows);
    }
}
