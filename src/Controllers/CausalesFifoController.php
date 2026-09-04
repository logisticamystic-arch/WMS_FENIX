<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\CausalFifo;

/**
 * CausalesFifoController — motivos parametrizables para justificar una
 * separación fuera del orden FEFO sugerido (rotación). Tabla independiente
 * de causales_novedad a propósito: esa alimenta afecta_nivel_servicio y
 * reportes de faltantes de mercancía, algo distinto a un motivo de rotación
 * de ubicación.
 */
class CausalesFifoController extends BaseController
{
    /**
     * GET /api/causales-fifo?incluir_inactivas=1
     */
    public function index(Request $req, Response $res): Response
    {
        try {
            $user      = $req->getAttribute('user');
            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            if (!$empresaId) return $this->error($res, 'Empresa no identificada.', 400);
            $params    = $req->getQueryParams();

            $query = CausalFifo::where('empresa_id', $empresaId)->orderBy('nombre');

            if (empty($params['incluir_inactivas'])) {
                $query->where('activo', true);
            }

            return $this->ok($res, $query->get());
        } catch (\Throwable $e) {
            error_log('CausalesFifoController::index error: ' . $e->getMessage());
            return $this->error($res, 'Error al obtener causales FIFO: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/causales-fifo  (solo Admin/Supervisor)
     */
    public function store(Request $req, Response $res): Response
    {
        try {
            $user = $req->getAttribute('user');
            if ($deny = $this->requireSupervisor($user, $res)) return $deny;

            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $body      = $req->getParsedBody() ?? [];

            $nombre = trim($body['nombre'] ?? '');
            if ($nombre === '') {
                return $this->error($res, 'El campo nombre es requerido.', 400);
            }

            $causal = CausalFifo::create([
                'empresa_id' => $empresaId,
                'nombre'     => $nombre,
                'activo'     => isset($body['activo']) ? (bool)$body['activo'] : true,
            ]);

            return $this->created($res, $causal, 'Causal FIFO creada con éxito.');
        } catch (\Throwable $e) {
            error_log('CausalesFifoController::store error: ' . $e->getMessage());
            return $this->error($res, 'Error al crear causal FIFO: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/causales-fifo/{id}  (solo Admin/Supervisor)
     */
    public function update(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            if ($deny = $this->requireSupervisor($user, $res)) return $deny;

            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $causal = CausalFifo::where('id', (int)($args['id'] ?? 0))
                ->where('empresa_id', $empresaId)
                ->first();

            if (!$causal) return $this->notFound($res, 'Causal FIFO no encontrada.');

            $body = $req->getParsedBody() ?? [];

            if (isset($body['nombre'])) {
                $nombre = trim($body['nombre']);
                if ($nombre === '') return $this->error($res, 'El campo nombre no puede estar vacío.', 400);
                $causal->nombre = $nombre;
            }
            if (isset($body['activo'])) {
                $causal->activo = (bool)$body['activo'];
            }
            $causal->save();

            return $this->ok($res, $causal, 'Causal FIFO actualizada con éxito.');
        } catch (\Throwable $e) {
            error_log('CausalesFifoController::update error: ' . $e->getMessage());
            return $this->error($res, 'Error al actualizar causal FIFO: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/causales-fifo/{id} → soft delete (activo=false)  (solo Admin/Supervisor)
     */
    public function destroy(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            if ($deny = $this->requireSupervisor($user, $res)) return $deny;

            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $causal = CausalFifo::where('id', (int)($args['id'] ?? 0))
                ->where('empresa_id', $empresaId)
                ->first();

            if (!$causal) return $this->notFound($res, 'Causal FIFO no encontrada.');

            $causal->activo = false;
            $causal->save();

            return $this->ok($res, null, 'Causal FIFO desactivada con éxito.');
        } catch (\Throwable $e) {
            error_log('CausalesFifoController::destroy error: ' . $e->getMessage());
            return $this->error($res, 'Error al desactivar causal FIFO: ' . $e->getMessage(), 500);
        }
    }
}
