<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Recepcion;
use App\Models\RecepcionDetalle;
use App\Models\Despacho;
use App\Models\OrdenPicking;
use App\Models\ConteoInventario;
use App\Models\OrdenCompra;
use App\Models\Devolucion;
use App\Models\Cita;
use App\Controllers\InventarioController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ReportesController — 9 reportes filtrados por rango de fecha.
 * Todos exportables a Excel (CSV + BOM UTF-8).
 * Solo Admin puede ver el reporte de audit_log.
 */
class ReportesController extends BaseController
{
    // ── 1. KARDEX ─────────────────────────────────────────────────────────────
    // Delegado a InventarioController::getKardex → /api/inventario/kardex
    // Aquí mantenemos el endpoint de reportes como alias
    public function kardex(Request $r, Response $res): Response
    {
        return (new InventarioController())->getKardex($r, $res);
    }

    // ── 2. STOCK ACTUAL ───────────────────────────────────────────────────────
    public function stockActual(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $stock = Inventario::where('inventarios.empresa_id', $user->empresa_id)
            ->where('inventarios.sucursal_id', $user->sucursal_id)
            ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
            ->select(
                'productos.codigo_interno',
                'productos.nombre as producto',
                'marcas.nombre as marca',
                'ubicaciones.codigo as ubicacion',
                'inventarios.lote',
                'inventarios.fecha_vencimiento',
                'inventarios.cantidad',
                'inventarios.cantidad',
                'inventarios.estado'
            )
            ->when($params['estado'] ?? null, fn($q, $e) => $q->where('inventarios.estado', $e))
            ->when($params['solo_proximos_vencer'] ?? null, function ($q) {
                $hoy = \Carbon\Carbon::now()->format('Y-m-d');
                $en30Dias = \Carbon\Carbon::now()->addDays(30)->format('Y-m-d');
                $q->whereNotNull('inventarios.fecha_vencimiento')
                  ->whereBetween('inventarios.fecha_vencimiento', [$hoy, $en30Dias]);
            })
            ->when($params['solo_vencidos'] ?? null, function ($q) {
                $hoy = \Carbon\Carbon::now()->format('Y-m-d');
                $q->whereNotNull('inventarios.fecha_vencimiento')
                  ->where('inventarios.fecha_vencimiento', '<', $hoy);
            })
            ->orderBy('inventarios.fecha_vencimiento')
            ->get();

        // Calcular días por vencer en PHP para compatibilidad universal
        $stock = $stock->map(function($item) {
            $item->dias_vencer = null;
            if ($item->fecha_vencimiento) {
                $fechaVen = \Carbon\Carbon::parse($item->fecha_vencimiento)->startOfDay();
                $hoy = \Carbon\Carbon::now()->startOfDay();
                $item->dias_vencer = $hoy->diffInDays($fechaVen, false);
            }
            return $item;
        });


        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Código', 'Producto', 'Marca', 'Ubicación', 'Lote',
                        'F.Vencimiento', 'Días p/Vencer', 'Cantidad', 'Estado'];
            $rows = $stock->map(fn($s) => [
                $s->codigo_interno, $s->producto, $s->marca ?? '—', $s->ubicacion,
                $s->lote ?? '—', $s->fecha_vencimiento ?? '—',
                $s->dias_vencer !== null ? $s->dias_vencer : '—',
                $s->cantidad, $s->estado,
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'stock_actual_' . date('Y-m-d'));
        }

