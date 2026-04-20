<?php
/**
 * API: Categorías de productos para filtros del dashboard
 * GET /public/api/dashboard-categorias.php
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
    $categories = $db::table('categoria_productos')
        ->select('id', 'nombre')
        ->orderBy('nombre')
        ->get();

    echo json_encode($categories);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
