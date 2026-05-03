<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * YardController — Gestión de Patio y Muelles (Yard Management System).
 *
 * Controla la programación de citas de camiones, asignación de muelles,
 * registro de tiempos reales y KPIs de eficiencia de patio.
 *
 * Endpoints:
 *  GET    /api/yard                        → Agenda del día (citas activas)
 *  POST   /api/yard                        → Crear cita de camión
 *  GET    /api/yard/{id}                   → Detalle de cita
 *  PUT    /api/yard/{id}                   → Actualizar cita
 *  POST   /api/yard/{id}/entrada           → Registrar entrada al patio
 *  POST   /api/yard/{id}/inicio-operacion  → Registrar inicio de carga/descarga
 *  POST   /api/yard/{id}/fin-operacion     → Registrar fin de carga/descarga
 *  POST   /api/yard/{id}/salida            → Registrar salida del patio
 *  POST   /api/yard/{id}/cancelar          → Cancelar cita
 *  GET    /api/yard/muelles                → Estado actual de cada muelle
 *  GET    /api/yard/kpis                   → Métricas de turnaround y eficiencia
 *  GET    /api/yard/export                 → Exportar CSV
 */
class YardController extends BaseController
{
    // ── GET /api/yard ─────────────────────────────────────────────────────────
    // Agenda con filtros de fecha, estado y muelle
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 50), 200);

        [$ini, $fin] = $this->getDateRange($params);

        $q = Capsule::table('yard_appointments as y')
            ->where('y.empresa_id',  $user->empresa_id)
            ->where('y.sucursal_id', $user->sucursal_id)
            ->whereBetween(
                Capsule::raw('DATE(y.fecha_cita)'),
                [substr($ini, 0, 10), substr($fin, 0, 10)]
            );

        if (!empty($params['estado'])) {
            $q->where('y.estado', $params['estado']);
        }
        if (!empty($params['muelle'])) {
            $q->where('y.muelle', $params['muelle']);
        }
        if (!empty($params['tipo'])) {
            $q->where('y.tipo', $params['tipo']);
        }
        if (!empty($params['q'])) {
            $term = '%' . $params['q'] . '%';
            $q->where(fn($sq) =>
                $sq->where('y.transportista', 'ilike', $term)
                   ->orWhere('y.placa_vehiculo', 'ilike', $term)
                   ->orWhere('y.numero', 'ilike', $term)
            );
        }

        $total  = (clone $q)->count();
        $citas  = $q->leftJoin(
                        Capsule::raw("personal AS op ON op.id = y.creado_por")
                    )
                    ->select('y.*', Capsule::raw('op.nombre AS operador_nombre'))
                    ->orderBy('y.fecha_cita', 'asc')
                    ->limit($limit)
                    ->offset((int)($params['offset'] ?? 0))
                    ->get();

        return $this->ok($res, ['citas' => $citas, 'total' => $total]);
    }

    // ── GET /api/yard/{id} ────────────────────────────────────────────────────
    public function show(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $cita = Capsule::table('yard_appointments')
            ->where('id',          $a['id'])
            ->where('empresa_id',  $user->empresa_id)
            ->first();

        if (!$cita) return $this->notFound($res);

        // Timeline de eventos calculados
        $timeline = [];
        if ($cita->llegada_est)    $timeline[] = ['evento' => 'Cita programada',       'ts' => $cita->fecha_cita,      'tipo' => 'plan'];
        if ($cita->entrada_real)   $timeline[] = ['evento' => 'Entrada al patio',       'ts' => $cita->entrada_real,    'tipo' => 'real'];
        if ($cita->inicio_op_real) $timeline[] = ['evento' => 'Inicio de operación',    'ts' => $cita->inicio_op_real,  'tipo' => 'real'];
        if ($cita->fin_op_real)    $timeline[] = ['evento' => 'Fin de operación',        'ts' => $cita->fin_op_real,     'tipo' => 'real'];
        if ($cita->salida_real)    $timeline[] = ['evento' => 'Salida del patio',        'ts' => $cita->salida_real,     'tipo' => 'real'];

        // Demoras calculadas
        $demoras = [];
        if ($cita->entrada_real && $cita->fecha_cita) {
            $diff = (strtotime($cita->entrada_real) - strtotime($cita->fecha_cita)) / 60;
            $demoras['llegada_min'] = round($diff);
        }
        if ($cita->entrada_real && $cita->inicio_op_real) {
            $diff = (strtotime($cita->inicio_op_real) - strtotime($cita->entrada_real)) / 60;
            $demoras['espera_muelle_min'] = round($diff);
        }
        if ($cita->inicio_op_real && $cita->fin_op_real) {
            $diff = (strtotime($cita->fin_op_real) - strtotime($cita->inicio_op_real)) / 60;
            $demoras['duracion_op_min'] = round($diff);
        }

        return $this->ok($res, [
            'cita'     => $cita,
            'timeline' => $timeline,
            'demoras'  => $demoras,
        ]);
    }

    // ── POST /api/yard ────────────────────────────────────────────────────────
    public function crear(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin', 'Logistico'])) {
            return $this->forbidden($res, 'Se requiere rol Logístico, Supervisor o Admin para crear citas de patio');
        }
        $body = (array)($r->getParsedBody() ?? []);

        if ($deny = $this->requireFields($body, ['numero', 'fecha_cita', 'tipo'], $res)) {
            return $deny;
        }

        // Verificar disponibilidad del muelle en la ventana horaria
        if (!empty($body['muelle'])) {
            $conflict = $this->_verificarConflictoMuelle(
                $user, $body['muelle'], $body['fecha_cita'],
                $body['duracion_estimada_min'] ?? 60
            );
            if ($conflict) {
                return $this->error($res, "El muelle {$body['muelle']} ya tiene una cita asignada en ese horario");
            }
        }

        try {
            $id = Capsule::table('yard_appointments')->insertGetId([
                'empresa_id'     => $user->empresa_id,
                'sucursal_id'    => $user->sucursal_id,
                'numero'         => $body['numero'],
                'transportista'  => $body['transportista'] ?? null,
                'placa_vehiculo' => $body['placa_vehiculo'] ?? null,
                'conductor'      => $body['conductor']      ?? null,
                'telefono'       => $body['telefono']       ?? null,
                'fecha_cita'     => $body['fecha_cita'],
                'muelle'         => $body['muelle']         ?? null,
                'tipo'           => $body['tipo'],
                'estado'         => 'Programado',
                'notas'          => $body['notas']          ?? null,
                'creado_por'     => $user->id,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            $this->audit($user, 'yard', 'crear', 'yard_appointments', $id,
                null, $body, "Cita yard #{$body['numero']} programada");

            return $this->created($res, ['id' => $id], 'Cita programada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── PUT /api/yard/{id} ────────────────────────────────────────────────────
    public function actualizar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        $cita = Capsule::table('yard_appointments')
            ->where('id',         $a['id'])
            ->where('empresa_id', $user->empresa_id)
            ->first();

        if (!$cita) return $this->notFound($res);

        if (in_array($cita->estado, ['Completado', 'Cancelado'])) {
            return $this->error($res, "No se puede editar una cita en estado {$cita->estado}");
        }

        $campos = array_filter([
            'fecha_cita'     => $body['fecha_cita']     ?? null,
            'muelle'         => $body['muelle']         ?? null,
            'transportista'  => $body['transportista']  ?? null,
            'placa_vehiculo' => $body['placa_vehiculo'] ?? null,
            'conductor'      => $body['conductor']      ?? null,
            'telefono'       => $body['telefono']       ?? null,
            'notas'          => $body['notas']          ?? null,
        ], fn($v) => $v !== null);

        if (!empty($campos)) {
            Capsule::table('yard_appointments')
                ->where('id', $a['id'])
                ->update($campos);
        }

        $this->audit($user, 'yard', 'actualizar', 'yard_appointments', $a['id']);
        return $this->ok($res, null, 'Cita actualizada');
    }

    // ── POST /api/yard/{id}/entrada ───────────────────────────────────────────
    public function registrarEntrada(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        $cita = $this->_getCita($a['id'], $user->empresa_id);
        if (!$cita) return $this->notFound($res);

        if ($cita->estado !== 'Programado') {
            return $this->error($res, "La cita ya registró entrada (estado: {$cita->estado})");
        }

        $ahora = $body['timestamp'] ?? date('Y-m-d H:i:s');

        Capsule::table('yard_appointments')->where('id', $a['id'])->update([
            'entrada_real' => $ahora,
            'estado'       => 'En Patio',
        ]);

        // Calcular demora de llegada vs cita programada
        $demoraMin = round((strtotime($ahora) - strtotime($cita->fecha_cita)) / 60);

        $this->audit($user, 'yard', 'entrada', 'yard_appointments', $a['id'],
            null, ['entrada_real' => $ahora, 'demora_min' => $demoraMin]);

        return $this->ok($res, [
            'entrada_registrada' => $ahora,
            'demora_min'         => $demoraMin,
            'en_tiempo'          => $demoraMin <= 15,
        ], 'Entrada al patio registrada');
    }

    // ── POST /api/yard/{id}/inicio-operacion ──────────────────────────────────
    public function registrarInicioOperacion(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        $cita = $this->_getCita($a['id'], $user->empresa_id);
        if (!$cita) return $this->notFound($res);

        if ($cita->estado !== 'En Patio') {
            return $this->error($res, 'El camión debe estar En Patio para iniciar operación');
        }

        $ahora = $body['timestamp'] ?? date('Y-m-d H:i:s');

        Capsule::table('yard_appointments')->where('id', $a['id'])->update([
            'inicio_op_real' => $ahora,
            'estado'         => 'Operando',
            'muelle'         => $body['muelle'] ?? $cita->muelle,
        ]);

        $this->audit($user, 'yard', 'inicio_op', 'yard_appointments', $a['id']);
        return $this->ok($res, null, 'Inicio de operación registrado');
    }

    // ── POST /api/yard/{id}/fin-operacion ─────────────────────────────────────
    public function registrarFinOperacion(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        $cita = $this->_getCita($a['id'], $user->empresa_id);
        if (!$cita) return $this->notFound($res);

        if ($cita->estado !== 'Operando') {
            return $this->error($res, 'No hay operación activa para este camión');
        }

        $ahora = $body['timestamp'] ?? date('Y-m-d H:i:s');
        $durMin = $cita->inicio_op_real
            ? round((strtotime($ahora) - strtotime($cita->inicio_op_real)) / 60)
            : null;

        Capsule::table('yard_appointments')->where('id', $a['id'])->update([
            'fin_op_real' => $ahora,
            'estado'      => 'En Patio',
        ]);

        $this->audit($user, 'yard', 'fin_op', 'yard_appointments', $a['id'],
            null, ['duracion_min' => $durMin]);

        return $this->ok($res, ['duracion_operacion_min' => $durMin], 'Fin de operación registrado');
    }

    // ── POST /api/yard/{id}/salida ────────────────────────────────────────────
    public function registrarSalida(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        $cita = $this->_getCita($a['id'], $user->empresa_id);
        if (!$cita) return $this->notFound($res);

        if (!in_array($cita->estado, ['En Patio', 'Operando'])) {
            return $this->error($res, "Estado inválido para registrar salida: {$cita->estado}");
        }

        $ahora      = $body['timestamp'] ?? date('Y-m-d H:i:s');
        $turnaroundMin = $cita->entrada_real
            ? round((strtotime($ahora) - strtotime($cita->entrada_real)) / 60)
            : null;

        Capsule::table('yard_appointments')->where('id', $a['id'])->update([
            'salida_real' => $ahora,
            'estado'      => 'Completado',
        ]);

        // Liberar el muelle
        if ($cita->muelle) {
            $this->_liberarMuelle($user, $cita->muelle);
        }

        $this->audit($user, 'yard', 'salida', 'yard_appointments', $a['id'],
            null, ['salida_real' => $ahora, 'turnaround_min' => $turnaroundMin]);

        return $this->ok($res, [
            'salida_registrada'   => $ahora,
            'turnaround_min'      => $turnaroundMin,
            'turnaround_ok'       => $turnaroundMin !== null && $turnaroundMin <= 120,
        ], 'Salida del patio registrada');
    }

    // ── POST /api/yard/{id}/cancelar ──────────────────────────────────────────
    public function cancelar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $body = (array)($r->getParsedBody() ?? []);

        $cita = $this->_getCita($a['id'], $user->empresa_id);
        if (!$cita) return $this->notFound($res);

        if (in_array($cita->estado, ['Completado', 'Cancelado'])) {
            return $this->error($res, "No se puede cancelar una cita {$cita->estado}");
        }

        Capsule::table('yard_appointments')->where('id', $a['id'])->update([
            'estado' => 'Cancelado',
            'notas'  => ($cita->notas ?? '') . ' | Cancelado: ' . ($body['motivo'] ?? 'Sin motivo'),
        ]);

        $this->audit($user, 'yard', 'cancelar', 'yard_appointments', $a['id'],
            null, ['motivo' => $body['motivo'] ?? null]);

        return $this->ok($res, null, 'Cita cancelada');
    }

    // ── GET /api/yard/muelles ─────────────────────────────────────────────────
    // Vista en tiempo real del estado de cada muelle
    public function muelles(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        // Citas activas en este momento por muelle
        $activas = Capsule::table('yard_appointments')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereNotNull('muelle')
            ->whereIn('estado', ['En Patio', 'Operando', 'Programado'])
            ->where('fecha_cita', '>=', date('Y-m-d 00:00:00'))
            ->where('fecha_cita', '<=', date('Y-m-d 23:59:59'))
            ->orderBy('fecha_cita', 'asc')
            ->get(['muelle', 'numero', 'transportista', 'placa_vehiculo',
                   'tipo', 'estado', 'fecha_cita', 'entrada_real', 'turnaround_min']);

        // Agrupar por muelle
        $porMuelle = $activas->groupBy('muelle');

        // Citas programadas para las próximas 4 horas (cola)
        $proximas = Capsule::table('yard_appointments')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('estado', 'Programado')
            ->whereBetween('fecha_cita', [
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', strtotime('+4 hours')),
            ])
            ->orderBy('fecha_cita', 'asc')
            ->get(['numero', 'muelle', 'transportista', 'tipo', 'fecha_cita']);

        return $this->ok($res, [
            'muelles_activos' => $porMuelle,
            'cola_proximas'   => $proximas,
        ]);
    }

    // ── GET /api/yard/kpis ────────────────────────────────────────────────────
    public function kpis(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $kpis = Capsule::table('yard_appointments')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('fecha_cita', [$ini, $fin])
            ->selectRaw("
                COUNT(*) AS total_citas,
                COUNT(*) FILTER (WHERE estado = 'Completado')                  AS completadas,
                COUNT(*) FILTER (WHERE estado = 'No Show')                     AS no_show,
                COUNT(*) FILTER (WHERE estado = 'Cancelado')                   AS canceladas,
                COUNT(*) FILTER (WHERE estado IN ('Programado','En Patio','Operando')) AS en_proceso,
                -- Turnaround (entrada → salida)
                ROUND(AVG(turnaround_min) FILTER (WHERE turnaround_min IS NOT NULL)::numeric, 1) AS avg_turnaround_min,
                MIN(turnaround_min) FILTER (WHERE turnaround_min IS NOT NULL)  AS min_turnaround_min,
                MAX(turnaround_min) FILTER (WHERE turnaround_min IS NOT NULL)  AS max_turnaround_min,
                -- Puntualidad (llegada vs cita programada)
                COUNT(*) FILTER (
                    WHERE entrada_real IS NOT NULL
                      AND EXTRACT(EPOCH FROM (entrada_real::timestamptz - fecha_cita::timestamptz))/60 <= 15
                ) AS llegadas_a_tiempo,
                -- Tiempo de espera en muelle (entrada → inicio op)
                ROUND(AVG(
                    EXTRACT(EPOCH FROM (inicio_op_real::timestamptz - entrada_real::timestamptz))/60
                ) FILTER (WHERE inicio_op_real IS NOT NULL AND entrada_real IS NOT NULL)::numeric, 1)
                    AS avg_espera_muelle_min,
                -- Duración de operación (inicio → fin)
                ROUND(AVG(
                    EXTRACT(EPOCH FROM (fin_op_real::timestamptz - inicio_op_real::timestamptz))/60
                ) FILTER (WHERE fin_op_real IS NOT NULL AND inicio_op_real IS NOT NULL)::numeric, 1)
                    AS avg_duracion_op_min
            ")
            ->first();

        // Distribución por tipo
        $porTipo = Capsule::table('yard_appointments')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('fecha_cita', [$ini, $fin])
            ->selectRaw("
                tipo,
                COUNT(*) AS total,
                ROUND(AVG(turnaround_min)::numeric, 1) AS avg_turnaround_min
            ")
            ->groupBy('tipo')
            ->get();

        return $this->ok($res, ['kpis' => $kpis, 'por_tipo' => $porTipo]);
    }

    // ── GET /api/yard/export ──────────────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $citas = Capsule::table('yard_appointments')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('fecha_cita', [$ini, $fin])
            ->orderBy('fecha_cita', 'desc')
            ->get();

        $headers = [
            'Número', 'Tipo', 'Transportista', 'Placa', 'Conductor',
            'Muelle', 'Cita Programada', 'Entrada Real', 'Inicio Op.', 'Fin Op.',
            'Salida Real', 'Turnaround (min)', 'Estado',
        ];

        $rows = $citas->map(fn($c) => [
            $c->numero, $c->tipo, $c->transportista ?? '—', $c->placa_vehiculo ?? '—',
            $c->conductor ?? '—', $c->muelle ?? '—',
            $c->fecha_cita, $c->entrada_real ?? '—', $c->inicio_op_real ?? '—',
            $c->fin_op_real ?? '—', $c->salida_real ?? '—',
            $c->turnaround_min ?? '—', $c->estado,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'yard_' . date('Y-m-d'));
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    private function _getCita(int $id, int $empresaId): ?object
    {
        return Capsule::table('yard_appointments')
            ->where('id',         $id)
            ->where('empresa_id', $empresaId)
            ->first();
    }

    private function _verificarConflictoMuelle($user, string $muelle, string $fechaCita, int $durMin): bool
    {
        $inicio = $fechaCita;
        $fin    = date('Y-m-d H:i:s', strtotime($fechaCita) + ($durMin * 60));

        return Capsule::table('yard_appointments')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('muelle',      $muelle)
            ->whereNotIn('estado', ['Completado', 'Cancelado', 'No Show'])
            ->where(fn($q) =>
                $q->whereBetween('fecha_cita', [$inicio, $fin])
            )
            ->exists();
    }

    private function _liberarMuelle($user, string $muelle): void
    {
        // Actualizar ocupación_pct de la ubicación de tipo muelle si existe
        Capsule::table('ubicaciones')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('codigo', $muelle)
            ->where('tipo_ubicacion', 'Recepcion')
            ->update(['estado' => 'Disponible', 'ocupacion_pct' => 0]);
    }
}
