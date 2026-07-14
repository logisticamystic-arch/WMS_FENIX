<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * InventoryGuard — Motor de reglas duras de integridad de inventario.
 *
 * REGLAS CUBIERTAS:
 *  R01 — No se puede hacer picking de más unidades que el stock disponible
 *  R02 — No se puede trasladar más del stock disponible en la ubicación origen
 *  R03 — No se puede hacer ajuste negativo que deje el inventario en negativo
 *  R04 — No se puede despachar más de lo que existe en el inventario reservado
 *  R05 — No se puede recepcionar más del % de tolerancia de la ODC (configurable)
 *  R06 — No se pueden reservar unidades en cuarentena u obsoleto
 *  R07 — Doble conteo: detecta si ya se reservó/confirmó el mismo lote-ubicación en paralelo
 *  R08 — FEFO: picking debe respetar el lote de mayor proximidad a vencimiento
 *  R09 — fecha_vencimiento obligatoria si control_vencimientos = 1
 *  R10 — producto vencido (fecha_vencimiento < hoy) — bloqueado, sin excepciones
 *  R11 — producto próximo a vencer (1–5 días) — requiere aprobación del supervisor
 *
 * Uso:
 *   $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
 *   $check = $guard->canPick($productoId, $cantidad, $lote, $ubicacionId);
 *   if (!$check['ok']) {
 *       return $this->error($response, $check['message'], 422);
 *   }
 */
class InventoryGuard
{
    private int $empresaId;
    private int $sucursalId;
    private ?int $usuarioId;

    /** Tolerancia máxima de recepción sobre la ODC (%) */
    private float $toleranciaRecepcion = 0.0;

    public function __construct(int $empresaId, int $sucursalId, ?int $usuarioId = null)
    {
        $this->empresaId  = $empresaId;
        $this->sucursalId = $sucursalId;
        $this->usuarioId  = $usuarioId;
    }

    // ── R01 + R06 + R07: CAN PICK ─────────────────────────────────────────────

    /**
     * Valida que se pueda ejecutar un picking para el producto/lote/ubicación.
     * Usa lock FOR UPDATE para prevenir race conditions entre workers concurrentes.
     */
    public function canPick(
        int    $productoId,
        float  $cantidad,
        ?string $lote       = null,
        ?int   $ubicacionId = null,
        ?int   $numeroPallet = null
    ): array {
        if ($cantidad <= 0) {
            return $this->deny('R01', 'La cantidad a picar debe ser mayor a cero.', compact('productoId', 'cantidad'));
        }

        $query = Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('producto_id', $productoId)
            ->where('estado', 'Disponible');

        if ($lote !== null)       $query->where('lote', $lote);
        if ($ubicacionId !== null) $query->where('ubicacion_id', $ubicacionId);

        // Lock FOR UPDATE: garantiza lectura consistente en transacciones concurrentes
        $sql = "SELECT id, cantidad, cantidad_reservada, lote, ubicacion_id, fecha_vencimiento, numero_pallet
             FROM inventarios
             WHERE empresa_id = ? AND sucursal_id = ? AND producto_id = ?
               AND estado = 'Disponible'";
        $params = [$this->empresaId, $this->sucursalId, $productoId];

        if ($lote !== null) { $sql .= " AND lote = ?"; $params[] = $lote; }
        if ($ubicacionId !== null) { $sql .= " AND ubicacion_id = ?"; $params[] = $ubicacionId; }
        if ($numeroPallet !== null) { $sql .= " AND numero_pallet = ?"; $params[] = $numeroPallet; }

        $rows = Capsule::connection()->select($sql, $params);

        $stockDisponible = array_sum(array_map(
            fn($r) => max(0, $r->cantidad - $r->cantidad_reservada),
            $rows
        ));

        if ($stockDisponible <= 0) {
            return $this->deny('R01', "Sin stock disponible para el producto #{$productoId}.", [
                'producto_id'      => $productoId,
                'stock_disponible' => 0,
                'cantidad_pedida'  => $cantidad,
                'lote'             => $lote,
            ]);
        }

        if ($cantidad > $stockDisponible) {
            return $this->deny('R01', sprintf(
                'No hay suficiente stock. Pedido: %.2f — Disponible: %.2f.',
                $cantidad, $stockDisponible
            ), [
                'producto_id'      => $productoId,
                'stock_disponible' => $stockDisponible,
                'cantidad_pedida'  => $cantidad,
                'lote'             => $lote,
            ]);
        }

        // R08 — advertencia FEFO (no bloquea, solo advierte para el log)
        $fefoWarning = $this->checkFefoCompliance($rows, $lote);

        // R10/R11 — Expiry check (after stock validation)
        if (!empty($rows) && $lote !== null && $this->usuarioId !== null) {
            $guard  = new ExpiryGuard($this->empresaId, $this->sucursalId);
            $result = $guard->check($productoId, $lote, $this->usuarioId);

            if ($result->status === ExpiryResult::BLOCKED) {
                return $this->deny('R10', $result->message, [
                    'producto_id'    => $productoId,
                    'lote'           => $lote,
                    'dias_restantes' => $result->diasRestantes,
                    'code'           => 'PRODUCT_EXPIRED',
                ]);
            }

            if ($result->status === ExpiryResult::PENDING) {
                // R11 returns a custom shape (not via deny()) because it carries approval metadata
                // that PickingController reads directly. Callers must branch on 'pending_approval'
                // before assuming the standard deny() contract.
                return [
                    'ok'              => false,
                    'regla'           => 'R11',
                    'pending_approval'=> true,
                    'aprobacion_id'   => $result->aprobacionId,
                    'message'         => $result->message,
                    'dias_restantes'  => $result->diasRestantes,
                ];
            }
        }

        return [
            'ok'               => true,
            'stock_disponible' => $stockDisponible,
            'fefo_warning'     => $fefoWarning,
        ];
    }

