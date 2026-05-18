<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Cita;
use Illuminate\Database\Capsule\Manager as Capsule;

class CitaController extends BaseController
{
    /**
     * GET /api/citas
     * Lista citas programadas de la sucursal actual
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $citas = Cita::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->orderBy('fecha', 'asc')
            ->orderBy('hora_programada', 'asc')
            ->get();

        return $this->json($response, [
            'error' => false,
            'data' => $citas
        ]);
    }

    /**
     * POST /api/citas
     * Crear nueva cita
     */
    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        // Todos los roles autenticados pueden programar citas

        $data = $request->getParsedBody();

        // Validaciones básicas
        if (empty($data['proveedor']) || empty($data['fecha']) || empty($data['hora_programada'])) {
            return $this->json($response, ['error' => true, 'message' => 'Proveedor, Fecha y Hora son requeridos.'], 400);
        }

        try {
            // Validar disponibilidad de horario (máximo 2 citas por hora)
            $maxCitasPorHora = 2;
            $ocupadas = Cita::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('fecha', $data['fecha'])
                ->where('hora_programada', $data['hora_programada'])
                ->whereIn('estado', ['Programada', 'EnPatio', 'EnCurso'])
                ->count();

            if ($ocupadas >= $maxCitasPorHora) {
                return $this->json($response, [
                    'error' => true, 
                    'message' => "El horario {$data['hora_programada']} ya está completo para esta fecha ({$maxCitasPorHora} citas máx)."
                ], 400);
            }

            // Resolver nombre del proveedor: acepta nombre libre o proveedor_id
            $nombreProv = trim($data['proveedor'] ?? '');
            if (!empty($data['proveedor_id'])) {
                $prov = \App\Models\Proveedor::where('empresa_id', $empresaId)
                    ->find((int)$data['proveedor_id']);
                if ($prov) $nombreProv = $prov->razon_social;
            }
            if (empty($nombreProv)) {
                return $this->json($response, ['error' => true, 'message' => 'Proveedor es requerido.'], 400);
            }

            $cita = new Cita();
            $cita->empresa_id     = $empresaId;
            $cita->sucursal_id    = $sucursalId;
            $cita->proveedor      = $nombreProv;
            $cita->fecha          = $data['fecha'];
            $cita->hora_programada= $data['hora_programada'];
            $cita->cantidad_cajas = (int)($data['cantidad_cajas'] ?? 0);
            $cita->tipo_vehiculo  = $data['tipo_vehiculo'] ?? null;
            $cita->kilos          = $data['kilos'] ?? 0;
            $cita->odc            = $data['odc'] ?? null;
            $cita->odc_id         = (int)($data['odc_id'] ?? null) ?: null;
            $cita->estado         = 'Programada';
            $cita->notas          = $data['notas'] ?? null;
            $cita->save();

            return $this->json($response, [
                'error'   => false,
                'message' => 'Cita programada correctamente.',
                'data'    => $cita
            ], 201);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al guardar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/citas/{id}
     * Actualizar cita
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        // Supervisor/Admin o el auxiliar responsable puede editar

        $cita = Cita::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($id);
        if (!$cita) {
            return $this->json($response, ['error' => true, 'message' => 'Cita no encontrada.'], 404);
        }

        $data = $request->getParsedBody();

        if (isset($data['proveedor'])) $cita->proveedor = $data['proveedor'];
        if (isset($data['fecha'])) $cita->fecha = $data['fecha'];
        if (isset($data['hora_programada'])) $cita->hora_programada = $data['hora_programada'];
        if (isset($data['cantidad_cajas'])) $cita->cantidad_cajas = $data['cantidad_cajas'];
        if (isset($data['tipo_vehiculo'])) $cita->tipo_vehiculo = $data['tipo_vehiculo'];
        if (isset($data['kilos'])) $cita->kilos = $data['kilos'];
        if (isset($data['odc'])) $cita->odc = $data['odc'];
        if (isset($data['estado'])) $cita->estado = $data['estado'];
        if (isset($data['notas'])) $cita->notas = $data['notas'];

        $cita->save();

        return $this->json($response, [
            'error' => false,
            'message' => 'Cita actualizada.',
            'data' => $cita
        ]);
    }

    /**
     * DELETE /api/citas/{id}
     * Cancelar cita (Soft drop/estado)
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        // Solo supervisores pueden cancelar citas
        $rol = strtolower($user->rol ?? '');
        if (!in_array($rol, ['admin', 'supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Se requiere rol Supervisor o Administrador'], 403);
        }

        $cita = Cita::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($id);
        if (!$cita) {
            return $this->json($response, ['error' => true, 'message' => 'Cita no encontrada.'], 404);
        }

        $cita->estado = 'Cancelada';
        $cita->save();

        return $this->json($response, [
            'error' => false,
            'message' => 'Cita cancelada correctamente.'
        ]);
    }

    /**
     * GET /api/citas/disponibilidad
     * Retorna las horas ocupadas/disponibles para una fecha dada
     */
    public function getDisponibilidad(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $fecha = $params['fecha'] ?? date('Y-m-d');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        // Configuración: max citas por hora (podría venir de DB/config)
        $maxCitasPorHora = 2; 

        $ocupacion = Cita::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('fecha', $fecha)
            ->whereIn('estado', ['Programada', 'EnCurso'])
            ->select('hora_programada', Capsule::raw('count(*) as total'))
            ->groupBy('hora_programada')
            ->get();

        return $this->json($response, [
            'error' => false,
            'fecha' => $fecha,
            'max_por_hora' => $maxCitasPorHora,
            'ocupacion' => $ocupacion
        ]);
    }

    /**
     * POST /api/citas/{id}/llegada
     * Marca el arribo físico (Check-in) en patio
     */
    public function marcarLlegada(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $cita = Cita::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($id);
        if (!$cita) {
            return $this->json($response, ['error' => true, 'message' => 'Cita no encontrada.'], 404);
        }

        $cita->estado = 'EnPatio';
        $cita->hora_llegada = date('Y-m-d H:i:s');
        $cita->save();

        return $this->json($response, [
            'error' => false,
            'message' => 'Llegada registrada.',
            'data' => $cita
        ]);
    }

    /**
     * POST /api/citas/{id}/completar-yms
     * Finaliza la operación de patio con evaluación
     */
    public function completarYMS(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $data = $request->getParsedBody();
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $cita = Cita::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($id);
        if (!$cita) {
            return $this->json($response, ['error' => true, 'message' => 'Cita no encontrada.'], 404);
        }

        $cita->estado = 'Completada';
        $cita->hora_fin_descargue = date('Y-m-d H:i:s');
        $cita->evaluacion_proveedor = (int)($data['evaluacion'] ?? 5);
        $cita->tipo_descargue = $data['tipo_descargue'] ?? 'Paletizado';
        $cita->save();

        return $this->json($response, [
            'error' => false,
            'message' => 'YMS completado.',
            'data' => $cita
        ]);
    }

}
