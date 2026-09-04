<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Illuminate\Database\Capsule\Manager as Capsule;

class TrazabilidadController extends BaseController
{
    // ── GET /api/trazabilidad/producto/{id} ──────────────────────────────────
    public function porProducto(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $productoId = (int)$a['id'];
        $params     = $r->getQueryParams();
        $fIni       = $params['f_ini'] ?? date('Y-m-d', strtotime('-90 days'));
        $fFin       = $params['f_fin'] ?? date('Y-m-d');
        $lote       = $params['lote'] ?? '';

        $producto = Capsule::table('productos')
            ->where('empresa_id', $empresaId)
            ->where('id', $productoId)
            ->select('id', 'codigo_interno', 'nombre', 'unidades_caja')
            ->first();
        if (!$producto) return $this->notFound($res);

        $query = Capsule::table('movimiento_inventarios as mi')
            ->leftJoin('ubicaciones as uo', 'uo.id', '=', 'mi.ubicacion_origen_id')
            ->leftJoin('ubicaciones as ud', 'ud.id', '=', 'mi.ubicacion_destino_id')
            ->leftJoin('personal as p', 'p.id', '=', 'mi.auxiliar_id')
            ->where('mi.empresa_id', $empresaId)
            ->where('mi.sucursal_id', $sucursalId)
            ->where('mi.producto_id', $productoId)
            ->whereBetween('mi.fecha_movimiento', [$fIni, $fFin])
            ->select(
                'mi.id', 'mi.tipo_movimiento', 'mi.cantidad', 'mi.cantidad_cajas',
                'mi.lote', 'mi.fecha_vencimiento', 'mi.fecha_movimiento',
                'mi.hora_inicio', 'mi.hora_fin', 'mi.observaciones',
                'mi.referencia_tipo', 'mi.referencia_id',
                'mi.ubicacion_origen_id', 'mi.ubicacion_destino_id',
                'uo.codigo as ubicacion_origen', 'uo.zona as ubicacion_origen_zona',
                'ud.codigo as ubicacion_destino', 'ud.zona as ubicacion_destino_zona',
                'p.nombre as responsable', 'p.documento as responsable_doc'
            )
            ->orderBy('mi.fecha_movimiento', 'desc')
            ->orderBy('mi.id', 'desc');

        if ($lote) $query->where('mi.lote', $lote);

        $movimientos = $query->get();

        $this->_enrichMovimientos($movimientos, $empresaId, $productoId, $fIni, $fFin);

        $stockActual = Capsule::table('inventarios as i')
            ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
            ->where('i.empresa_id', $empresaId)
            ->where('i.sucursal_id', $sucursalId)
            ->where('i.producto_id', $productoId)
            ->where('i.cantidad', '>', 0)
            ->select(
                'u.codigo as ubicacion', 'u.zona as ubicacion_zona',
                'i.lote', 'i.fecha_vencimiento', 'i.cantidad', 'i.cantidad_reservada',
                'i.estado', 'i.numero_pallet'
            )
            ->orderBy('u.codigo')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $upc = max(1, (int)$producto->unidades_caja);
            $headers = ['Fecha', 'Tipo Movimiento', 'Cajas', 'Saldos', 'UND/TOTAL', 'Lote', 'F.Vencimiento', 'Ubic. Origen', 'Ubic. Destino', 'Responsable', 'Documento / Punto Despacho', 'Observaciones'];
            $rows = $movimientos->map(function ($m) use ($upc) {
                $cajas  = $m->cantidad_cajas ?? intdiv((int)$m->cantidad, $upc);
                $saldos = (int)$m->cantidad - ($cajas * $upc);
                $docStr = '—';
                if ($m->documento) {
                    if (($m->documento['tipo'] ?? '') === 'Picking') {
                        $pDest = $m->documento['punto_despacho'] ?? $m->documento['cliente'] ?? '';
                        $pNum  = $m->documento['numero'] ?? 'Picking';
                        $docStr = "Picking: {$pNum} → {$pDest}";
                        if (!empty($m->documento['planilla'])) $docStr .= " ({$m->documento['planilla']})";
                    } elseif (!empty($m->documento['numero'])) {
                        $docStr = ($m->documento['tipo'] ?? '') . ' ' . $m->documento['numero'];
                        if (!empty($m->documento['cliente'])) $docStr .= ' — ' . $m->documento['cliente'];
                    }
                }
                return [
                    $m->fecha_movimiento, $m->tipo_movimiento, $cajas, $saldos, $m->cantidad,
                    $m->lote ?? '—', $m->fecha_vencimiento ?? '—',
                    $m->ubicacion_origen ?? '—', $m->ubicacion_destino ?? '—',
                    $m->responsable ?? '—', $docStr, $m->observaciones ?? '',
                ];
            })->toArray();
            return $this->exportCsv($res, $headers, $rows, 'trazabilidad_' . $producto->codigo_interno . '_' . date('Y-m-d'));
        }

