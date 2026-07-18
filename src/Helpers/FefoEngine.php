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
 *
 * REGLA DE APLICACIÓN:
 *  - Productos con controla_vencimiento = true  → FEFO estricto (menor fecha_vencimiento primero).
 *  - Productos con controla_vencimiento = false → ordenar por cantidad disponible DESC
 *    (agotar el registro más lleno primero, sin importar fecha).
 */
class FefoEngine
{
    private int $empresaId;
    private int $sucursalId;
    private static array $_quarantinedThisRequest = [];

    // Cantidad nominal "infinita" para consultas donde se quiere ver todos los lotes.
    // Evita usar PHP_INT_MAX convertido a float (pierde precisión) o PHP_FLOAT_MAX
    // (puede desbordar cálculos de pendiente en el loop).
    private const LOTES_ALL_QTY = 999_999_999.0;

    public function __construct(int $empresaId, int $sucursalId)
    {
        $this->empresaId  = $empresaId;
        $this->sucursalId = $sucursalId;
    }

    private function isPg(): bool
    {
        return Capsule::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Consulta el flag controla_vencimiento del producto desde la BD.
     * Retorna true si el producto requiere control de fecha de vencimiento.
     */
    private function productoControlaVencimiento(int $productoId): bool
    {
        $row = Capsule::table('productos')
            ->where('id', $productoId)
            ->value('controla_vencimiento');

        // NULL se interpreta como sin control (producto sin categoría perecedera)
        return (bool)$row;
    }

    // ── 1. SUGERENCIA DE LOTES EN ORDEN FEFO ──────────────────────────────────

    /**
     * Dado un producto y una cantidad requerida, retorna los lotes/ubicaciones
     * en el orden correcto para cubrir exactamente la cantidad pedida.
     *
     * ORDEN DE SELECCIÓN:
     *  - controla_vencimiento = true  → FEFO estricto: menor fecha_vencimiento primero,
     *    lotes sin fecha al final (no perecederos mezclados se consumen al último).
     *  - controla_vencimiento = false → mayor cantidad disponible primero (vaciar
     *    registros más llenos reduce fragmentación de inventario).
     *
     * INTEGRIDAD: la cantidad asignada (a_tomar) nunca supera el disponible real
     * (cantidad - cantidad_reservada) de cada registro de inventario.
     *
     * Retorna:
     *   [
     *     'ok'           => bool,          // true si se cubrió la cantidad completa
     *     'lotes'        => [
     *       [ 'inventario_id', 'lote', 'ubicacion_id', 'ubicacion_codigo',
     *         'fecha_vencimiento', 'disponible', 'a_tomar' ],
     *       ...
     *     ],
     *     'faltante'     => float,          // 0 si se cubrió completamente
     *     'advertencias' => string[],       // alertas de vencimiento próximo
     *     'modo'         => 'fefo'|'qty',   // modo de selección aplicado
     *   ]
     */
    public function getSuggestedLots(int $productoId, float $cantidadRequerida): array
    {
        // ── Auto-cuarentena preventiva (máximo una vez por request por empresa+sucursal) ──
        // ExpiryGuard marca como Cuarentena los lotes cuya fecha_vencimiento ya pasó
        // antes de que se consulten para picking. Esto evita que lotes vencidos aparezcan
        // como "Disponible" en la sugerencia FEFO.
        $quarantineKey = "{$this->empresaId}:{$this->sucursalId}";
        if (!isset(self::$_quarantinedThisRequest[$quarantineKey])) {
            self::$_quarantinedThisRequest[$quarantineKey] = true;
            $quarantined = (new ExpiryGuard($this->empresaId, $this->sucursalId))->autoQuarantine();
            if ($quarantined > 0) {
                error_log("[ExpiryGuard] autoQuarantine: {$quarantined} lotes marcados como Cuarentena"
                    . " (empresa={$this->empresaId}, sucursal={$this->sucursalId})");
            }
        }

        // ── Determinar modo de selección según configuración del producto ──────
        $controlaVencimiento = $this->productoControlaVencimiento($productoId);

        $query = Capsule::table('inventarios as i')
            ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
            ->where('i.empresa_id',  $this->empresaId)
            ->where('i.sucursal_id', $this->sucursalId)
            ->where('i.producto_id', $productoId)
            ->where('i.estado', 'Disponible')
            // Integridad: solo filas con stock realmente disponible para consumir
            ->whereRaw('(i.cantidad - i.cantidad_reservada) > 0')
            ->select([
                'i.id as inventario_id',
                'i.lote',
                'i.ubicacion_id',
                'u.codigo as ubicacion_codigo',
                'i.fecha_vencimiento',
                Capsule::raw('(i.cantidad - i.cantidad_reservada) as disponible'),
            ]);

        if ($controlaVencimiento) {
            // FEFO estricto: menor fecha_vencimiento primero.
            // Los lotes SIN fecha van al final (son sustitutos no perecederos;
            // consumirlos antes no aporta rotación de riesgo).
            // Desempate: created_at ASC (FIFO dentro del mismo vencimiento).
            $query
                ->orderByRaw('CASE WHEN i.fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('i.fecha_vencimiento', 'asc')
                ->orderBy('i.created_at', 'asc');
        } else {
            // Sin control de FV: priorizar registros con mayor stock disponible.
            // Objetivo: reducir fragmentación vaciando primero los registros más llenos.
            $query->orderByRaw('(i.cantidad - i.cantidad_reservada) DESC');
        }

        $rows = $query->get();

        $lotes        = [];
        $pendiente    = $cantidadRequerida;
        $advertencias = [];

        foreach ($rows as $row) {
            if ($pendiente <= 0) break;

            // Integridad: disponible viene calculado en la query (cantidad - cantidad_reservada).
            // El cast a float garantiza aritmética consistente con $pendiente.
            $disponible = (float)$row->disponible;

            // Nunca asignar más de lo que hay en este registro (protección doble).
            $aTomar = min($disponible, $pendiente);
            $pendiente -= $aTomar;

            // Advertencia de vencimiento próximo (solo para productos con control de FV)
            if ($controlaVencimiento && $row->fecha_vencimiento) {
                $diasRestantes = (int)floor(
                    (strtotime($row->fecha_vencimiento) - time()) / 86400
                );
                if ($diasRestantes <= 0) {
                    // Lote vencido que escapó al auto-quarantine (raza de carrera);
                    // registrar como error para investigación posterior.
                    error_log("[FefoEngine] ALERTA: lote vencido en sugerencia"
                        . " | producto={$productoId} lote={$row->lote}"
                        . " fecha={$row->fecha_vencimiento}"
                        . " empresa={$this->empresaId} sucursal={$this->sucursalId}");
                    $advertencias[] = "LOTE VENCIDO: {$row->lote} (venció hace " . abs($diasRestantes) . " día(s)).";
                } elseif ($diasRestantes <= 15) {
                    $advertencias[] = "Lote {$row->lote} vence en {$diasRestantes} día(s). Priorizar despacho.";
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

        // Log de stock insuficiente para trazabilidad operativa
        if ($pendiente > 0) {
            error_log("[FefoEngine] Stock insuficiente"
                . " | producto={$productoId}"
                . " requerido={$cantidadRequerida} faltante=" . round($pendiente, 4)
                . " empresa={$this->empresaId} sucursal={$this->sucursalId}");
        }

        return [
            'ok'           => $pendiente <= 0,
            'lotes'        => $lotes,
            'faltante'     => round($pendiente, 4),
            'advertencias' => $advertencias,
            'modo'         => $controlaVencimiento ? 'fefo' : 'qty',
        ];
    }

    // ── 2. VALIDAR QUE UN PICKING RESPETA FEFO ────────────────────────────────

    /**
     * Dado un array de líneas de picking ya confirmadas, verifica si el
     * conjunto respeta el orden FEFO para cada producto.
     *
     * Solo valida productos con controla_vencimiento = true.
     * Productos sin control de FV no tienen orden FEFO obligatorio.
     *
     * $lineas = [ ['producto_id'=>1, 'lote'=>'L001', 'fecha_vencimiento'=>'2024-06-01'], … ]
     *
     * Retorna array de violaciones detectadas.
     */
    public function validatePickingOrder(array $lineas, ?int $planillaId = null): array
    {
        $violations = [];

        // Agrupar por producto para evaluar el orden FEFO de cada uno por separado
        $byProducto = [];
        foreach ($lineas as $l) {
            $byProducto[$l['producto_id']][] = $l;
        }

        foreach ($byProducto as $productoId => $items) {
            // Solo aplicar validación FEFO a productos que controlan vencimiento.
            // Para los demás, el orden de picking no es una violación de negocio.
            if (!$this->productoControlaVencimiento($productoId)) {
                continue;
            }

            // Obtener el primer lote FEFO disponible en inventario.
            // Se usa self::LOTES_ALL_QTY para recuperar todos los lotes sin
            // truncar por cantidad (necesitamos el lote correcto, no la cobertura).
            $suggested = $this->getSuggestedLots($productoId, self::LOTES_ALL_QTY);
            if (empty($suggested['lotes'])) continue;

            $loteFefo  = $suggested['lotes'][0]['lote'];
            $fechaFefo = $suggested['lotes'][0]['fecha_vencimiento'];

            foreach ($items as $item) {
                if ($item['lote'] !== null && $item['lote'] !== $loteFefo) {
                    // Violación confirmada solo si la fecha del lote elegido es
                    // POSTERIOR al lote FEFO (se tomó uno que vence más tarde).
                    $fechaElegida = $item['fecha_vencimiento'] ?? null;
                    if ($fechaElegida && $fechaFefo && $fechaElegida > $fechaFefo) {
                        $violations[] = [
                            'producto_id'     => $productoId,
                            'lote_elegido'    => $item['lote'],
                            'fecha_elegida'   => $fechaElegida,
                            'lote_fefo'       => $loteFefo,
                            'fecha_fefo'      => $fechaFefo,
                            'dias_diferencia' => (int)floor(
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

        // Última fecha de movimiento real por producto (sin acotar a la ventana) —
        // necesaria para mostrar "días estancado" y "último movimiento" reales en vez
        // de solo el parámetro de filtro (antes el reporte no calculaba esto en absoluto).
        $ultimoMovPorProducto = Capsule::table('movimiento_inventarios')
            ->where('empresa_id', $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->selectRaw('producto_id, MAX(fecha_movimiento) as ultimo_movimiento')
            ->groupBy('producto_id');

        // Productos con stock pero sin movimiento reciente
        $sinMovimiento = Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->leftJoin('movimiento_inventarios as m', function ($join) use ($fechaCorte) {
                $join->on('m.producto_id', '=', 'i.producto_id')
                     ->on('m.empresa_id',  '=', 'i.empresa_id')
                     ->where('m.fecha_movimiento', '>=', $fechaCorte);
            })
            ->leftJoinSub($ultimoMovPorProducto, 'um', 'um.producto_id', '=', 'i.producto_id')
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
                Capsule::raw('MAX(um.ultimo_movimiento) as ultimo_movimiento'),
            ])
            ->groupBy('i.producto_id', 'p.nombre', 'p.codigo_interno')
            ->orderByRaw($this->isPg()
                ? 'MIN(i.fecha_vencimiento) ASC NULLS LAST'
                : 'MIN(i.fecha_vencimiento) IS NULL, MIN(i.fecha_vencimiento) ASC')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                $row->dias_sin_movimiento = $row->ultimo_movimiento
                    ? (int) floor((strtotime(date('Y-m-d')) - strtotime($row->ultimo_movimiento)) / 86400)
                    : null; // null = nunca ha tenido un movimiento registrado
                return $row;
            });

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
