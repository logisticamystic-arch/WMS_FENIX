<?php
/**
 * scripts/yms_push_pedidos.php — Empuja al YMS (sistema de entrega en punto de
 * venta, hosting propio con IP pública) los pedidos recién certificados.
 *
 * El WMS vive en la bodega SIN IP pública (ver análisis de infraestructura) —
 * por eso el flujo es push (el WMS llama al YMS), nunca pull. Este script es
 * el único punto que dispara ese push: en vez de engancharlo en cada flujo de
 * certificación (certRemisionMultiple, certLiberacionPlanilla, auto-certificar,
 * confirmación consolidada — hay varios y es fácil olvidar uno), corre por cron
 * cada 1-2 min y barre lo certificado desde la última pasada, marcando
 * orden_pickings.yms_sync_status para no reenviar ni perder nada si el YMS
 * estaba caído (reintenta 'error' en la siguiente pasada).
 *
 * ─── CÓMO PROGRAMARLO ────────────────────────────────────────────────────────
 * Task Scheduler (Windows), cada 1-2 minutos:
 *   C:\xampp\php\php.exe scripts\yms_push_pedidos.php
 *
 * Requiere en .env:
 *   YMS_ENDPOINT_URL=https://<tu-yms>/api/wms/pedidos
 *   YMS_SHARED_SECRET=<secreto largo, mismo valor configurado en el YMS>
 * Si no están configuradas, el script se sale sin error (no bloquea nada
 * mientras el YMS aún no existe).
 * ─────────────────────────────────────────────────────────────────────────────
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);
require $rootDir . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$logFile = $rootDir . '/logs/yms_push_pedidos.log';
$log = function (string $msg) use ($logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
};

$endpoint = $_ENV['YMS_ENDPOINT_URL'] ?? getenv('YMS_ENDPOINT_URL') ?: null;
$secret   = $_ENV['YMS_SHARED_SECRET'] ?? getenv('YMS_SHARED_SECRET') ?: null;
if (!$endpoint || !$secret) {
    $log('YMS_ENDPOINT_URL / YMS_SHARED_SECRET no configurados en .env — nada que hacer.');
    exit(0);
}

$log('=== Push de pedidos certificados hacia el YMS ===');

// Mismo criterio "certificada, aún no despachada" ya usado en certPendientes()/
// _manualLineasQuery() de PickingController — IS DISTINCT FROM, no "!=", porque
// estado_certificacion NULL no debe colarse ni excluirse por accidente aquí.
$ordenes = Capsule::table('orden_pickings as o')
    ->where('o.estado_certificacion', 'Certificada')
    ->whereNull('o.estado_despacho')
    ->where(function ($q) {
        $q->whereNull('o.yms_sync_status')->orWhere('o.yms_sync_status', 'error');
    })
    ->select('o.id', 'o.empresa_id', 'o.sucursal_id', 'o.numero_orden', 'o.numero_factura',
             'o.sucursal_entrega', 'o.planilla_numero')
    ->get();

if ($ordenes->isEmpty()) {
    $log('Sin pedidos certificados pendientes de enviar.');
    exit(0);
}

$enviados = 0;
$fallidos = 0;

// Catálogo de motivos de devolución — el YMS no puede jalarlo en vivo
// (push-only), así que viaja dentro de cada payload de pedido y se upsertea
// allá por wms_causal_id. Cacheado por empresa_id para no repetir la consulta.
$motivosPorEmpresa = [];

foreach ($ordenes as $orden) {
    if (!array_key_exists($orden->empresa_id, $motivosPorEmpresa)) {
        $motivosPorEmpresa[$orden->empresa_id] = Capsule::table('causales_devolucion')
            ->where('empresa_id', $orden->empresa_id)
            ->where('activo', true)
            ->select(['id as wms_causal_id', 'causal as nombre'])
            ->get()
            ->values()->toArray();
    }
    $motivos = $motivosPorEmpresa[$orden->empresa_id];

    $lineas = Capsule::table('picking_detalles as pd')
        ->join('productos as p', 'p.id', '=', 'pd.producto_id')
        ->where('pd.orden_picking_id', $orden->id)
        ->where('pd.cantidad_certificada', '>', 0)
        ->select([
            'p.id as producto_id', 'p.codigo_interno as codigo', 'p.nombre',
            'p.unidades_caja', 'p.factor_udm',
            'pd.cantidad_certificada', 'pd.lote', 'pd.fecha_vencimiento',
        ])
        ->get()
        ->map(function ($d) {
            $upc = (isset($d->factor_udm) && (float)$d->factor_udm > 0)
                ? (float)$d->factor_udm : max(1, (float)($d->unidades_caja ?? 1));
            $cant  = (float)$d->cantidad_certificada;
            $cajas = $upc > 1 ? (int)floor($cant / $upc) : $cant;
            $saldo = $upc > 1 ? round($cant - ($cajas * $upc), 3) : 0;
            return [
                'producto_id'       => $d->producto_id,
                'codigo'            => $d->codigo,
                'nombre'            => $d->nombre,
                'cajas'             => $cajas,
                'saldo'             => $saldo,
                'total_unidades'    => $cant,
                'lote'              => $d->lote,
                'fecha_vencimiento' => $d->fecha_vencimiento,
            ];
        });

    if ($lineas->isEmpty()) {
        // Certificada pero sin líneas con cantidad_certificada > 0 — nada que
        // entregar todavía; se deja para la próxima pasada, no se marca error.
        continue;
    }

    $payload = [
        'orden_picking_id' => $orden->id,
        'numero_pedido'    => trim($orden->numero_factura ?: $orden->numero_orden ?: ('#' . $orden->id)),
        'planilla_numero'  => $orden->planilla_numero,
        'sucursal_entrega' => $orden->sucursal_entrega,
        'empresa_id'       => $orden->empresa_id,
        'sucursal_id'      => $orden->sucursal_id,
        'lineas'           => $lineas->values()->toArray(),
        'motivos_devolucion' => $motivos,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-YMS-Secret: ' . $secret,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $respBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        Capsule::table('orden_pickings')->where('id', $orden->id)->update([
            'yms_sync_status' => 'enviado',
            'yms_sync_at'     => date('Y-m-d H:i:s'),
        ]);
        $enviados++;
        $log("OK   pedido {$payload['numero_pedido']} (orden #{$orden->id}) -> YMS");
    } else {
        Capsule::table('orden_pickings')->where('id', $orden->id)->update([
            'yms_sync_status' => 'error',
            'yms_sync_at'     => date('Y-m-d H:i:s'),
        ]);
        $fallidos++;
        $log("ERROR pedido {$payload['numero_pedido']} (orden #{$orden->id}) -> HTTP {$httpCode} {$curlErr} {$respBody}");
    }
}

$log("=== Fin: {$enviados} enviado(s), {$fallidos} fallido(s) ===");