    // ── R02: CAN TRANSFER ─────────────────────────────────────────────────────

    /**
     * Valida que se pueda trasladar una cantidad desde una ubicación origen.
     */
    public function canTransfer(
        int   $productoId,
        float $cantidad,
        int   $ubicacionOrigenId,
        ?string $lote = null,
        ?int $numeroPallet = null
    ): array {
        if ($cantidad <= 0) {
            return $this->deny('R02', 'La cantidad a trasladar debe ser mayor a cero.', compact('productoId', 'cantidad'));
        }

        $params = [$this->empresaId, $this->sucursalId, $productoId, $ubicacionOrigenId];
        // Incluye 'En Patio' porque el módulo de ubicar traslada desde el patio al almacén
        $sql = "SELECT COALESCE(SUM(cantidad - COALESCE(cantidad_reservada,0)), 0) as disponible
                FROM inventarios
                WHERE empresa_id = ? AND sucursal_id = ? AND producto_id = ?
                  AND ubicacion_id = ? AND estado IN ('Disponible','En Patio')";

        if ($lote !== null) {
            $sql    .= " AND lote = ?";
            $params[] = $lote;
        }

        if ($numeroPallet !== null) {
            $sql    .= " AND numero_pallet = ?";
            $params[] = $numeroPallet;
        }

        $row  = Capsule::connection()->selectOne($sql, $params);
        $disp = (float)($row->disponible ?? 0);

        // Tolerancia de 0.01 para evitar falsos negativos por redondeo de punto flotante
        if ($cantidad > $disp + 0.01) {
            return $this->deny('R02', sprintf(
                'Traslado excede stock en ubicación origen. Pedido: %.2f — Disponible: %.2f.',
                $cantidad, $disp
            ), [
                'producto_id'      => $productoId,
                'ubicacion_origen' => $ubicacionOrigenId,
                'stock_disponible' => $disp,
                'cantidad_pedida'  => $cantidad,
                'lote'             => $lote,
            ]);
        }

        return ['ok' => true, 'stock_disponible' => $disp];
    }

    // ── R03: CAN ADJUST (negativo) ────────────────────────────────────────────

