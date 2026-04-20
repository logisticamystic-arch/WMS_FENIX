<?php
/**
 * API: Varianza de ODCs para dashboard
 * GET /public/api/dashboard-variance.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$cfg = require __DIR__ . '/../../config/database.php';
$db = new Illuminate\Database\Capsule\Manager();
$db->addConnection($cfg);
$db->setAsGlobal();
$db->bootEloquent();

header('Content-Type: application/json; charset=utf-8');

try {
    // Varianza por ODC y SKU (últimos 30 días)
    $variance = $db::table('ordenes_compra as o')
        ->join('orden_compra_detalles as od', 'od.orden_compra_id', '=', 'o.id')
        ->leftJoin('recepciones as r', 'r.odc_id', '=', 'o.id')
        ->leftJoin('recepcion_detalles as rd', 'rd.recepcion_id', '=', 'r.id')
        ->leftJoin('productos as p', 'p.id', '=', 'od.producto_id')
        ->select(
            'o.numero_odc',
            'p.codigo_interno as sku',
            'p.nombre as descripcion',
            $db::raw('SUM(od.cantidad_solicitada) as total_solicitado'),
            $db::raw('COALESCE(SUM(rd.cantidad_recibida), 0) as total_recibido'),
            $db::raw('COALESCE(SUM(CASE WHEN rd.estado_mercancia = "BuenEstado" THEN rd.cantidad_recibida ELSE 0 END), 0) as recibido_bueno'),
            $db::raw('COALESCE(SUM(CASE WHEN rd.estado_mercancia != "BuenEstado" THEN rd.cantidad_recibida ELSE 0 END), 0) as recibido_novedad'),
            $db::raw('ROUND(COALESCE(SUM(rd.cantidad_recibida), 0) * 100.0 / NULLIF(SUM(od.cantidad_solicitada), 0), 1) as porcentaje_cumplimiento')
        )
        ->where('o.created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
        ->groupBy('o.id', 'p.codigo_interno', 'p.nombre')
        ->orderByDesc('porcentaje_cumplimiento')
        ->limit(20)
        ->get();

    echo json_encode($variance);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
