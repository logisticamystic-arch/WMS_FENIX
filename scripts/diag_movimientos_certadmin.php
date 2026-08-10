<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
use Illuminate\Database\Capsule\Manager as Capsule;
$c = new Capsule;
$c->addConnection([
    'driver'=>$_ENV['DB_DRIVER']??'pgsql','host'=>$_ENV['DB_HOST']??'127.0.0.1','port'=>$_ENV['DB_PORT']??'5432',
    'database'=>$_ENV['DB_NAME'],'username'=>$_ENV['DB_USER'],'password'=>$_ENV['DB_PASS'],
    'charset'=>'utf8','prefix'=>'','schema'=>'public',
]);
$c->setAsGlobal(); $c->bootEloquent();

echo "== Movimientos DescuentoCertificacion / DevolucionCertificacion (todo el historico) ==\n";
$mov = Capsule::select("
    SELECT *
    FROM movimiento_inventarios
    WHERE tipo_movimiento IN ('DescuentoCertificacion','DevolucionCertificacion')
    ORDER BY fecha_movimiento, hora_inicio
");
echo "Total: " . count($mov) . "\n";
foreach ($mov as $m) {
    echo "  " . json_encode($m) . "\n";
}

echo "\n== picking_detalles de las 19 ordenes con cert anomalo: estado_certificacion, ubicacion_id ==\n";
$rows = Capsule::select("
    SELECT pd.id, pd.orden_picking_id, pd.producto_id, pd.ubicacion_id, pd.cantidad_pickeada, pd.cantidad_certificada, pd.estado_certificacion
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    WHERE op.estado_certificacion = 'Certificada'
      AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada,0) <> pd.cantidad_pickeada
      AND op.fecha_certificacion >= '2026-08-06' AND op.fecha_certificacion < '2026-08-08'
");
foreach ($rows as $r) {
    echo "  det={$r->id} orden={$r->orden_picking_id} prod={$r->producto_id} ubic=" . ($r->ubicacion_id ?? 'NULL') . " pick={$r->cantidad_pickeada} cert={$r->cantidad_certificada} estcert={$r->estado_certificacion}\n";
}
