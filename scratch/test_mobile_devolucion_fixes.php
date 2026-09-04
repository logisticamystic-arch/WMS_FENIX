<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Models\CausalDevolucion;
use App\Models\Sucursal;
use App\Models\Producto;

echo "=== VERIFICACIÓN DE CAUSALES DE DEVOLUCIÓN ===" . PHP_EOL;
$causales = CausalDevolucion::where('activo', true)->get();
echo "Total causales activas en BD: " . $causales->count() . PHP_EOL;
foreach ($causales as $c) {
    echo "  - ID: {$c->id} | Empresa: {$c->empresa_id} | Causal: {$c->causal} | Responsable: {$c->responsable}" . PHP_EOL;
}

echo PHP_EOL . "=== VERIFICACIÓN DE SUCURSALES ===" . PHP_EOL;
$sucursales = Sucursal::where('activo', true)->get();
echo "Total sucursales activas en BD: " . $sucursales->count() . PHP_EOL;
foreach ($sucursales as $s) {
    echo "  - ID: {$s->id} | Empresa: {$s->empresa_id} | Nombre: {$s->nombre}" . PHP_EOL;
}

echo PHP_EOL . "=== VERIFICACIÓN BÚSQUEDA DE PRODUCTOS ===" . PHP_EOL;
$prods = Producto::limit(5)->get();
echo "Total productos de muestra: " . $prods->count() . PHP_EOL;
foreach ($prods as $p) {
    echo "  - ID: {$p->id} | Nombre: {$p->nombre} | Código: {$p->codigo_interno}" . PHP_EOL;
}
