<?php
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

$tables = ['sesiones_inventario', 'sesion_asignaciones', 'sesion_lineas', 'ajustes_inventario'];
$missing = [];

foreach ($tables as $t) {
    if (!Capsule::schema()->hasTable($t)) {
        $missing[] = $t;
    }
}

if (empty($missing)) {
    echo "INFRAESTRUCTURA V2 VERIFICADA ✅ TODAS LAS TABLAS EXISTEN.\n";
} else {
    echo "ERROR: Faltan las siguientes tablas:\n";
    foreach ($missing as $m) echo "- $m\n";
    exit(1);
}
