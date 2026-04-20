<?php
/**
 * API: Recepciones activas para dashboard
 * GET /public/api/dashboard-receptions.php
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
    $params = $_GET;
    $numeroOdc = trim($params['numero_odc'] ?? '');
    $proveedor = trim($params['proveedor'] ?? '');
    $categoria = (int)($params['categoria_id'] ?? 0);
    $auxiliarId = (int)($params['auxiliar_id'] ?? 0);

    $received = $db::table('recepciones as r')
        ->join('recepcion_detalles as rd', 'rd.recepcion_id', '=', 'r.id')
        ->select('r.odc_id', $db::raw('SUM(rd.cantidad_recibida) as total_recibido'))
        ->groupBy('r.odc_id');

    $odcs = $db::table('ordenes_compra as o')
        ->leftJoin('proveedores as p', 'o.proveedor_id', '=', 'p.id')
        ->leftJoin('orden_compra_detalles as d', 'd.orden_compra_id', '=', 'o.id')
        ->leftJoinSub($received, 'rec', 'rec.odc_id', '=', 'o.id')
        ->select(
            'o.id',
            'o.numero_odc',
            'o.estado',
            'p.razon_social as proveedor',
            $db::raw('COUNT(DISTINCT d.id) as lineas'),
            $db::raw('COALESCE(SUM(d.cantidad_solicitada), 0) as total_solicitado'),
            $db::raw('COALESCE(rec.total_recibido, 0) as total_recibido'),
            $db::raw('CASE WHEN SUM(d.cantidad_solicitada) > 0 THEN ROUND(COALESCE(rec.total_recibido, 0) * 100.0 / SUM(d.cantidad_solicitada), 1) ELSE 0 END as cumplimiento')
        )
        ->whereIn('o.estado', ['Confirmada', 'En Proceso'])
        ->groupBy('o.id', 'o.numero_odc', 'o.estado', 'p.razon_social', 'rec.total_recibido');

    if ($numeroOdc !== '') {
        $odcs->where('o.numero_odc', 'like', '%' . $numeroOdc . '%');
    }
    if ($proveedor !== '') {
        $odcs->where('p.razon_social', 'like', '%' . $proveedor . '%');
    }
    if ($categoria > 0) {
        $odcs->whereExists(function ($query) use ($categoria, $db) {
            $query->select($db::raw(1))
                ->from('orden_compra_detalles as od2')
                ->join('productos as pr', 'pr.id', '=', 'od2.producto_id')
                ->whereColumn('od2.orden_compra_id', 'o.id')
                ->where('pr.categoria_id', $categoria);
        });
    }
    if ($auxiliarId > 0) {
        $odcs->whereExists(function ($query) use ($auxiliarId, $db) {
            $query->select($db::raw(1))
                ->from('odc_auxiliares')
                ->whereColumn('odc_auxiliares.orden_compra_id', 'o.id')
                ->where('odc_auxiliares.auxiliar_id', $auxiliarId);
        });
    }

    $receptions = $odcs->orderByDesc('o.created_at')->limit(30)->get();

    echo json_encode($receptions);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