        return $this->ok($res, [
            'producto'     => $producto,
            'movimientos'  => $movimientos,
            'stock_actual' => $stockActual,
            'filtros'      => compact('fIni', 'fFin', 'lote'),
        ]);
    }

    // ── GET /api/trazabilidad/ubicacion/{id} ─────────────────────────────────
    public function porUbicacion(Request $r, Response $res, array $a): Response
    {
        $user        = $r->getAttribute('user');
        $empresaId   = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId  = $this->getEffectiveSucursalId($user, $r);
        $ubicacionId = (int)$a['id'];
        $params      = $r->getQueryParams();
        $fIni        = $params['f_ini'] ?? date('Y-m-d', strtotime('-90 days'));
        $fFin        = $params['f_fin'] ?? date('Y-m-d');
        $productoId  = !empty($params['producto_id']) ? (int)$params['producto_id'] : null;

        $ubicacion = Capsule::table('ubicaciones')
            ->where('empresa_id', $empresaId)
            ->where('id', $ubicacionId)
            ->select('id', 'codigo', 'zona', 'tipo_ubicacion as tipo')
            ->first();
        if (!$ubicacion) return $this->notFound($res);

        $movimientos = Capsule::table('movimiento_inventarios as mi')
            ->leftJoin('productos as pr', 'pr.id', '=', 'mi.producto_id')
            ->leftJoin('personal as p', 'p.id', '=', 'mi.auxiliar_id')
            ->leftJoin('ubicaciones as uo', 'uo.id', '=', 'mi.ubicacion_origen_id')
            ->leftJoin('ubicaciones as ud', 'ud.id', '=', 'mi.ubicacion_destino_id')
            ->where('mi.empresa_id', $empresaId)
            ->where('mi.sucursal_id', $sucursalId)
            ->where(function ($q) use ($ubicacionId) {
                $q->where('mi.ubicacion_origen_id', $ubicacionId)
                  ->orWhere('mi.ubicacion_destino_id', $ubicacionId);
            })
            ->when($productoId, fn($q) => $q->where('mi.producto_id', $productoId))
            ->whereBetween('mi.fecha_movimiento', [$fIni, $fFin])
            ->select(
                'mi.id', 'mi.tipo_movimiento', 'mi.cantidad', 'mi.cantidad_cajas',
                'mi.lote', 'mi.fecha_vencimiento', 'mi.fecha_movimiento',
                'mi.hora_inicio', 'mi.hora_fin', 'mi.observaciones',
                'mi.referencia_tipo', 'mi.referencia_id',
                'mi.ubicacion_origen_id', 'mi.ubicacion_destino_id',
                'uo.codigo as ubicacion_origen', 'ud.codigo as ubicacion_destino',
                'pr.id as producto_id', 'pr.codigo_interno as producto_codigo',
                'pr.nombre as producto_nombre', 'pr.unidades_caja',
                'p.nombre as responsable', 'p.documento as responsable_doc'
            )
            ->orderBy('mi.fecha_movimiento', 'desc')
            ->orderBy('mi.id', 'desc')
            ->limit(500)
            ->get();

        $this->_enrichMovimientos($movimientos, $empresaId, null, $fIni, $fFin);

        $inventarioActual = Capsule::table('inventarios as i')
            ->join('productos as pr', 'pr.id', '=', 'i.producto_id')
            ->where('i.empresa_id', $empresaId)
            ->where('i.sucursal_id', $sucursalId)
            ->where('i.ubicacion_id', $ubicacionId)
            ->where('i.cantidad', '>', 0)
            ->when($productoId, fn($q) => $q->where('i.producto_id', $productoId))
            ->select(
                'pr.codigo_interno', 'pr.nombre as producto_nombre', 'pr.unidades_caja',
                'i.lote', 'i.fecha_vencimiento', 'i.cantidad', 'i.cantidad_reservada',
                'i.estado', 'i.numero_pallet'
            )
            ->orderBy('pr.nombre')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Fecha', 'Producto', 'Código', 'Tipo Movimiento', 'Cajas', 'Saldos', 'UND/TOTAL', 'Lote', 'F.Vencimiento', 'Ubic. Origen', 'Ubic. Destino', 'Responsable', 'Documento / Punto Despacho'];
            $rows = $movimientos->map(function ($m) {
                $upc    = max(1, (int)$m->unidades_caja);
                $cajas  = $m->cantidad_cajas ?? intdiv((int)$m->cantidad, $upc);
                $saldos = (int)$m->cantidad - ($cajas * $upc);
                $docStr = '—';
                if ($m->documento) {
                    if (($m->documento['tipo'] ?? '') === 'Picking') {
                        $pDest = $m->documento['punto_despacho'] ?? $m->documento['cliente'] ?? '';
                        $pNum  = $m->documento['numero'] ?? 'Picking';
                        $docStr = "Picking: {$pNum} → {$pDest}";
                        if (!empty($m->documento['planilla'])) $docStr .= " ({$m->documento['planilla']})";
                    } elseif (!empty($m->documento['numero'])) {
                        $docStr = ($m->documento['tipo'] ?? '') . ' ' . $m->documento['numero'];
                        if (!empty($m->documento['cliente'])) $docStr .= ' — ' . $m->documento['cliente'];
                    }
                }
                return [
                    $m->fecha_movimiento, $m->producto_nombre ?? '—', $m->producto_codigo ?? '—',
                    $m->tipo_movimiento, $cajas, $saldos, $m->cantidad,
                    $m->lote ?? '—', $m->fecha_vencimiento ?? '—',
                    $m->ubicacion_origen ?? '—', $m->ubicacion_destino ?? '—',
                    $m->responsable ?? '—', $docStr,
                ];
            })->toArray();
            return $this->exportCsv($res, $headers, $rows, 'trazabilidad_' . $ubicacion->codigo . '_' . date('Y-m-d'));
        }

        return $this->ok($res, [
            'ubicacion'         => $ubicacion,
            'movimientos'       => $movimientos,
            'inventario_actual' => $inventarioActual,
            'filtros'           => compact('fIni', 'fFin'),
        ]);
    }

    // ── GET /api/trazabilidad/buscar-producto?q= ────────────────────────────
    public function buscarProducto(Request $r, Response $res): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $q         = trim($r->getQueryParams()['q'] ?? '');
        if (strlen($q) < 2) return $this->ok($res, []);

        $like = $this->isPg() ? 'ILIKE' : 'LIKE';
        $term = '%' . $q . '%';

        $productos = Capsule::table('productos')
            ->where('empresa_id', $empresaId)
            ->where(function ($query) use ($like, $term) {
                $query->where('nombre', $like, $term)
                      ->orWhere('codigo_interno', $like, $term);
            })
            ->select('id', 'codigo_interno', 'nombre', 'unidades_caja')
            ->orderBy('nombre')
            ->limit(20)
            ->get();

        return $this->ok($res, $productos);
    }

    // ── GET /api/trazabilidad/buscar-ubicacion?q= ───────────────────────────
    public function buscarUbicacion(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $q          = trim($r->getQueryParams()['q'] ?? '');
        if (strlen($q) < 1) return $this->ok($res, []);

        $like = $this->isPg() ? 'ILIKE' : 'LIKE';
        $term = '%' . $q . '%';

        $ubicaciones = Capsule::table('ubicaciones')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where(function ($query) use ($like, $term) {
                $query->where('codigo', $like, $term)
                      ->orWhere('zona', $like, $term);
            })
            ->select('id', 'codigo', 'tipo_ubicacion as tipo', 'zona')
            ->orderBy('codigo')
            ->limit(20)
            ->get();

        return $this->ok($res, $ubicaciones);
    }

    // ── Enriquecer movimientos con datos de documentos (batch, sin N+1) ──────
    private function _enrichMovimientos($movimientos, int $empresaId, ?int $productoId, string $fIni, string $fFin): void
    {
        if ($movimientos->isEmpty()) return;

        $despachoIds     = [];
        $devolucionIds   = [];
        $recepcionIds    = [];
        $ordenPickingIds = [];

        foreach ($movimientos as $m) {
            $rt      = trim((string)($m->referencia_tipo ?? ''));
            $ri      = (int)($m->referencia_id ?? 0);
            $rtLower = strtolower($rt);

            if (in_array($rtLower, ['despachos', 'despacho']) && $ri) {
                $despachoIds[] = $ri;
            } elseif ($rtLower === 'devolucion' && $ri) {
                $devolucionIds[] = $ri;
            } elseif (in_array($rt, ['ODC', 'SinODC']) && $ri) {
                $recepcionIds[] = $ri;
            } elseif (in_array($rtLower, ['ordenpicking', 'orden_picking', 'picking']) && $ri) {
                $ordenPickingIds[] = $ri;
            }
        }

        $ordenesPicking = [];
        if (!empty($ordenPickingIds)) {
            $ops = Capsule::table('orden_pickings')
                ->whereIn('id', array_unique($ordenPickingIds))
                ->select('id', 'numero_orden', 'cliente', 'sucursal_entrega', 'planilla_numero', 'ruta', 'despacho_id', 'despachado_directo')
                ->get();

            foreach ($ops as $op) {
                $ordenesPicking[$op->id] = $op;
                if ($op->despacho_id) {
                    $despachoIds[] = $op->despacho_id;
                }
            }
        }

        $despachos = [];
        if (!empty($despachoIds)) {
            foreach (Capsule::table('despachos')->whereIn('id', array_unique($despachoIds))
                ->select('id', 'numero_despacho', 'cliente', 'ruta')->get() as $d) {
                $despachos[$d->id] = $d;
            }
        }

        $devoluciones = [];
        if (!empty($devolucionIds)) {
            foreach (Capsule::table('devoluciones')->whereIn('id', array_unique($devolucionIds))
                ->select('id', 'numero_devolucion', 'tipo', 'motivo_general')->get() as $d) {
                $devoluciones[$d->id] = $d;
            }
        }

        $recepciones = [];
        if (!empty($recepcionIds)) {
            foreach (Capsule::table('recepciones')->whereIn('id', array_unique($recepcionIds))
                ->select('id', 'numero_recepcion', 'odc_id', 'estado')->get() as $rec) {
                $recepciones[$rec->id] = $rec;
            }
        }

        // Picking sin referencia_tipo: aproximar por producto + fecha
        $pickingMap = [];
        if ($productoId) {
            $rows = Capsule::table('picking_detalles as pd')
                ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
                ->where('op.empresa_id', $empresaId)
                ->where('pd.producto_id', $productoId)
                ->whereBetween('op.fecha_movimiento', [$fIni, $fFin])
                ->select(
                    'op.numero_orden', 'op.numero_factura', 'op.cliente',
                    'op.sucursal_entrega', 'op.planilla_numero', 'op.fecha_movimiento',
                    'pd.cantidad_pickeada', 'pd.estado as linea_estado'
                )
                ->orderBy('op.fecha_movimiento', 'desc')
                ->limit(100)
                ->get();
            foreach ($rows as $row) {
                $pickingMap[$row->fecha_movimiento][] = $row;
            }
        }

        foreach ($movimientos as $m) {
            $rt      = trim((string)($m->referencia_tipo ?? ''));
            $ri      = (int)($m->referencia_id ?? 0);
            $rtLower = strtolower($rt);
            $doc     = null;

            if (in_array($rtLower, ['despachos', 'despacho']) && isset($despachos[$ri])) {
                $d   = $despachos[$ri];
                $doc = ['tipo' => 'Despacho', 'numero' => $d->numero_despacho,
                        'cliente' => $d->cliente, 'ruta' => $d->ruta];
            } elseif ($rtLower === 'devolucion' && isset($devoluciones[$ri])) {
                $d   = $devoluciones[$ri];
                $doc = ['tipo' => 'Devolución', 'numero' => $d->numero_devolucion,
                        'subtipo' => $d->tipo, 'motivo' => $d->motivo_general];
            } elseif (in_array($rt, ['ODC', 'SinODC']) && isset($recepciones[$ri])) {
                $rec = $recepciones[$ri];
                $doc = ['tipo' => 'Recepción', 'numero' => $rec->numero_recepcion,
                        'odc_id' => $rec->odc_id, 'estado' => $rec->estado];
            } elseif (in_array($rtLower, ['ordenpicking', 'orden_picking', 'picking']) && isset($ordenesPicking[$ri])) {
                $op = $ordenesPicking[$ri];
                $d  = ($op->despacho_id && isset($despachos[$op->despacho_id])) ? $despachos[$op->despacho_id] : null;
                $doc = [
                    'tipo'               => 'Picking',
                    'numero'             => $op->numero_orden ?: ('Orden #' . $op->id),
                    'cliente'            => $op->cliente ?: '—',
                    'punto_despacho'     => $op->sucursal_entrega ?: $op->cliente ?: '—',
                    'planilla'           => $op->planilla_numero ?: null,
                    'ruta'               => $op->ruta ?: ($d ? $d->ruta : null),
                    'despacho_numero'    => $d ? $d->numero_despacho : null,
                    'despachado_directo' => (bool)$op->despachado_directo,
                ];
            } elseif (($m->tipo_movimiento === 'Picking' || in_array($rtLower, ['ordenpicking', 'orden_picking', 'picking'])) && isset($pickingMap[$m->fecha_movimiento])) {
                $doc = ['tipo' => 'Picking', 'pedidos' => array_slice($pickingMap[$m->fecha_movimiento], 0, 5)];
            }

            $m->documento = $doc;
        }
    }
}