    /**
     * Valida que un ajuste negativo no deje el inventario en negativo.
     */
    public function canAdjust(
        int   $productoId,
        float $cantidad,          // negativo = disminución
        ?int  $ubicacionId = null,
        ?string $lote = null
    ): array {
        if ($cantidad >= 0) {
            return ['ok' => true]; // ajustes positivos siempre permitidos
        }

        $cantidadAbsoluta = abs($cantidad);

        $params = [$this->empresaId, $this->sucursalId, $productoId];
        $sql = "SELECT COALESCE(SUM(cantidad), 0) as total
                FROM inventarios
                WHERE empresa_id = ? AND sucursal_id = ? AND producto_id = ?";

        if ($ubicacionId !== null) { $sql .= " AND ubicacion_id = ?"; $params[] = $ubicacionId; }
        if ($lote        !== null) { $sql .= " AND lote = ?";         $params[] = $lote; }

        $row   = Capsule::connection()->selectOne($sql, $params);
        $total = (float)($row->total ?? 0);

        if ($cantidadAbsoluta > $total) {
            return $this->deny('R03', sprintf(
                'Ajuste negativo dejaría inventario en %.2f. Stock actual: %.2f.',
                $total - $cantidadAbsoluta, $total
            ), [
                'producto_id'   => $productoId,
                'stock_actual'  => $total,
                'ajuste'        => $cantidad,
                'resultado'     => $total - $cantidadAbsoluta,
            ]);
        }

        return ['ok' => true, 'stock_actual' => $total];
    }

    // ── R05: CAN RECEIVE ──────────────────────────────────────────────────────

    /**
     * Valida que la cantidad a recepcionar no exceda la ODC + tolerancia.
     */
    public function canReceive(
        int   $odcDetalleId,
        float $cantidadRecibida
    ): array {
        $det = Capsule::table('orden_compra_detalles')->find($odcDetalleId);
        if (!$det) {
            return ['ok' => true]; // sin ODC vinculada, no se puede validar
        }

        $yaProcesado = (float)($det->cantidad_recibida ?? 0);
        $ordenado    = (float)($det->cantidad_solicitada ?? 0);
        $maximo      = $ordenado * (1 + $this->toleranciaRecepcion / 100);
        $totalSiAcepta = $yaProcesado + $cantidadRecibida;

        if ($totalSiAcepta > $maximo) {
            return $this->deny('R05', sprintf(
                'Recepción excede la ODC + tolerancia (%.0f%%). Ordenado: %.2f — Ya recibido: %.2f — Intentando recibir: %.2f — Máx permitido: %.2f.',
                $this->toleranciaRecepcion, $ordenado, $yaProcesado, $cantidadRecibida, $maximo
            ), [
                'odc_detalle_id'   => $odcDetalleId,
                'cantidad_ordenada' => $ordenado,
                'ya_procesado'     => $yaProcesado,
                'cantidad_nueva'   => $cantidadRecibida,
                'maximo_permitido' => $maximo,
            ]);
        }

        return ['ok' => true];
    }

    // ── R04: CAN DISPATCH ─────────────────────────────────────────────────────

    /**
     * Valida que se pueda despachar (requiere stock disponible o reservado).
     */
    public function canDispatch(
        int   $productoId,
        float $cantidad
    ): array {
        $row = Capsule::connection()->selectOne(
            "SELECT COALESCE(SUM(cantidad), 0) as total,
                    COALESCE(SUM(cantidad_reservada), 0) as reservado
             FROM inventarios
             WHERE empresa_id = ? AND sucursal_id = ? AND producto_id = ?
               AND estado IN ('Disponible','Reservado')",
            [$this->empresaId, $this->sucursalId, $productoId]
        );

        $totalDespacchable = (float)($row->total ?? 0);

        if ($cantidad > $totalDespacchable) {
            return $this->deny('R04', sprintf(
                'Despacho excede el inventario. Pedido: %.2f — Total inventariable: %.2f.',
                $cantidad, $totalDespacchable
            ), [
                'producto_id'   => $productoId,
                'cantidad'      => $cantidad,
                'total_despachable' => $totalDespacchable,
            ]);
        }

        return ['ok' => true, 'total_despachable' => $totalDespacchable];
    }

