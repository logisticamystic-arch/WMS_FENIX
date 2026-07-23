<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dsn = 'pgsql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'wms_fenix');
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'postgres', $_ENV['DB_PASS'] ?? '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$planillas = [16811, 16845];

foreach ($planillas as $p) {
    echo "=== Planilla $p ===\n";
    $stmt = $pdo->prepare("SELECT id, estado, estado_certificacion, sucursal_entrega, fecha_movimiento, despachado_directo FROM orden_pickings WHERE planilla_numero = ?");
    $stmt->execute([$p]);
    $ops = $stmt->fetchAll(PDO::FETCH_OBJ);
    foreach ($ops as $op) {
        echo "Orden ID: {$op->id} | Estado: {$op->estado} | Cert: {$op->estado_certificacion} | Sucursal: {$op->sucursal_entrega} | FechaMov: {$op->fecha_movimiento} | Directo: {$op->despachado_directo}\n";
        
        $stmt2 = $pdo->prepare("SELECT id, estado, producto_id, cantidad_pickeada, cantidad_solicitada FROM picking_detalles WHERE orden_picking_id = ?");
        $stmt2->execute([$op->id]);
        $detalles = $stmt2->fetchAll(PDO::FETCH_OBJ);
        foreach ($detalles as $d) {
            echo "  Detalle ID: {$d->id} | Estado: {$d->estado} | Prod: {$d->producto_id} | Pickeado: {$d->cantidad_pickeada} | Solicitado: {$d->cantidad_solicitada}\n";
            
            $stmt3 = $pdo->prepare("SELECT cantidad, unidad_id FROM packing_items WHERE picking_detalle_id = ?");
            $stmt3->execute([$d->id]);
            $packs = $stmt3->fetchAll(PDO::FETCH_OBJ);
            if (count($packs) > 0) {
                foreach ($packs as $pk) {
                    echo "    Packed: {$pk->cantidad} en Unidad: {$pk->unidad_id}\n";
                }
            } else {
                echo "    Packed: 0\n";
            }
        }
    }
}
