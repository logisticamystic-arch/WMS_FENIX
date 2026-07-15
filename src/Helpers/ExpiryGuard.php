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
     * Check expiry status for a specific product at time of picking/packing.
     *
     * $fechaVencimiento (cuando se conoce, p.ej. ya resuelta por quien llama desde el
     * detalle de picking/packing) es el criterio PRIMARIO de búsqueda — "lote" ya no es
     * un diferenciador confiable porque puede repetirse (o ser 'N/A') entre partidas con
     * fechas de vencimiento distintas. $lote se conserva solo como filtro adicional
     * opcional y como fallback para llamadores que aún no resuelven la fecha exacta.
     */
    public function check(int $productoId, ?string $lote, int $solicitadoPor, ?string $fechaVencimiento = null): ExpiryResult
    {
        if ($lote === null && $fechaVencimiento === null) {
            return new ExpiryResult(ExpiryResult::OK);
        }

        if ($fechaVencimiento !== null) {
            $inv = Capsule::table('inventarios')
                ->where('empresa_id',  $this->empresaId)
                ->where('sucursal_id', $this->sucursalId)
                ->where('producto_id', $productoId)
                ->where('fecha_vencimiento', $fechaVencimiento)
                ->first();
        } else {
            $inv = Capsule::table('inventarios')
                ->where('empresa_id',  $this->empresaId)
                ->where('sucursal_id', $this->sucursalId)
                ->where('producto_id', $productoId)
                ->where('lote',        $lote)
                ->whereNotNull('fecha_vencimiento')
                ->orderBy('fecha_vencimiento', 'asc')
                ->first();
        }

        if (!$inv || !$inv->fecha_vencimiento) {
            return new ExpiryResult(ExpiryResult::OK);
        }

        // A partir de aquí, la fecha real encontrada es la que gobierna el chequeo —
        // independientemente de si se llegó por $fechaVencimiento o por $lote.
        $fechaVencimiento = $inv->fecha_vencimiento;

        $today         = strtotime(date('Y-m-d'));
        $fechaVencTs   = strtotime($inv->fecha_vencimiento);
        $diasRestantes = (int)floor(($fechaVencTs - $today) / 86400);
        $nombre        = Capsule::table('productos')->where('id', $productoId)->value('nombre')
                         ?? "Producto #{$productoId}";

        if ($diasRestantes <= 0) {
            $this->_quarantinePorFecha($productoId, $fechaVencimiento);
            return new ExpiryResult(
                ExpiryResult::BLOCKED,
                message: "El producto {$nombre} (vence {$inv->fecha_vencimiento}) está vencido. No puede ser despachado.",
                productName: $nombre,
                lote: $lote,
                diasRestantes: $diasRestantes
            );
        }

        if ($diasRestantes <= 5) {
            // aprobaciones_vencimiento indexa por 'lote' (columna NOT NULL en el esquema
            // actual, sin fecha_vencimiento) — se conserva ese criterio aquí como excepción
            // acotada; migrar esta tabla a fecha_vencimiento queda fuera del alcance de esta
            // corrección. Si no hay lote (caso ya frecuente), se usa 'N/A' como placeholder,
            // igual convención que ya usa el resto del proyecto (RecepcionController, etc.)
            $loteAprobacion = $lote ?? 'N/A';

            // Check for existing valid approval today
            $existing = Capsule::table('aprobaciones_vencimiento')
                ->where('empresa_id',  $this->empresaId)
                ->where('sucursal_id', $this->sucursalId)
                ->where('producto_id', $productoId)
                ->where('lote',        $loteAprobacion)
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
                ->where('lote',        $loteAprobacion)
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
                'lote'          => $loteAprobacion,
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

    /**
     * Pone en cuarentena TODAS las filas de inventario con esa fecha_vencimiento exacta
     * (independientemente del lote) — fecha_vencimiento es el diferenciador real de una
     * partida vencida, no el lote, que puede repetirse (o ser 'N/A') entre partidas.
     */
    private function _quarantinePorFecha(int $productoId, string $fechaVencimiento): void
    {
        Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('producto_id', $productoId)
            ->where('fecha_vencimiento', $fechaVencimiento)
            ->where('estado', '!=', 'Cuarentena')
            ->update([
                'estado'     => 'Cuarentena',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
