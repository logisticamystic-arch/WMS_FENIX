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
}