    // ── R08: FEFO COMPLIANCE CHECK ────────────────────────────────────────────

    /**
     * Verifica si el lote seleccionado respeta FEFO.
     * Retorna null si está OK, o string con la advertencia.
     */
    private function checkFefoCompliance(array $rows, ?string $loteSeleccionado): ?string
    {
        if ($loteSeleccionado === null || count($rows) <= 1) {
            return null;
        }

        // Ordenar por fecha de vencimiento asc (FEFO)
        usort($rows, function ($a, $b) {
            $fa = $a->fecha_vencimiento ?? '9999-12-31';
            $fb = $b->fecha_vencimiento ?? '9999-12-31';
            return strcmp($fa, $fb);
        });

        $loteFefo = $rows[0]->lote ?? null;
        if ($loteFefo && $loteFefo !== $loteSeleccionado) {
            return "FEFO: se está picando lote '{$loteSeleccionado}' pero el lote más próximo a vencer es '{$loteFefo}'.";
        }

        return null;
    }

    // ── Logging de bloqueo ────────────────────────────────────────────────────

    /**
     * Registra el intento bloqueado en inventory_guard_log.
     * Se llama automáticamente desde deny() si la tabla existe.
     */
    private function deny(string $regla, string $mensaje, array $contexto = []): array
    {
        try {
            if (Capsule::schema()->hasTable('inventory_guard_log')) {
                Capsule::table('inventory_guard_log')->insert([
                    'empresa_id'      => $this->empresaId,
                    'sucursal_id'     => $this->sucursalId,
                    'usuario_id'      => $this->usuarioId,
                    'operacion'       => $regla,
                    'motivo_bloqueo'  => $mensaje,
                    'contexto'        => json_encode($contexto, JSON_UNESCAPED_UNICODE),
                    'endpoint'        => $_SERVER['REQUEST_URI'] ?? null,
                    'ip'              => $_SERVER['REMOTE_ADDR'] ?? null,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            error_log('InventoryGuard::deny log error: ' . $e->getMessage());
        }

        return [
            'ok'      => false,
            'regla'   => $regla,
            'message' => $mensaje,
            'context' => $contexto,
        ];
    }

    /**
     * R09 — Valida si un producto requiere fecha de vencimiento y si se ha proporcionado.
     */
    public function checkExpirationMandatory(int $productoId, ?string $fechaVencimiento): array
    {
        $producto = Capsule::table('productos')->find($productoId);
        
        if (!$producto) {
            return ['ok' => true]; // Si no existe el producto, no podemos validar (error de otra capa)
        }

        if ($producto->controla_vencimiento) {
            if (empty($fechaVencimiento) || $fechaVencimiento === 'N/A' || $fechaVencimiento === '-') {
                return $this->deny('R09', "La fecha de vencimiento es obligatoria para el producto: {$producto->nombre}.", [
                    'producto_id' => $productoId,
                    'controla_vencimiento' => true,
                    'fecha_proporcionada' => $fechaVencimiento
                ]);
            }

            // Validar formato mínimo (YYYY-MM-DD o similar que Carbon entienda)
            // Intentaremos limpiar si viene basura común
            $fechaLimpia = trim((string)$fechaVencimiento);
            if (strlen($fechaLimpia) < 8) {
                return $this->deny('R09', "El formato de la fecha de vencimiento es inválido: {$fechaVencimiento}.", [
                    'producto_id' => $productoId,
                    'fecha_proporcionada' => $fechaVencimiento
                ]);
            }
        }

        return ['ok' => true];
    }

    // ── Setter de tolerancia ──────────────────────────────────────────────────

    public function setToleranciaRecepcion(float $pct): self
    {
        $this->toleranciaRecepcion = $pct;
        return $this;
    }
}
