<?php
$pdo = new PDO('mysql:host=localhost;dbname=wms_prooriente', 'root', '');
foreach(['productos', 'categoria_productos', 'picking_detalles', 'orden_pickings', 'despachos'] as $t) {
    echo "=== SCHEMA PARA $t ===\n";
    $stmt = $pdo->query("SHOW CREATE TABLE $t");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . "\n\n";
}
