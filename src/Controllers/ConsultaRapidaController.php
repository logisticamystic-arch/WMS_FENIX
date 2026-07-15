<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Producto;
use App\Models\Inventario;

class ConsultaRapidaController extends BaseController
{
    // ── GET /api/consulta-rapida/buscar?q=X ──────────────────────────────────
    public function buscar(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $params    = $request->getQueryParams();
        $q         = trim($params['q'] ?? '');

        if (mb_strlen($q) < 2) {
            return $this->ok($response, []);
        }

        $resultados = Producto::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where(function ($qb) use ($q) {
                $qb->where('nombre', 'ilike', "%{$q}%")
                   ->orWhere('codigo_interno', 'ilike', "%{$q}%");
            })
            ->orderByRaw("CASE WHEN LOWER(codigo_interno) = LOWER(?) THEN 0 WHEN codigo_interno ILIKE ? THEN 1 ELSE 2 END", [$q, "{$q}%"])
            ->limit(15)
            ->get(['id', 'codigo_interno', 'nombre', 'unidad_medida', 'controla_lote', 'controla_vencimiento', 'stock_minimo']);

        return $this->ok($response, $resultados->toArray());
    }

    // ── GET /api/consulta-rapida/{producto_id} ────────────────────────────────
    public function dashboard(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $productoId = (int) ($args['producto_id'] ?? 0);

        if (!$productoId) {
            return $this->error($response, 'producto_id inválido');
        }

        // ── Producto ──────────────────────────────────────────────────────────
        $producto = Producto::where('id', $productoId)
            ->where('empresa_id', $empresaId)
            ->first(['id', 'codigo_interno', 'nombre', 'unidad_medida',
                     'controla_lote', 'controla_vencimiento', 'stock_minimo', 'descripcion',
                     'factor_udm', 'unidad_contenido', 'bloqueado']);

        if (!$producto) {
            return $this->error($response, 'Producto no encontrado', 404);
        }

        $pdo = Capsule::connection()->getPdo();

        // ── Totales de inventario ─────────────────────────────────────────────
        $totalDisponible  = (int) Inventario::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('producto_id', $productoId)
            ->where('estado', 'Disponible')
            ->sum('cantidad');

        // ── Disponible PARA VENTA: distinto de disponible físico — descuenta
        //    producto/lotes bloqueados por calidad o vencimiento (BloqueoController). ──
        $lotesBloqueadosCR = \App\Models\BloqueoLote::where('empresa_id', $empresaId)
            ->where('producto_id', $productoId)
            ->pluck('lote')->toArray();
        $cantidadBloqueada = !empty($lotesBloqueadosCR)
            ? (int) Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('producto_id', $productoId)
                ->where('estado', 'Disponible')
                ->whereIn('lote', $lotesBloqueadosCR)
                ->sum('cantidad')
            : 0;
        $totalDisponibleVenta = $producto->bloqueado ? 0 : max(0, $totalDisponible - $cantidadBloqueada);

        $totalReservado   = (int) Inventario::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('producto_id', $productoId)
            ->where('estado', 'Reservado')
            ->sum('cantidad');

        $totalCuarentena  = (int) Inventario::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('producto_id', $productoId)
            ->where('estado', 'Cuarentena')
            ->sum('cantidad');

        // ── Stock por lote ────────────────────────────────────────────────────
        $porLote = [];
        if ($producto->controla_lote) {
            try {
                $stmtLote = $pdo->prepare("
                    SELECT lote,
                           fecha_vencimiento::text AS fecha_vencimiento,
                           SUM(cantidad)            AS cantidad,
                           SUM(cantidad_reservada)  AS cantidad_reservada
                    FROM inventarios
                    WHERE empresa_id  = :emp
                      AND sucursal_id = :suc
                      AND producto_id = :pid
                      AND estado      = 'Disponible'
                    GROUP BY lote, fecha_vencimiento
                    ORDER BY fecha_vencimiento ASC NULLS LAST
                ");
                $stmtLote->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':pid' => $productoId]);
                $porLote = $stmtLote->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                wmsLog('ERROR', 'ConsultaRapida:por_lote — ' . $e->getMessage());
            }
        }

