<?php
/**
 * scripts/tms_push_pedidos.php — Empuja al TMS (sistema de entrega en punto de
 * venta, hosting propio con IP pública, antes llamado "YMS" — renombrado a
 * pedido explícito de Camilo, 2026-09-27, para que el término sea consistente
 * con TmsController/api/tms/webhook del lado WMS) los pedidos recién
 * certificados. Las columnas `orden_pickings.yms_sync_status`/`yms_sync_at`
 * (migración 148) NO se renombraron — es un detalle interno de BD invisible
 * para el usuario, renombrarlas obligaría otra migración sin beneficio real.
 *
 * El WMS vive en la bodega SIN IP pública (ver análisis de infraestructura) —
 * por eso el flujo es push (el WMS llama al TMS), nunca pull. Este script
 * barre por cron (cada 1-2 min) lo certificado desde la última pasada; el
 * armado del payload + envío real vive en App\Helpers\TmsPush::enviarOrden()
 * (reusado también por DespachoController::agregarPedidos() para reenviar de
 * inmediato cuando se asigna el auxiliar de entrega en la Planilla de
 * Cargue — ver comentario en TmsPush.php sobre por qué hace falta un segundo
 * disparador ahí).
 *
 * ─── CÓMO PROGRAMARLO ────────────────────────────────────────────────────────
 * Task Scheduler (Windows), cada 1-2 minutos:
 *   C:\xampp\php\php.exe scripts\tms_push_pedidos.php
 *
 * Requiere en .env:
 *   TMS_ENDPOINT_URL=https://<tu-tms>/api/wms/recibir.php
 *   TMS_SHARED_SECRET=<secreto largo, mismo valor configurado en el TMS>
 * Si no están configuradas, el script se sale sin error (no bloquea nada
 * mientras el TMS aún no existe).
 * ─────────────────────────────────────────────────────────────────────────────
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);
require $rootDir . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\TmsPush;

$logFile = $rootDir . '/logs/tms_push_pedidos.log';
$log = function (string $msg) use ($logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
};

if (!(getenv('TMS_ENDPOINT_URL')) || !(getenv('TMS_SHARED_SECRET'))) {
    $log('TMS_ENDPOINT_URL / TMS_SHARED_SECRET no configurados en .env — nada que hacer.');
    exit(0);
}

$log('=== Push de pedidos certificados hacia el TMS ===');

// Mismo criterio "certificada, aún no despachada" ya usado en certPendientes()/
// _manualLineasQuery() de PickingController — IS DISTINCT FROM, no "!=", porque
// estado_certificacion NULL no debe colarse ni excluirse por accidente aquí.
$ordenes = Capsule::table('orden_pickings as o')
    ->where('o.estado_certificacion', 'Certificada')
    ->whereNull('o.estado_despacho')
    ->where(function ($q) {
        $q->whereNull('o.yms_sync_status')->orWhere('o.yms_sync_status', 'error');
    })
    ->pluck('o.id');

if ($ordenes->isEmpty()) {
    $log('Sin pedidos certificados pendientes de enviar.');
    exit(0);
}

$enviados = 0;
$fallidos = 0;

foreach ($ordenes as $ordenId) {
    $r = TmsPush::enviarOrden((int)$ordenId);
    if ($r['ok']) {
        $enviados++;
        $log("OK   pedido {$r['numero_pedido']} (orden #{$ordenId}) -> TMS");
    } else {
        $fallidos++;
        $log("ERROR orden #{$ordenId} -> {$r['message']}");
    }
}

$log("=== Fin: {$enviados} enviado(s), {$fallidos} fallido(s) ===");
