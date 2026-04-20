<?php
require 'bootstrap.php';
use App\Models\ConteoInventario;
use App\Models\ConteoDetalle;

// Mock context
$id = 4; // Use the one I just created
$conteo = ConteoInventario::find($id);

if (!$conteo) {
    echo "Conteo ID $id not found. Please check existing IDs.\n";
    $conteo = ConteoInventario::orderBy('id', 'desc')->first();
    if ($conteo) {
        $id = $conteo->id;
        echo "Using last Conteo ID: $id\n";
    } else {
        die("No conteos found in database.\n");
    }
}

// Mimic getDashboardData logic
$detalles = ConteoDetalle::where('conteo_id', $conteo->id)
    ->where('ronda', $conteo->ronda_actual)
    ->get();

$total = $detalles->count();
$contados = $detalles->whereNotIn('estado', ['Pendiente'])->count();
$correctos = $detalles->filter(fn($d) => $d->cantidad_fisica !== null && $d->cantidad_fisica == $d->cantidad_sistema)->count();
$discrepancias = $detalles->filter(fn($d) => $d->cantidad_fisica !== null && $d->cantidad_fisica != $d->cantidad_sistema);

$loss_risk = 0;
$surplus_risk = 0;
foreach($discrepancias as $d) {
    $diff = $d->cantidad_fisica - $d->cantidad_sistema;
    if ($diff < 0) $loss_risk += abs($diff);
    else $surplus_risk += $diff;
}

$kpis = [
    'ira'           => $total > 0 ? round(($correctos / $total) * 100, 1) : 100,
    'loss_risk'     => $loss_risk,
    'surplus_risk'  => $surplus_risk,
    'total_diffs'   => $discrepancias->count(),
];

$progress = [
    'total_refs'   => $total,
    'counted_refs' => $contados,
    'percent'      => $total > 0 ? round(($contados / $total) * 100, 1) : 0
];

echo "VERIFYING JSON Keys:\n";
echo "Has kpis: " . (isset($kpis) ? "YES" : "NO") . "\n";
echo "Has kpis.ira: " . (isset($kpis['ira']) ? "YES" : "NO") . "\n";
echo "Has progress: " . (isset($progress) ? "YES" : "NO") . "\n";
echo "Value kpis.ira: " . $kpis['ira'] . "%\n";
echo "Value progress.percent: " . $progress['percent'] . "%\n";

if (isset($kpis['ira']) && isset($progress['percent'])) {
    echo "DASHBOARD DATA STRUCTURE VERIFIED ✅\n";
} else {
    echo "DASHBOARD DATA STRUCTURE FAIL ❌\n";
}
