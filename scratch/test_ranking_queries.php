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
$fecha = date('Y-m-d');
$start = $fecha . ' 00:00:00';
$end   = $fecha . ' 23:59:59';
$empresaId = 2;
$sucursalId = 2;

echo "=== PRUEBA DE CONSULTAS AVANZADAS RANKING DE PICKING ===" . PHP_EOL;

// 1. Resumen global de SKUs y Unidades (Solicitadas, Pickeadas, Pendientes)
$stmtTotals = $pdo->prepare("
    SELECT
        COUNT(DISTINCT pd.producto_id) as total_refs_solicitadas,
        COUNT(DISTINCT CASE WHEN pd.estado IN ('Completado', 'Completada') THEN pd.producto_id END) as total_refs_completadas,
        COUNT(DISTINCT CASE WHEN pd.estado NOT IN ('Completado', 'Completada') THEN pd.producto_id END) as total_refs_pendientes,
        COALESCE(SUM(pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1)), 0) as total_unidades_solicitadas,
        COALESCE(SUM(pd.cantidad_pickeada), 0) as total_unidades_completadas,
        COALESCE(SUM(GREATEST(0, (pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1)) - pd.cantidad_pickeada)), 0) as total_unidades_pendientes
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    LEFT JOIN productos p ON p.id = pd.producto_id
    WHERE op.empresa_id = :emp
      AND op.sucursal_id = :suc
      AND (op.fecha_movimiento::date = :fecha OR pd.created_at BETWEEN :start AND :end)
");
$stmtTotals->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha, ':start' => $start, ':end' => $end]);
$totals = $stmtTotals->fetch(\PDO::FETCH_ASSOC);

echo "--- TOTALES GLOBALES DEL DÍA ---" . PHP_EOL;
print_r($totals);

// 2. Ranking completo de Auxiliares por múltiples métricas
$stmtAux = $pdo->prepare("
    SELECT
        COALESCE(pe.id, 0) as auxiliar_id,
        COALESCE(pe.nombre, 'Sin asignar') AS nombre,
        COUNT(DISTINCT CASE WHEN pd.cantidad_pickeada > 0 THEN pd.producto_id END) AS referencias,
        COALESCE(SUM(pd.cantidad_pickeada), 0) AS unidades,
        COUNT(DISTINCT CASE WHEN pd.estado = 'Faltante' OR pf.id IS NOT NULL THEN pd.producto_id END) AS agotados,
        COALESCE(SUM(CASE WHEN pd.estado = 'Faltante' OR pf.id IS NOT NULL THEN pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1) ELSE 0 END), 0) AS unidades_agotadas,
        ROUND(AVG(
            CASE 
                WHEN op.hora_inicio IS NOT NULL AND op.hora_fin IS NOT NULL AND op.hora_fin >= op.hora_inicio 
                THEN EXTRACT(EPOCH FROM (op.hora_fin::time - op.hora_inicio::time))/60 
                ELSE NULL 
            END
        )::numeric, 1) as tiempo_promedio_min
    FROM picking_detalles pd
    JOIN orden_pickings op ON op.id = pd.orden_picking_id
    LEFT JOIN productos p ON p.id = pd.producto_id
    LEFT JOIN personal pe ON pe.id = COALESCE(pd.auxiliar_id, op.auxiliar_id)
    LEFT JOIN picking_faltantes pf ON pf.orden_picking_id = op.id AND pf.producto_id = pd.producto_id
    WHERE op.empresa_id = :emp
      AND op.sucursal_id = :suc
      AND (op.fecha_movimiento::date = :fecha OR pd.created_at BETWEEN :start AND :end)
    GROUP BY pe.id, pe.nombre
    HAVING COUNT(pd.id) > 0
    ORDER BY referencias DESC, unidades DESC
");
$stmtAux->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':fecha' => $fecha, ':start' => $start, ':end' => $end]);
$auxiliares = $stmtAux->fetchAll(\PDO::FETCH_ASSOC);

echo "--- MÉTRICAS POR AUXILIAR (" . count($auxiliares) . ") ---" . PHP_EOL;
print_r($auxiliares);
