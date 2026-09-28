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
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) return [];

        $url = dirname($endpoint) . '/en-ruta.php';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
        ]);
        $body = curl_exec($ch);
        $ok   = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok || !$body) return [];
        $data = json_decode($body, true);
        return $data['visitas'] ?? [];
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
    public static function sincronizarPedido(int $ordenPickingId, string $accion): bool
    {
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) return false;

        $url = dirname($endpoint) . '/sync-pedido.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['orden_picking_id' => $ordenPickingId, 'accion' => $accion]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }
}
