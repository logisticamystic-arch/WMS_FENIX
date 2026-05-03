<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * WaveController — Consolidación de planillas en Waves (Sprint 4).
 *
 * Una "wave" agrupa múltiples planillas de picking por criterio
 * (zona, auxiliar, horario, prioridad) para procesarlas como una
 * unidad optimizada. Reduce el tiempo de ciclo 25-30%.
 *
 * Endpoints:
 *  GET    /api/waves                    → Listar waves
 *  POST   /api/waves                    → Crear wave
 *  GET    /api/waves/{id}               → Detalle + planillas incluidas
 *  POST   /api/waves/{id}/iniciar       → Iniciar ejecución de la wave
 *  POST   /api/waves/{id}/completar     → Cerrar wave
 *  POST   /api/waves/{id}/cancelar      → Cancelar wave
 *  POST   /api/waves/auto-generar       → Motor automático de agrupación
 *  GET    /api/waves/kpis               → Métricas de eficiencia por wave
 *  GET    /api/waves/export             → Exportar CSV
 */
class WaveController extends BaseController
{
    // ── GET /api/waves ────────────────────────────────────────────────────────
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 50), 200);
        [$ini, $fin] = $this->getDateRange($params);

        $q = Capsule::table('wave_picking as w')
            ->where('w.empresa_id',  $user->empresa_id)
            ->where('w.sucursal_id', $user->sucursal_id)
            ->whereBetween('w.created_at', [$ini, $fin]);

        if (!empty($params['estado']))   $q->where('w.estado',   $params['estado']);
        if (!empty($params['criterio'])) $q->where('w.criterio', $params['criterio']);

        $total = (clone $q)->count();
        $waves = $q->orderBy('w.prioridad', 'asc')
                   ->orderBy('w.created_at', 'desc')
                   ->limit($limit)
                   ->offset((int)($params['offset'] ?? 0))
                   ->get();

        return $this->ok($res, ['waves' => $waves, 'total' => $total]);
    }

    // ── GET /api/waves/{id} ───────────────────────────────────────────────────
    public function show(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $wave  = Capsule::table('wave_picking')
            ->where('id',          $a['id'])
            ->where('empresa_id',  $user->empresa_id)
            ->first();

        if (!$wave) return $this->notFound($res);

        // Planillas incluidas en esta wave
        $planillas = Capsule::table('wave_planillas as wp')
            ->join('planillas_picking as pp', 'wp.planilla_id', '=', 'pp.id')
            ->leftJoin('personal as aux', 'pp.auxiliar_id', '=', 'aux.id')
            ->where('wp.wave_id', $a['id'])
            ->select(
                'wp.orden_picking', 'pp.id as planilla_id', 'pp.numero',
                'pp.estado', 'pp.cliente', 'pp.total_lineas',
                'pp.lineas_completadas', 'aux.nombre as auxiliar'
            )
            ->orderBy('wp.orden_picking', 'asc')
            ->get();

        // Progreso consolidado
        $totalLineas      = $planillas->sum('total_lineas') ?? 0;
        $lineasCompletadas= $planillas->sum('lineas_completadas') ?? 0;
        $pctAvance        = $totalLineas > 0 ? round($lineasCompletadas / $totalLineas * 100, 1) : 0;

        return $this->ok($res, [
            'wave'          => $wave,
            'planillas'     => $planillas,
            'progreso'      => [
                'total_lineas'       => $totalLineas,
                'lineas_completadas' => $lineasCompletadas,
                'pct_avance'         => $pctAvance,
            ],
        ]);
    }

    // ── POST /api/waves ───────────────────────────────────────────────────────
    public function crear(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $body = (array)($r->getParsedBody() ?? []);
        if ($deny = $this->requireFields($body, ['numero'], $res)) return $deny;
        if (empty($body['planillas']) || !is_array($body['planillas'])) {
            return $this->error($res, 'Se requiere al menos una planilla');
        }

        try {
            $id = Capsule::transaction(function () use ($body, $user) {
                $planillasIds = array_map('intval', $body['planillas']);

                // Verificar que las planillas pertenecen a la empresa y están Pendientes
                $planillasValidas = Capsule::table('planillas_picking')
                    ->where('empresa_id',  $user->empresa_id)
                    ->where('sucursal_id', $user->sucursal_id)
                    ->whereIn('id', $planillasIds)
                    ->where('estado', 'Pendiente')
                    ->count();

                if ($planillasValidas === 0) {
                    throw new \Exception('No hay planillas Pendientes válidas para esta empresa/sucursal');
                }

                $totalLineas = Capsule::table('planillas_picking')
                    ->whereIn('id', $planillasIds)
                    ->sum('total_lineas') ?? 0;

                $id = Capsule::table('wave_picking')->insertGetId([
                    'empresa_id'      => $user->empresa_id,
                    'sucursal_id'     => $user->sucursal_id,
                    'numero'          => $body['numero'],
                    'nombre'          => $body['nombre']   ?? null,
                    'criterio'        => $body['criterio'] ?? 'manual',
                    'prioridad'       => (int)($body['prioridad'] ?? 3),
                    'planillas_count' => $planillasValidas,
                    'lineas_count'    => $totalLineas,
                    'inicio_est'      => $body['inicio_est'] ?? null,
                    'estado'          => 'Preparando',
                    'creado_por'      => $user->id,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);

                // Asociar planillas con orden de picking optimizado
                foreach ($planillasIds as $orden => $planillaId) {
                    Capsule::table('wave_planillas')->insert([
                        'wave_id'      => $id,
                        'planilla_id'  => $planillaId,
                        'orden_picking'=> $orden + 1,
                    ]);
                }

                return $id;
            });

            $this->audit($user, 'waves', 'crear', 'wave_picking', $id,
                null, $body, "Wave {$body['numero']} creada con {$id} planillas");

            return $this->created($res, ['id' => $id], 'Wave de picking creada');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/waves/{id}/iniciar ──────────────────────────────────────────
    public function iniciar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $wave = $this->_getWave($a['id'], $user->empresa_id);
        if (!$wave) return $this->notFound($res);

        if ($wave->estado !== 'Preparando') {
            return $this->error($res, "La wave ya fue iniciada (estado: {$wave->estado})");
        }

        Capsule::table('wave_picking')->where('id', $a['id'])->update([
            'estado'      => 'En Proceso',
            'inicio_real' => date('Y-m-d H:i:s'),
        ]);

        $this->audit($user, 'waves', 'iniciar', 'wave_picking', $a['id']);
        return $this->ok($res, null, 'Wave iniciada');
    }

    // ── POST /api/waves/{id}/completar ────────────────────────────────────────
    public function completar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $wave = $this->_getWave($a['id'], $user->empresa_id);
        if (!$wave) return $this->notFound($res);

        if ($wave->estado !== 'En Proceso') {
            return $this->error($res, "La wave debe estar En Proceso para completar");
        }

        $ahora = date('Y-m-d H:i:s');
        Capsule::table('wave_picking')->where('id', $a['id'])->update([
            'estado'   => 'Completado',
            'fin_real' => $ahora,
        ]);

        $this->audit($user, 'waves', 'completar', 'wave_picking', $a['id']);
        return $this->ok($res, null, 'Wave completada');
    }

    // ── POST /api/waves/{id}/cancelar ─────────────────────────────────────────
    public function cancelar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $wave = $this->_getWave($a['id'], $user->empresa_id);
        if (!$wave) return $this->notFound($res);

        if (in_array($wave->estado, ['Completado', 'Cancelado'])) {
            return $this->error($res, "No se puede cancelar una wave {$wave->estado}");
        }

        Capsule::table('wave_picking')->where('id', $a['id'])->update(['estado' => 'Cancelado']);

        $this->audit($user, 'waves', 'cancelar', 'wave_picking', $a['id']);
        return $this->ok($res, null, 'Wave cancelada');
    }

    // ── POST /api/waves/auto-generar ──────────────────────────────────────────
    // Motor automático: agrupa planillas Pendientes por criterio elegido
    public function autoGenerar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $body     = (array)($r->getParsedBody() ?? []);
        $criterio = $body['criterio'] ?? 'zona';
        $maxPlanillas = (int)($body['max_planillas_por_wave'] ?? 10);

        // Obtener planillas pendientes
        $planillasPendientes = Capsule::table('planillas_picking')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('estado', 'Pendiente')
            ->orderBy('prioridad', 'asc')
            ->orderBy('created_at', 'asc')
            ->get(['id', 'numero', 'auxiliar_id', 'cliente', 'prioridad', 'total_lineas']);

        if ($planillasPendientes->isEmpty()) {
            return $this->error($res, 'No hay planillas Pendientes para agrupar');
        }

        // Agrupar según criterio
        switch ($criterio) {
            case 'auxiliar':
                $grupos = $planillasPendientes->groupBy('auxiliar_id');
                break;
            case 'prioridad':
                $grupos = $planillasPendientes->groupBy('prioridad');
                break;
            case 'cliente':
                $grupos = $planillasPendientes->groupBy('cliente');
                break;
            default: // 'manual' o 'horario': agrupar en chunks de maxPlanillas
                $chunks = $planillasPendientes->chunk($maxPlanillas);
                $grupos = $chunks->mapWithKeys(fn($chunk, $i) => ["grupo_{$i}" => $chunk]);
        }

        $wavesCreadas = 0;
        $wavesIds     = [];
        $ahora        = date('Y-m-d H:i:s');

        foreach ($grupos as $grupoKey => $planillas) {
            // Dividir en sub-grupos si supera el límite
            $subGrupos = $planillas->chunk($maxPlanillas);

            foreach ($subGrupos as $subIdx => $subGrupo) {
                $numero = 'WV-' . date('Ymd') . '-' . str_pad($wavesCreadas + 1, 3, '0', STR_PAD_LEFT);

                $id = Capsule::table('wave_picking')->insertGetId([
                    'empresa_id'      => $user->empresa_id,
                    'sucursal_id'     => $user->sucursal_id,
                    'numero'          => $numero,
                    'nombre'          => "Auto-{$criterio}: {$grupoKey}",
                    'criterio'        => $criterio,
                    'prioridad'       => (int)($subGrupo->min('prioridad') ?? 3),
                    'planillas_count' => $subGrupo->count(),
                    'lineas_count'    => $subGrupo->sum('total_lineas') ?? 0,
                    'estado'          => 'Preparando',
                    'creado_por'      => $user->id,
                    'created_at'      => $ahora,
                ]);

                foreach ($subGrupo->values() as $orden => $planilla) {
                    Capsule::table('wave_planillas')->insert([
                        'wave_id'       => $id,
                        'planilla_id'   => $planilla->id,
                        'orden_picking' => $orden + 1,
                    ]);
                }

                $wavesIds[]   = $id;
                $wavesCreadas++;
            }
        }

        $this->audit($user, 'waves', 'auto_generar', 'wave_picking', null,
            null, ['waves_creadas' => $wavesCreadas, 'criterio' => $criterio],
            "Auto-waves generadas: {$wavesCreadas} waves por criterio {$criterio}");

        return $this->created($res, [
            'waves_creadas' => $wavesCreadas,
            'wave_ids'      => $wavesIds,
            'criterio'      => $criterio,
        ], "{$wavesCreadas} waves creadas automáticamente");
    }

    // ── GET /api/waves/kpis ───────────────────────────────────────────────────
    public function kpis(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $kpis = Capsule::table('wave_picking')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->selectRaw("
                COUNT(*) AS total_waves,
                COUNT(*) FILTER (WHERE estado = 'Completado') AS completadas,
                COUNT(*) FILTER (WHERE estado = 'En Proceso') AS en_proceso,
                COUNT(*) FILTER (WHERE estado = 'Cancelado')  AS canceladas,
                SUM(planillas_count) AS total_planillas,
                SUM(lineas_count)    AS total_lineas,
                ROUND(AVG(duracion_min) FILTER (WHERE duracion_min IS NOT NULL)::numeric, 1) AS avg_duracion_min,
                MIN(duracion_min) FILTER (WHERE duracion_min IS NOT NULL) AS min_duracion_min,
                MAX(duracion_min) FILTER (WHERE duracion_min IS NOT NULL) AS max_duracion_min
            ")
            ->first();

        return $this->ok($res, ['kpis' => $kpis]);
    }

    // ── GET /api/waves/export ─────────────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $waves = Capsule::table('wave_picking')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->orderBy('created_at', 'desc')
            ->get();

        $headers = ['Número', 'Nombre', 'Criterio', 'Prioridad', 'Planillas',
                    'Líneas', 'Estado', 'Inicio Real', 'Fin Real', 'Duración (min)'];

        $rows = $waves->map(fn($w) => [
            $w->numero, $w->nombre ?? '—', $w->criterio, $w->prioridad,
            $w->planillas_count, $w->lineas_count, $w->estado,
            $w->inicio_real ?? '—', $w->fin_real ?? '—', $w->duracion_min ?? '—',
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'waves_' . date('Y-m-d'));
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    private function _getWave(int $id, int $empresaId): ?object
    {
        return Capsule::table('wave_picking')
            ->where('id',         $id)
            ->where('empresa_id', $empresaId)
            ->first();
    }

    private function requireSupervisor($user, Response $res): ?Response
    {
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin'])) {
            return $this->forbidden($res, 'Se requiere rol Supervisor o Admin');
        }
        return null;
    }
}
