<?php
require __DIR__ . '/src/bootstrap.php';
$p = \App\Models\OrdenPicking::where(function($q) {
  $q->where('planilla_numero', 'Planilla 1157')
    ->orWhere('numero_orden', 'Planilla 1157')
    ->orWhere('planilla_lote', 'Planilla 1157')
    ->orWhere('planilla_numero', '1157')
    ->orWhere('numero_orden', '1157')
    ->orWhere('planilla_lote', '1157');
})->get(['id', 'planilla_numero', 'numero_orden', 'planilla_lote']);
echo json_encode($p, JSON_PRETTY_PRINT);
