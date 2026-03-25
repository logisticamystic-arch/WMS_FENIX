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
use Illuminate\Database\Capsule\Manager as Capsule;

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
                'inventarios.estado',
                Capsule::raw('DATEDIFF(inventarios.fecha_vencimiento, CURDATE()) as dias_vencer')
            )
            ->when($params['estado'] ?? null, fn($q, $e) => $q->where('inventarios.estado', $e))
            ->when($params['solo_proximos_vencer'] ?? null, function ($q) {
                $q->whereNotNull('inventarios.fecha_vencimiento')
                  ->whereRaw('fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')
                  ->whereRaw('fecha_vencimiento >= CURDATE()');
            })
            ->when($params['solo_vencidos'] ?? null, function ($q) {
                $q->whereNotNull('inventarios.fecha_vencimiento')
                  ->whereRaw('fecha_vencimiento < CURDATE()');
            })
            ->orderBy('inventarios.fecha_vencimiento')
            ->get();

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
            ->with(['detalles.producto'])
            ->orderBy('recepciones.created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($recepciones as $rec) {
                foreach ($rec->detalles as $d) {
                    $rows[] = [
                        $rec->numero_recepcion,
                        $rec->proveedor ?? '—',
                        $rec->created_at,
                        $rec->estado,
                        $d->producto->nombre    ?? '—',
                        $d->producto->codigo_interno ?? '—',
                        $d->cantidad_recibida,
                        $d->lote ?? '—',
                        $d->fecha_vencimiento ?? '—',
                        $d->estado_mercancia,
                    ];
                }
            }
            $headers = ['# Recepción', 'Proveedor', 'Fecha', 'Estado',
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

        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->with('detalles.producto')
            ->orderBy('created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($ordenes as $o) {
                foreach ($o->detalles as $d) {
                    $rows[] = [
                        $o->numero_orden, $o->cliente ?? '—', $o->estado,
                        $o->fecha_movimiento, $o->prioridad,
                        $d->producto->nombre ?? '—',
                        $d->cantidad_solicitada, $d->cantidad_pickeada, $d->estado,
                        $d->lote ?? '—',
                    ];
                }
            }
            $headers = ['# Orden', 'Cliente', 'Estado Orden', 'Fecha', 'Prioridad',
                        'Producto', 'Solicitado', 'Pickeado', 'Estado Línea', 'Lote'];
            return $this->exportCsv($res, $headers, $rows, 'picking_' . date('Y-m-d'));
        }

        return $this->ok($res, $ordenes);
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

        $odc = OrdenCompra::where('empresa_id', $user->empresa_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->with(['proveedor', 'detalles.producto'])
            ->orderBy('created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $rows = [];
            foreach ($odc as $o) {
                foreach ($o->detalles as $d) {
                    $rows[] = [
                        $o->numero_odc,
                        $o->proveedor->razon_social ?? '—',
                        $o->fecha, $o->estado,
                        $d->producto->nombre ?? '—',
                        $d->producto->codigo_interno ?? '—',
                        $d->cantidad_solicitada,
                        $d->cantidad_recibida,
                        $d->cantidad_solicitada - $d->cantidad_recibida,
                    ];
                }
            }
            $headers = ['# ODC', 'Proveedor', 'Fecha', 'Estado',
                        'Producto', 'Código', 'Solicitado', 'Recibido', 'Pendiente'];
            return $this->exportCsv($res, $headers, $rows, 'odc_reporte_' . date('Y-m-d'));
        }

        return $this->ok($res, $odc);
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
                ->whereRaw('fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)')
                ->count(),
            'vencidos'          => Inventario::where('empresa_id', $eId)
                ->whereNotNull('fecha_vencimiento')
                ->whereRaw('fecha_vencimiento < CURDATE()')
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

        return $this->ok($res, $data);
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

    // ── 11. REPORTE VENCIMIENTOS ──────────────────────────────────────────────
    public function vencimientos(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $dias   = (int)($params['dias'] ?? 30);

        $stock = Inventario::where('inventarios.empresa_id', $user->empresa_id)
            ->where('inventarios.sucursal_id', $user->sucursal_id)
            ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->whereNotNull('inventarios.fecha_vencimiento')
            ->whereRaw("inventarios.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)")
            ->select(
                'productos.nombre as producto',
                'productos.codigo_interno as codigo',
                'ubicaciones.codigo as ubicacion',
                'inventarios.lote',
                'inventarios.fecha_vencimiento',
                'inventarios.cantidad',
                'inventarios.estado',
                Capsule::raw('DATEDIFF(inventarios.fecha_vencimiento, CURDATE()) as dias_vencer')
            )
            ->orderBy('inventarios.fecha_vencimiento')
            ->get();

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

        return $this->ok($res, $stock);
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
                Capsule::raw('SUM(CASE WHEN odc.estado = "Cerrada" THEN 1 ELSE 0 END) as odc_completadas'),
                Capsule::raw('COUNT(DISTINCT rec.id) as total_recepciones'),
                Capsule::raw('SUM(CASE WHEN rec.estado = "Confirmada" THEN 1 ELSE 0 END) as rec_confirmadas'),
                Capsule::raw('COUNT(DISTINCT cit.id) as total_citas'),
                Capsule::raw('SUM(CASE WHEN cit.estado IN ("Completada","Confirmada") THEN 1 ELSE 0 END) as citas_cumplidas'),
                Capsule::raw('SUM(CASE WHEN cit.estado = "Cancelada" THEN 1 ELSE 0 END) as citas_canceladas')
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
            return $p;
        });

        if (($params['export'] ?? '') === 'excel') {
            $headers = [
                'Proveedor', 'NIT', 'Total ODC', 'ODC Completadas', '% Cumpl. ODC',
                'Total Recepciones', 'Rec. Confirmadas', 'Total Citas', 'Citas Cumplidas',
                '% Cumpl. Citas', 'Citas Canceladas', 'Novedades Recepción'
            ];
            $rows = $result->map(fn($p) => [
                $p->proveedor, $p->nit ?? '—',
                $p->total_odc, $p->odc_completadas, $p->pct_cumplimiento_odc !== null ? $p->pct_cumplimiento_odc . '%' : '—',
                $p->total_recepciones, $p->rec_confirmadas,
                $p->total_citas, $p->citas_cumplidas,
                $p->pct_cumplimiento_citas !== null ? $p->pct_cumplimiento_citas . '%' : '—',
                $p->citas_canceladas, $p->novedades_recepcion,
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
}
