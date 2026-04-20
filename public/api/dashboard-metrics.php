<?php
/**
 * API: Métricas principales del dashboard
 * GET /public/api/dashboard-metrics.php
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
    $stats = [
        'recepciones_activas' => $db::table('recepciones')
            ->whereIn('estado', ['Borrador', 'EnProceso'])
            ->count(),

        'odc_abiertas' => $db::table('ordenes_compra')
            ->whereIn('estado', ['Confirmada', 'En Proceso'])
            ->count(),

        'citas_completadas' => $db::table('citas')
            ->where('estado', 'Completada')
            ->whereDate('created_at', '>=', date('Y-m-01'))
            ->count(),

        'proveedores_activos' => $db::table('proveedores')
            ->where('estado', '=', 1)
            ->count(),

        'pendientes_revision' => $db::table('recepcion_detalles as rd')
            ->join('recepciones as r', 'rd.recepcion_id', '=', 'r.id')
            ->where('rd.aprobado_admin', 0)
            ->where('r.estado', 'Cerrada')
            ->count()
    ];

    echo json_encode($stats);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
