<?php

namespace App\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Módulo de Calidad (a pedido explícito, 2026-08-23): consolida — sin duplicar
 * datos, solo lectura — los registros que hoy dependen del departamento de
 * Calidad y que hasta ahora vivían repartidos en otros módulos:
 *   1. Auditoría de calidad en Recepción (recepcion_calidad + recepcion_detalle_calidad).
 *   2. Registro Preoperacional de Vehículos (preoperacionales + preoperacional_items).
 * Acceso controlado por los permisos 'calidad.ver' (tablero/matriz) y
 * 'calidad.inspeccionar' (abrir el detalle completo de un registro).
 */
class CalidadController extends BaseController
{
    // ── GET /api/calidad/dashboard ───────────────────────────────────────────
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requirePermiso($user, $r, 'calidad', 'ver', $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucId     = $user->sucursal_id;
        $params    = $r->getQueryParams();
        $fIni      = $params['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fFin      = $params['fecha_fin']    ?? date('Y-m-d');

        // ── Recepción: recepcion_calidad (calidad de transporte) ────────────
        $recepQ = Capsule::table('recepcion_calidad as rc')
            ->join('recepciones as r', 'r.id', '=', 'rc.recepcion_id')
            ->where('r.empresa_id', $empresaId)
            ->where('r.sucursal_id', $sucId)
            ->whereBetween(Capsule::raw('rc.created_at::date'), [$fIni, $fFin]);

        // BUG EVITADO 2026-08-23 (validado contra datos reales antes de publicar
        // el módulo): rc.conforme mezcla 3 estados distintos, no 2 — 'Conforme' y
        // 'Inconforme' son auditorías manuales reales (ver mobile/index.html
        // _setConformeToggle), pero el valor '1' es un registro AUTOMÁTICO que
        // el sistema crea en silencio cuando la recepción se cierra SIN que el
        // auxiliar haga la auditoría manual (RecepcionController.php:1231,
        // recepcion.js:4552) — no significa "no conforme", significa "sin
        // auditoría". Contarlo como no-conforme mostraría una alarma falsa a
        // Calidad (en producción, 10 de 11 registros eran de este tipo, no
        // fallas reales). Se reporta aparte.
        $totalRecepcionCalidad = (clone $recepQ)->count();
        $recepConformes    = (clone $recepQ)->where('rc.conforme', 'Conforme')->count();
        $recepNoConformes  = (clone $recepQ)->where('rc.conforme', 'Inconforme')->count();
        $recepSinAuditoria = $totalRecepcionCalidad - $recepConformes - $recepNoConformes;

        // Detalle por producto (olor/color/textura/temperatura/empaque/rotulado)
        $detalleQ = Capsule::table('recepcion_detalle_calidad as rdc')
            ->join('recepcion_detalles as rd', 'rd.id', '=', 'rdc.recepcion_detalle_id')
            ->join('recepciones as r', 'r.id', '=', 'rd.recepcion_id')
            ->where('r.empresa_id', $empresaId)
            ->where('r.sucursal_id', $sucId)
            ->whereBetween(Capsule::raw('rdc.created_at::date'), [$fIni, $fFin]);
        $totalLineasDetalle = (clone $detalleQ)->count();
        $lineasConNc = (clone $detalleQ)->where(function ($q) {
            $q->where('olor', 'NC')->orWhere('color', 'NC')->orWhere('textura', 'NC')
              ->orWhere('temperatura', 'NC')->orWhere('empaque', 'NC')->orWhere('rotulado', 'NC');
        })->count();

        // ── Preoperacional ───────────────────────────────────────────────────
        $preopQ = Capsule::table('preoperacionales')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucId)
            ->whereBetween('fecha', [$fIni, $fFin]);
        $totalPreop = (clone $preopQ)->count();
        $preopConNc = (clone $preopQ)->whereExists(function ($q) {
            $q->select(Capsule::raw(1))->from('preoperacional_items as pi')
              ->whereColumn('pi.preoperacional_id', 'preoperacionales.id')
              ->where('pi.calificacion', 'NC');
        })->count();

        // Serie diaria combinada (para gráfico de tendencia) — mismo criterio de
        // 3 estados que arriba, "no_conformes" aquí es SOLO Inconforme real.
        $serieRecep = Capsule::table('recepcion_calidad as rc')
            ->join('recepciones as r', 'r.id', '=', 'rc.recepcion_id')
            ->where('r.empresa_id', $empresaId)->where('r.sucursal_id', $sucId)
            ->whereBetween(Capsule::raw('rc.created_at::date'), [$fIni, $fFin])
            ->selectRaw("rc.created_at::date as dia, count(*) as total, count(*) filter (where rc.conforme = 'Inconforme') as no_conformes")
            ->groupBy(Capsule::raw('rc.created_at::date'))
            ->get()->keyBy('dia');

        $seriePreop = Capsule::table('preoperacionales')
            ->where('empresa_id', $empresaId)->where('sucursal_id', $sucId)
            ->whereBetween('fecha', [$fIni, $fFin])
            ->selectRaw('fecha as dia, count(*) as total')
            ->groupBy('fecha')
            ->get()->keyBy('dia');

        return $this->ok($res, [
            'rango' => ['inicio' => $fIni, 'fin' => $fFin],
            'recepcion' => [
                'total_registros'   => $totalRecepcionCalidad,
                'conformes'         => $recepConformes,
                'no_conformes'      => $recepNoConformes,
                'sin_auditoria'     => $recepSinAuditoria,
                // % conforme calculado solo sobre lo REALMENTE auditado — sin_auditoria
                // no cuenta ni a favor ni en contra, no es una calificación real.
                'pct_conforme'      => ($recepConformes + $recepNoConformes) > 0
                    ? round(100 * $recepConformes / ($recepConformes + $recepNoConformes), 1) : null,
                'lineas_producto'   => $totalLineasDetalle,
                'lineas_con_nc'     => $lineasConNc,
            ],
            'preoperacional' => [
                'total_registros' => $totalPreop,
                'con_nc'          => $preopConNc,
                'sin_nc'          => $totalPreop - $preopConNc,
                'pct_sin_nc'      => $totalPreop > 0 ? round(100 * ($totalPreop - $preopConNc) / $totalPreop, 1) : null,
            ],
            'serie_recepcion'      => $serieRecep,
            'serie_preoperacional' => $seriePreop,
        ]);
    }

