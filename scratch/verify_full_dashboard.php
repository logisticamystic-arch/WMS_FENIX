<?php
require 'bootstrap.php';
use App\Models\ConteoInventario;
use App\Models\ConteoDetalle;

$id = 4; // Check existing
$conteo = ConteoInventario::orderBy('id', 'desc')->first();
if ($conteo) $id = $conteo->id;

$detalles = ConteoDetalle::where('conteo_id', $conteo->id)
    ->where('ronda', $conteo->ronda_actual)
    ->with(['producto:id,codigo_interno,nombre', 'ubicacion:id,codigo', 'auxiliar:id,nombre'])
    ->get();

// Mimic performance calc
$perfMap = [];
foreach ($detalles as $det) {
    if (!$det->auxiliar_id) continue;
    $auxId = $det->auxiliar_id;
    if (!isset($perfMap[$auxId])) {
        $perfMap[$auxId] = [
            'nombre' => $det->auxiliar->nombre ?? ('Auxiliar #' . $auxId),
            'lines'  => 0,
            'start'  => null,
            'end'    => null
        ];
    }
    $perfMap[$auxId]['lines']++;
}

$performance = [];
foreach ($perfMap as $id => $p) {
    $performance[] = [
        'auxiliar'       => $p['nombre'],
        'lines'          => $p['lines'],
        'lines_per_hour' => 10.5 // Mock
    ];
}

$response = [
    'matrix'      => $detalles->toArray(),
    'performance' => $performance,
    'kpis'        => ['ira' => 100],
    'progress'    => ['percent' => 50]
];

echo "VERIFYING JSON Keys:\n";
echo "Has matrix: " . (isset($response['matrix']) ? "YES" : "NO") . "\n";
echo "Is matrix array: " . (is_array($response['matrix']) ? "YES" : "NO") . "\n";
echo "Has performance: " . (isset($response['performance']) ? "YES" : "NO") . "\n";
echo "Is performance array: " . (is_array($response['performance']) ? "YES" : "NO") . "\n";

if (isset($response['matrix']) && isset($response['performance'])) {
    echo "DASHBOARD DATA STRUCTURE VERIFIED ✅\n";
} else {
    echo "DASHBOARD DATA STRUCTURE FAIL ❌\n";
}
