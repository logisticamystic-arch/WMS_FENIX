<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * CrossDockController — Operaciones de Cross-Docking.
 *
 * El cross-docking transfiere mercancía directamente del camión de entrada
 * al de salida, sin almacenamiento intermedio. Reduce costos 15-25%.
 *
 * Endpoints:
 *  GET    /api/cross-dock                    → Listar órdenes
 *  POST   /api/cross-dock                    → Crear orden de cross-dock
 *  GET    /api/cross-dock/{id}               → Detalle de orden
 *  PUT    /api/cross-dock/{id}/estado        → Actualizar estado
 *  POST   /api/cross-dock/{id}/recibir       → Registrar recepción parcial/total
 *  POST   /api/cross-dock/{id}/transferir    → Marcar ítems como transferidos a despacho
 *  POST   /api/cross-dock/{id}/completar     → Cerrar orden de cross-dock
 *  GET    /api/cross-dock/export             → Exportar CSV
 *  GET    /api/cross-dock/kpis               → Métricas de tiempo en suelo
 */
class CrossDockController extends BaseController
{
    // ── GET /api/cross-dock ───────────────────────────────────────────────────
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 50), 200);
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        [$ini, $fin] = $this->getDateRange($params);

        $q = Capsule::table('cross_dock_ordenes as cd')
            ->where('cd.empresa_id',  $empresaId)
            ->where('cd.sucursal_id', $sucursalId)
            ->whereBetween('cd.created_at', [$ini, $fin]);

        if (!empty($params['estado'])) {
            $q->where('cd.estado', $params['estado']);
        }
        if (!empty($params['muelle'])) {
            $q->where(fn($sq) =>
                $sq->where('cd.muelle_entrada', $params['muelle'])
                   ->orWhere('cd.muelle_salida',  $params['muelle'])
            );
        }
        if (!empty($params['q'])) {
            $term = '%' . $params['q'] . '%';
            $q->where('cd.numero', 'like', $term);
        }

        $total  = (clone $q)->count();
        $ordenes = $q->orderBy('cd.created_at', 'desc')
                     ->limit($limit)
                     ->offset((int)($params['offset'] ?? 0))
                     ->get();

        // Enriquecer con conteo de detalles
        $ids = $ordenes->pluck('id')->toArray();
        if (!empty($ids)) {
            $conteos = Capsule::table('cross_dock_detalles')
                ->whereIn('cross_dock_id', $ids)
                ->selectRaw('cross_dock_id, COUNT(*) as total_lineas,
                             SUM(cantidad_esp) as total_esperado,
                             SUM(cantidad_real) as total_recibido')
                ->groupBy('cross_dock_id')
                ->get()
                ->keyBy('cross_dock_id');

            $ordenes->transform(function ($o) use ($conteos) {
                $c = $conteos->get($o->id);
                $o->total_lineas   = $c->total_lineas  ?? 0;
                $o->total_esperado = $c->total_esperado ?? 0;
                $o->total_recibido = $c->total_recibido ?? 0;
                return $o;
            });
        }

        return $this->ok($res, ['ordenes' => $ordenes, 'total' => $total]);
    }

    // ── GET /api/cross-dock/{id} ──────────────────────────────────────────────
    public function show(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $cd   = Capsule::table('cross_dock_ordenes')
            ->where('id',          $a['id'])
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if (!$cd) return $this->notFound($res);

        $detalles = Capsule::table('cross_dock_detalles as d')
            ->join('productos as p', 'd.producto_id', '=', 'p.id')
            ->where('d.cross_dock_id', $a['id'])
            ->select('d.*', 'p.nombre as producto_nombre', 'p.codigo_interno as codigo')
            ->get();

        return $this->ok($res, array_merge((array)$cd, ['detalles' => $detalles]));
    }

    // ── POST /api/cross-dock ──────────────────────────────────────────────────
    public function crear(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin', 'Logistico'])) {
            return $this->forbidden($res, 'Se requiere rol Logístico, Supervisor o Admin para crear cross-dock');
        }
        $body = (array)($r->getParsedBody() ?? []);

        if ($deny = $this->requireFields($body, ['numero'], $res)) return $deny;
        if (empty($body['detalles']) || !is_array($body['detalles'])) {
            return $this->error($res, 'Se requiere al menos una línea de producto');
        }

        try {
            $id = Capsule::transaction(function () use ($body, $user, $empresaId, $sucursalId) {
                $id = Capsule::table('cross_dock_ordenes')->insertGetId([
                    'empresa_id'     => $empresaId,
                    'sucursal_id'    => $sucursalId,
                    'numero'         => $body['numero'],
                    'recepcion_id'   => $body['recepcion_id'] ?? null,
                    'despacho_id'    => $body['despacho_id']  ?? null,
                    'muelle_entrada' => $body['muelle_entrada'] ?? null,
                    'muelle_salida'  => $body['muelle_salida']  ?? null,
                    'llegada_est'    => $body['llegada_est']  ?? null,
                    'salida_est'     => $body['salida_est']   ?? null,
                    'estado'         => 'Programado',
                    'notas'          => $body['notas'] ?? null,
                    'creado_por'     => $user->id,
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);

                foreach ($body['detalles'] as $d) {
                    Capsule::table('cross_dock_detalles')->insert([
                        'cross_dock_id' => $id,
                        'producto_id'   => (int)$d['producto_id'],
                        'cantidad_esp'  => (float)$d['cantidad'],
                        'cantidad_real' => 0,
                        'estado'        => 'Pendiente',
                        'notas'         => $d['notas'] ?? null,
                    ]);
                }

                return $id;
            });

            $this->audit($user, 'cross_dock', 'crear', 'cross_dock_ordenes', $id,
                null, $body, "Cross-dock {$body['numero']} creado");

            return $this->created($res, ['id' => $id], 'Orden de cross-dock creada');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/cross-dock/{id}/recibir ─────────────────────────────────────
    // Registra la cantidad real recibida por línea
    public function recibir(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $body = (array)($r->getParsedBody() ?? []);

        $cd = Capsule::table('cross_dock_ordenes')
            ->where('id',         $a['id'])
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if (!$cd) return $this->notFound($res);
        if (!in_array($cd->estado, ['Programado', 'Recibiendo'])) {
            return $this->error($res, "No se puede recibir en estado {$cd->estado}");
        }

        Capsule::transaction(function () use ($a, $body, $cd, $user) {
            // Actualizar estado del encabezado
            Capsule::table('cross_dock_ordenes')->where('id', $a['id'])->update([
                'estado'      => 'Recibiendo',
                'llegada_real'=> $body['llegada_real'] ?? date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            // Actualizar líneas recibidas
            foreach ($body['lineas'] ?? [] as $linea) {
                $cantReal = (float)($linea['cantidad_real'] ?? 0);
                $detalle  = Capsule::table('cross_dock_detalles')
                    ->where('id',           $linea['id'])
                    ->where('cross_dock_id', $a['id'])
                    ->first();

                if (!$detalle) continue;

                $estado = $cantReal >= $detalle->cantidad_esp ? 'Recibido'
                        : ($cantReal > 0 ? 'Recibido' : 'Pendiente');

                if ($cantReal > 0 && abs($cantReal - $detalle->cantidad_esp) > 0.01) {
                    $estado = 'Diferencia';
                }

                Capsule::table('cross_dock_detalles')->where('id', $linea['id'])->update([
                    'cantidad_real' => $cantReal,
                    'estado'        => $estado,
                    'notas'         => $linea['notas'] ?? $detalle->notas,
                ]);
            }
        });

        $this->audit($user, 'cross_dock', 'recibir', 'cross_dock_ordenes', $a['id']);

        return $this->ok($res, null, 'Recepción registrada');
    }

    // ── POST /api/cross-dock/{id}/transferir ──────────────────────────────────
    // Marca ítems como transferidos al camión de salida
    public function transferir(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $body = (array)($r->getParsedBody() ?? []);

        $cd = Capsule::table('cross_dock_ordenes')
            ->where('id',         $a['id'])
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if (!$cd) return $this->notFound($res);

        Capsule::transaction(function () use ($a, $body, $user) {
            Capsule::table('cross_dock_ordenes')->where('id', $a['id'])->update([
                'estado'     => 'Despachando',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Transferir todas las líneas recibidas
            Capsule::table('cross_dock_detalles')
                ->where('cross_dock_id', $a['id'])
                ->whereIn('estado', ['Recibido', 'Diferencia'])
                ->update(['estado' => 'Transferido']);
        });

        $this->audit($user, 'cross_dock', 'transferir', 'cross_dock_ordenes', $a['id']);

        return $this->ok($res, null, 'Ítems marcados como transferidos');
    }

    // ── POST /api/cross-dock/{id}/completar ───────────────────────────────────
    public function completar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $body = (array)($r->getParsedBody() ?? []);

        Capsule::table('cross_dock_ordenes')
            ->where('id',         $a['id'])
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->update([
                'estado'      => 'Completado',
                'salida_real' => $body['salida_real'] ?? date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

        $this->audit($user, 'cross_dock', 'completar', 'cross_dock_ordenes', $a['id']);

        return $this->ok($res, null, 'Orden de cross-dock completada');
    }

    // ── GET /api/cross-dock/kpis ──────────────────────────────────────────────
    // Métricas de tiempo en suelo y eficiencia
    public function kpis(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $stats = Capsule::table('cross_dock_ordenes')
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereBetween('created_at', [$ini, $fin])
            ->selectRaw("
                COUNT(*) as total_ordenes,
                COUNT(CASE WHEN estado = 'Completado' THEN 1 END) as completadas,
                COUNT(CASE WHEN estado IN ('Programado','Recibiendo','Clasificando','Despachando') THEN 1 END) as en_proceso,
                AVG(CASE WHEN tiempo_suelo_min IS NOT NULL THEN tiempo_suelo_min END) as avg_tiempo_suelo_min,
                MIN(CASE WHEN tiempo_suelo_min IS NOT NULL THEN tiempo_suelo_min END) as min_tiempo_suelo_min,
                MAX(CASE WHEN tiempo_suelo_min IS NOT NULL THEN tiempo_suelo_min END) as max_tiempo_suelo_min
            ")
            ->first();

        return $this->ok($res, ['kpis' => $stats]);
    }

    // ── GET /api/cross-dock/export ────────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $ordenes = Capsule::table('cross_dock_ordenes')
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereBetween('created_at', [$ini, $fin])
            ->orderBy('created_at', 'desc')
            ->get();

        $headers = ['Número', 'Estado', 'Muelle Entrada', 'Muelle Salida',
                    'Llegada Est.', 'Llegada Real', 'Salida Est.', 'Salida Real',
                    'Tiempo Suelo (min)', 'Creado'];

        $rows = $ordenes->map(fn($o) => [
            $o->numero, $o->estado, $o->muelle_entrada ?? '—', $o->muelle_salida ?? '—',
            $o->llegada_est ?? '—', $o->llegada_real ?? '—',
            $o->salida_est ?? '—', $o->salida_real ?? '—',
            $o->tiempo_suelo_min ?? '—', $o->created_at,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'cross_dock_' . date('Y-m-d'));
    }
}
