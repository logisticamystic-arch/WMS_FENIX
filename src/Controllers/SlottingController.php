<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * SlottingController — Motor de asignación óptima de ubicaciones.
 *
 * El slotting inteligente asigna cada producto a la ubicación más eficiente
 * basándose en su segmento ABC-XYZ, distancia al muelle y accesibilidad.
 * Reduce el tiempo de picking entre 25-35%.
 *
 * Endpoints:
 *  GET  /api/slotting                    → Asignaciones vigentes
 *  POST /api/slotting/ejecutar           → Corre el motor de slotting
 *  POST /api/slotting/aprobar/{id}       → Supervisor aprueba un cambio de ubicación
 *  POST /api/slotting/rechazar/{id}      → Supervisor rechaza un cambio
 *  GET  /api/slotting/sugerencias        → Productos con reubicación sugerida
 *  GET  /api/slotting/mapa               → Mapa del almacén con ocupación actual
 *  GET  /api/slotting/export             → Exportar asignaciones CSV
 */
class SlottingController extends BaseController
{
    // Zonas ordenadas por prioridad de asignación (mejor = menor índice)
    private const ZONA_PRIORIDAD = ['oro' => 1, 'plata' => 2, 'bronce' => 3, 'frio' => 4, 'peligroso' => 5];

