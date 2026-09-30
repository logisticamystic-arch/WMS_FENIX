<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();

$targets = [
    ['producto_id'=>6003,'ubicacion_id'=>2476,'lote'=>'N/A'],
    ['producto_id'=>6805,'ubicacion_id'=>2378,'lote'=>'3/09/2026'],
    ['producto_id'=>5936,'ubicacion_id'=>3332,'lote'=>'G'],
    ['producto_id'=>6805,'ubicacion_id'=>2368,'lote'=>'14/09/2026'],
    ['producto_id'=>5792,'ubicacion_id'=>2548,'lote'=>'N/A'],
    ['producto_id'=>5842,'ubicacion_id'=>2605,'lote'=>'G'],
    ['producto_id'=>5795,'ubicacion_id'=>2677,'lote'=>'01/09/2026'],
];
foreach ($targets as $t) {
    $rows = $pdo->query("SELECT id, orden_picking_id, cantidad_solicitada, estado FROM picking_detalles WHERE producto_id={$t['producto_id']} AND ubicacion_id={$t['ubicacion_id']} AND lote=" . $pdo->quote($t['lote']) . " AND estado IN ('EnProceso','Pendiente')")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($t) . " => " . json_encode($rows) . PHP_EOL;
}
