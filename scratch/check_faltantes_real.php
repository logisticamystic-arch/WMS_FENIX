<?php
require __DIR__ . '/../bootstrap.php';
$C = Illuminate\Database\Capsule\Manager::class;

echo "PHP date('Y-m-d H:i:s') con bootstrap real: " . date('Y-m-d H:i:s') . "\n";
echo "Timezone activo: " . date_default_timezone_get() . "\n\n";

$hoy = date('Y-m-d');
echo "=== Filtrando 'hoy' = $hoy (igual que hace novedadesStockLegacy) ===\n";
$rows = $C::table('picking_faltantes')
    ->whereBetween('created_at', ["$hoy 00:00:00", "$hoy 23:59:59"])
    ->count();
echo "picking_faltantes hoy: $rows\n";

$ultimos = $C::table('picking_faltantes')->whereBetween('created_at', ["$hoy 00:00:00", "$hoy 23:59:59"])->orderBy('created_at','desc')->limit(5)->get();
foreach ($ultimos as $r) echo "  {$r->created_at} | prod:{$r->producto_id} | causa:{$r->causa}\n";
