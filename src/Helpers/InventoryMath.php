<?php
namespace App\Helpers;

use App\Models\Producto;

class InventoryMath
{
    /**
     * Convert boxes and loose units (saldos) to total units using the product's UPC factor.
     */
    public static function unitsFromBoxes(int $cajas, float $saldos, int $upc): float
    {
        $upc = $upc > 0 ? $upc : 1;
        return $cajas * $upc + $saldos;
    }

    /**
     * Convert total units to boxes and saldos using the product's UPC factor.
     * Returns [int $cajas, float $saldos]
     */
    public static function boxesFromUnits(float $units, int $upc): array
    {
        $upc = $upc > 0 ? $upc : 1;
        $cajas = (int) floor($units / $upc);
        $saldos = round(fmod($units, (float) $upc), 4);
        return [$cajas, $saldos];
    }

    /**
     * Get UPC factor for a product (prefers factor_udm, then unidades_caja, default 1).
     */
    public static function getUpcFactor(Producto $product): int
    {
        $factor = $product->factor_udm ?? $product->unidades_caja ?? null;
        $upc = (int) ($factor ?: 1);
        return $upc > 0 ? $upc : 1;
    }
}
?>