        // ── Stock por ubicación ───────────────────────────────────────────────
        $porUbicacion = [];
        try {
            $stmtUbic = $pdo->prepare("
                SELECT u.codigo AS ubicacion_codigo,
                       u.zona,
                       SUM(i.cantidad)           AS cantidad,
                       SUM(i.cantidad_reservada) AS cantidad_reservada
                FROM inventarios i
                JOIN ubicaciones u ON u.id = i.ubicacion_id
                WHERE i.empresa_id  = :emp
                  AND i.sucursal_id = :suc
                  AND i.producto_id = :pid
                  AND i.estado      = 'Disponible'
                GROUP BY u.codigo, u.zona
                ORDER BY cantidad DESC
            ");
            $stmtUbic->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':pid' => $productoId]);
            $porUbicacion = $stmtUbic->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'ConsultaRapida:por_ubicacion — ' . $e->getMessage());
        }

        // ── KPIs — picking (promedio por pedido) ──────────────────────────────
        $kpiPicking = ['total_pedidos' => 0, 'total_solicitado' => 0, 'promedio_por_linea' => 0];
        try {
            $stmtPick = $pdo->prepare("
                SELECT COUNT(DISTINCT op.id)                                   AS total_pedidos,
                       COALESCE(SUM(pd.cantidad_solicitada), 0)                AS total_solicitado,
                       COALESCE(ROUND(AVG(pd.cantidad_solicitada)::numeric, 1), 0) AS promedio_por_linea
                FROM picking_detalles pd
                JOIN orden_pickings op ON op.id = pd.orden_picking_id
                WHERE op.empresa_id  = :emp
                  AND op.sucursal_id = :suc
                  AND pd.producto_id = :pid
                  AND op.fecha_movimiento >= CURRENT_DATE - INTERVAL '30 days'
                  AND op.estado = 'Completada'
            ");
            $stmtPick->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':pid' => $productoId]);
            $kpiPicking = $stmtPick->fetch(\PDO::FETCH_ASSOC) ?: $kpiPicking;
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'ConsultaRapida:kpi_picking — ' . $e->getMessage());
        }

        // ── KPIs — recepciones (promedio de ingreso) ──────────────────────────
        $kpiRec = ['total_ingresos' => 0, 'total_ingresado' => 0, 'promedio_ingreso' => 0];
        try {
            $stmtRec = $pdo->prepare("
                SELECT COUNT(DISTINCT rd.recepcion_id)                              AS total_ingresos,
                       COALESCE(SUM(rd.cantidad_recibida), 0)                      AS total_ingresado,
                       COALESCE(ROUND(AVG(rd.cantidad_recibida)::numeric, 1), 0)   AS promedio_ingreso
                FROM recepcion_detalles rd
                JOIN recepciones r ON r.id = rd.recepcion_id
                WHERE r.empresa_id  = :emp
                  AND r.sucursal_id = :suc
                  AND rd.producto_id = :pid
                  AND r.fecha_movimiento >= CURRENT_DATE - INTERVAL '30 days'
            ");
            $stmtRec->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':pid' => $productoId]);
            $kpiRec = $stmtRec->fetch(\PDO::FETCH_ASSOC) ?: $kpiRec;
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'ConsultaRapida:kpi_recepciones — ' . $e->getMessage());
        }

        // ── Movimientos últimos 30 días ───────────────────────────────────────
        $movimientos30d = [];
        try {
            $stmtMov = $pdo->prepare("
                SELECT fecha_movimiento::text AS fecha,
                       SUM(CASE WHEN tipo_movimiento IN ('Entrada','Devolucion','AjustePositivo','Reabastecimiento')
                                THEN ABS(cantidad) ELSE 0 END) AS entradas,
                       SUM(CASE WHEN tipo_movimiento IN ('Salida','Picking','AjusteNegativo')
                                THEN ABS(cantidad) ELSE 0 END) AS salidas
                FROM movimiento_inventarios
                WHERE empresa_id  = :emp
                  AND sucursal_id = :suc
                  AND producto_id = :pid
                  AND fecha_movimiento >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY fecha_movimiento
                ORDER BY fecha_movimiento ASC
            ");
            $stmtMov->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':pid' => $productoId]);
            $movimientos30d = $stmtMov->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'ConsultaRapida:movimientos_30d — ' . $e->getMessage());
        }

        // ── Ventas por cliente últimos 30 días ────────────────────────────────
        $ventasPorCliente = [];
        try {
            $stmtCli = $pdo->prepare("
                SELECT COALESCE(NULLIF(TRIM(op.cliente), ''), 'Sin identificar') AS cliente,
                       COUNT(DISTINCT op.id)                                      AS total_pedidos,
                       COALESCE(SUM(pd.cantidad_pickeada), 0)                    AS total_unidades,
                       COALESCE(ROUND(AVG(pd.cantidad_pickeada)::numeric, 1), 0) AS promedio_por_pedido
                FROM orden_pickings op
                JOIN picking_detalles pd ON pd.orden_picking_id = op.id
                WHERE op.empresa_id  = :emp
                  AND op.sucursal_id = :suc
                  AND pd.producto_id = :pid
                  AND op.fecha_movimiento >= CURRENT_DATE - INTERVAL '30 days'
                  AND op.estado = 'Completada'
                GROUP BY op.cliente
                ORDER BY total_unidades DESC
                LIMIT 10
            ");
            $stmtCli->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':pid' => $productoId]);
            $ventasPorCliente = $stmtCli->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            wmsLog('ERROR', 'ConsultaRapida:ventas_cliente — ' . $e->getMessage());
        }

        return $this->ok($response, [
            'producto'  => $producto->toArray(),
            'inventario' => [
                'total_disponible'       => $totalDisponible,
                'total_disponible_venta' => $totalDisponibleVenta,
                'total_bloqueado'        => $producto->bloqueado ? $totalDisponible : $cantidadBloqueada,
                'total_reservado'        => $totalReservado,
                'total_cuarentena'       => $totalCuarentena,
                'por_lote'               => $porLote,
                'por_ubicacion'          => $porUbicacion,
            ],
            'kpis' => [
                'promedio_por_pedido'   => (float) ($kpiPicking['promedio_por_linea'] ?? 0),
                'promedio_ingreso'      => (float) ($kpiRec['promedio_ingreso'] ?? 0),
                'total_pedidos_30d'     => (int)   ($kpiPicking['total_pedidos']   ?? 0),
                'total_ingresos_30d'    => (int)   ($kpiRec['total_ingresos']      ?? 0),
                'unidades_vendidas_30d' => (int)   ($kpiPicking['total_solicitado'] ?? 0),
                'unidades_ingresadas_30d' => (int) ($kpiRec['total_ingresado']     ?? 0),
            ],
            'movimientos_30d'    => $movimientos30d,
            'ventas_por_cliente' => $ventasPorCliente,
        ]);
    }
}
