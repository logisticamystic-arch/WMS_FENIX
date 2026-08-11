<?php
/**
 * Backfill puntual: corrige picking_detalles.cantidad_certificada cuando su valor
 * coincide con cantidad_pickeada * precio_unitario del producto (el campo quedó con
 * un valor monetario en vez de una cantidad). Confirmado por diagnóstico manual el
 * 2026-08-10: 19 órdenes, patrón cert/pick == precio constante por producto.
 *
 * Excluye explícitamente la orden 1310 (patrón distinto: certificado < pickeado,
 * no coincide con precio — parece certificación parcial legítima, requiere revisión
 * manual aparte, NO se toca aquí).
 *
 * NO toca inventarios ni movimiento_inventarios — confirmado que ubicacion_id es
 * NULL en todas las líneas afectadas, por lo que el inventario real nunca se ajustó.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
use Illuminate\Database\Capsule\Manager as Capsule;
$c = new Capsule;
$c->addConnection([
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'     => $_ENV['DB_HOST']   ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT']   ?? '5432',
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8', 'prefix' => '', 'schema' => 'public',
]);
$c->setAsGlobal(); $c->bootEloquent();

$ordenesAfectadas = [1200,1201,1204,1206,1207,1208,1210,1212,1213,1214,1215,1216,1218,1220,1221,1223,1224,1225];

$afectadas = Capsule::select("
    SELECT pd.id AS detalle_id, pd.orden_picking_id, pd.cantidad_pickeada, pd.cantidad_certificada
    FROM picking_detalles pd
    WHERE pd.orden_picking_id = ANY(?)
      AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada, 0) <> pd.cantidad_pickeada
", ['{' . implode(',', $ordenesAfectadas) . '}']);

echo "Líneas a corregir: " . count($afectadas) . "\n";
if (!count($afectadas)) { echo "Nada que hacer.\n"; exit; }

$sample = 0;
foreach ($afectadas as $row) {
    if ($sample++ < 5) {
        echo "  detalle={$row->detalle_id} orden={$row->orden_picking_id} pick={$row->cantidad_pickeada} cert_actual={$row->cantidad_certificada} -> nuevo={$row->cantidad_pickeada}\n";
    }
}

Capsule::connection()->transaction(function () use ($afectadas) {
    foreach ($afectadas as $row) {
        Capsule::table('picking_detalles')
            ->where('id', $row->detalle_id)
            ->update(['cantidad_certificada' => $row->cantidad_pickeada]);
    }
});
// orden_pickings no tiene columnas total_* en el esquema actual (se agregan al vuelo
// vía SUM() en la lectura, ej. certRemisionMultiple) — no hay agregado que recalcular aquí.

echo "\nBackfill aplicado. Verificación:\n";
$restantes = Capsule::select("
    SELECT COUNT(*) AS n FROM picking_detalles pd
    WHERE pd.orden_picking_id = ANY(?)
      AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada, 0) <> pd.cantidad_pickeada
", ['{' . implode(',', $ordenesAfectadas) . '}']);
echo "  Líneas con discrepancia restante en esas 18 órdenes: {$restantes[0]->n}\n";
