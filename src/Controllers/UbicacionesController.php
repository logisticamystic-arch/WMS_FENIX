<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * UbicacionesController — Gestión del mapa físico del almacén.
 *
 * Endpoints:
 *  GET    /api/ubicaciones                  → Listar todas las ubicaciones
 *  POST   /api/ubicaciones                  → Crear ubicación
 *  GET    /api/ubicaciones/{id}             → Detalle de ubicación
 *  PUT    /api/ubicaciones/{id}             → Actualizar ubicación
 *  DELETE /api/ubicaciones/{id}             → Desactivar ubicación
 *  POST   /api/ubicaciones/importar         → Importar desde CSV (matriz pasillo/estantería)
 *  GET    /api/ubicaciones/disponibles      → Solo ubicaciones libres para slotting
 *  PUT    /api/ubicaciones/{id}/estado      → Cambiar estado (bloquear/liberar)
 *  GET    /api/ubicaciones/ocupacion        → Reporte de ocupación por zona/pasillo
 *  GET    /api/ubicaciones/export           → Exportar CSV
 */
class UbicacionesController extends BaseController
{
    // ── GET /api/ubicaciones ──────────────────────────────────────────────────
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 200), 1000);

        $q = Capsule::table('ubicaciones as ub')
            ->where('ub.empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('ub.sucursal_id', $this->getEffectiveSucursalId($user, $r))
            ->where('ub.activa', true);

        if (!empty($params['zona']))   $q->where('ub.zona',   $params['zona']);
        if (!empty($params['estado'])) $q->where('ub.estado', $params['estado']);
        if (!empty($params['pasillo'])) $q->where('ub.pasillo', $params['pasillo']);
        if (!empty($params['tipo']))   $q->where('ub.tipo_ubicacion', $params['tipo']);

        if (!empty($params['q'])) {
            $term = '%' . $params['q'] . '%';
            $q->where('ub.codigo', 'like', $term);
        }

        // Enriquecer con producto asignado (desde slotting)
        $q->leftJoin('ubicaciones_optimas as uo',
                fn($j) => $j->on('uo.ubicacion_id', '=', 'ub.id')->where('uo.vigente', true))
          ->leftJoin('productos as p', 'uo.producto_id', '=', 'p.id')
          ->select(
              'ub.*',
              'p.nombre as producto_asignado',
              'p.codigo_interno as codigo_producto',
              'uo.segmento'
          );

        $total = (clone $q)->count();
        $items = $q->orderBy('ub.pasillo')
                   ->orderBy('ub.estanteria')
                   ->orderBy('ub.nivel')
                   ->limit($limit)
                   ->offset((int)($params['offset'] ?? 0))
                   ->get();

        return $this->ok($res, ['ubicaciones' => $items, 'total' => $total]);
    }

    // ── GET /api/ubicaciones/{id} ─────────────────────────────────────────────
    public function show(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $ub   = Capsule::table('ubicaciones')
            ->where('id',          $a['id'])
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->first();

        if (!$ub) return $this->notFound($res);

        // Historial de movimientos en esta ubicación (últimos 50)
        $movimientos = Capsule::table('movimientos_inventario as mv')
            ->join('productos as p', 'mv.producto_id', '=', 'p.id')
            ->where('mv.ubicacion_id', $a['id'])
            ->select('mv.*', 'p.nombre as producto', 'p.codigo_interno as codigo')
            ->orderBy('mv.created_at', 'desc')
            ->limit(50)
            ->get();

        // Inventario actual en esta ubicación
        $inventario = Capsule::table('inventarios as inv')
            ->join('productos as p', 'inv.producto_id', '=', 'p.id')
            ->where('inv.ubicacion_id', $a['id'])
            ->where('inv.cantidad', '>', 0)
            ->select('inv.*', 'p.nombre as producto', 'p.codigo_interno as codigo')
            ->get();

        return $this->ok($res, [
            'ubicacion'   => $ub,
            'inventario'  => $inventario,
            'movimientos' => $movimientos,
        ]);
    }

    // ── POST /api/ubicaciones ─────────────────────────────────────────────────
    public function crear(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $body = (array)($r->getParsedBody() ?? []);
        if ($deny = $this->requireFields($body, ['codigo', 'pasillo', 'estanteria', 'nivel', 'posicion'], $res)) {
            return $deny;
        }

        // Verificar unicidad del código
        $existe = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('codigo',      $body['codigo'])
            ->exists();

        if ($existe) {
            return $this->error($res, "Ya existe una ubicación con código {$body['codigo']}");
        }

        $id = Capsule::table('ubicaciones')->insertGetId([
            'empresa_id'      => $this->getEffectiveEmpresaId($user, $r),
            'sucursal_id'     => $user->sucursal_id,
            'codigo'          => $body['codigo'],
            'pasillo'         => $body['pasillo'],
            'estanteria'      => (int)$body['estanteria'],
            'nivel'           => (int)$body['nivel'],
            'posicion'        => (int)$body['posicion'],
            'capacidad_kg'    => $body['capacidad_kg']   ?? null,
            'capacidad_m3'    => $body['capacidad_m3']   ?? null,
            'capacidad_unid'  => $body['capacidad_unid'] ?? null,
            'zona'            => $body['zona']            ?? 'bronce',
            'distancia_muelle'=> $body['distancia_muelle'] ?? null,
            'accesibilidad'   => $body['accesibilidad']  ?? 3,
            'tipo_ubicacion'  => $body['tipo_ubicacion'] ?? 'Picking',
            'estado'          => 'Libre',
            'activo'          => true,
            'activa'          => true,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->audit($user, 'ubicaciones', 'crear', 'ubicaciones', $id,
            null, $body, "Ubicación {$body['codigo']} creada");

        return $this->created($res, ['id' => $id], 'Ubicación creada');
    }

    // ── PUT /api/ubicaciones/{id} ─────────────────────────────────────────────
    public function actualizar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $body = (array)($r->getParsedBody() ?? []);

        $campos = array_filter([
            'zona'             => $body['zona']             ?? null,
            'accesibilidad'    => isset($body['accesibilidad']) ? (int)$body['accesibilidad'] : null,
            'capacidad_kg'     => $body['capacidad_kg']     ?? null,
            'capacidad_m3'     => $body['capacidad_m3']     ?? null,
            'distancia_muelle' => $body['distancia_muelle'] ?? null,
            'tipo_ubicacion'   => $body['tipo_ubicacion']   ?? null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], fn($v) => $v !== null);

        Capsule::table('ubicaciones')
            ->where('id',         $a['id'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->update($campos);

        $this->audit($user, 'ubicaciones', 'actualizar', 'ubicaciones', $a['id']);

        return $this->ok($res, null, 'Ubicación actualizada');
    }

    // ── PUT /api/ubicaciones/{id}/estado ──────────────────────────────────────
    public function cambiarEstado(Request $r, Response $res, array $a): Response
    {
        $user    = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $body    = (array)($r->getParsedBody() ?? []);
        $estados = ['Libre', 'Ocupada', 'Parcial', 'Locked'];

        if (!in_array($body['estado'] ?? '', $estados)) {
            return $this->error($res, 'Estado inválido. Válidos: ' . implode(', ', $estados));
        }

        Capsule::table('ubicaciones')
            ->where('id',         $a['id'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->update(['estado' => $body['estado'], 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit($user, 'ubicaciones', 'estado', 'ubicaciones', $a['id'],
            null, ['estado' => $body['estado']]);

        return $this->ok($res, null, "Estado actualizado a {$body['estado']}");
    }

    // ── DELETE /api/ubicaciones/{id} ──────────────────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        // Verificar que no tiene inventario activo
        $tieneInventario = Capsule::table('inventarios')
            ->where('ubicacion_id', $a['id'])
            ->where('cantidad', '>', 0)
            ->exists();

        if ($tieneInventario) {
            return $this->error($res, 'No se puede eliminar: la ubicación tiene inventario activo');
        }

        // Desactivación lógica
        Capsule::table('ubicaciones')
            ->where('id',         $a['id'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->update(['activa' => false, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit($user, 'ubicaciones', 'eliminar', 'ubicaciones', $a['id']);

        return $this->ok($res, null, 'Ubicación desactivada');
    }

    // ── GET /api/ubicaciones/disponibles ─────────────────────────────────────
    public function disponibles(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $items = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activa', true)
            ->where('estado', 'Disponible')
            ->when(!empty($params['zona']), fn($q) => $q->where('zona', $params['zona']))
            ->when(!empty($params['tipo']), fn($q) => $q->where('tipo_ubicacion', $params['tipo']))
            ->orderBy('distancia_muelle', 'asc')
            ->orderBy('accesibilidad', 'desc')
            ->get(['id', 'codigo', 'zona', 'pasillo', 'nivel', 'tipo_ubicacion',
                   'capacidad_kg', 'ocupacion_pct', 'distancia_muelle', 'accesibilidad']);

        return $this->ok($res, ['ubicaciones' => $items]);
    }

    // ── GET /api/ubicaciones/ocupacion ────────────────────────────────────────
    public function ocupacion(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $porZona = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activa', true)
            ->selectRaw("
                zona,
                COUNT(*) AS total,
                COUNT(CASE WHEN estado = 'Libre'   THEN 1 END) AS disponibles,
                COUNT(CASE WHEN estado = 'Ocupada' THEN 1 END) AS ocupadas,
                COUNT(CASE WHEN estado = 'Locked'  THEN 1 END) AS bloqueadas,
                ROUND(AVG(ocupacion_pct), 2)                      AS ocupacion_promedio,
                ROUND(SUM(capacidad_m3), 2)                       AS capacidad_m3_total
            ")
            ->groupBy('zona')
            ->orderByRaw("CASE zona WHEN 'oro' THEN 1 WHEN 'plata' THEN 2 WHEN 'bronce' THEN 3 ELSE 4 END")
            ->get();

        $porPasillo = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activa', true)
            ->selectRaw("
                pasillo,
                COUNT(*) AS total_ubicaciones,
                ROUND(AVG(ocupacion_pct), 2) AS ocupacion_promedio
            ")
            ->groupBy('pasillo')
            ->orderBy('pasillo')
            ->get();

        return $this->ok($res, ['por_zona' => $porZona, 'por_pasillo' => $porPasillo]);
    }

    // ── GET /api/ubicaciones/export ───────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $items = Capsule::table('ubicaciones')
            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activa', true)
            ->orderBy('pasillo')->orderBy('estanteria')->orderBy('nivel')
            ->get();

        $headers = ['Código', 'Pasillo', 'Estantería', 'Nivel', 'Posición',
                    'Zona', 'Tipo', 'Estado', 'Capacidad Kg', 'Capacidad M3',
                    'Distancia Muelle (m)', 'Accesibilidad (1-5)', 'Ocupación %'];

        $rows = $items->map(fn($u) => [
            $u->codigo, $u->pasillo, $u->estanteria, $u->nivel, $u->posicion,
            $u->zona, $u->tipo_ubicacion, $u->estado,
            $u->capacidad_kg ?? '—', $u->capacidad_m3 ?? '—',
            $u->distancia_muelle ?? '—', $u->accesibilidad, $u->ocupacion_pct,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'ubicaciones_' . date('Y-m-d'));
    }

    // ── POST /api/ubicaciones/importar ────────────────────────────────────────
    // Importa ubicaciones en bulk desde JSON. Formato:
    // { "pasillos": ["A","B"], "estanterias": 10, "niveles": 5, "posiciones": 2, "zona": "bronce" }
    public function importar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $body = (array)($r->getParsedBody() ?? []);
        if ($deny = $this->requireFields($body, ['pasillos', 'estanterias', 'niveles'], $res)) {
            return $deny;
        }

        $pasillos    = (array)$body['pasillos'];
        $estanterias = (int)$body['estanterias'];
        $niveles     = (int)$body['niveles'];
        $posiciones  = (int)($body['posiciones'] ?? 1);
        $zona        = $body['zona'] ?? 'bronce';
        $tipo        = $body['tipo'] ?? 'Picking';

        $creadas = 0;
        $ahora   = date('Y-m-d H:i:s');

        foreach ($pasillos as $pasillo) {
            for ($est = 1; $est <= $estanterias; $est++) {
                for ($niv = 1; $niv <= $niveles; $niv++) {
                    for ($pos = 1; $pos <= $posiciones; $pos++) {
                        $codigo = sprintf('%s/%02d-%02d-%02d', strtoupper($zona), $est, $niv, $pos);

                        $existe = Capsule::table('ubicaciones')
                            ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
                            ->where('sucursal_id', $user->sucursal_id)
                            ->where('codigo', $codigo)
                            ->exists();

                        if ($existe) continue;

                        Capsule::table('ubicaciones')->insert([
                            'empresa_id'     => $this->getEffectiveEmpresaId($user, $r),
                            'sucursal_id'    => $user->sucursal_id,
                            'codigo'         => $codigo,
                            'pasillo'        => strtoupper($pasillo),
                            'estanteria'     => $est,
                            'nivel'          => $niv,
                            'posicion'       => $pos,
                            'zona'           => $zona,
                            'tipo_ubicacion' => $tipo,
                            'estado'         => 'Disponible',
                            'activa'         => true,
                            'created_at'     => $ahora,
                            'updated_at'     => $ahora,
                        ]);
                        $creadas++;
                    }
                }
            }
        }

        $this->audit($user, 'ubicaciones', 'importar', 'ubicaciones', null,
            null, ['creadas' => $creadas], "Importación: {$creadas} ubicaciones creadas");

        return $this->created($res, ['creadas' => $creadas],
            "{$creadas} ubicaciones creadas");
    }

}
