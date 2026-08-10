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

echo "== Columnas relevantes ==\n";
foreach (['orden_pickings', 'picking_detalles'] as $t) {
    $cols = Capsule::select(
        "SELECT column_name FROM information_schema.columns WHERE table_name=? AND column_name IN
         ('estado_certificacion','fecha_certificacion','cantidad_pickeada','cantidad_certificada','estado','id')",
        [$t]
    );
    echo "$t: " . implode(', ', array_map(fn($r) => $r->column_name, $cols)) . "\n";
}

echo "\n== Líneas con cantidad_pickeada > 0 pero cantidad_certificada distinta (órdenes certificadas) ==\n";
$rows = Capsule::select("
    SELECT op.id AS orden_id, op.fecha_certificacion, op.estado_certificacion,
           COUNT(pd.id) AS lineas_afectadas,
           SUM(pd.cantidad_pickeada) AS total_pickeado,
           SUM(COALESCE(pd.cantidad_certificada,0)) AS total_certificado
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    WHERE op.estado_certificacion = 'Certificada'
      AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada,0) <> pd.cantidad_pickeada
    GROUP BY op.id, op.fecha_certificacion, op.estado_certificacion
    ORDER BY op.fecha_certificacion ASC
");

echo "Órdenes afectadas: " . count($rows) . "\n";
$antes = 0; $despues = 0;
foreach ($rows as $r) {
    $esAntes = $r->fecha_certificacion && $r->fecha_certificacion < '2026-08-08';
    if ($esAntes) $antes++; else $despues++;
}
echo "  - Certificadas ANTES de 2026-08-08: $antes\n";
echo "  - Certificadas DESDE 2026-08-08 (fix ya aplicado, no deberían aparecer): $despues\n";

echo "\nDetalle (máx 20):\n";
foreach (array_slice($rows, 0, 20) as $r) {
    echo "  orden={$r->orden_id} fecha_cert={$r->fecha_certificacion} lineas={$r->lineas_afectadas} pickeado={$r->total_pickeado} certificado={$r->total_certificado}\n";
}

echo "\n== Detalle del/los caso(s) DESDE 2026-08-08 (no deberían existir si el fix cubrió todo) ==\n";
$posteriores = Capsule::select("
    SELECT op.id, op.fecha_certificacion, op.estado_certificacion, pd.id AS detalle_id,
           pd.cantidad_pickeada, pd.cantidad_certificada, pd.estado AS estado_linea
    FROM picking_detalles pd JOIN orden_pickings op ON op.id = pd.orden_picking_id
    WHERE op.estado_certificacion = 'Certificada' AND pd.cantidad_pickeada > 0
      AND COALESCE(pd.cantidad_certificada,0) <> pd.cantidad_pickeada
      AND op.fecha_certificacion >= '2026-08-08'
");
foreach ($posteriores as $r) {
    echo "  orden={$r->id} fecha_cert={$r->fecha_certificacion} detalle_id={$r->detalle_id} pickeado={$r->cantidad_pickeada} certificado={$r->cantidad_certificada} estado_linea={$r->estado_linea}\n";
}
