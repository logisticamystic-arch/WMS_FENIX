<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\ExpiryGuard;

/**
 * FefoEngine — Motor de rotación FEFO (First Expiry, First Out).
 *
 * Responsabilidades:
 *  1. getSuggestedLots()    — devuelve los lotes a consumir en orden FEFO para cubrir una cantidad
 *  2. validatePickingOrder()— verifica que las líneas de picking respetan FEFO
 *  3. getExpiryAlerts()     — productos con vencimiento próximo (hoy + N días)
 *  4. getRotationReport()   — informe de rotación: productos lentos, sin movimiento, en riesgo
 *  5. logFefoViolation()    — registra cuando se viola el orden FEFO (va a anomaly_flags)
 *
 * FEFO es la regla de negocio más importante en un WMS con productos perecederos.
 * Cualquier violación debe quedar registrada y visible en el dashboard.
 */
class FefoEngine
{
    private int $empresaId;
    private int $sucursalId;
    private static array $_quarantinedThisRequest = [];

    public function __construct(int $empresaId, int $sucursalId)
    {
        $this->empresaId  = $empresaId;
        $this->sucursalId = $sucursalId;
    }

    private function isPg(): bool
    {
        return Capsule::connection()->getDriverName() === 'pgsql';
    }

    // ── 1. SUGERENCIA DE LOTES EN ORDEN FEFO ──────────────────────────────────

