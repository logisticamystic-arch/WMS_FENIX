<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();

$rows = $pdo->query("
  SELECT pd.id, pd.orden_picking_id, pd.producto_id, p.nombre, p.unidades_caja, p.factor_udm,
         pd.ubicacion_id, u.codigo, pd.lote, pd.estado
  FROM picking_detalles pd
  JOIN productos p ON p.id=pd.producto_id
  LEFT JOIN ubicaciones u ON u.id=pd.ubicacion_id
  WHERE pd.cantidad_solicitada = 0 AND pd.created_at >= '2026-09-17 14:00:00' AND pd.estado='EnProceso'
  ORDER BY pd.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $upc = ($r['factor_udm'] && $r['factor_udm'] > 0) ? $r['factor_udm'] : $r['unidades_caja'];
    $loteCond = $r['lote'] === null ? "lote IS NULL" : "lote = " . $pdo->quote($r['lote']);
    $inv = $pdo->query("SELECT id, lote, cantidad, cantidad_reservada FROM inventarios WHERE producto_id={$r['producto_id']} AND ubicacion_id={$r['ubicacion_id']} AND $loteCond")->fetchAll(PDO::FETCH_ASSOC);
    echo "detalle #{$r['id']} pedido#{$r['orden_picking_id']} {$r['nombre']} @ {$r['codigo']} lote=" . var_export($r['lote'],true) . " upc=$upc\n";
    foreach ($inv as $i) {
        echo "   inv#{$i['id']} cantidad={$i['cantidad']} reservada={$i['cantidad_reservada']}\n";
    }
}
