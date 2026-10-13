<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ProgramacionController — Horarios mensuales de personal (CDP).
 *
 * Tres entidades editables (filas, tareas, asignaciones) + un cálculo de
 * festivos de Colombia que vive solo en el servidor (no se guarda, siempre
 * se deriva con easter_days() de ext/calendar).
 */
class ProgramacionController extends BaseController
{
    // ── FILAS (TURNO-1, RUTA-1…) ──────────────────────────────────────────

    public function filasIndex(Request $req, Response $res): Response
    {
        $empresaId = $this->getEffectiveEmpresaId($req->getAttribute('user'), $req);
        if (!$empresaId) return $this->error($res, 'Empresa no identificada.');
        $filas = DB::table('prog_filas')->where('empresa_id', $empresaId)
            ->orderBy('orden')->orderBy('codigo')->get();
        return $this->ok($res, $filas);
    }

    public function filasSave(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $req);
        $b = $this->body($req);
        $codigo = trim($b['codigo'] ?? '');
        if ($codigo === '') return $this->error($res, 'Código requerido.');

        $data = [
            'empresa_id'  => $empresaId,
            'codigo'      => mb_strtoupper($codigo),
            'nombre'      => trim($b['nombre'] ?? '') ?: null,
            'hora_inicio' => $b['hora_inicio'] ?: null,
            'hora_fin'    => $b['hora_fin'] ?: null,
            'slots'       => max(1, (int)($b['slots'] ?? 1)),
            'orden'       => (int)($b['orden'] ?? 0),
            'activo'      => !empty($b['activo']) || !isset($b['activo']),
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if (!empty($b['id'])) {
            DB::table('prog_filas')->where('id', $b['id'])->where('empresa_id', $empresaId)->update($data);
            return $this->ok($res, ['id' => (int)$b['id']], 'Fila actualizada');
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = DB::table('prog_filas')->insertGetId($data);
        return $this->created($res, ['id' => $id]);
    }

    public function filasDelete(Request $req, Response $res, array $args): Response
    {
        if ($deny = $this->requireSupervisor($req->getAttribute('user'), $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($req->getAttribute('user'), $req);
        DB::table('prog_filas')->where('id', (int)$args['id'])->where('empresa_id', $empresaId)->delete();
        return $this->ok($res, null, 'Fila eliminada');
    }

    // ── TAREAS (REC, DVO, INV…) ───────────────────────────────────────────

    public function tareasIndex(Request $req, Response $res): Response
    {
        $empresaId = $this->getEffectiveEmpresaId($req->getAttribute('user'), $req);
        if (!$empresaId) return $this->error($res, 'Empresa no identificada.');
        $tareas = DB::table('prog_tareas')->where('empresa_id', $empresaId)
            ->orderBy('orden')->orderBy('codigo')->get();
        return $this->ok($res, $tareas);
    }

    public function tareasSave(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $req);
        $b = $this->body($req);
        $codigo = trim($b['codigo'] ?? '');
        if ($codigo === '') return $this->error($res, 'Código requerido.');
        $data = [
            'empresa_id' => $empresaId,
            'codigo'     => mb_strtoupper($codigo),
            'nombre'     => trim($b['nombre'] ?? '') ?: $codigo,
            'color'      => $b['color'] ?: '#fde2e4',
            'orden'      => (int)($b['orden'] ?? 0),
            'activo'     => !empty($b['activo']) || !isset($b['activo']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($b['id'])) {
            DB::table('prog_tareas')->where('id', $b['id'])->where('empresa_id', $empresaId)->update($data);
            return $this->ok($res, ['id' => (int)$b['id']], 'Tarea actualizada');
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = DB::table('prog_tareas')->insertGetId($data);
        return $this->created($res, ['id' => $id]);
    }

    public function tareasDelete(Request $req, Response $res, array $args): Response
    {
        if ($deny = $this->requireSupervisor($req->getAttribute('user'), $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($req->getAttribute('user'), $req);
        DB::table('prog_tareas')->where('id', (int)$args['id'])->where('empresa_id', $empresaId)->delete();
        return $this->ok($res, null, 'Tarea eliminada');
    }

    // ── PERSONAL (reutiliza tabla `personal`) ─────────────────────────────

    public function personalIndex(Request $req, Response $res): Response
    {
        $empresaId = $this->getEffectiveEmpresaId($req->getAttribute('user'), $req);
        $personal = DB::table('personal')->where('empresa_id', $empresaId)
            ->orderBy('activo', 'desc')->orderBy('nombre')
            ->get(['id', 'nombre', 'documento', 'rol', 'activo']);
        return $this->ok($res, $personal);
    }

    // ── FESTIVOS COLOMBIA (Ley Emiliani + Pascua) ─────────────────────────

    /**
     * GET /api/programacion/festivos?anio=2026
     *
     * Devuelve array ["YYYY-MM-DD" => "Nombre"] del año solicitado. Se
     * calcula en runtime con easter_days(); si falta ext/calendar (poco
     * probable en XAMPP), se devuelven solo los fijos y los trasladables
     * fijos — los basados en Pascua quedan por fuera y el frontend muestra
     * un aviso: pedirle al admin habilitar la extensión.
     */
    public function festivos(Request $req, Response $res): Response
    {
        $anio = (int)($req->getQueryParams()['anio'] ?? date('Y'));
        if ($anio < 2000 || $anio > 2100) $anio = (int)date('Y');
        return $this->ok($res, self::festivosColombia($anio));
    }

    public static function festivosColombia(int $anio): array
    {
        $f = [];
        $set = function (string $fecha, string $nombre) use (&$f) { $f[$fecha] = $nombre; };

        // Fijos (no se trasladan)
        $set(sprintf('%04d-01-01', $anio), 'Año Nuevo');
        $set(sprintf('%04d-05-01', $anio), 'Día del Trabajo');
        $set(sprintf('%04d-07-20', $anio), 'Independencia');
        $set(sprintf('%04d-08-07', $anio), 'Batalla de Boyacá');
        $set(sprintf('%04d-12-08', $anio), 'Inmaculada Concepción');
        $set(sprintf('%04d-12-25', $anio), 'Navidad');

        // Ley Emiliani (se mueven al siguiente lunes). Fecha "oficial":
        $emilianiFijos = [
            ['01-06', 'Reyes Magos'],
            ['03-19', 'San José'],
            ['06-29', 'San Pedro y San Pablo'],
            ['08-15', 'Asunción de la Virgen'],
            ['10-12', 'Día de la Raza'],
            ['11-01', 'Todos los Santos'],
            ['11-11', 'Independencia de Cartagena'],
        ];
        foreach ($emilianiFijos as [$md, $nom]) {
            $set(self::proximoLunes("$anio-$md"), $nom);
        }

        // Pascua — necesita ext/calendar (viene con XAMPP por defecto)
        if (function_exists('easter_days')) {
            $pascua = new \DateTime("$anio-03-21");
            $pascua->modify('+' . easter_days($anio) . ' days');
            $plus = fn(int $d) => (clone $pascua)->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
            $set($plus(-3), 'Jueves Santo');
            $set($plus(-2), 'Viernes Santo');
            $set(self::proximoLunes($plus(39)), 'Ascensión del Señor');
            $set(self::proximoLunes($plus(60)), 'Corpus Christi');
            $set(self::proximoLunes($plus(68)), 'Sagrado Corazón');
        }

        ksort($f);
        return $f;
    }

    private static function proximoLunes(string $fecha): string
    {
        $d = new \DateTime($fecha);
        while ((int)$d->format('N') !== 1) {
            $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }

    // ── MATRIZ MENSUAL ────────────────────────────────────────────────────

    /**
     * GET /api/programacion/matriz?anio=2026&mes=10
     *
     * Devuelve { filas, tareas, personal, dias:[{dia,dow,festivo}], celdas:{"dia-fila-slot": {personal_id, tarea_id, descansa}} }.
     * Una sola llamada = pintar grilla completa.
     */
    public function matriz(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $req);
        if (!$empresaId) return $this->error($res, 'Empresa no identificada.');

        $qp   = $req->getQueryParams();
        $anio = (int)($qp['anio'] ?? date('Y'));
        $mes  = (int)($qp['mes']  ?? date('n'));
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            return $this->error($res, 'Parámetros anio/mes inválidos.');
        }

        $diasEnMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $festivos  = self::festivosColombia($anio);

        $dias = [];
        for ($d = 1; $d <= $diasEnMes; $d++) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
            $dow   = (int)date('N', strtotime($fecha)); // 1=Lun..7=Dom
            $dias[] = [
                'dia'     => $d,
                'fecha'   => $fecha,
                'dow'     => $dow,
                'festivo' => $festivos[$fecha] ?? null,
            ];
        }

        // Asegurar fila especial DESCANSO (panel lateral del calendario).
        // Se crea on-demand para que empresas nuevas la hereden sin migración extra.
        $tieneDescanso = DB::table('prog_filas')
            ->where('empresa_id', $empresaId)->where('codigo', 'DESCANSO')->exists();
        if (!$tieneDescanso) {
            DB::table('prog_filas')->insert([
                'empresa_id' => $empresaId, 'codigo' => 'DESCANSO', 'nombre' => 'Descansos del día',
                'slots' => 20, 'orden' => 999, 'activo' => true,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $filas    = DB::table('prog_filas')->where('empresa_id', $empresaId)->where('activo', true)
                       ->orderBy('orden')->orderBy('codigo')->get();
        $tareas   = DB::table('prog_tareas')->where('empresa_id', $empresaId)->where('activo', true)
                       ->orderBy('orden')->orderBy('codigo')->get();
        $personal = DB::table('personal')->where('empresa_id', $empresaId)->where('activo', true)
                       ->orderBy('nombre')->get(['id', 'nombre']);
        $asigs    = DB::table('prog_asignaciones')
                       ->where('empresa_id', $empresaId)
                       ->where('anio', $anio)->where('mes', $mes)->get();

        $celdas = [];
        foreach ($asigs as $a) {
            $celdas["{$a->dia}-{$a->fila_id}-{$a->slot}"] = [
                'personal_id' => $a->personal_id,
                'tarea_id'    => $a->tarea_id,
                'descansa'    => (bool)$a->descansa,
                'nota'        => $a->nota,
            ];
        }

        return $this->ok($res, [
            'anio'     => $anio,
            'mes'      => $mes,
            'dias'     => $dias,
            'filas'    => $filas,
            'tareas'   => $tareas,
            'personal' => $personal,
            'celdas'   => (object)$celdas,
            'festivos' => $festivos,
        ]);
    }

    /**
     * POST /api/programacion/matriz
     *
     * Body: { anio, mes, celdas: [ {dia, fila_id, slot, personal_id|null, tarea_id|null, descansa, nota} ] }
     * Upsert por (empresa, anio, mes, dia, fila, slot). Si personal_id Y tarea_id Y descansa quedan vacíos,
     * la celda se borra (así una celda limpia no ocupa espacio).
     */
    public function guardarMatriz(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $req);
        $b = $this->body($req);
        $anio = (int)($b['anio'] ?? 0);
        $mes  = (int)($b['mes'] ?? 0);
        $celdas = $b['celdas'] ?? [];
        if ($mes < 1 || $mes > 12 || $anio < 2000 || !is_array($celdas)) {
            return $this->error($res, 'Datos inválidos.');
        }

        DB::connection()->transaction(function () use ($empresaId, $anio, $mes, $celdas) {
            foreach ($celdas as $c) {
                $dia  = (int)($c['dia'] ?? 0);
                $fid  = (int)($c['fila_id'] ?? 0);
                $slot = (int)($c['slot'] ?? 0);
                if ($dia < 1 || $dia > 31 || $fid < 1) continue;
                $personalId = !empty($c['personal_id']) ? (int)$c['personal_id'] : null;
                $tareaId    = !empty($c['tarea_id'])    ? (int)$c['tarea_id']    : null;
                $descansa   = !empty($c['descansa']);
                $nota       = trim((string)($c['nota'] ?? '')) ?: null;

                $vacia = $personalId === null && $tareaId === null && !$descansa && !$nota;
                $where = [
                    'empresa_id' => $empresaId,
                    'anio' => $anio, 'mes' => $mes, 'dia' => $dia,
                    'fila_id' => $fid, 'slot' => $slot,
                ];
                if ($vacia) {
                    DB::table('prog_asignaciones')->where($where)->delete();
                    continue;
                }
                $existe = DB::table('prog_asignaciones')->where($where)->first();
                $data = $where + [
                    'personal_id' => $personalId,
                    'tarea_id'    => $tareaId,
                    'descansa'    => $descansa,
                    'nota'        => $nota,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ];
                if ($existe) {
                    DB::table('prog_asignaciones')->where('id', $existe->id)->update($data);
                } else {
                    $data['created_at'] = date('Y-m-d H:i:s');
                    DB::table('prog_asignaciones')->insert($data);
                }
            }
        });

        return $this->ok($res, null, 'Programación guardada');
    }

    private function body(Request $req): array
    {
        $b = $req->getParsedBody();
        if (empty($b)) {
            $raw = (string)$req->getBody();
            $b = json_decode($raw, true) ?: [];
        }
        return is_array($b) ? $b : [];
    }
}