    // ── GET /api/slotting ─────────────────────────────────────────────────────
    // Columnas reales: ubicacion_sugerida_id, score_afinidad, motivo_recomendacion
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 100), 500);

        $q = Capsule::table('ubicaciones_optimas as uo')
            ->join('productos as p',   'uo.producto_id',         '=', 'p.id')
            ->join('ubicaciones as ub', 'uo.ubicacion_sugerida_id', '=', 'ub.id')
            ->join('clasificaciones_abc_xyz as c',
                fn($j) => $j->on('c.producto_id', '=', 'uo.producto_id')
                             ->where('c.vigente', true))
            ->where('uo.empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('uo.sucursal_id', $user->sucursal_id)
            ->select(
                'uo.id', 'uo.producto_id', 'uo.ubicacion_sugerida_id',
                'uo.score_afinidad', 'uo.motivo_recomendacion',
                'uo.created_at', 'uo.updated_at',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo',
                'ub.codigo as ubicacion_codigo',
                'ub.zona as zona_actual',
                'ub.pasillo', 'ub.nivel', 'ub.distancia_muelle',
                'c.segmento', 'c.clase_abc', 'c.clase_xyz'
            );

        if (!empty($params['segmento'])) {
            $q->where('c.segmento', strtoupper($params['segmento']));
        }
        if (!empty($params['zona'])) {
            $q->where('ub.zona', $params['zona']);
        }
        if (!empty($params['q'])) {
            $term = '%' . $params['q'] . '%';
            $q->where(fn($sq) =>
                $sq->where('p.nombre', 'like', $term)
                   ->orWhere('p.codigo_interno', 'like', $term)
            );
        }

        $total = (clone $q)->count();
        $items = $q->orderBy('uo.score_afinidad', 'desc')
                   ->limit($limit)
                   ->offset((int)($params['offset'] ?? 0))
                   ->get();

        return $this->ok($res, ['asignaciones' => $items, 'total' => $total]);
    }

    // ── POST /api/slotting/ejecutar ───────────────────────────────────────────
    // Motor principal: asigna ubicaciones óptimas basado en ABC-XYZ
    public function ejecutar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $body = (array)($r->getParsedBody() ?? []);
        $soloSugerencias = (bool)($body['solo_sugerencias'] ?? true); // por defecto no aplica auto

        // 1. Obtener clasificación ABC-XYZ vigente
        $clasificaciones = Capsule::table('clasificaciones_abc_xyz')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('vigente', true)
            ->get()
            ->keyBy('producto_id');

        if ($clasificaciones->isEmpty()) {
            return $this->error($res, 'No hay clasificación ABC-XYZ vigente. Ejecute primero POST /api/rotacion/abc-xyz/ejecutar');
        }

        // 2. Obtener ubicaciones disponibles agrupadas por zona
        $ubicaciones = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activa', true)
            ->where('tipo_ubicacion', 'Picking')
            ->whereIn('estado', ['Libre', 'Parcial'])
            ->orderBy('distancia_muelle', 'asc')
            ->orderBy('accesibilidad', 'desc')
            ->get()
            ->groupBy('zona');

        // 3. Calcular asignaciones óptimas
        $sugerencias = [];
        $asignadas   = [];

        // Prioridad: AX > AY > AZ > BX > BY > BZ > CX > CY > CZ
        $ordenSegmentos = ['AX','AY','AZ','BX','BY','BZ','CX','CY','CZ'];

        foreach ($ordenSegmentos as $seg) {
            $productosSegmento = $clasificaciones->filter(
                fn($c) => ($c->segmento ?? ($c->clase_abc . $c->clase_xyz)) === $seg
            );

            // Zona ideal para cada segmento
            $zonaIdeal = match(true) {
                str_starts_with($seg, 'A') => 'oro',
                str_starts_with($seg, 'B') => 'plata',
                default                    => 'bronce',
            };

            foreach ($productosSegmento as $clas) {
                // Buscar ubicación disponible en zona ideal, luego fallback
                $ub = $this->_buscarUbicacionDisponible($ubicaciones, $zonaIdeal, $asignadas);

                if (!$ub) continue;

                $asignadas[$ub->id] = true;

                // Score de asignación (0–10)
                $score = $this->_calcularScore($clas, $ub, $zonaIdeal);

                // Verificar asignación actual para detectar cambio necesario
                $actual = Capsule::table('ubicaciones_optimas')
                    ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
                    ->where('sucursal_id', $user->sucursal_id)
                    ->where('producto_id', $clas->producto_id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                $necesitaCambio = !$actual || $actual->ubicacion_sugerida_id !== $ub->id;

                $sugerencias[] = [
                    'producto_id'          => $clas->producto_id,
                    'ubicacion_sugerida_id'=> $ub->id,
                    'ubicacion_codigo'     => $ub->codigo,
                    'segmento'             => $seg,
                    'zona_recomendada'     => $zonaIdeal,
                    'score_afinidad'       => $score,
                    'necesita_cambio'      => $necesitaCambio,
                    'ubicacion_actual_id'  => $actual ? $actual->ubicacion_sugerida_id : null,
                    'motivo'               => "Segmento {$seg} → zona {$zonaIdeal} (score: {$score})",
                ];

                // Si no es solo sugerencias, aplicar directamente
                if (!$soloSugerencias) {
                    $this->_aplicarAsignacion(
                        $this->getEffectiveEmpresaId($user, $r),
                        $user->sucursal_id,
                        $clas->producto_id,
                        $ub->id,
                        $score,
                        "Segmento {$seg} → zona {$zonaIdeal} (score: {$score})"
                    );
                }
            }
        }

        $aplicadas = $soloSugerencias ? 0 : count(array_filter($sugerencias, fn($s) => $s['necesita_cambio']));

        $this->audit($user, 'slotting', 'ejecutar', 'ubicaciones_optimas', null,
            null, ['sugerencias' => count($sugerencias), 'aplicadas' => $aplicadas],
            "Motor slotting: {$aplicadas} reasignaciones" . ($soloSugerencias ? ' (solo sugerencias)' : ''));

        return $this->ok($res, [
            'sugerencias'        => $sugerencias,
            'total_sugerencias'  => count($sugerencias),
            'cambios_necesarios' => count(array_filter($sugerencias, fn($s) => $s['necesita_cambio'])),
            'aplicadas'          => $aplicadas,
            'modo'               => $soloSugerencias ? 'sugerencias' : 'aplicado',
        ], $soloSugerencias
            ? 'Sugerencias de slotting generadas. Use aprobar/{id} para aplicar individualmente.'
            : 'Slotting aplicado automáticamente');
    }

    // ── POST /api/slotting/aprobar/{id} ───────────────────────────────────────
    // Supervisor aprueba una sugerencia: actualiza motivo_recomendacion con la aprobación
    public function aprobar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $asig = Capsule::table('ubicaciones_optimas')
            ->where('id',         $a['id'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->first();

        if (!$asig) return $this->notFound($res);

        // Anotar aprobación en motivo_recomendacion (no hay columnas de aprobación en el esquema real)
        $motivoActual = $asig->motivo_recomendacion ?? '';
        Capsule::table('ubicaciones_optimas')->where('id', $a['id'])->update([
            'motivo_recomendacion' => $motivoActual . ' [APROBADO por usuario_id=' . $user->id . ' el ' . date('Y-m-d H:i:s') . ']',
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $this->audit($user, 'slotting', 'aprobar', 'ubicaciones_optimas', $a['id']);

        return $this->ok($res, null, 'Asignación de ubicación aprobada');
    }

    // ── POST /api/slotting/rechazar/{id} ──────────────────────────────────────
    public function rechazar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        // Eliminar la sugerencia rechazada (no hay columna vigente en el esquema real)
        $deleted = Capsule::table('ubicaciones_optimas')
            ->where('id',         $a['id'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->delete();

        if (!$deleted) return $this->notFound($res);

        $this->audit($user, 'slotting', 'rechazar', 'ubicaciones_optimas', $a['id']);

        return $this->ok($res, null, 'Sugerencia rechazada');
    }

    // ── GET /api/slotting/sugerencias ─────────────────────────────────────────
    // Productos cuya ubicación actual no coincide con la óptima según ABC-XYZ
    public function sugerencias(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        if (!$this->isPg()) {
            return $this->error($res, 'Requiere PostgreSQL', 503);
        }

        // Detecta productos en zona incorrecta comparando segmento vs zona sugerida
        // Usa columnas reales: ubicacion_sugerida_id, score_afinidad (no vigente/ubicacion_id)
        $sugerencias = Capsule::select("
            SELECT
                c.producto_id,
                p.nombre AS producto_nombre,
                p.codigo_interno AS codigo,
                c.segmento,
                ub_actual.zona AS zona_actual,
                CASE
                    WHEN c.clase_abc = 'A' THEN 'oro'
                    WHEN c.clase_abc = 'B' THEN 'plata'
                    ELSE 'bronce'
                END AS zona_recomendada,
                c.total_valor,
                uo.score_afinidad
            FROM clasificaciones_abc_xyz c
            JOIN productos p ON c.producto_id = p.id
            LEFT JOIN (
                SELECT DISTINCT ON (producto_id) *
                FROM ubicaciones_optimas
                WHERE empresa_id = ? AND sucursal_id = ?
                ORDER BY producto_id, created_at DESC
            ) uo ON uo.producto_id = c.producto_id
            LEFT JOIN ubicaciones ub_actual ON ub_actual.id = uo.ubicacion_sugerida_id
            WHERE c.empresa_id  = ?
              AND c.sucursal_id = ?
              AND c.vigente     = TRUE
              AND (
                  ub_actual.zona IS NULL
                  OR (c.clase_abc = 'A' AND ub_actual.zona != 'oro')
                  OR (c.clase_abc = 'B' AND ub_actual.zona NOT IN ('oro', 'plata'))
              )
            ORDER BY c.total_valor DESC
            LIMIT 100
        ", [
            $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
            $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
        ]);

        return $this->ok($res, ['sugerencias' => $sugerencias, 'total' => count($sugerencias)]);
    }

    // ── GET /api/slotting/mapa ────────────────────────────────────────────────
    // Mapa del almacén: cada ubicación con su ocupación y producto asignado
    public function mapa(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        // Usar DISTINCT ON para obtener la última sugerencia por ubicación
        $empId = $this->getEffectiveEmpresaId($user, $r);
        $sucId = $user->sucursal_id;

        $ubicaciones = Capsule::table('ubicaciones as ub')
            ->where('ub.empresa_id',  $empId)
            ->where('ub.sucursal_id', $sucId)
            ->where('ub.activa', true)
            ->leftJoin('ubicaciones_optimas as uo',
                fn($j) => $j->on('uo.ubicacion_sugerida_id', '=', 'ub.id')
                             ->where('uo.empresa_id', $empId)
                             ->where('uo.sucursal_id', $sucId))
            ->leftJoin('productos as p', 'uo.producto_id', '=', 'p.id')
            ->leftJoin('clasificaciones_abc_xyz as c',
                fn($j) => $j->on('c.producto_id', '=', 'uo.producto_id')
                             ->where('c.vigente', true))
            ->select(
                'ub.id', 'ub.codigo', 'ub.pasillo', 'ub.estanteria',
                'ub.nivel', 'ub.posicion', 'ub.zona', 'ub.estado',
                'ub.ocupacion_pct', 'ub.capacidad_kg', 'ub.capacidad_m3',
                'p.nombre as producto_nombre', 'p.codigo_interno as producto_codigo',
                'c.segmento',
                'uo.score_afinidad'
            )
            ->orderBy('ub.pasillo')
            ->orderBy('ub.estanteria')
            ->orderBy('ub.nivel')
            ->get();

        // Agrupar por pasillo para facilitar renderizado del mapa
        $mapa = $ubicaciones->groupBy('pasillo');

        $resumen = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activa', true)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN estado = 'Disponible' THEN 1 END) as disponibles,
                COUNT(CASE WHEN estado = 'Ocupado'    THEN 1 END) as ocupadas,
                COUNT(CASE WHEN estado = 'Bloqueado'  THEN 1 END) as bloqueadas,
                AVG(ocupacion_pct) as ocupacion_promedio
            ")
            ->first();

        return $this->ok($res, ['mapa' => $mapa, 'resumen' => $resumen]);
    }

    // ── GET /api/slotting/export ──────────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $items = Capsule::table('ubicaciones_optimas as uo')
            ->join('productos as p',   'uo.producto_id',          '=', 'p.id')
            ->join('ubicaciones as ub', 'uo.ubicacion_sugerida_id', '=', 'ub.id')
            ->join('clasificaciones_abc_xyz as c',
                fn($j) => $j->on('c.producto_id', '=', 'uo.producto_id')
                             ->where('c.vigente', true))
            ->where('uo.empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('uo.sucursal_id', $user->sucursal_id)
            ->select(
                'p.codigo_interno', 'p.nombre',
                'c.segmento', 'c.clase_abc', 'c.clase_xyz',
                'ub.codigo as ubicacion', 'ub.zona', 'ub.pasillo',
                'uo.score_afinidad', 'uo.motivo_recomendacion', 'uo.created_at as vigente_desde'
            )
            ->orderBy('uo.score_afinidad', 'desc')
            ->get();

        $headers = ['Código Producto', 'Producto', 'Segmento', 'Clase ABC', 'Clase XYZ',
                    'Ubicación', 'Zona', 'Pasillo', 'Score Afinidad', 'Motivo', 'Fecha Sugerencia'];

        $rows = $items->map(fn($i) => [
            $i->codigo_interno, $i->nombre,
            $i->segmento ?? ($i->clase_abc . $i->clase_xyz), $i->clase_abc, $i->clase_xyz,
            $i->ubicacion, $i->zona, $i->pasillo,
            $i->score_afinidad ?? '—',
            $i->motivo_recomendacion ?? '—', $i->vigente_desde ?? '—',
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'slotting_' . date('Y-m-d'));
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    private function _buscarUbicacionDisponible($ubicacionesPorZona, string $zonaIdeal, array $asignadas): ?object
    {
        // Buscar primero en zona ideal, luego degradar
        $prioridades = [$zonaIdeal, 'plata', 'bronce', 'oro', 'frio'];

        foreach ($prioridades as $zona) {
            $candidatas = $ubicacionesPorZona->get($zona, collect());
            foreach ($candidatas as $ub) {
                if (!isset($asignadas[$ub->id])) {
                    return $ub;
                }
            }
        }
        return null;
    }

    private function _calcularScore($clasificacion, $ubicacion, string $zonaIdeal): float
    {
        $score = 5.0;

        // +2 si la zona de la ubicación coincide con la ideal
        $zonaUb = $ubicacion->zona ?? 'bronce';
        if ($zonaUb === $zonaIdeal) {
            $score += 2.0;
        } elseif ((self::ZONA_PRIORIDAD[$zonaUb] ?? 5) < (self::ZONA_PRIORIDAD[$zonaIdeal] ?? 5)) {
            $score += 0.5;
        } else {
            $score -= 1.0;
        }

        // +1 si tiene alta accesibilidad
        if (($ubicacion->accesibilidad ?? 3) >= 4) $score += 1.0;

        // +1 si está cerca del muelle (distancia < 20m)
        if (($ubicacion->distancia_muelle ?? 999) < 20) $score += 1.0;
        elseif (($ubicacion->distancia_muelle ?? 999) < 50) $score += 0.5;

        // -1 si el coeficiente de variación es alto (demanda errática = difícil planificar)
        // Columna real en clasificaciones_abc_xyz: cv_demanda (no coef_variacion)
        if (($clasificacion->cv_demanda ?? 0) > 1.0) $score -= 1.0;

        return round(min(max($score, 0), 10), 2);
    }

    // Firma corregida: recibe $empId y $sucId directamente (no $user ni $r)
    // Usa columnas reales: ubicacion_sugerida_id, score_afinidad, motivo_recomendacion
    private function _aplicarAsignacion(int $empId, int $sucId, int $productoId,
                                         int $ubicacionId, float $score, string $motivo): void
    {
        // Eliminar sugerencia anterior del mismo producto (no hay columna vigente)
        Capsule::table('ubicaciones_optimas')
            ->where('empresa_id',  $empId)
            ->where('sucursal_id', $sucId)
            ->where('producto_id', $productoId)
            ->delete();

        // Insertar nueva sugerencia usando columnas reales de la tabla
        Capsule::table('ubicaciones_optimas')->insert([
            'empresa_id'            => $empId,
            'sucursal_id'           => $sucId,
            'producto_id'           => $productoId,
            'ubicacion_sugerida_id' => $ubicacionId,
            'score_afinidad'        => $score,
            'motivo_recomendacion'  => $motivo,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

}
