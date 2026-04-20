<?php
$pdo = new PDO('mysql:host=localhost;dbname=wms_prooriente', 'root', '');
$stmt = $pdo->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
$exclude = ['categorias', 'empresas', 'sucursales', 'personal', 'empresa', 'sucursal', 'marcas'];
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";
foreach($tables as $t) {
    if (!in_array(strtolower($t), $exclude)) {
        echo "TRUNCATE TABLE `$t`;\n";
    }
}
echo "\nSET FOREIGN_KEY_CHECKS = 1;\n";
