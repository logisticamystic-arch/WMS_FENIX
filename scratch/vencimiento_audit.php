<?php

require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Inventario;
use App\Models\Producto;

echo "--- INICIO DE AUDITORÍA DE VENCIMIENTOS ---\n";

$registrosHuerfanos = Inventario::where('cantidad', '>', 0)
    ->where(function($q) {
        $q->whereNull('fecha_vencimiento')
          ->orWhere('fecha_vencimiento', '0000-00-00');
    })
    ->get();

$conteo = 0;
$corregidos = 0;

foreach ($registrosHuerfanos as $inv) {
    $producto = $inv->producto;
    if ($producto && $producto->controla_vencimiento) {
        $conteo++;
        echo "ALERTA: Producto [{$producto->codigo_interno}] {$producto->nombre} en ubicación [{$inv->ubicacion?->codigo}] tiene stock ({$inv->cantidad}) sin fecha de vencimiento.\n";
        
        // Intentar búsqueda de recuperación (Fallback)
        // Buscamos en recepcion_detalles un lote coincidente que SI tenga fecha
        $recDetail = Capsule::table('recepcion_detalles')
            ->where('producto_id', $inv->producto_id)
            ->where('lote', $inv->lote)
            ->whereNotNull('fecha_vencimiento')
            ->orderBy('id', 'desc')
            ->first();

        if ($recDetail) {
            echo "   -> RECUPERACIÓN: Se encontró fecha [{$recDetail->fecha_vencimiento}] en recepción previa. Aplicando...\n";
            $inv->fecha_vencimiento = $recDetail->fecha_vencimiento;
            $inv->save();
            $corregidos++;
        } else {
            echo "   -> ERROR: No se encontró referencia previa para este lote. Se requiere intervención manual.\n";
        }
    }
}

echo "--- RESUMEN DE AUDITORÍA ---\n";
echo "Total registros huérfanos detectados (con control de vencimiento): $conteo\n";
echo "Total registros recuperados auto: $corregidos\n";
echo "Pendientes de corrección manual: " . ($conteo - $corregidos) . "\n";
echo "--- FIN ---\n";
