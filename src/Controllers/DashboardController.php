<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Cita;
use App\Models\Recepcion;
use App\Models\OrdenPicking;
use App\Models\Despacho;
use App\Models\ConteoInventario;
use App\Models\Inventario;
use App\Models\Ubicacion;
use App\Models\Personal;
use App\Models\Producto;
use Illuminate\Database\Capsule\Manager as DB;

class DashboardController extends BaseController
{
    /**
     * GET /api/dashboard
     * Retorna estadísticas en tiempo real para el Supervisor
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $user = $request->getAttribute('user');
            
            if (!in_array($user->rol, ['Admin', 'Supervisor'])) {
                return $this->error($response, 'Acceso denegado.', 403);
            }

            $sucursal = $user->sucursal_id;
            $hoy = date('Y-m-d');

            // 1. KPIs Base
            $pickingPendiente = OrdenPicking::where('sucursal_id', $sucursal)->where('estado', 'Pendiente')->count();
            $pickingCompletado = OrdenPicking::where('sucursal_id', $sucursal)->where('estado', 'Completada')->where('fecha_movimiento', $hoy)->count();
            
            $recepcionesCerradas = Recepcion::where('sucursal_id', $sucursal)->where('estado', 'Cerrada')->where('fecha_movimiento', $hoy)->count();

        // 2. Ubicaciones Vacías
        $ubicacionesVacias = Ubicacion::where('sucursal_id', $sucursal)->where('activo', 1)->where('estado', 'Libre')->count();

        // 3. Bajo Stock (Fixed: Start from productos to include zero-stock items)
        $bajoStockQuery = DB::table('productos')
            ->leftJoin('inventarios', function($join) use ($sucursal) {
                $join->on('productos.id', '=', 'inventarios.producto_id')
                     ->where('inventarios.sucursal_id', '=', $sucursal);
            })
            ->where('productos.stock_minimo', '>', 0)
            ->select('productos.id', 'productos.stock_minimo', DB::raw('COALESCE(SUM(inventarios.cantidad), 0) as total_stock'))
            ->groupBy('productos.id', 'productos.stock_minimo')
            ->havingRaw('COALESCE(SUM(inventarios.cantidad), 0) <= productos.stock_minimo');

        $bajoStock = $bajoStockQuery->get()->count();

        // 4. Certificaciones (Planillas)
        $certPendientes = DB::table('cert_planillas')->where('sucursal_id', $sucursal)->where('estado', 'EnProceso')->count();
        $certCompletadas = DB::table('cert_planillas')->where('sucursal_id', $sucursal)->where('estado', 'Completada')->where('fecha', $hoy)->count();

        // 5. Ocupación (%) - Cálculo basado en inventario real
        $totalUbicaciones = Ubicacion::where('sucursal_id', $sucursal)->where('activo', 1)->count();
        $ubicacionesOcupadas = DB::table('inventarios')
            ->where('sucursal_id', $sucursal)
            ->where('cantidad', '>', 0)
            ->distinct()
            ->count('ubicacion_id');
        
        $pctOcupacion = $totalUbicaciones > 0 ? round(($ubicacionesOcupadas / $totalUbicaciones) * 100, 1) : 0;
        $ubicacionesVacias = $totalUbicaciones - $ubicacionesOcupadas;

        // 6. Referencias en Stock
        $referenciasStock = Inventario::where('sucursal_id', $sucursal)->where('cantidad', '>', 0)->count(DB::raw('DISTINCT producto_id'));

        // 6b. Bajo Stock - Lista Detallada
        $bajoStockList = DB::table('productos')
            ->leftJoin('inventarios', function($join) use ($sucursal) {
                $join->on('productos.id', '=', 'inventarios.producto_id')
                     ->where('inventarios.sucursal_id', '=', $sucursal);
            })
            ->where('productos.stock_minimo', '>', 0)
            ->select(
                'productos.id',
                'productos.codigo_interno',
                'productos.nombre',
                'productos.stock_minimo',
                DB::raw('COALESCE(SUM(inventarios.cantidad), 0) as total_stock')
            )
            ->groupBy('productos.id', 'productos.codigo_interno', 'productos.nombre', 'productos.stock_minimo')
            ->havingRaw('COALESCE(SUM(inventarios.cantidad), 0) <= productos.stock_minimo')
            ->get();

        // 6c. Inventario por Categoría
        $invCategoricoRaw = DB::table('inventarios')
            ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
            ->leftJoin('categoria_productos', 'productos.categoria_id', '=', 'categoria_productos.id')
            ->where('inventarios.sucursal_id', $sucursal)
            ->where('inventarios.cantidad', '>', 0)
            ->select(
                DB::raw('COALESCE(categoria_productos.nombre, "Sin Categoría") as categoria'),
                'productos.nombre as producto_nombre',
                'productos.codigo_interno as producto_codigo',
                DB::raw('SUM(inventarios.cantidad) as cant_producto')
            )
            ->groupBy('categoria', 'productos.id', 'productos.nombre', 'productos.codigo_interno')
            ->get();

        $totalGralStock = $invCategoricoRaw->sum('cant_producto');
        $invPorCategoria = $invCategoricoRaw->groupBy('categoria')->map(function ($items, $catName) use ($totalGralStock) {
            $totalCat = $items->sum('cant_producto');
            return [
                'categoria' => $catName,
                'total_cantidad' => $totalCat,
                'total_referencias' => $items->unique('producto_codigo')->count(),
                'porcentaje' => $totalGralStock > 0 ? round(($totalCat / $totalGralStock) * 100, 1) : 0,
                'productos' => $items->map(fn($i) => [
                    'codigo' => $i->producto_codigo,
                    'nombre' => $i->producto_nombre,
                    'cantidad' => $i->cant_producto
                ])->values()->all()
            ];
        })->values()->all();

        // 7. Vencimientos próximos (<60 días)
        $hoyStr = \Carbon\Carbon::now()->format('Y-m-d');
        $en60DiasStr = \Carbon\Carbon::now()->addDays(60)->format('Y-m-d');

        $inventariosVenc = Inventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $sucursal)
            ->whereNotNull('fecha_vencimiento')
            ->whereBetween('fecha_vencimiento', [$hoyStr, $en60DiasStr])
            ->where('cantidad', '>', 0)
            ->with(['producto:id,nombre,codigo_interno', 'ubicacion:id,codigo'])
            ->orderBy('fecha_vencimiento', 'asc')
            ->get(['id', 'producto_id', 'ubicacion_id', 'lote', 'fecha_vencimiento', 'cantidad', 'created_at']);

        $proximosVencer = $inventariosVenc->map(function ($inv) {
            $hoyDate = new \DateTime();
            $fecha = $inv->fecha_vencimiento instanceof \DateTime
                ? $inv->fecha_vencimiento
                : new \DateTime($inv->fecha_vencimiento);
            $dias = (int)$hoyDate->diff($fecha)->format('%r%a');
            $fv = $inv->fecha_vencimiento;
            $fi = $inv->created_at;
            return [
                'id' => $inv->id,
                'producto' => $inv->producto ? $inv->producto->nombre : 'N/A',
                'codigo' => $inv->producto ? $inv->producto->codigo_interno : 'N/A',
                'ubicacion' => $inv->ubicacion ? ($inv->ubicacion->codigo ?? $inv->ubicacion->name ?? 'N/A') : 'N/A',
                'lote' => $inv->lote ?? 'N/A',
                'fecha_vencimiento' => $fv ? ($fv instanceof \DateTime ? $fv->format('Y-m-d') : (string)$fv) : null,
                'fecha_ingreso' => $fi ? ($fi instanceof \DateTime ? $fi->format('Y-m-d') : date('Y-m-d', strtotime((string)$fi))) : null,
                'cantidad' => $inv->cantidad,
                'dias_vencer' => $dias,
            ];
        });

        // 8. Actividad Reciente (últimos 3 días)
        $hace3Dias = \Carbon\Carbon::now()->subDays(3)->toDateTimeString();
        $actividad = DB::table('movimiento_inventarios')
            ->join('personal', 'movimiento_inventarios.auxiliar_id', '=', 'personal.id')
            ->join('productos', 'movimiento_inventarios.producto_id', '=', 'productos.id')
            ->where('movimiento_inventarios.sucursal_id', $sucursal)
            ->where('movimiento_inventarios.created_at', '>=', $hace3Dias)
            ->select(
                'movimiento_inventarios.created_at as fecha',
                'personal.nombre as auxiliar',
                'movimiento_inventarios.tipo_movimiento',
                'productos.nombre as producto',
                'movimiento_inventarios.cantidad'
            )
            ->orderBy('movimiento_inventarios.created_at', 'desc')
            ->limit(15)
            ->get()
            ->map(function ($a) {
                return [
                    'fecha'     => date('Y-m-d H:i', strtotime($a->fecha)),
                    'auxiliar'  => $a->auxiliar,
                    'tipo'      => $a->tipo_movimiento,
                    'producto'  => $a->producto,
                    'cantidad'  => $a->cantidad,
                ];
            });

        return $this->ok($response, [
            'kpis' => [
                'picking_pendiente'     => $pickingPendiente,
                'picking_completado'    => $pickingCompletado,
                'recepciones_cerradas'  => $recepcionesCerradas,
                'bajo_stock'            => $bajoStock,
                'cert_pendientes'       => $certPendientes,
                'cert_completadas'      => $certCompletadas,
                'pct_ocupacion'         => $pctOcupacion,
                'ubicaciones_vacias'    => $ubicacionesVacias,
                'total_ubicaciones'     => $totalUbicaciones,
                'referencias_stock'     => $referenciasStock,
            ],
            'bajo_stock_list'   => $bajoStockList,
            'inv_por_categoria' => $invPorCategoria,
            'proximos_vencer'   => $proximosVencer,
            'actividad'         => $actividad,
        ]);

        } catch (\Exception $e) {
            error_log('DashboardController::index error: ' . $e->getMessage());
            return $this->error($response, 'Error al cargar dashboard.', 500);
        }
    }

    /**
     * GET /api/dashboard/summary
     * Resumen ligero para el dashboard de inicio (inicio.js)
     */
    public function summary(Request $request, Response $response): Response
    {
        try {
            $user     = $request->getAttribute('user');
            $sucursal = $user->sucursal_id;
            $hoy      = date('Y-m-d');

            // KPIs básicos
            $productos   = Producto::where('empresa_id', $user->empresa_id)->where('activo', 1)->count();
            $recepciones = Recepcion::where('sucursal_id', $sucursal)->whereDate('created_at', $hoy)->count();
            $pickings    = OrdenPicking::where('sucursal_id', $sucursal)->whereDate('created_at', $hoy)->count();
            $ubicaciones = Ubicacion::where('sucursal_id', $sucursal)->where('activo', 1)->count();
            $alertas     = DB::table('productos')
                ->leftJoin('inventarios', function ($j) use ($sucursal) {
                    $j->on('productos.id', '=', 'inventarios.producto_id')
                      ->where('inventarios.sucursal_id', '=', $sucursal);
                })
                ->where('productos.stock_minimo', '>', 0)
                ->select('productos.id')
                ->groupBy('productos.id', 'productos.stock_minimo')
                ->havingRaw('COALESCE(SUM(inventarios.cantidad), 0) <= productos.stock_minimo')
                ->get()->count();

            // Tendencia últimos 7 días (picking completado)
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $fecha = date('Y-m-d', strtotime("-{$i} days"));
                $cnt   = OrdenPicking::where('sucursal_id', $sucursal)
                    ->where('estado', 'Completada')
                    ->whereDate('updated_at', $fecha)
                    ->count();
                $trend[] = ['fecha' => $fecha, 'valor' => $cnt];
            }

            // 1. Disponibilidad de Productos (Basado en Productos con Presencia en Inventario)
            // Solo contamos productos que han sido ingresados al WMS (existen en la tabla inventarios)
            $prodStats = DB::table('productos as p')
                ->join('inventarios as i', 'p.id', '=', 'i.producto_id')
                ->where('p.empresa_id', $user->empresa_id)
                ->where('p.activo', 1)
                ->where('i.sucursal_id', $sucursal)
                ->select('p.id', 'p.stock_minimo', DB::raw("SUM(i.cantidad) as total_qty"))
                ->groupBy('p.id', 'p.stock_minimo')
                ->get();

            $totalActiveRefs = $prodStats->count();
            $dispOk    = 0;
            $dispWarn  = 0;
            $dispEmpty = 0;

            foreach ($prodStats as $ps) {
                if ($ps->total_qty > ($ps->stock_minimo ?? 0) && $ps->total_qty > 0) {
                    $dispOk++;
                } elseif ($ps->total_qty > 0) {
                    $dispWarn++;
                } else {
                    $dispEmpty++;
                }
            }

            // Fallback: si no hay productos en inventario, usamos catálogo activo (pero permitimos al usuario saber por qué)
            if ($totalActiveRefs === 0) {
                $totalActiveRefs = $productos;
                $dispEmpty = $productos;
            }

            // 2. Ocupación de Bodega (Basado en Ubicaciones Físicas)
            $totalUbic = Ubicacion::where('sucursal_id', $sucursal)->where('activo', 1)->count();
            $ocupadas  = Inventario::where('sucursal_id', $sucursal)
                ->where('cantidad', '>', 0)
                ->distinct()
                ->count('ubicacion_id');
            $vacias    = max(0, $totalUbic - $ocupadas);

            return $this->ok($response, [
                'stats' => [
                    'productos'   => $productos,
                    'recepciones' => $recepciones,
                    'pickings'    => $pickings,
                    'ubicaciones' => $ubicaciones,
                    'alertas'     => $alertas,
                ],
                'trend'     => $trend,
                'inv_state' => [
                    // Estos datos alimentan el donut principal por defecto
                    'ok'    => $dispOk,
                    'warn'  => $dispWarn,
                    'empty' => $dispEmpty,
                ],
                'availability' => [
                    'ok'    => $dispOk,
                    'warn'  => $dispWarn,
                    'empty' => $dispEmpty,
                    'total' => $totalActiveRefs
                ],
                'occupancy' => [
                    'occupied' => $ocupadas,
                    'empty'    => $vacias,
                    'total'    => $totalUbic
                ]
            ]);
        } catch (\Exception $e) {
            error_log('DashboardController::summary error: ' . $e->getMessage());
            return $this->error($response, 'Error al cargar resumen.', 500);
        }
    }

    /**
     * GET /api/dashboard/actividad
     * Feed de actividad reciente para el dashboard de inicio
     */
    public function actividad(Request $request, Response $response): Response
    {
        try {
            $user     = $request->getAttribute('user');
            $sucursal = $user->sucursal_id;

            // Intentar desde kardex/movimientos de inventario
            $rows = [];

            // Recepciones recientes
            $recs = Recepcion::where('sucursal_id', $sucursal)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'numero_recepcion', 'estado', 'created_at']);
            foreach ($recs as $r) {
                $rows[] = [
                    'tipo'    => 'Recepción',
                    'icono'   => 'truck-ramp-box',
                    'color'   => 'blue',
                    'texto'   => 'Recepción ' . ($r->numero_recepcion ?? '#' . $r->id) . ' — ' . $r->estado,
                    'fecha'   => $r->created_at,
                ];
            }

            // Despachos recientes
            $desps = Despacho::where('sucursal_id', $sucursal)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'numero_despacho', 'estado', 'created_at']);
            foreach ($desps as $d) {
                $rows[] = [
                    'tipo'  => 'Despacho',
                    'icono' => 'truck',
                    'color' => 'green',
                    'texto' => 'Despacho ' . ($d->numero_despacho ?? '#' . $d->id) . ' — ' . $d->estado,
                    'fecha' => $d->created_at,
                ];
            }

            // Órdenes de picking recientes
            $picks = OrdenPicking::where('sucursal_id', $sucursal)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'numero_orden', 'estado', 'created_at']);
            foreach ($picks as $p) {
                $rows[] = [
                    'tipo'  => 'Picking',
                    'icono' => 'cart-flatbed',
                    'color' => 'orange',
                    'texto' => 'Orden ' . ($p->numero_orden ?? '#' . $p->id) . ' — ' . $p->estado,
                    'fecha' => $p->created_at,
                ];
            }

            // Ordenar por fecha descendente y limitar a 15 items
            usort($rows, fn($a, $b) => strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? '')));
            $rows = array_slice($rows, 0, 15);

            return $this->ok($response, $rows);
        } catch (\Exception $e) {
            error_log('DashboardController::actividad error: ' . $e->getMessage());
            return $this->error($response, 'Error al cargar actividad.', 500);
        }
    }
}
                  