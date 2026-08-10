<?php
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

$rows = Capsule::select("
    SELECT pd.id, pd.orden_picking_id, pd.producto_id, pd.cantidad_solicitada, pd.cantidad_pickeada,
           pd.cantidad_certificada, pd.updated_at,
           p.nombre AS producto, p.precio_venta, p.precio_compra, p.unidades_caja
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    LEFT JOIN productos p ON p.id = pd.producto_id
    WHERE op.id IN (1200, 1201, 1205, 1224)
    ORDER BY pd.orden_picking_id, pd.id
");
foreach ($rows as $r) {
    $ratio = $r->cantidad_pickeada > 0 ? round($r->cantidad_certificada / $r->cantidad_pickeada, 4) : null;
    echo "orden={$r->orden_picking_id} detalle={$r->id} prod={$r->producto_id} ({$r->producto}) sol={$r->cantidad_solicitada} pick={$r->cantidad_pickeada} cert={$r->cantidad_certificada} ratio_cert/pick={$ratio} precio_venta={$r->precio_venta} precio_compra={$r->precio_compra} upc={$r->unidades_caja} updated_at={$r->updated_at}\n";
}