    /**
     * Dado un producto y una cantidad requerida, retorna los lotes/ubicaciones
     * en orden FEFO (menor fecha_vencimiento primero) para cubrir exactamente
     * la cantidad pedida. Respeta el stock disponible (cantidad - cantidad_reservada).
     *
     * Retorna:
     *   [
     *     'ok'        => bool,
     *     'lotes'     => [
     *       [ 'inventario_id', 'lote', 'ubicacion_id', 'ubicacion_codigo',
     *         'fecha_vencimiento', 'disponible', 'a_tomar' ],
     *       ...
     *     ],
     *     'faltante'  => float,   // 0 si se pudo cubrir completamente
     *     'advertencias' => []
     *   ]
     */
    public function getSuggestedLots(int $productoId, float $cantidadRequerida): array
    {
        // Lazy auto-quarantine: runs at most once per empresa+sucursal per request
        $quarantineKey = "{$this->empresaId}:{$this->sucursalId}";
        if (!isset(self::$_quarantinedThisRequest[$quarantineKey])) {
            self::$_quarantinedThisRequest[$quarantineKey] = true;
            $quarantined = (new ExpiryGuard($this->empresaId, $this->sucursalId))->autoQuarantine();
            if ($quarantined > 0) {
                error_log("[ExpiryGuard] autoQuarantine: {$quarantined} lotes marcados como Cuarentena (empresa={$this->empresaId}, sucursal={$this->sucursalId})");
            }
        }

        $rows = Capsule::table('inventarios as i')
            ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
            ->where('i.empresa_id',  $this->empresaId)
            ->where('i.sucursal_id', $this->sucursalId)
            ->where('i.producto_id', $productoId)
            ->where('i.estado', 'Disponible')
            ->whereRaw('(i.cantidad - i.cantidad_reservada) > 0')
            // FEFO: menor fecha_vencimiento primero; NULL al final (sin vencimiento)
            ->orderByRaw('CASE WHEN i.fecha_vencimiento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('i.fecha_vencimiento', 'asc')
            ->orderBy('i.created_at', 'asc')
            ->select([
                'i.id as inventario_id',
                'i.lote',
                'i.ubicacion_id',
                'u.codigo as ubicacion_codigo',
                'i.fecha_vencimiento',
                Capsule::raw('(i.cantidad - i.cantidad_reservada) as disponible'),
            ])
            ->get();

        $lotes       = [];
        $pendiente   = $cantidadRequerida;
        $advertencias = [];

        foreach ($rows as $row) {
            if ($pendiente <= 0) break;

            $disponible = (float)$row->disponible;
            $aTomar     = min($disponible, $pendiente);
            $pendiente -= $aTomar;

            // Advertencia si el lote vence en menos de 15 días
            if ($row->fecha_vencimiento) {
                $diasRestantes = (int)floor(
                    (strtotime($row->fecha_vencimiento) - time()) / 86400
                );
                if ($diasRestantes <= 15) {
                    $advertencias[] = "Lote {$row->lote} vence en {$diasRestantes} días.";
                }
            }

            $lotes[] = [
                'inventario_id'     => $row->inventario_id,
                'lote'              => $row->lote,
                'ubicacion_id'      => $row->ubicacion_id,
                'ubicacion_codigo'  => $row->ubicacion_codigo,
                'fecha_vencimiento' => $row->fecha_vencimiento,
                'disponible'        => $disponible,
                'a_tomar'           => $aTomar,
            ];
        }

        return [
            'ok'           => $pendiente <= 0,
            'lotes'        => $lotes,
            'faltante'     => round($pendiente, 4),
            'advertencias' => $advertencias,
        ];
    }

    // ── 2. VALIDAR QUE UN PICKING RESPETA FEFO ────────────────────────────────

    /**
     * Dado un array de líneas de picking ya confirmadas, verifica si el
     * conjunto respeta el orden FEFO.
     *
     * $lineas = [ ['producto_id'=>1, 'lote'=>'L001', 'fecha_vencimiento'=>'2024-06-01'], … ]
     *
     * Retorna array de violaciones detectadas.
     */
    public function validatePickingOrder(array $lineas, ?int $planillaId = null): array
    {
        $violations = [];

        // Agrupar por producto
        $byProducto = [];
        foreach ($lineas as $l) {
            $byProducto[$l['producto_id']][] = $l;
        }

        foreach ($byProducto as $productoId => $items) {
            // Obtener el lote FEFO correcto para este producto
            $suggested = $this->getSuggestedLots($productoId, PHP_INT_MAX);
            if (empty($suggested['lotes'])) continue;

            $loteFefo = $suggested['lotes'][0]['lote'];
            $fechaFefo = $suggested['lotes'][0]['fecha_vencimiento'];

            foreach ($items as $item) {
                if ($item['lote'] !== null && $item['lote'] !== $loteFefo) {
                    // Verificar que el lote elegido tiene una fecha POSTERIOR al FEFO
                    $fechaElegida = $item['fecha_vencimiento'] ?? null;
                    if ($fechaElegida && $fechaFefo && $fechaElegida > $fechaFefo) {
                        $violations[] = [
                            'producto_id'       => $productoId,
                            'lote_elegido'      => $item['lote'],
                            'fecha_elegida'     => $fechaElegida,
                            'lote_fefo'         => $loteFefo,
                            'fecha_fefo'        => $fechaFefo,
                            'dias_diferencia'   => (int)floor(
                                (strtotime($fechaElegida) - strtotime($fechaFefo)) / 86400
                            ),
                        ];
                    }
                }
            }
        }

        if (!empty($violations)) {
            $this->logFefoViolation($violations, $planillaId);
        }

        return $violations;
    }

    // ── 3. ALERTAS DE VENCIMIENTO PRÓXIMO ─────────────────────────────────────

    /**
     * Devuelve productos con stock cuya fecha_vencimiento es <= hoy + $dias.
     * Agrupa por producto+lote y muestra el stock en riesgo.
     */
    public function getExpiryAlerts(int $dias = 30): array
    {
        $fechaLimite = date('Y-m-d', strtotime("+{$dias} days"));

        return Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.empresa_id',  $this->empresaId)
            ->where('i.sucursal_id', $this->sucursalId)
            ->where('i.estado', 'Disponible')
            ->where('i.cantidad', '>', 0)
            ->whereNotNull('i.fecha_vencimiento')
            ->where('i.fecha_vencimiento', '<=', $fechaLimite)
            ->orderBy('i.fecha_vencimiento', 'asc')
            ->select([
                'i.producto_id',
                'p.nombre as producto',
                'p.codigo_interno as referencia',
                'i.lote',
                'i.fecha_vencimiento',
                Capsule::raw('SUM(i.cantidad) as stock_total'),
                Capsule::raw('SUM(i.cantidad - i.cantidad_reservada) as stock_disponible'),
                Capsule::raw($this->isPg()
                    ? "(i.fecha_vencimiento::date - CURRENT_DATE) as dias_para_vencer"
                    : "DATEDIFF(i.fecha_vencimiento, CURDATE()) as dias_para_vencer"),
            ])
            ->groupBy('i.producto_id', 'p.nombre', 'p.codigo_interno', 'i.lote', 'i.fecha_vencimiento')
            ->get()
            ->map(function ($r) {
                $dias = (int)$r->dias_para_vencer;
                $r->nivel_riesgo = match(true) {
                    $dias <= 0  => 'vencido',
                    $dias <= 7  => 'critico',
                    $dias <= 15 => 'alto',
                    $dias <= 30 => 'medio',
                    default     => 'bajo',
                };
                return $r;
            })
            ->toArray();
    }

    // ── 4. INFORME DE ROTACIÓN ─────────────────────────────────────────────────

    /**
     * Informe de rotación: identifica productos lentos y sin movimiento.
     * "Lento" = no tuvo movimiento en los últimos $diasSinMovimiento días.
     */
    public function getRotationReport(int $diasSinMovimiento = 60): array
    {
        $fechaCorte = date('Y-m-d', strtotime("-{$diasSinMovimiento} days"));

        // Productos con stock pero sin movimiento reciente
        $sinMovimiento = Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->leftJoin('movimiento_inventarios as m', function ($join) use ($fechaCorte) {
                $join->on('m.producto_id', '=', 'i.producto_id')
                     ->on('m.empresa_id',  '=', 'i.empresa_id')
                     ->where('m.fecha_movimiento', '>=', $fechaCorte);
            })
            ->where('i.empresa_id',  $this->empresaId)
            ->where('i.sucursal_id', $this->sucursalId)
            ->where('i.cantidad', '>', 0)
            ->whereNull('m.id')
            ->select([
                'i.producto_id',
                'p.nombre as producto',
                'p.codigo_interno as referencia',
                Capsule::raw('SUM(i.cantidad) as stock_total'),
                Capsule::raw('MIN(i.fecha_vencimiento) as proximo_vencimiento'),
            ])
            ->groupBy('i.producto_id', 'p.nombre', 'p.codigo_interno')
            ->orderByRaw($this->isPg()
                ? 'MIN(i.fecha_vencimiento) ASC NULLS LAST'
                : 'MIN(i.fecha_vencimiento) IS NULL, MIN(i.fecha_vencimiento) ASC')
            ->limit(100)
            ->get();

        return [
            'dias_sin_movimiento' => $diasSinMovimiento,
            'fecha_corte'         => $fechaCorte,
            'productos_lentos'    => $sinMovimiento,
            'total_lentos'        => count($sinMovimiento),
        ];
    }

    // ── 5. LOG DE VIOLACIÓN FEFO ──────────────────────────────────────────────

    private function logFefoViolation(array $violations, ?int $planillaId): void
    {
        try {
            if (!Capsule::schema()->hasTable('anomaly_flags')) return;

            Capsule::table('anomaly_flags')->insert([
                'empresa_id'     => $this->empresaId,
                'sucursal_id'    => $this->sucursalId,
                'tipo'           => 'picking',
                'severidad'      => 'alta',
                'titulo'         => 'Violación FEFO detectada' . ($planillaId ? " — Planilla #{$planillaId}" : ''),
                'descripcion'    => count($violations) . ' línea(s) de picking no respetan el orden FEFO.',
                'datos_anomalia' => json_encode([
                    'planilla_id' => $planillaId,
                    'violations'  => $violations,
                ], JSON_UNESCAPED_UNICODE),
                'estado'         => 'pendiente',
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            error_log('FefoEngine::logFefoViolation error: ' . $e->getMessage());
        }
    }
}
