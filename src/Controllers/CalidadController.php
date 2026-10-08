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

        // ── Recepción ─────────────────────────────────────────────────────────

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
        // 2026-10-07: mismo criterio que la matriz — todas las recepciones del rango,
        // calificadas por la inspección obligatoria por referencia (ver
        // recepcionesConCalidad). Antes solo contaba cabeceras recepcion_calidad.
        $recCal = $this->recepcionesConCalidad($empresaId, $sucId, $fIni, $fFin);
        $totalRecepcionCalidad = count($recCal);
        $recepConformes    = count(array_filter($recCal, fn($x) => $x['es_conforme'] === true));
        $recepNoConformes  = count(array_filter($recCal, fn($x) => $x['es_conforme'] === false));
        $recepPendientes   = count(array_filter($recCal, fn($x) => $x['pendiente']));
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
        $serieRecep = [];
        foreach ($recCal as $x) {
            $dia = substr((string)$x['fecha'], 0, 10);
            $serieRecep[$dia] = $serieRecep[$dia] ?? (object)['dia' => $dia, 'total' => 0, 'no_conformes' => 0];
            $serieRecep[$dia]->total++;
            if ($x['es_conforme'] === false) $serieRecep[$dia]->no_conformes++;
        }

        // Preoperacionales por día: realizados y NO conformes (con al menos un ítem NC).
        $seriePreop = Capsule::table('preoperacionales')
            ->where('empresa_id', $empresaId)->where('sucursal_id', $sucId)
            ->whereBetween('fecha', [$fIni, $fFin])
            ->selectRaw("fecha as dia, count(*) as total, count(*) filter (where exists (
                select 1 from preoperacional_items pi where pi.preoperacional_id = preoperacionales.id and pi.calificacion = 'NC'
            )) as no_conformes")
            ->groupBy('fecha')
            ->get()->keyBy('dia');

        // Recepción por día (a pedido de Camilo, 2026-09-30): referencias distintas
        // recibidas y proveedores distintos recibidos, según las líneas capturadas.
        // El proveedor es texto libre por línea (sin ODC) → se normaliza (mayúsculas,
        // sin tildes ni espacios sobrantes) para no contar "FÉLIX" y "FELIX" como dos;
        // con ODC y sin proveedor en la línea se toma la razón social de la orden.
        $provNorm = "translate(upper(trim(coalesce(nullif(trim(rd.proveedor), ''), pv.razon_social, ''))), 'ÁÉÍÓÚÜÑ', 'AEIOUUN')";
        $serieRefs = Capsule::table('recepcion_detalles as rd')
            ->join('recepciones as r', 'r.id', '=', 'rd.recepcion_id')
            ->leftJoin('ordenes_compra as oc', 'oc.id', '=', 'r.odc_id')
            ->leftJoin('proveedores as pv', 'pv.id', '=', 'oc.proveedor_id')
            ->where('r.empresa_id', $empresaId)->where('r.sucursal_id', $sucId)
            ->whereBetween(Capsule::raw('rd.created_at::date'), [$fIni, $fFin])
            ->selectRaw("rd.created_at::date as dia, count(distinct rd.producto_id) as referencias,
                         count(distinct nullif({$provNorm}, '')) as proveedores")
            ->groupBy(Capsule::raw('rd.created_at::date'))
            ->get()->keyBy('dia');

        return $this->ok($res, [
            'rango' => ['inicio' => $fIni, 'fin' => $fFin],
            'recepcion' => [
                'total_registros'   => $totalRecepcionCalidad,
                'conformes'         => $recepConformes,
                'no_conformes'      => $recepNoConformes,
                'sin_auditoria'     => $recepSinAuditoria,
                'pendientes'        => $recepPendientes,
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
            'serie_referencias'    => $serieRefs,
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
            // 2026-10-07: TODAS las recepciones del rango (con y sin ODC), no solo las
            // que tienen cabecera recepcion_calidad — esa cabecera solo se crea al
            // cerrar el recibo, y los recibos sin ODC en Borrador (ya con su inspección
            // por referencia hecha en el móvil) no aparecían. El resultado sale de la
            // inspección OBLIGATORIA por referencia (misma regla que
            // RecepcionController::referenciasSinCalidad: CDP no la requiere).
            foreach ($this->recepcionesConCalidad($empresaId, $sucId, $fIni, $fFin) as $row) {
                if ($soloNc && !$row['con_nc']) continue;
                $filas[] = [
                    'tipo'        => 'recepcion',
                    'tipo_label'  => 'Recepción',
                    'id'          => $row['id'],
                    'fecha'       => $row['fecha'],
                    'referencia'  => $row['numero_recepcion'],
                    'detalle'     => $row['detalle'],
                    'resultado'   => $row['resultado'],
                    'es_conforme' => $row['es_conforme'],
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

    /**
     * Recepciones del rango con el estado de su inspección de calidad por
     * referencia. Una referencia (producto no-CDP de la recepción) está inspeccionada
     * si alguna de sus capturas tiene los 6 criterios diligenciados; es No Conforme
     * si algún criterio de alguna captura es 'NC'. La cabecera (transporte), si
     * existe, también puede marcar 'Inconforme'.
     */
    // Desde esta fecha la inspección por referencia es obligatoria para cerrar/ingresar
    // (RecepcionController::confirm, pedido de Camilo 2026-09-29).
    private const CALIDAD_OBLIGATORIA_DESDE = '2026-09-29';

    private function recepcionesConCalidad(int $empresaId, int $sucId, string $fIni, string $fFin): array
    {
        $refs = Capsule::table('recepciones as r')
            ->join('recepcion_detalles as rd', 'rd.recepcion_id', '=', 'r.id')
            ->leftJoin('recepcion_detalle_calidad as rdc', 'rdc.recepcion_detalle_id', '=', 'rd.id')
            ->where('r.empresa_id', $empresaId)->where('r.sucursal_id', $sucId)
            ->whereBetween(Capsule::raw('r.created_at::date'), [$fIni, $fFin])
            ->whereRaw("UPPER(TRIM(COALESCE(rd.proveedor, ''))) <> 'CDP'")
            ->groupBy('r.id', 'rd.producto_id')
            ->selectRaw("r.id, rd.producto_id,
                BOOL_OR(rdc.olor IS NOT NULL AND rdc.color IS NOT NULL AND rdc.textura IS NOT NULL
                        AND rdc.temperatura IS NOT NULL AND rdc.empaque IS NOT NULL AND rdc.rotulado IS NOT NULL) AS ok,
                BOOL_OR('NC' IN (rdc.olor, rdc.color, rdc.textura, rdc.temperatura, rdc.empaque, rdc.rotulado)) AS nc")
            ->get()->groupBy('id');

        $recs = Capsule::table('recepciones as r')
            ->leftJoin('recepcion_calidad as rc', 'rc.recepcion_id', '=', 'r.id')
            ->where('r.empresa_id', $empresaId)->where('r.sucursal_id', $sucId)
            ->whereBetween(Capsule::raw('r.created_at::date'), [$fIni, $fFin])
            ->whereExists(fn($q) => $q->select(Capsule::raw(1))->from('recepcion_detalles as x')->whereColumn('x.recepcion_id', 'r.id'))
            ->select('r.id', 'r.created_at as fecha', 'r.numero_recepcion', 'r.odc_id', 'r.estado', 'rc.conforme', 'rc.trans_placa', 'rc.factura')
            ->orderBy('r.created_at', 'desc')
            ->get();

        $out = [];
        foreach ($recs as $r) {
            $lista = $refs[$r->id] ?? collect();
            $req = $lista->count();
            $ok  = $lista->filter(fn($x) => $x->ok)->count();
            $nc  = $lista->contains(fn($x) => $x->nc) || $r->conforme === 'Inconforme';
            if ($nc)            { $res = 'Inconforme'; $esOk = false; }
            elseif ($req === 0) { $res = $r->conforme === 'Conforme' ? 'Conforme' : 'No requiere (CDP)'; $esOk = $r->conforme === 'Conforme' ? true : null; }
            elseif ($ok < $req && substr((string)$r->fecha, 0, 10) < self::CALIDAD_OBLIGATORIA_DESDE) { $res = 'Sin auditoría (antes de ser obligatoria)'; $esOk = null; }
            elseif ($ok < $req) { $res = "Pendiente ({$ok} de {$req} ref.)"; $esOk = null; }
            else                { $res = 'Conforme'; $esOk = true; }
            $out[] = [
                'id' => $r->id, 'fecha' => $r->fecha, 'numero_recepcion' => $r->numero_recepcion,
                'detalle' => implode(' · ', array_filter([
                    $r->odc_id ? 'Con ODC' : 'Sin ODC', $r->estado,
                    $req ? "{$ok}/{$req} ref. inspeccionadas" : null,
                    $r->trans_placa ? "Placa {$r->trans_placa}" : ($r->factura ?: null),
                ])),
                'resultado' => $res, 'es_conforme' => $esOk, 'con_nc' => $nc,
                'pendiente' => $req > 0 && $ok < $req && !$nc && substr((string)$r->fecha, 0, 10) >= self::CALIDAD_OBLIGATORIA_DESDE,
            ];
        }
        return $out;
    }

    // ── GET /api/calidad/recepcion/{id} ──────────────────────────────────────
    // Detalle completo de un registro de calidad de recepción — exige
    // 'calidad.inspeccionar' (ver e inspeccionar en detalle, no solo la matriz).
    public function verRecepcion(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requirePermiso($user, $r, 'calidad', 'inspeccionar', $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        // {id} = id de la RECEPCIÓN (2026-10-07; antes id de la cabecera de calidad,
        // que los recibos sin ODC abiertos no tienen). La cabecera es opcional.
        $rc = Capsule::table('recepciones as r')
            ->leftJoin('recepcion_calidad as rc', 'rc.recepcion_id', '=', 'r.id')
            ->where('r.empresa_id', $empresaId)
            ->where('r.sucursal_id', $user->sucursal_id)
            ->where('r.id', (int)$a['id'])
            ->select('rc.*', 'r.id as recepcion_id', 'r.numero_recepcion', 'r.fecha_movimiento', 'r.created_at')
            ->first();

        if (!$rc) return $this->notFound($res);

        // Referencias que requieren inspección y aún no la tienen completa
        $pendientes = Capsule::table('recepcion_detalles as rd')
            ->leftJoin('recepcion_detalle_calidad as rdc', 'rdc.recepcion_detalle_id', '=', 'rd.id')
            ->leftJoin('productos as p', 'p.id', '=', 'rd.producto_id')
            ->where('rd.recepcion_id', $rc->recepcion_id)
            ->whereRaw("UPPER(TRIM(COALESCE(rd.proveedor, ''))) <> 'CDP'")
            ->groupBy('rd.producto_id', 'p.nombre', 'p.codigo_interno')
            ->havingRaw('NOT BOOL_OR(rdc.olor IS NOT NULL AND rdc.color IS NOT NULL AND rdc.textura IS NOT NULL AND rdc.temperatura IS NOT NULL AND rdc.empaque IS NOT NULL AND rdc.rotulado IS NOT NULL)')
            ->select('p.nombre as producto_nombre', 'p.codigo_interno as producto_codigo')
            ->get();

        $detalles = Capsule::table('recepcion_detalle_calidad as rdc')
            ->join('recepcion_detalles as rd', 'rd.id', '=', 'rdc.recepcion_detalle_id')
            ->leftJoin('productos as p', 'p.id', '=', 'rd.producto_id')
            ->where('rd.recepcion_id', $rc->recepcion_id)
            ->select('rdc.*', 'p.nombre as producto_nombre', 'p.codigo_interno as producto_codigo')
            ->get();

        return $this->ok($res, ['encabezado' => $rc, 'detalles' => $detalles, 'pendientes' => $pendientes]);
    }
}
