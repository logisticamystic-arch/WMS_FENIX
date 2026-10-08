<?php
/**
 * Cierre automático diario de Recepciones sin ODC (pedido de Camilo, 2026-10-07).
 *
 * Desde el 2026-10-07 cada captura sin ODC entra sola al inventario al tener la
 * inspección de calidad de su referencia (RecepcionController::cargarInspeccionadasSinOdc),
 * pero el recibo queda en Borrador. Esta tarea, al final del día, cierra los recibos
 * sin ODC en Borrador cuyas capturas YA están todas en inventario, con el mismo
 * RecepcionController::confirm() del botón "Cerrar recibo" (no mueve stock).
 *
 * NO cierra (quedan para revisión, se registran en el log):
 *   - recibos con capturas aún sin cargar (falta calidad u otra causa): cerrarlos
 *     cargaría stock sin la inspección obligatoria;
 *   - recibos vacíos (sin capturas).
 *
 * Programada por scripts/instalar_tarea_cierre_recepciones.ps1 (23:30 diario).
 * Uso manual:  php scripts/cerrar_recepciones_dia.php [--dry-run]
 * Log:         logs/cierre_recepciones.log
 */
require __DIR__ . '/../bootstrap.php';

use App\Controllers\RecepcionController;
use App\Models\Personal;
use App\Models\Recepcion;
use Illuminate\Database\Capsule\Manager as DB;

$dryRun = in_array('--dry-run', $argv ?? [], true);
$logFile = __DIR__ . '/../logs/cierre_recepciones.log';
$log = function (string $msg) use ($logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
};

$borradores = Recepcion::whereNull('odc_id')->where('estado', 'Borrador')
    ->withCount([
        'detalles',
        'detalles as pendientes_count' => fn($q) => $q->where('aprobado_admin', 0),
    ])
    ->orderBy('id')->get();

$ctrl = new RecepcionController();
$cerrados = 0; $omitidos = [];

foreach ($borradores as $r) {
    if ($r->detalles_count === 0)  { $omitidos[] = "{$r->numero_recepcion} (vacío)"; continue; }
    if ($r->pendientes_count > 0)  { $omitidos[] = "{$r->numero_recepcion} ({$r->pendientes_count} captura(s) sin cargar)"; continue; }
    if ($dryRun) { $cerrados++; continue; }

    // Cierra a nombre del auxiliar del recibo; si ya no está activo, otro activo de la sucursal.
    $user = Personal::where('id', $r->auxiliar_id)->where('activo', true)->first()
         ?? Personal::where('sucursal_id', $r->sucursal_id)->where('activo', true)->orderBy('id')->first();
    if (!$user) { $omitidos[] = "{$r->numero_recepcion} (sin usuario activo en la sucursal)"; continue; }

    $req = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/cron/cierre-recepciones')
        ->withAttribute('user', $user);
    $res  = $ctrl->confirm($req, new Slim\Psr7\Response(), ['id' => $r->id]);
    $body = json_decode((string)$res->getBody(), true);
    if ($res->getStatusCode() === 200 && empty($body['error'])) {
        $cerrados++;
    } else {
        $omitidos[] = "{$r->numero_recepcion} (" . ($body['message'] ?? 'HTTP ' . $res->getStatusCode()) . ')';
    }
}

$log(($dryRun ? '[SIMULACIÓN] ' : '') . "Recibos sin ODC en Borrador: {$borradores->count()} · cerrados: {$cerrados} · sin cerrar: " . count($omitidos));
foreach ($omitidos as $o) $log('  sin cerrar: ' . $o);
