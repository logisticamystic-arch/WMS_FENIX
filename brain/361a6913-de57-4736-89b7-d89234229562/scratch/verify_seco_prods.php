<?php

require_once __DIR__ . '/../../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

echo "=== Verificación de Productos en Ambiente SECO ===\n\n";

$secoIds = DB::table('ambientes')
    ->whereRaw("UPPER(codigo) = 'SECO'")
    ->pluck('id')
    ->toArray();

echo "IDs de ambiente SECO encontrados: " . implode(', ', $secoIds) . "\n";

$prods = DB::table('productos')
    ->where(function($q) use ($secoIds) {
        if (!empty($secoIds)) {
            $q->whereIn('ambiente_id', $secoIds);
        }
        $q->orWhereRaw("UPPER(temperatura_almacen) = 'SECO'");
    })
    ->get();

echo "Total productos en ambiente SECO: " . $prods->count() . "\n\n";

$conControles = $prods->filter(fn($p) => $p->controla_lote || $p->controla_vencimiento);

echo "Productos en SECO con controla_lote o controla_vencimiento activados: " . $conControles->count() . "\n\n";

if ($conControles->count() > 0) {
    echo "¡ATENCIÓN! Se encontraron productos en SECO inconsistentes. Corrigiendo...\n";
    DB::table('productos')
        ->whereIn('id', $conControles->pluck('id')->toArray())
        ->update([
            'controla_lote' => false,
            'controla_vencimiento' => false
        ]);
    echo "¡Todos los productos fueron corregidos exitosamente!\n\n";
} else {
    echo "✓ VERIFICACIÓN EXITOSA: El 100% de los productos en ambiente SECO tienen deshabilitado el control de lote y vencimiento.\n\n";
}

echo "Detalle de los productos en ambiente SECO:\n";
foreach ($prods as $p) {
    $lote = $p->controla_lote ? 'SÍ' : 'NO';
    $venc = $p->controla_vencimiento ? 'SÍ' : 'NO';
    echo sprintf(" - [ID: %4d] Cod: %-15s | Lote: %-2s | Venc: %-2s | Nom: %s\n",
        $p->id, $p->codigo_interno, $lote, $venc, $p->nombre
    );
}

echo "\nListo.\n";
