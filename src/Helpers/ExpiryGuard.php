<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * ExpiryGuard — Central expiry enforcement service.
 *
 * R10: fecha_vencimiento < today  → BLOCKED (no exceptions)
 * R11: 1 ≤ dias_restantes ≤ 5    → PENDING (supervisor approval required)
 *
 * autoQuarantine() marks all expired inventory rows as Cuarentena.
 * Called lazy from FefoEngine and on each BLOCKED result.
 */
class ExpiryGuard
{
    public function __construct(
        private readonly int $empresaId,
        private readonly int $sucursalId
    ) {}

    /**
     * Check expiry status for a specific product+lote at time of picking/packing.
     * If lote is null or no fecha_vencimiento exists in inventory, returns OK.
     */
    public function check(int $productoId, ?string $lote, int $solicitadoPor): ExpiryResult
    {
        if ($lote === null) {
            return new ExpiryResult(ExpiryResult::OK);
        }

        $inv = Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('producto_id', $productoId)
            ->where('lote',        $lote)
            ->whereNotNull('fecha_vencimiento')
            ->orderBy('fecha_vencimiento', 'asc')
            ->first();

        if (!$inv || !$inv->fecha_vencimiento) {
            return new ExpiryResult(ExpiryResult::OK);
        }

        $today         = strtotime(date('Y-m-d'));
        $fechaVencTs   = strtotime($inv->fecha_vencimiento);
        $diasRestantes = (int)floor(($fechaVencTs - $today) / 86400);
        $nombre        = Capsule::table('productos')->where('id', $productoId)->value('nombre')
                         ?? "Producto #{$productoId}";

        if ($diasRestantes <= 0) {
            $this->_quarantineLote($productoId, $lote);
            return new ExpiryResult(
                ExpiryResult::BLOCKED,
                message: "El producto {$nombre} (Lote {$lote}) está vencido ({$inv->fecha_vencimiento}). No puede ser despachado.",
                productName: $nombre,
                lote: $lote,
                diasRestantes: $diasRestantes
            );
        }

        if ($diasRestantes <= 5) {
            // Check for existing valid approval today
            $existing = Capsule::table('aprobaciones_vencimiento')
                ->where('empresa_id',  $this->empresaId)
                ->where('sucursal_id', $this->sucursalId)
                ->where('producto_id', $productoId)
                ->where('lote',        $lote)
                ->where('estado',      'aprobada')
                ->where('valid_until', date('Y-m-d'))
                ->first();

            if ($existing) {
                return new ExpiryResult(ExpiryResult::OK);
            }

            // Reuse existing pending request rather than creating duplicates in supervisor queue
            $pending = Capsule::table('aprobaciones_vencimiento')
                ->where('empresa_id',  $this->empresaId)
                ->where('sucursal_id', $this->sucursalId)
                ->where('producto_id', $productoId)
                ->where('lote',        $lote)
                ->where('estado',      'pendiente')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($pending) {
                return new ExpiryResult(
                    ExpiryResult::PENDING,
                    aprobacionId: $pending->id,
                    message: "Producto próximo a vencer ({$diasRestantes} días). Esperando autorización del supervisor.",
                    productName: $nombre,
                    lote: $lote,
                    diasRestantes: $diasRestantes
                );
            }

            $aprobacionId = Capsule::table('aprobaciones_vencimiento')->insertGetId([
                'empresa_id'    => $this->empresaId,
                'sucursal_id'   => $this->sucursalId,
                'producto_id'   => $productoId,
                'lote'          => $lote,
                'dias_restantes'=> $diasRestantes,
                'solicitado_por'=> $solicitadoPor,
                'estado'        => 'pendiente',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            return new ExpiryResult(
                ExpiryResult::PENDING,
                aprobacionId: $aprobacionId,
                message: "Producto próximo a vencer ({$diasRestantes} días). Esperando autorización del supervisor.",
                productName: $nombre,
                lote: $lote,
                diasRestantes: $diasRestantes
            );
        }

        return new ExpiryResult(ExpiryResult::OK);
    }

    /**
     * Marks all expired inventory (across entire empresa/sucursal) as Cuarentena.
     * Returns count of rows updated.
     */
    public function autoQuarantine(): int
    {
        return Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('estado', '!=', 'Cuarentena')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<', date('Y-m-d'))
            ->update([
                'estado'     => 'Cuarentena',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function _quarantineLote(int $productoId, string $lote): void
    {
        Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('producto_id', $productoId)
            ->where('lote',        $lote)
            ->where('estado', '!=', 'Cuarentena')
            ->update([
                'estado'     => 'Cuarentena',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
