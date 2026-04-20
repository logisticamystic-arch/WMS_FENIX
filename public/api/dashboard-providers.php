<?php
/**
 * API: Desempeño de proveedores para dashboard
 * GET /public/api/dashboard-providers.php
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
    $providers = $db::table('proveedores')
        ->select(
            'id',
            'razon_social',
            'clasificacion',
            'indice_desempeno_pct',
            'cumplimiento_entregas_pct',
            'cumplimiento_citas_pct',
            'calidad_aceptacion_pct',
            'evaluacion_promedio'
        )
        ->where('estado', 1)
        ->orderByDesc('indice_desempeno_pct')
        ->limit(10)
        ->get();

    echo json_encode($providers);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
