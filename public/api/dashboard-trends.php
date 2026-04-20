<?php
/**
 * API: Tendencias diarias para dashboard
 * GET /public/api/dashboard-trends.php
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
    // Citas completadas por día (últimos 30 días)
    $citasDiarias = $db::table('citas')
        ->select(
            $db::raw('DATE(completed_at) as fecha'),
            $db::raw('COUNT(*) as completadas')
        )
        ->where('estado', 'Completada')
        ->where('completed_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
        ->groupByRaw('DATE(completed_at)')
        ->orderBy('fecha')
        ->get();

    // Calidad por día (últimos 30 días)
    $calidadDiaria = $db::table('recepciones as r')
        ->select(
            $db::raw('DATE(r.created_at) as fecha'),
            $db::raw('ROUND(COUNT(CASE WHEN rd.estado = "BuenEstado" THEN 1 END) * 100.0 / COUNT(*), 2) as porcentaje')
        )
        ->leftJoin('recepcion_detalles as rd', 'r.id', '=', 'rd.recepcion_id')
        ->where('r.created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
        ->groupByRaw('DATE(r.created_at)')
        ->orderBy('fecha')
        ->get();

    echo json_encode([
        'citas_diarias' => $citasDiarias,
        'calidad_diaria' => $calidadDiaria
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