        return $this->ok($res, $stock);
    }

    // ── 3. RECEPCIONES ────────────────────────────────────────────────────────
    public function recepciones(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $recepciones = Recepcion::where('recepciones.empresa_id', $user->empresa_id)
            ->where('recepciones.sucursal_id', $user->sucursal_id)
            ->whereBetween('recepciones.created_at', [$ini, $fin])
            ->when(!empty($params['numero_odc']), function ($q) use ($params) {
                $q->whereHas('ordenCompra', function ($q2) use ($params) {
                    $q2->where('numero_odc', 'LIKE', "%{$params['numero_odc']}%");
                });
            })
            ->when(!empty($params['proveedor']), function ($q) use ($params) {
                $q->where(function ($w) use ($params) {
                    $w->whereHas('ordenCompra', function ($q2) use ($params) {
                        $q2->whereHas('proveedor', function ($q3) use ($params) {
                            $q3->where('razon_social', 'LIKE', "%{$params['proveedor']}%");
                        });
                    })
                    ->orWhereHas('cita', function ($q2) use ($params) {
                        $q2->where('proveedor', 'LIKE', "%{$params['proveedor']}%");
                    });
                });
            })
            ->when(!empty($params['producto']), function ($q) use ($params) {
                $q->whereHas('detalles.producto', function ($q2) use ($params) {
                    $q2->where('nombre', 'LIKE', "%{$params['producto']}%")
                       ->orWhere('codigo_interno', 'LIKE', "%{$params['producto']}%");
                });
            })
            ->with(['detalles.producto', 'ordenCompra.proveedor', 'cita', 'auxiliar'])
            ->orderBy('recepciones.created_at', 'desc')
            ->get();

        $recepciones->each(function ($rec) {
            $rec->proveedor = $rec->ordenCompra?->proveedor?->razon_social ?? $rec->cita?->proveedor ?? 'Manual/Directo';
            $rec->odc_numero = $rec->ordenCompra?->numero_odc ?? '-';
            $rec->auxiliar_nombre = $rec->auxiliar?->nombre ?? '-';
            $rec->total_productos = $rec->detalles->sum('cantidad_recibida');
        });

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($recepciones as $rec) {
                foreach ($rec->detalles as $d) {
                    $rows[] = [
                        $rec->numero_recepcion,
                        $rec->odc_numero,
                        $rec->proveedor,
                        $rec->created_at,
                        $rec->estado,
                        $d->producto->nombre    ?? '—',
                        $d->producto->codigo_interno ?? '—',
                        $d->cantidad_recibida,
                        $d->lote ?? '—',
                        $d->fecha_vencimiento ?? '—',
                        $d->estado_mercancia ?? '—',
                    ];
                }
            }
            $headers = ['# Recepción', 'ODC', 'Proveedor', 'Fecha', 'Estado',
                        'Producto', 'Código', 'Cantidad', 'Lote', 'F.Venc.', 'Estado Mercancía'];
            return $this->exportCsv($res, $headers, $rows, 'recepciones_' . date('Y-m-d'));
        }

        return $this->ok($res, $recepciones);
    }

    // ── 4. DESPACHOS ──────────────────────────────────────────────────────────
    public function despachos(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $despachos = Despacho::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('fecha_movimiento', [substr($ini, 0, 10), substr($fin, 0, 10)])
            ->with('certificaciones.producto')
            ->orderBy('fecha_movimiento', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($despachos as $d) {
                foreach ($d->certificaciones as $c) {
                    $rows[] = [
                        $d->numero_despacho, $d->cliente ?? '—', $d->ruta ?? '—',
                        $d->fecha_movimiento, $d->estado,
                        $c->producto->nombre ?? '—',
                        $c->producto->codigo_interno ?? '—',
                        $c->lote ?? '—', $c->cantidad_certificada,
                    ];
                }
            }
            $headers = ['# Despacho', 'Cliente', 'Ruta', 'Fecha', 'Estado',
                        'Producto', 'Código', 'Lote', 'Cant. Certificada'];
            return $this->exportCsv($res, $headers, $rows, 'despachos_' . date('Y-m-d'));
        }

        return $this->ok($res, $despachos);
    }

    // ── 5. DEVOLUCIONES ───────────────────────────────────────────────────────
    public function devoluciones(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $devs = Devolucion::where('devoluciones.empresa_id', $user->empresa_id)
            ->whereBetween('devoluciones.created_at', [$ini, $fin])
            ->with('detalles.producto')
            ->orderBy('devoluciones.created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($devs as $dv) {
                foreach ($dv->detalles as $det) {
                    $rows[] = [
                        $dv->numero_devolucion ?? $dv->id,
                        $dv->tipo, $dv->proveedor ?? '—',
                        $dv->created_at, $dv->estado,
                        $det->producto->nombre ?? '—',
                        $det->cantidad,
                        $det->destino ?? '—',
                        $det->motivo ?? '—',
                    ];
                }
            }
            $headers = ['# Devolución', 'Tipo', 'Proveedor', 'Fecha', 'Estado',
                        'Producto', 'Cantidad', 'Destino', 'Motivo'];
            return $this->exportCsv($res, $headers, $rows, 'devoluciones_' . date('Y-m-d'));
        }

        return $this->ok($res, $devs);
    }

    // ── 6. PICKING ────────────────────────────────────────────────────────────
    public function picking(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        // Búsqueda detallada por línea para el reporte
        $query = Capsule::table('picking_detalles as d')
            ->join('orden_pickings as o', 'd.orden_picking_id', '=', 'o.id')
            ->join('productos as p', 'd.producto_id', '=', 'p.id')
            ->leftJoin('ubicaciones as u', 'd.ubicacion_id', '=', 'u.id')
            ->leftJoin('personal as aux', 'd.auxiliar_id', '=', 'aux.id')
            ->where('o.empresa_id', $user->empresa_id)
            ->where('o.sucursal_id', $user->sucursal_id)
            ->whereBetween('o.created_at', [$ini, $fin])
            ->select(
                'o.planilla_numero',
                'o.area_comercial as ruta',
                'o.cliente',
                'p.codigo_interno as ean',
                'p.nombre as producto',
                'd.cantidad_solicitada',
                'd.cantidad_pickeada',
                'u.codigo as ubicacion',
                'aux.nombre as auxiliar',
                'o.hora_inicio',
                'd.updated_at as hora_fin_linea',
                'o.estado as orden_estado',
                'd.estado as linea_estado'
            )
            ->when($params['planilla_numero'] ?? null, fn($q, $v) => $q->where('o.planilla_numero', 'LIKE', "%$v%"))
            ->when($params['ruta'] ?? null, fn($q, $v) => $q->where('o.area_comercial', 'LIKE', "%$v%"))
            ->orderBy('o.planilla_numero', 'desc')
            ->orderBy('o.created_at', 'desc');

        $rows = $query->get();

        if (($params['export'] ?? '') === 'excel') {
            $data = $rows->map(fn($row) => [
                $row->planilla_numero ?? '—',
                $row->ruta            ?? '—',
                "($row->ean) $row->producto",
                $row->cantidad_solicitada,
                $row->cantidad_pickeada,
                $row->ubicacion       ?? '—',
                $row->auxiliar        ?? '—',
                $row->hora_inicio     ?? '—',
                $row->hora_fin_linea ? substr($row->hora_fin_linea, 11, 8) : '—',
                $row->linea_estado
            ])->toArray();

            $headers = ['Planilla', 'Ruta', 'Producto (EAN)', 'Solicitado', 'Separado', 'Ubicación', 'Auxiliar', 'Hora Inicio', 'Hora Fin', 'Estado'];
            return $this->exportCsv($res, $headers, $data, 'reporte_picking_detallado_' . date('Y-m-d'));
        }

        return $this->ok($res, $rows);
    }

    // ── 7. CONTEOS ────────────────────────────────────────────────────────────
    public function conteos(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $conteos = ConteoInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->with('detalles.producto')
            ->orderBy('created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($conteos as $c) {
                foreach ($c->detalles as $d) {
                    $rows[] = [
                        $c->numero, $c->tipo, $c->estado,
                        $c->fecha_inicio, $c->fecha_fin ?? '—',
                        $d->producto->nombre ?? '—',
                        $d->cantidad_sistema, $d->cantidad_contada,
                        $d->diferencia ?? ($d->cantidad_contada - $d->cantidad_sistema),
                        $d->lote ?? '—',
                        $d->fecha_vencimiento ?? '—',
                    ];
                }
            }
            $headers = ['# Conteo', 'Tipo', 'Estado', 'F.Inicio', 'F.Fin',
                        'Producto', 'Cant.Sistema', 'Cant.Física', 'Diferencia', 'Lote', 'F.Venc.'];
            return $this->exportCsv($res, $headers, $rows, 'conteos_' . date('Y-m-d'));
        }

        return $this->ok($res, $conteos);
    }

    // ── 8. ODC REPORTE ────────────────────────────────────────────────────────
    public function odcReporte(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $q = OrdenCompra::where('empresa_id', $user->empresa_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->with(['proveedor', 'detalles.producto']);

        if (!empty($params['numero_odc'])) {
            $q->where('numero_odc', 'LIKE', '%' . $params['numero_odc'] . '%');
        }

        $odcs = $q->orderBy('created_at', 'desc')->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            if (($params['group'] ?? '') === 'pallet') {
                $headers = ['# ODC', 'Proveedor', 'Fecha ODC', 'Estado ODC', 'Pallet', 'Producto', 'Código', 'Lote', 'Cant. Recibida', 'Estado Mercancía', 'F. Vencimiento'];
                foreach ($odcs as $o) {
                    $recIds = $o->recepciones()->pluck('id')->toArray();
                    $dets = RecepcionDetalle::whereIn('recepcion_id', $recIds)->with('producto')->get();
                    if ($dets->isEmpty()) {
                        $rows[] = [
                            $o->numero_odc, 
                            $o->proveedor->razon_social ?? '-', 
                            $o->fecha, 
                            $o->estado,
                            '-', '-', '-', '-', 0, '-', '-'
                        ];
                    } else {
                        foreach ($dets as $d) {
                            $rows[] = [
                                $o->numero_odc, 
                                $o->proveedor->razon_social ?? '-', 
                                $o->fecha,
                                $o->estado,
                                $d->pallet_id ?? 'Manual', 
                                $d->producto->nombre ?? '-', 
                                $d->producto->codigo_interno ?? '-',
                                $d->lote ?? '-', 
                                $d->cantidad_recibida,
                                $d->estado_mercancia ?? 'BuenEstado',
                                $d->fecha_vencimiento ?? '-'
                            ];
                        }
                    }
                }
            } else {
                foreach ($odcs as $o) {
                    foreach ($o->detalles as $d) {
                        $rows[] = [
                            $o->numero_odc, $o->proveedor->razon_social ?? '—',
                            $o->fecha, $o->estado,
                            $d->producto->nombre ?? '—', $d->producto->codigo_interno ?? '—',
                            $d->cantidad_solicitada, $d->cantidad_recibida,
                            $d->cantidad_solicitada - $d->cantidad_recibida,
                        ];
                    }
                }
                $headers = ['# ODC', 'Proveedor', 'Fecha', 'Estado', 'Producto', 'Código', 'Solicitado', 'Recibido', 'Pendiente'];
            }
            return $this->exportCsv($res, $headers, $rows, 'odc_report_' . date('Y-m-d'));
        }

        return $this->ok($res, $odcs);
    }

    // ── 9. DASHBOARD GERENCIAL ────────────────────────────────────────────────
    public function dashboardGerencial(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $eId = $user->empresa_id;
        $sId = $user->sucursal_id;
        $hoy = date('Y-m-d');

        $data = [
            // Inventario
            'total_skus'        => Inventario::where('empresa_id', $eId)->distinct('producto_id')->count('producto_id'),
            'stock_total_unidades' => Inventario::where('empresa_id', $eId)->where('sucursal_id', $sId)->sum('cantidad'),
            'proximos_vencer_30' => Inventario::where('empresa_id', $eId)
                ->whereNotNull('fecha_vencimiento')
                ->whereBetween('fecha_vencimiento', [$hoy, \Carbon\Carbon::now()->addDays(30)->format('Y-m-d')])
                ->count(),
            'vencidos'          => Inventario::where('empresa_id', $eId)
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<', $hoy)
                ->count(),

            // Recepción período
            'recepciones_periodo' => Recepcion::where('empresa_id', $eId)
                ->whereBetween('created_at', [$ini, $fin])->count(),

            // Despacho período
            'despachos_periodo'  => Despacho::where('empresa_id', $eId)
                ->whereBetween('fecha_movimiento', [substr($ini, 0, 10), substr($fin, 0, 10)])->count(),
            'despachos_hoy'      => Despacho::where('empresa_id', $eId)
                ->where('fecha_movimiento', $hoy)->count(),

            // Picking
            'picking_pendiente'  => OrdenPicking::where('empresa_id', $eId)->where('estado', 'Pendiente')->count(),
            'picking_en_proceso' => OrdenPicking::where('empresa_id', $eId)->where('estado', 'EnProceso')->count(),

            // ODC
            'odc_abiertas'       => OrdenCompra::where('empresa_id', $eId)->whereIn('estado', ['Borrador', 'Confirmada'])->count(),

            // Devoluciones período
            'devoluciones_periodo' => Devolucion::where('empresa_id', $eId)
                ->whereBetween('created_at', [$ini, $fin])->count(),
        ];

        return $this->ok($res, [
            'error' => false,
            'data'  => $data
        ]);
    }

    // ── 10. AUDIT LOG — solo Admin ────────────────────────────────────────────
    public function auditLog(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $logs = Capsule::table('audit_logs')
            ->leftJoin('personal', 'audit_logs.usuario_id', '=', 'personal.id')
            ->where('audit_logs.empresa_id', $user->empresa_id)
            ->whereBetween('audit_logs.created_at', [$ini, $fin])
            ->when($params['modulo'] ?? null, fn($q, $m) => $q->where('modulo', $m))
            ->when($params['usuario_id'] ?? null, fn($q, $u) => $q->where('audit_logs.usuario_id', $u))
            ->select('audit_logs.*', 'personal.nombre as usuario_nombre')
            ->orderBy('audit_logs.created_at', 'desc')
            ->limit(500)
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Fecha', 'Usuario', 'Módulo', 'Acción', 'Tabla', 'Registro ID', 'Descripción', 'IP'];
            $rows = $logs->map(fn($l) => [
                $l->created_at, $l->usuario_nombre ?? '—', $l->modulo, $l->accion,
                $l->tabla_afectada ?? '—', $l->registro_id ?? '—',
                $l->descripcion ?? '—', $l->ip_address ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'audit_log_' . date('Y-m-d'));
        }

        return $this->ok($res, $logs);
    }

    // ── 11. REPORTE VECIMIENTOS ──────────────────────────────────────────────
    public function vencimientos(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $dias   = (int)($params['dias'] ?? 365); // Default a un año para ver más datos

        $query = Inventario::where('inventarios.empresa_id', $user->empresa_id)
            ->where('inventarios.sucursal_id', $user->sucursal_id)
            ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->whereNotNull('inventarios.fecha_vencimiento')
            ->where('inventarios.cantidad', '>', 0);

        if (!isset($params['todo'])) {
            $limite = \Carbon\Carbon::now()->addDays($dias)->format('Y-m-d');
            $query->where('inventarios.fecha_vencimiento', '<=', $limite);
        }

        $stock = $query->select(
                'productos.nombre as producto',
                'productos.nombre as producto_nombre', 
                'productos.codigo_interno as codigo',
                'ubicaciones.codigo as ubicacion',
                'ubicaciones.codigo as ubicacion_codigo', 
                'inventarios.lote',
                'inventarios.fecha_vencimiento',
                'inventarios.cantidad',
                'inventarios.estado'
            )
            ->orderBy('inventarios.fecha_vencimiento')
            ->get();

        // Calcular días por vencer en PHP para compatibilidad universal
        $stock = $stock->map(function($s) {
            $s->dias_vencer = null;
            if ($s->fecha_vencimiento) {
                $fechaVen = \Carbon\Carbon::parse($s->fecha_vencimiento)->startOfDay();
                $hoy = \Carbon\Carbon::now()->startOfDay();
                $s->dias_vencer = $hoy->diffInDays($fechaVen, false);
            }
            return $s;
        });


        // Calcular resumen para los cuadros de mando
        $resumen = [
            'vencido' => 0,
            'r0_30'   => 0,
            'r31_60'  => 0,
            'r61_90'  => 0,
            'r90_mas' => 0,
        ];

        foreach ($stock as $s) {
            $d = $s->dias_vencer;
            if ($d < 0) $resumen['vencido']++;
            elseif ($d <= 30) $resumen['r0_30']++;
            elseif ($d <= 60) $resumen['r31_60']++;
            elseif ($d <= 90) $resumen['r61_90']++;
            else $resumen['r90_mas']++;
        }

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Producto', 'Código', 'Ubicación', 'Lote', 'F.Vencimiento',
                        'Días p/Vencer', 'Cantidad', 'Estado'];
            $rows = $stock->map(fn($s) => [
                $s->producto, $s->codigo, $s->ubicacion,
                $s->lote ?? '—', $s->fecha_vencimiento,
                $s->dias_vencer, $s->cantidad, $s->estado,
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'vencimientos_' . date('Y-m-d'));
        }

        return $this->ok($res, [
            'detalle' => $stock,
            'resumen' => $resumen
        ]);
    }

    // ── DASHBOARD BI (ANALÍTICA EXPERTA GERENCIAL) ───────────────────────────
    public function dashboardBI(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $eId    = $user->empresa_id;
        $params = $r->getQueryParams();

        // Filtros globales recibidos desde el Frontend
        $mes       = $params['mes'] ?? date('m');
        $anio      = $params['anio'] ?? date('Y');
        $categoria = $params['categoria'] ?? '';
        $producto  = $params['producto'] ?? '';

        // 1. OBTENER ESTADÍSTICAS COMERCIALES (KARDEX/PICKING COMPLETADO)
        // Ventas Mes a Mes (Ventas = Picking Completado)
        $qVentas = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereYear('op.created_at', $anio);
        
        if ($categoria) {
            $qVentas->where('p.categoria_id', $categoria);
        }
        if ($producto) {
            $qVentas->where('p.id', $producto);
        }

        $ventasMesAMes = (clone $qVentas)
            ->select(
                Capsule::raw($this->isPg() ? "EXTRACT(MONTH FROM op.created_at) as mes" : "MONTH(op.created_at) as mes"),
                Capsule::raw("SUM(pd.cantidad_pickeada) as total_ventas")
            )
            ->groupBy('mes')
            ->get()
            ->keyBy('mes');

        
        $ventasArray = [];
        for ($i=1; $i<=12; $i++) {
            $ventasArray[] = (float)($ventasMesAMes->get($i)->total_ventas ?? 0);
        }

        // Total Picking por de la Categoría seleccionada
        $qCategorias = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->leftJoin('categoria_productos as cp', 'p.categoria_id', '=', 'cp.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereMonth('op.created_at', $mes)
            ->whereYear('op.created_at', $anio);

        if ($categoria) {
            $qCategorias->where('p.categoria_id', $categoria);
        }
        
        $picksPorCategoria = (clone $qCategorias)
            ->select('cp.nombre as categoria', Capsule::raw('SUM(pd.cantidad_pickeada) as total'))
            ->groupBy('cp.id', 'cp.nombre')
            ->orderBy('total', 'desc')
            ->get();

        // Calcular crecimiento (Mes actual vs Mes Anterior)
        $mesAnterior = $mes - 1;
        $anioAnterior = $anio;
        if ($mesAnterior == 0) { $mesAnterior = 12; $anioAnterior--; }

        $pickMesAnterior = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereMonth('op.created_at', $mesAnterior)
            ->whereYear('op.created_at', $anioAnterior);
        if ($categoria) $pickMesAnterior->where('p.categoria_id', $categoria);

        $totalAnterior = $pickMesAnterior->sum('pd.cantidad_pickeada');
        $totalActual   = $ventasMesAMes->get((int)$mes)->total_ventas ?? 0;
        $crecimiento = 0;
        if ($totalAnterior > 0) {
            $crecimiento = (($totalActual - $totalAnterior) / $totalAnterior) * 100;
        } else if ($totalActual > 0) {
            $crecimiento = 100;
        }

        // Baja Rotación
        $bajaRotacion = Capsule::table('inventarios as i')
            ->join('productos as p', 'i.producto_id', '=', 'p.id')
            ->leftJoin('categoria_productos as cp', 'p.categoria_id', '=', 'cp.id')
            ->where('i.empresa_id', $eId)
            ->where('i.cantidad', '>', 0)
            ->whereNotExists(function($query) {
                $query->select(Capsule::raw(1))
                      ->from('picking_detalles as pd2')
                      ->join('orden_pickings as op2', 'pd2.orden_picking_id', '=', 'op2.id')
                      ->whereColumn('pd2.producto_id', 'i.producto_id')
                      ->where('op2.estado', 'Completada')
                      ->where('op2.created_at', '>=', \Carbon\Carbon::now()->subDays(90)->toDateString());
            })

            ->select('p.codigo_interno', 'p.nombre as producto', 'cp.nombre as categoria', Capsule::raw('SUM(i.cantidad) as stock_inmovilizado'))
            ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'cp.nombre')
            ->orderBy('stock_inmovilizado', 'desc')
            ->limit(10)
            ->get();

        // 2. FORECASTING (PROYECCIÓN MOCK LOCAL)
        $forecastData = [];
        $meses = [];
        for ($i=1; $i<=12; $i++) {
            $real = $ventasMesAMes->get($i)->total_ventas ?? 0;
            if ($i <= $mes) {
                $forecastData[] = null;
                $meses[] = $real; 
            } else {
                $last3 = array_slice($ventasArray, max(0, $mes-3), 3);
                $avg = count($last3) > 0 ? array_sum($last3)/count($last3) : 0;
                $forecast = round($avg * mt_rand(90, 115) / 100);
                $forecastData[] = $forecast;
            }
        }

        $mlData = [
            'reales' => $ventasArray,
            'forecast' => $forecastData
        ];

        // Tendencia mensual de las top 5 categorías
        $qTopCategorias = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->leftJoin('categoria_productos as cp', 'p.categoria_id', '=', 'cp.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereYear('op.created_at', $anio);
            
        $topCategorias = (clone $qTopCategorias)
            ->select('cp.id', 'cp.nombre', Capsule::raw('SUM(pd.cantidad_pickeada) as total'))
            ->groupBy('cp.id', 'cp.nombre')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();
            
        $tendenciaMensualCat = [];
        foreach ($topCategorias as $cat) {
            $tendenciaCatQuery = (clone $qTopCategorias)
                ->where('cp.id', $cat->id)
                ->select(Capsule::raw('MONTH(op.created_at) as mes'), Capsule::raw('SUM(pd.cantidad_pickeada) as total'))
                ->groupBy(Capsule::raw('MONTH(op.created_at)'))
                ->get()
                ->keyBy('mes');
            
            $catData = [];
            for ($i = 1; $i <= 12; $i++) {
                $catData[] = (float)($tendenciaCatQuery->get($i)->total ?? 0);
            }
            
            $tendenciaMensualCat[] = [
                'categoria' => $cat->nombre ?? 'Sin Categoria',
                'data' => $catData
            ];
        }

        $listaCategorias = Capsule::table('categoria_productos')->where('empresa_id', $eId)->select('id', 'nombre')->get();
        $listaProductos = Capsule::table('productos')->where('empresa_id', $eId)->select('id', 'codigo_interno', 'nombre')->limit(500)->get();

        return $this->ok($res, [
            'metrics' => [
                'totalPicksMes'    => $totalActual,
                'crecimientoPct'   => round($crecimiento, 2),
                'bajaRotacionCount'=> $bajaRotacion->count(),
                'mesFiltro'        => $mes,
            ],
            'pickingPorCategoria' => $picksPorCategoria,
            'ventasMesAMes'       => $ventasArray,
            'tendenciaCat'        => $tendenciaMensualCat,
            'bajaRotacion'        => $bajaRotacion,
            'mlForecast'          => $mlData,
            'filtros' => [
                'categorias' => $listaCategorias,
                'productos'  => $listaProductos
            ]
        ]);
    }

    // ── 13. EVALUACIÓN DE PROVEEDORES ─────────────────────────────────────────
    public function evaluacionProveedores(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $eId = $user->empresa_id;
        $sId = $user->sucursal_id;

        // Proveedores con al menos una ODC en el período.
        // Recepciones se vinculan al proveedor a través de citas (rec.cita_id → cit → pv.razon_social)
        $proveedores = Capsule::table('proveedores as pv')
            ->leftJoin('ordenes_compra as odc', function ($j) use ($eId, $ini, $fin) {
                $j->on('pv.id', '=', 'odc.proveedor_id')
                  ->where('odc.empresa_id', $eId)
                  ->whereBetween('odc.created_at', [$ini, $fin]);
            })
            ->leftJoin('citas as cit', function ($j) use ($eId, $sId, $ini, $fin) {
                $j->on(Capsule::raw("LOWER(pv.razon_social)"), '=', Capsule::raw("LOWER(cit.proveedor)"))
                  ->where('cit.empresa_id', $eId)
                  ->where('cit.sucursal_id', $sId)
                  ->whereBetween('cit.fecha', [substr($ini, 0, 10), substr($fin, 0, 10)]);
            })
            ->leftJoin('recepciones as rec', function ($j) use ($eId, $ini, $fin) {
                $j->on('rec.cita_id', '=', 'cit.id')
                  ->where('rec.empresa_id', $eId)
                  ->whereBetween('rec.created_at', [$ini, $fin]);
            })
            ->where('pv.empresa_id', $eId)
            ->select(
                'pv.id',
                'pv.razon_social as proveedor',
                'pv.nit',
                Capsule::raw('COUNT(DISTINCT odc.id) as total_odc'),
                Capsule::raw("SUM(CASE WHEN odc.estado = 'Cerrada' THEN 1 ELSE 0 END) as odc_completadas"),
                Capsule::raw('COUNT(DISTINCT rec.id) as total_recepciones'),
                Capsule::raw("SUM(CASE WHEN rec.estado = 'Confirmada' THEN 1 ELSE 0 END) as rec_confirmadas"),
                Capsule::raw('COUNT(DISTINCT cit.id) as total_citas'),
                Capsule::raw("SUM(CASE WHEN cit.estado IN ('Completada','Confirmada') THEN 1 ELSE 0 END) as citas_cumplidas"),
                Capsule::raw("SUM(CASE WHEN cit.estado = 'Cancelada' THEN 1 ELSE 0 END) as citas_canceladas"),
                Capsule::raw($this->isPg() 
                    ? "AVG(EXTRACT(EPOCH FROM (cit.hora_inicio_descargue::timestamp - cit.hora_llegada::timestamp))/60) as avg_demora_atencion" 
                    : "AVG(TIMESTAMPDIFF(MINUTE, cit.hora_llegada, cit.hora_inicio_descargue)) as avg_demora_atencion"),
                Capsule::raw($this->isPg() 
                    ? "AVG(EXTRACT(EPOCH FROM (cit.hora_fin_descargue::timestamp - cit.hora_inicio_descargue::timestamp))/60) as avg_tiempo_operation" 
                    : "AVG(TIMESTAMPDIFF(MINUTE, cit.hora_inicio_descargue, cit.hora_fin_descargue)) as avg_tiempo_operation")
            )
            ->groupBy('pv.id', 'pv.razon_social', 'pv.nit')
            ->orderByRaw('total_odc DESC, total_recepciones DESC')
            ->get();

        // Novedades de recepción: detalles con estado distinto a BuenEstado, vinculados por cita → proveedor
        $novedades = Capsule::table('recepcion_detalles as rd')
            ->join('recepciones as rec', 'rd.recepcion_id', '=', 'rec.id')
            ->join('citas as cit', 'rec.cita_id', '=', 'cit.id')
            ->join('proveedores as pv2', Capsule::raw("LOWER(pv2.razon_social)"), '=', Capsule::raw("LOWER(cit.proveedor)"))
            ->where('rec.empresa_id', $eId)
            ->whereBetween('rec.created_at', [$ini, $fin])
            ->where('rd.estado_mercancia', '!=', 'BuenEstado')
            ->select('pv2.id as proveedor_id', Capsule::raw('COUNT(*) as novedades'))
            ->groupBy('pv2.id')
            ->get()
            ->keyBy('proveedor_id');

        // Enriquecer con novedades y calcular tasa de cumplimiento
        $result = $proveedores->map(function ($p) use ($novedades) {
            $nov = $novedades->get($p->id);
            $p->novedades_recepcion = $nov ? $nov->novedades : 0;
            $p->pct_cumplimiento_citas = $p->total_citas > 0
                ? round(($p->citas_cumplidas / $p->total_citas) * 100, 1) : null;
            $p->pct_cumplimiento_odc = $p->total_odc > 0
                ? round(($p->odc_completadas / $p->total_odc) * 100, 1) : null;
            $p->avg_demora_atencion = $p->avg_demora_atencion !== null ? round($p->avg_demora_atencion, 1) : null;
            $p->avg_tiempo_operacion = $p->avg_tiempo_operacion !== null ? round($p->avg_tiempo_operacion, 1) : null;
            return $p;
        });

        if (($params['export'] ?? '') === 'excel') {
            $headers = [
                'Proveedor', 'NIT', 'Total ODC', 'ODC Completadas', '% Cumpl. ODC',
                'Total Recepciones', 'Rec. Confirmadas', 'Total Citas', 'Citas Cumplidas',
                '% Cumpl. Citas', 'Citas Canceladas', 'Novedades Recepción', 'Demora Atención (min)', 'Tiempo Operación (min)'
            ];
            $rows = $result->map(fn($p) => [
                $p->proveedor, $p->nit ?? '—',
                $p->total_odc, $p->odc_completadas, $p->pct_cumplimiento_odc !== null ? $p->pct_cumplimiento_odc . '%' : '—',
                $p->total_recepciones, $p->rec_confirmadas,
                $p->total_citas, $p->citas_cumplidas,
                $p->pct_cumplimiento_citas !== null ? $p->pct_cumplimiento_citas . '%' : '—',
                $p->citas_canceladas, $p->novedades_recepcion,
                $p->avg_demora_atencion ?? '—',
                $p->avg_tiempo_operacion ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'evaluacion_proveedores_' . date('Y-m-d'));
        }

        return $this->ok($res, $result);
    }

    // ── 12. REPORTE AGOTADOS / BAJO MÍNIMO ───────────────────────────────────
    public function agotadosYBajoMinimo(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        // Productos con nivel de reposición configurado
        $niveles = Capsule::table('niveles_reposicion as nr')
            ->join('productos as p', 'nr.producto_id', '=', 'p.id')
            ->leftJoin(Capsule::raw(
                '(SELECT producto_id, SUM(cantidad) as total
                  FROM inventarios
                  WHERE empresa_id = ' . (int)$user->empresa_id . '
                    AND sucursal_id = ' . (int)$user->sucursal_id . "
                    AND estado = 'Disponible'
                  GROUP BY producto_id
                ) as inv"
            ), 'p.id', '=', 'inv.producto_id')
            ->where('nr.empresa_id', $user->empresa_id)
            ->where('nr.sucursal_id', $user->sucursal_id)
            ->where('nr.activo', true)
            ->select(
                'p.nombre as producto', 'p.codigo_interno as codigo',
                'nr.stock_minimo', 'nr.punto_reorden', 'nr.cantidad_reorden',
                Capsule::raw('COALESCE(inv.total, 0) as stock_actual'),
                Capsule::raw('CASE
                    WHEN COALESCE(inv.total, 0) = 0 THEN "Agotado"
                    WHEN COALESCE(inv.total, 0) <= nr.punto_reorden THEN "Punto Reorden"
                    WHEN COALESCE(inv.total, 0) < nr.stock_minimo THEN "Bajo Mínimo"
                    ELSE "OK"
                END as alerta')
            )
            ->havingRaw("alerta != 'OK'")
            ->orderByRaw("FIELD(alerta, 'Agotado', 'Punto Reorden', 'Bajo Mínimo')")
            ->get();

        $params = $r->getQueryParams();
        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Producto', 'Código', 'Stock Actual', 'Stock Mínimo',
                        'Punto Reorden', 'Cant. a Pedir', 'Alerta'];
            $rows = $niveles->map(fn($n) => [
                $n->producto, $n->codigo, $n->stock_actual, $n->stock_minimo,
                $n->punto_reorden, $n->cantidad_reorden, $n->alerta,
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'agotados_' . date('Y-m-d'));
        }

        return $this->ok($res, $niveles);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  REPORTES DE CONTINGENCIA — Operación sin Internet / Plan Manual
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/reportes/contingencia/separacion
     * ?fecha=YYYY-MM-DD  &formato=html|csv|json
     *
     * Planilla imprimible de órdenes de picking pendientes.
     */
    public function contingenciaSeparacion(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $fecha  = $params['fecha'] ?? date('Y-m-d');

        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnCurso'])
            ->whereDate('created_at', $fecha)
            ->with(['detalles.producto:id,nombre,codigo_interno', 'asignado:id,nombre'])
            ->orderByRaw("FIELD(prioridad,'Alta','Media','Normal','Baja') ASC")
            ->orderBy('id')
            ->get();

        $formato = $params['formato'] ?? 'json';

        if ($formato === 'html') {
            $html = $this->buildHtmlSeparacion($ordenes, $fecha, $user);
            $res->getBody()->write($html);
            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        if ($formato === 'csv') {
            $headers = ['Orden','Prioridad','Asignado','Producto','Código','Cant. Pedida','Cant. Alistada','Ubicación','Obs.'];
            $rows    = [];
            foreach ($ordenes as $o) {
                foreach ($o->detalles as $d) {
                    $rows[] = [
                        $o->numero_orden ?? $o->id, $o->prioridad ?? 'Normal',
                        $o->asignado->nombre ?? '-',
                        $d->producto->nombre ?? '-', $d->producto->codigo_interno ?? '-',
                        $d->cantidad_solicitada ?? 0, '', $d->ubicacion ?? '-', '',
                    ];
                }
            }
            return $this->exportCsv($res, $headers, $rows, 'separacion_' . $fecha);
        }

        return $this->ok($res, $ordenes);
    }

    /**
     * GET /api/reportes/contingencia/certificacion
     * ?fecha=YYYY-MM-DD  &planilla_id=X  &formato=html|csv|json
     *
     * Planilla imprimible de certificación con líneas de verificación.
     */
    public function contingenciaCertificacion(Request $r, Response $res): Response
    {
        $user    = $r->getAttribute('user');
        $params  = $r->getQueryParams();
        $formato = $params['formato'] ?? 'json';

        $query = DB::table('lineas_planilla as lp')
            ->leftJoin('archivos_planilla as ap', 'ap.id', '=', 'lp.archivo_id')
            ->where('ap.empresa_id', $user->empresa_id)
            ->where('ap.sucursal_id', $user->sucursal_id);

        if (!empty($params['planilla_id'])) {
            $query->where('lp.numero_planilla', $params['planilla_id']);
        } else {
            $query->whereDate('ap.created_at', $params['fecha'] ?? date('Y-m-d'));
        }

        $lineas = $query->select([
            'lp.numero_planilla', 'lp.cliente_nombre as cliente', 'lp.ruta', 'ap.created_at as fecha_despacho',
            'lp.producto_nombre as producto', 'lp.producto_codigo as codigo',
            'lp.cantidad as cantidad_planilla',
            DB::raw('COALESCE((SELECT SUM(cpd.cantidad_certificada) FROM cert_planilla_det cpd JOIN cert_planillas cp2 ON cp2.id = cpd.cert_id WHERE cp2.numero_planilla = lp.numero_planilla AND cp2.archivo_id = lp.archivo_id AND cpd.producto_codigo = lp.producto_codigo), 0) as cantidad_certificada')
        ])->orderBy('lp.numero_planilla')->orderBy('lp.producto_nombre')->get();

        // Enriquecer con diferencia
        $lineas = $lineas->map(function($l) {
            $l->cantidad_certificada = (float)$l->cantidad_certificada;
            $l->cantidad_planilla   = (float)$l->cantidad_planilla;
            $l->diferencia          = $l->cantidad_certificada - $l->cantidad_planilla;
            $l->observacion         = $l->diferencia != 0 ? 'Discrepancia detectada' : '';
            return $l;
        });

        if ($formato === 'html') {
            $html = $this->buildHtmlCertificacion($lineas, $user);
            $res->getBody()->write($html);
            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        if ($formato === 'csv') {
            $headers = ['Planilla','Cliente','Ruta','Fecha Desp.','Producto','Código',
                        'Cant. Planilla','Cant. Certificada','Diferencia','Observación'];
            $rows = $lineas->map(fn($l) => [
                $l->numero_planilla ?? $l->planilla_id, $l->cliente ?? '-', $l->ruta ?? '-',
                $l->fecha_despacho, $l->producto ?? '-', $l->codigo ?? '-',
                $l->cantidad_planilla ?? 0, '', $l->diferencia ?? 0, $l->observacion ?? '',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'certificacion_' . date('Y-m-d'));
        }

        return $this->ok($res, $lineas);
    }

    // ── HTML Builders ─────────────────────────────────────────────────────────

    private function buildHtmlSeparacion($ordenes, string $fecha, $user): string
    {
        $rows = '';
        foreach ($ordenes as $o) {
            $asig = htmlspecialchars($o->asignado->nombre ?? '___________');
            $bg   = $o->prioridad === 'Alta' ? '#fff3cd' : 'inherit';
            foreach ($o->detalles as $d) {
                $rows .= "<tr style=\"background:{$bg}\">"
                    . '<td>' . htmlspecialchars($o->numero_orden ?? $o->id) . '</td>'
                    . '<td>' . htmlspecialchars($o->prioridad ?? 'Normal') . '</td>'
                    . '<td>' . $asig . '</td>'
                    . '<td>' . htmlspecialchars($d->producto->nombre ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($d->producto->codigo_interno ?? '-') . '</td>'
                    . '<td style="text-align:center">' . ($d->cantidad_solicitada ?? 0) . '</td>'
                    . '<td style="text-align:center;border-bottom:1px solid #555;min-width:55px">&nbsp;</td>'
                    . '<td>' . htmlspecialchars($d->ubicacion ?? '-') . '</td>'
                    . '<td style="min-width:130px">&nbsp;</td>'
                    . '</tr>';
            }
        }
        $emp      = htmlspecialchars($user->empresa ?? 'ProOriente WMS');
        $total    = $ordenes->count();
        $impreso  = date('d/m/Y H:i:s');
        return <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Separación {$fecha}</title>
<style>
  body{font-family:Arial,sans-serif;font-size:11px;margin:18px}
  h2{margin:0;font-size:15px}
  .meta{display:flex;gap:36px;margin:6px 0 12px;font-size:11px}
  table{width:100%;border-collapse:collapse;font-size:10px}
  th{background:#1e3a5f;color:#fff;padding:5px 4px;text-align:left;font-size:10px}
  td{padding:4px 3px;border-bottom:1px solid #ddd;vertical-align:middle}
  .firma{margin-top:36px;display:flex;gap:70px}
  .firma-box{border-top:1px solid #333;width:190px;padding-top:4px;font-size:10px}
  @media print{button{display:none!important}}
</style></head><body>
<div style="border-bottom:2px solid #000;padding-bottom:6px">
  <h2>🏭 {$emp} — PLANILLA DE SEPARACIÓN / PICKING</h2>
  <div class="meta">
    <span><b>Fecha:</b> {$fecha}</span>
    <span><b>Impreso:</b> {$impreso}</span>
    <span><b>Órdenes:</b> {$total}</span>
  </div>
</div>
<button onclick="window.print()" style="margin:10px 0 14px;padding:6px 18px;background:#1e3a5f;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px">🖨 Imprimir Planilla</button>
<table>
  <thead><tr>
    <th>Orden</th><th>Prioridad</th><th>Operario</th><th>Producto</th><th>Código</th>
    <th>Cant. Pedida</th><th>Cant. Alistada ✓</th><th>Ubicación</th><th>Observación</th>
  </tr></thead>
  <tbody>{$rows}</tbody>
</table>
<div class="firma">
  <div class="firma-box">Jefe de Bodega</div>
  <div class="firma-box">Picker / Auxiliar</div>
  <div class="firma-box">Supervisor</div>
</div>
</body></html>
HTML;
    }

    private function buildHtmlCertificacion($lineas, $user): string
    {
        $rows    = '';
        $current = null;
        foreach ($lineas as $l) {
            $idPlanilla = $l->numero_planilla ?? '-';
            if ($current !== $idPlanilla) {
                $rows .= '<tr style="background:#f1f5f9;font-weight:bold">'
                    . '<td colspan="7" style="padding:10px;border-top:2px solid #1e3a5f">'
                    . '<b>Planilla # ' . htmlspecialchars($idPlanilla) . '</b>'
                    . ' | Cliente: ' . htmlspecialchars($l->cliente ?? '-')
                    . ' | Ruta: ' . htmlspecialchars($l->ruta ?? '-')
                    . ' | Despacho: ' . htmlspecialchars($l->fecha_despacho ?? '-')
                    . '</td></tr>';
                $current = $idPlanilla;
            }
            $dif = (float)($l->diferencia ?? 0);
            $difHtml = $dif != 0 
                ? '<b style="color:#dc2626">' . $dif . '</b>' 
                : '<span style="color:#64748b">0</span>';

            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($l->producto ?? '-') . '</td>'
                . '<td>' . htmlspecialchars($l->codigo ?? '-') . '</td>'
                . '<td style="text-align:center;font-weight:700">' . ($l->cantidad_planilla ?? 0) . '</td>'
                . '<td style="text-align:center;border-bottom:1px solid #555;min-width:60px">&nbsp;</td>'
                . '<td style="text-align:center">' . $difHtml . '</td>'
                . '<td style="min-width:120px">&nbsp;</td>'
                . '</tr>';
        }

        $emp = htmlspecialchars($user->empresa ?? 'ProOriente WMS');
        $fecha = date('d/m/Y');
        $hora_gen = date('H:i:s');
        
        return <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Certificación Contingencia</title>
<style>
  body{font-family:Segoe UI,Tahoma,Arial,sans-serif;font-size:11px;margin:20px;color:#1e293b}
  h2{margin:0;font-size:16px;color:#1e3a5f}
  .header{border-bottom:2px solid #1e3a5f;padding-bottom:10px;margin-bottom:15px}
  table{width:100%;border-collapse:collapse}
  th{background:#1e3a5f;color:#fff;padding:5px 4px;text-align:left;font-size:10px}
  td{padding:4px 3px;border-bottom:1px solid #eee;vertical-align:middle}
  .firma{margin-top:36px;display:flex;gap:70px}
  .firma-box{border-top:1px solid #333;width:190px;padding-top:4px;font-size:10px}
  @media print{button{display:none!important}}
</style></head><body>
<div class="header">
  <h2>🏭 {$emp} — CERTIFICACIÓN CONTINGENCIA</h2>
  <div style="display:flex;gap:36px;margin:6px 0 0;font-size:11px">
    <span><b>Fecha:</b> {$fecha}</span>
    <span><b>Generado:</b> {$hora_gen}</span>
  </div>
</div>
<button onclick="window.print()" style="margin:10px 0 14px;padding:6px 18px;background:#1e3a5f;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px">🖨 Imprimir</button>
<table>
  <thead><tr>
    <th>Producto</th><th>Código</th><th>Cant. Planilla</th><th>Cant. Real ✓</th><th>Diferencia</th><th>Observación</th>
  </tr></thead>
  <tbody>{$rows}</tbody>
</table>
<div class="firma">
  <div class="firma-box">Jefe de Bodega</div>
  <div class="firma-box">Auxiliar</div>
  <div class="firma-box">Supervisor</div>
</div>
</body></html>
HTML;
    }
}
