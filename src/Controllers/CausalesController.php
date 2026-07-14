<?php

/*
 * ============================================================
 * MIGRACIÓN — ejecutar en PostgreSQL antes de usar este módulo
 * ============================================================
 *
 * -- 1. Tabla de causales de novedad
 * CREATE TABLE IF NOT EXISTS causales_novedad (
 *     id                     SERIAL PRIMARY KEY,
 *     empresa_id             INTEGER NOT NULL,
 *     nombre                 VARCHAR(150) NOT NULL,
 *     area_responsable       VARCHAR(50)  NOT NULL DEFAULT 'Otro',
 *     afecta_nivel_servicio  BOOLEAN      NOT NULL DEFAULT FALSE,
 *     activo                 BOOLEAN      NOT NULL DEFAULT TRUE,
 *     created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
 *     updated_at             TIMESTAMP    NOT NULL DEFAULT NOW()
 * );
 * CREATE INDEX IF NOT EXISTS idx_causales_novedad_empresa ON causales_novedad (empresa_id);
 *
 * -- 2. Columna causal_id en picking_faltantes (nullable para retrocompatibilidad)
 * ALTER TABLE picking_faltantes
 *     ADD COLUMN IF NOT EXISTS causal_id INTEGER REFERENCES causales_novedad(id) ON DELETE SET NULL;
 * CREATE INDEX IF NOT EXISTS idx_picking_faltantes_causal ON picking_faltantes (causal_id);
 * ============================================================
 */

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\CausalNovedad;

class CausalesController extends BaseController
{
    private const AREAS_PERMITIDAS = ['CDP', 'Logistica', 'Comercial', 'Operaciones', 'Otro'];

    /**
     * GET /api/causales-novedad?incluir_inactivas=1
     */
    public function index(Request $req, Response $res): Response
    {
        try {
            $user      = $req->getAttribute('user');
            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            if (!$empresaId) return $this->error($res, 'Empresa no identificada.', 400);
            $params    = $req->getQueryParams();

            $query = CausalNovedad::where('empresa_id', $empresaId)
                ->orderBy('area_responsable')
                ->orderBy('nombre');

            if (empty($params['incluir_inactivas'])) {
                $query->where('activo', true);
            }

            return $this->ok($res, $query->get());
        } catch (\Throwable $e) {
            error_log('CausalesController::index error: ' . $e->getMessage());
            return $this->error($res, 'Error al obtener causales: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/causales-novedad  (solo Admin/Supervisor)
     */
    public function store(Request $req, Response $res): Response
    {
        try {
            $user = $req->getAttribute('user');
            if (!$this->isAdminOrSupervisor($user)) {
                return $this->forbidden($res);
            }

            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $body      = $req->getParsedBody();
            if (empty($body)) {
                $raw  = (string) $req->getBody();
                $body = json_decode($raw, true) ?? [];
            }

            $nombre = trim($body['nombre'] ?? '');
            if ($nombre === '') {
                return $this->error($res, 'El campo nombre es requerido.', 400);
            }

            $area = $body['area_responsable'] ?? 'Otro';
            if (!in_array($area, self::AREAS_PERMITIDAS, true)) {
                return $this->error($res, 'area_responsable inválida. Valores permitidos: ' . implode(', ', self::AREAS_PERMITIDAS), 400);
            }

            $afecta = in_array($area, ['CDP', 'Logistica'], true);
            $activo = isset($body['activo']) ? (bool)$body['activo'] : true;

            $causal = CausalNovedad::create([
                'empresa_id'            => $empresaId,
                'nombre'                => $nombre,
                'area_responsable'      => $area,
                'afecta_nivel_servicio' => $afecta,
                'activo'                => $activo,
            ]);

            return $this->created($res, $causal, 'Causal creada con éxito.');
        } catch (\Throwable $e) {
            error_log('CausalesController::store error: ' . $e->getMessage());
            return $this->error($res, 'Error al crear causal: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/causales-novedad/{id}  (solo Admin/Supervisor)
     */
    public function update(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            if (!$this->isAdminOrSupervisor($user)) {
                return $this->forbidden($res);
            }

            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $id        = (int)($args['id'] ?? 0);

            $causal = CausalNovedad::where('id', $id)
                ->where('empresa_id', $empresaId)
                ->first();

            if (!$causal) {
                return $this->notFound($res, 'Causal no encontrada.');
            }

            $body = $req->getParsedBody();
            if (empty($body)) {
                $raw  = (string) $req->getBody();
                $body = json_decode($raw, true) ?? [];
            }

            if (isset($body['nombre'])) {
                $nombre = trim($body['nombre']);
                if ($nombre === '') {
                    return $this->error($res, 'El campo nombre no puede estar vacío.', 400);
                }
                $causal->nombre = $nombre;
            }

            if (isset($body['area_responsable'])) {
                $area = $body['area_responsable'];
                if (!in_array($area, self::AREAS_PERMITIDAS, true)) {
                    return $this->error($res, 'area_responsable inválida. Valores permitidos: ' . implode(', ', self::AREAS_PERMITIDAS), 400);
                }
                $causal->area_responsable     = $area;
                $causal->afecta_nivel_servicio = in_array($area, ['CDP', 'Logistica'], true);
            }

            if (isset($body['activo'])) {
                $causal->activo = (bool)$body['activo'];
            }

            $causal->save();

            return $this->ok($res, $causal, 'Causal actualizada con éxito.');
        } catch (\Throwable $e) {
            error_log('CausalesController::update error: ' . $e->getMessage());
            return $this->error($res, 'Error al actualizar causal: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/causales-novedad/{id}  → soft delete (activo = false)  (solo Admin/Supervisor)
     */
    public function destroy(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            if (!$this->isAdminOrSupervisor($user)) {
                return $this->forbidden($res);
            }

            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $id        = (int)($args['id'] ?? 0);

            $causal = CausalNovedad::where('id', $id)
                ->where('empresa_id', $empresaId)
                ->first();

            if (!$causal) {
                return $this->notFound($res, 'Causal no encontrada.');
            }

            $causal->activo = false;
            $causal->save();

            return $this->ok($res, null, 'Causal desactivada con éxito.');
        } catch (\Throwable $e) {
            error_log('CausalesController::destroy error: ' . $e->getMessage());
            return $this->error($res, 'Error al desactivar causal: ' . $e->getMessage(), 500);
        }
    }

    // ── Helper privado ──────────────────────────────────────────────────────────

    private function isAdminOrSupervisor($user): bool
    {
        return $this->isSupervisorOrAbove($user);
    }
}
