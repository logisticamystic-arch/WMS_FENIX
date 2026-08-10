<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT'] ?? '5432',
    'database' => $_ENV['DB_NAME'] ?? 'wms_fenix',
    'username' => $_ENV['DB_USER'] ?? 'postgres',
    'password' => $_ENV['DB_PASS'] ?? 'Logistica2101+',
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$pdo = Capsule::connection()->getPdo();

$stmtUnits = $pdo->prepare("
    SELECT
        COALESCE(pe.nombre, 'Sin asignar') AS nombre,
        SUM(pd.cantidad_pickeada) AS unidades
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    LEFT JOIN personal pe ON pe.id = COALESCE(pd.auxiliar_id, op.auxiliar_id)
    WHERE op.empresa_id = 2
      AND pd.cantidad_pickeada > 0
    GROUP BY pe.id, pe.nombre
    ORDER BY unidades DESC
    LIMIT 10
");
$stmtUnits->execute();
echo "Ranking Units (SUM pd.cantidad_pickeada):" . PHP_EOL;
print_r($stmtUnits->fetchAll(PDO::FETCH_ASSOC));

$stmtAmb = $pdo->prepare("
    SELECT
        LOWER(COALESCE(NULLIF(pd.ambiente,''), am.codigo, 'seco')) AS ambiente,
        COUNT(pd.id) AS total_lineas,
        COUNT(CASE WHEN pd.estado IN ('Completado', 'Completada', 'Faltante') THEN 1 END) AS lineas_completadas,
        SUM(pd.cantidad_solicitada) AS unidades_solicitadas,
        SUM(CASE WHEN pd.estado IN ('Completado', 'Completada') THEN pd.cantidad_pickeada ELSE 0 END) AS unidades_separadas
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    LEFT JOIN productos pr ON pr.id = pd.producto_id
    LEFT JOIN ambientes am ON am.id = pr.ambiente_id
    WHERE op.empresa_id = 2
      AND op.estado IN ('Pendiente', 'EnProceso')
    GROUP BY LOWER(COALESCE(NULLIF(pd.ambiente,''), am.codigo, 'seco'))
");
$stmtAmb->execute();
echo "Ambiente breakdown active orders:" . PHP_EOL;
print_r($stmtAmb->fetchAll(PDO::FETCH_ASSOC));