    // ── GET /api/calidad/matriz ───────────────────────────────────────────────
    // Vista unificada de los dos tipos de registro, para el listado/matriz.
    public function matriz(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requirePermiso($user, $r, 'calidad', 'ver', $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucId     = $user->sucursal_id;
        $params    = $r->getQueryParams();
        $fIni      = $params['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fFin      = $params['fecha_fin']    ?? date('Y-m-d');
        $tipo      = $params['tipo'] ?? ''; // 'recepcion' | 'preoperacional' | '' (ambos)
        $soloNc     = ($params['solo_nc'] ?? '') === '1';

        $filas = [];

        if ($tipo === '' || $tipo === 'recepcion') {
            $recep = Capsule::table('recepcion_calidad as rc')
                ->join('recepciones as r', 'r.id', '=', 'rc.recepcion_id')
                ->where('r.empresa_id', $empresaId)
                ->where('r.sucursal_id', $sucId)
                ->whereBetween(Capsule::raw('rc.created_at::date'), [$fIni, $fFin])
                ->when($soloNc, fn($q) => $q->where('rc.conforme', 'Inconforme'))
                ->select(
                    'rc.id', 'rc.created_at as fecha', 'r.numero_recepcion', 'rc.trans_placa',
                    'rc.conforme', 'rc.factura'
                )
                ->orderBy('rc.created_at', 'desc')
                ->get();

            foreach ($recep as $row) {
                if ($row->conforme === 'Conforme') {
                    $resultado = 'Conforme';
                    $esConforme = true;
                } elseif ($row->conforme === 'Inconforme') {
                    $resultado = 'Inconforme';
                    $esConforme = false;
                } else {
                    $resultado = 'Sin auditoría';
                    $esConforme = null;
                }
                $filas[] = [
                    'tipo'        => 'recepcion',
                    'tipo_label'  => 'Recepción',
                    'id'          => $row->id,
                    'fecha'       => $row->fecha,
                    'referencia'  => $row->numero_recepcion,
                    'detalle'     => $row->trans_placa ? "Placa {$row->trans_placa}" : ($row->factura ?: ''),
                    'resultado'   => $resultado,
                    'es_conforme' => $esConforme,
                ];
            }
        }

        if ($tipo === '' || $tipo === 'preoperacional') {
            $preop = Capsule::table('preoperacionales as p')
                ->where('p.empresa_id', $empresaId)
                ->where('p.sucursal_id', $sucId)
                ->whereBetween('p.fecha', [$fIni, $fFin])
                ->select('p.id', 'p.fecha', 'p.vehiculo', 'p.conductor')
                ->selectRaw('(SELECT count(*) FROM preoperacional_items pi WHERE pi.preoperacional_id = p.id AND pi.calificacion = \'NC\') as nc_count')
                ->orderBy('p.fecha', 'desc')
                ->get()
                ->when($soloNc, fn($c) => $c->filter(fn($row) => $row->nc_count > 0));

            foreach ($preop as $row) {
                $filas[] = [
                    'tipo'        => 'preoperacional',
                    'tipo_label'  => 'Preoperacional',
                    'id'          => $row->id,
                    'fecha'       => $row->fecha,
                    'referencia'  => $row->vehiculo,
                    'detalle'     => $row->conductor,
                    'resultado'   => $row->nc_count > 0 ? "{$row->nc_count} No Cumple" : 'Sin novedades',
                    'es_conforme' => $row->nc_count == 0,
                ];
            }
        }

        usort($filas, fn($a, $b) => strcmp((string)$b['fecha'], (string)$a['fecha']));

        return $this->ok($res, array_values($filas));
    }

    // ── GET /api/calidad/recepcion/{id} ──────────────────────────────────────
    // Detalle completo de un registro de calidad de recepción — exige
    // 'calidad.inspeccionar' (ver e inspeccionar en detalle, no solo la matriz).
    public function verRecepcion(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requirePermiso($user, $r, 'calidad', 'inspeccionar', $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $rc = Capsule::table('recepcion_calidad as rc')
            ->join('recepciones as r', 'r.id', '=', 'rc.recepcion_id')
            ->where('r.empresa_id', $empresaId)
            ->where('r.sucursal_id', $user->sucursal_id)
            ->where('rc.id', (int)$a['id'])
            ->select('rc.*', 'r.numero_recepcion', 'r.fecha_movimiento')
            ->first();

        if (!$rc) return $this->notFound($res);

        $detalles = Capsule::table('recepcion_detalle_calidad as rdc')
            ->join('recepcion_detalles as rd', 'rd.id', '=', 'rdc.recepcion_detalle_id')
            ->leftJoin('productos as p', 'p.id', '=', 'rd.producto_id')
            ->where('rd.recepcion_id', $rc->recepcion_id)
            ->select('rdc.*', 'p.nombre as producto_nombre', 'p.codigo_interno as producto_codigo')
            ->get();

        return $this->ok($res, ['encabezado' => $rc, 'detalles' => $detalles]);
    }
}
