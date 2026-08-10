<?php
/**
 * Backfill puntual: repara picking_detalles.cantidad_certificada = 0 en órdenes
 * ya Certificadas ANTES del fix del 2026-08-08 (finalizarSesion/recertificar/autoPack
 * no copiaban cantidad_pickeada -> cantidad_certificada).
 *
 * Alcance deliberadamente ACOTADO al patrón exacto del bug para no tocar
 * certificaciones parciales legítimas:
 *   - orden.estado_certificacion = 'Certificada'
 *   - orden.fecha_certificacion < 2026-08-08
 *   - cantidad_pickeada > 0
 *   - cantidad_certificada = 0 (no NULL, no parcial distinto de 0)
 *
 * NO toca: inventarios, movimientos_inventario/kardex, cantidad_pickeada,
 * cantidad_solicitada. Solo corrige el campo de reporte usado para imprimir remisión.
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

$afectadas = Capsule::select("
    SELECT pd.id AS detalle_id, pd.orden_picking_id, pd.cantidad_pickeada, pd.cantidad_certificada
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    WHERE op.estado_certificacion = 'Certificada'
      AND op.fecha_certificacion < '2026-08-08'
      AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada, 0) = 0
");

echo "Líneas a corregir: " . count($afectadas) . "\n";
if (!count($afectadas)) { echo "Nada que hacer.\n"; exit; }

Capsule::connection()->transaction(function () use ($afectadas) {
    foreach ($afectadas as $row) {
        Capsule::table('picking_detalles')
            ->where('id', $row->detalle_id)
            ->update(['cantidad_certificada' => $row->cantidad_pickeada]);
    }
});

echo "Backfill aplicado. Verificación post-fix:\n";
$restantes = Capsule::select("
    SELECT COUNT(*) AS n
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    WHERE op.estado_certificacion = 'Certificada'
      AND op.fecha_certificacion < '2026-08-08'
      AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada, 0) = 0
");
echo "  Líneas con el patrón del bug aún pendientes: {$restantes[0]->n}\n";

$ordenes = Capsule::select("SELECT DISTINCT orden_picking_id FROM (SELECT unnest(?::int[]) AS orden_picking_id) x",
    ['{' . implode(',', array_unique(array_map(fn($r) => $r->orden_picking_id, $afectadas))) . '}']);
echo "  Órdenes corregidas: " . count($ordenes) . "\n";
