<?php

namespace App\Helpers;

/**
 * TmsClient — lado inverso de TmsPush: aquí el WMS LEE datos del TMS bajo
 * demanda (a diferencia del push/webhook, que son de una sola vía cada uno).
 * Hoy solo trae las visitas en curso (aún no confirmadas) para el mapa "en
 * tiempo real" del Dashboard TMS/TV — las ya confirmadas viven en
 * `entregas_ruta` del WMS vía el webhook ENTREGA_CONFIRMADA.
 */
class TmsClient
{
    public static function visitasEnRuta(): array
    {
        return self::enRuta()['visitas'];
    }

    // Visitas en curso + recorrido continuo por auxiliar (tracking cada 5 min)
    // de la fecha pedida — 2026-10-01, mapa en tiempo real.
    public static function enRuta(?string $fecha = null): array
    {
        $data = self::get('en-ruta.php', $fecha ? ['fecha' => $fecha] : []);
        return [
            'visitas' => $data['visitas'] ?? [], 'tracks' => $data['tracks'] ?? [],
            'rutas'   => $data['rutas'] ?? [],   'bodega' => $data['bodega'] ?? null,
        ];
    }

    // Ventana de GPS de una Planilla de Cargue (2026-10-01): 'iniciar' al
    // marcarla Despachado (y de nuevo si cambia el auxiliar o se transfiere
    // un pedido — es idempotente), 'finalizar' al liquidarla. El cierre normal
    // lo hace el propio TMS al detectar que el vehículo volvió a la bodega.
    // Best-effort: si el TMS no responde no bloquea nada del lado WMS.
    public static function rutaDespacho(int $despachoId, string $accion): bool
    {
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) return false;

        $payload = ['despacho_id' => $despachoId, 'accion' => $accion];
        if ($accion === 'iniciar') {
            $d = \Illuminate\Database\Capsule\Manager::table('despachos')->where('id', $despachoId)->first();
            if (!$d) return false;
            // Auxiliar de la planilla + los que tengan pedidos transferidos.
            $ids = \Illuminate\Database\Capsule\Manager::table('orden_pickings')->where('despacho_id', $despachoId)
                ->whereNotNull('tms_auxiliar_id')->pluck('tms_auxiliar_id')->all();
            if ($d->auxiliar_id) $ids[] = $d->auxiliar_id;
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!$ids) return false;
            $payload += ['numero_despacho' => $d->numero_despacho, 'placa' => $d->placa, 'personal_ids' => $ids];
        }

        $ch = curl_init(dirname($endpoint) . '/ruta.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    private static function get(string $path, array $query = []): ?array
    {
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) return null;

        $url = dirname($endpoint) . '/' . $path;
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $ok   = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        return ($ok && $body) ? json_decode($body, true) : null;
    }

    // Módulo de mantenimiento del tracking GPS (2026-10-03): estadísticas,
    // retención y depuración. GET sin $body; POST con $body.
    public static function mantenimientoTracking(?array $body = null): ?array
    {
        if ($body === null) return self::get('tracking-mantenimiento.php');
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) return null;
        $ch = curl_init(dirname($endpoint) . '/tracking-mantenimiento.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ? json_decode($res, true) : null;
    }

    // Datos crudos para el reporte "Tiempos de paro" (2026-10-01): puntos GPS,
    // visitas y rutas del rango. null si el TMS no responde.
    public static function paradas(string $desde, string $hasta): ?array
    {
        return self::get('paradas.php', ['fecha_desde' => $desde, 'fecha_hasta' => $hasta]);
    }

    // Listado para "Reabrir Pedidos" (Dashboard TMS, escritorio).
    public static function consultarPedidos(array $filtros): array
    {
        $data = self::get('pedidos-consulta.php', $filtros);
        return $data ?? ['pedidos' => [], 'filtros' => ['rutas' => [], 'sucursales' => []]];
    }

    public static function detallePedido(int $ordenPickingId): ?array
    {
        return self::get('pedido-detalle.php', ['orden_picking_id' => $ordenPickingId]);
    }

    // 'liquidar' (planilla ya no debe volver a salirle al auxiliar) o
    // 'reabrir' (admin permite rehacer la entrega en el TMS) — best-effort,
    // igual que TmsPush: si el TMS no responde no revierte nada del lado
    // WMS, solo queda desincronizado hasta el próximo intento manual.
    // 2026-10-01: también 'activar' / 'desactivar' / 'transferir' ($extra =
    // ['auxiliar_personal_id' => N]) — módulo Gestión de Pedidos.
    public static function sincronizarPedido(int $ordenPickingId, string $accion, array $extra = []): bool
    {
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) return false;

        $url = dirname($endpoint) . '/sync-pedido.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['orden_picking_id' => $ordenPickingId, 'accion' => $accion] + $extra),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string)$body, true);
        $ok = $code >= 200 && $code < 300 && is_array($json) && empty($json['error']);
        if (!$ok) error_log("TmsClient::sincronizarPedido({$ordenPickingId}, {$accion}) falló: HTTP {$code} " . substr((string)$body, 0, 200));
        return $ok;
    }
}
