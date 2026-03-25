<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Cita;
use Illuminate\Database\Capsule\Manager as Capsule;

class CitaController
{
    /**
     * GET /api/citas
     * Lista citas programadas de la sucursal actual
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        $citas = Cita::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
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
        
        if (!$user->hasPermission('recepcion', 'crear')) {
            return $this->json($response, ['error' => true, 'message' => 'No tienes permiso para programar citas.'], 403);
        }

        $data = $request->getParsedBody();

        // Validaciones básicas
        if (empty($data['proveedor']) || empty($data['fecha']) || empty($data['hora_programada'])) {
            return $this->json($response, ['error' => true, 'message' => 'Proveedor, Fecha y Hora son requeridos.'], 400);
        }

        try {
            // Resolver nombre del proveedor: acepta nombre libre o proveedor_id
            $nombreProv = trim($data['proveedor'] ?? '');
            if (!empty($data['proveedor_id'])) {
                $prov = \App\Models\Proveedor::where('empresa_id', $user->empresa_id)
                    ->find((int)$data['proveedor_id']);
                if ($prov) $nombreProv = $prov->nombre;
            }
            if (empty($nombreProv)) {
                return $this->json($response, ['error' => true, 'message' => 'Proveedor es requerido.'], 400);
            }

            $cita = new Cita();
            $cita->empresa_id     = $user->empresa_id;
            $cita->sucursal_id    = $user->sucursal_id;
            $cita->proveedor      = $nombreProv;
            $cita->fecha          = $data['fecha'];
            $cita->hora_programada= $data['hora_programada'];
            $cita->cantidad_cajas = (int)($data['cantidad_cajas'] ?? 0);
            $cita->tipo_vehiculo  = $data['tipo_vehiculo'] ?? null;
            $cita->kilos          = (float)($data['kilos'] ?? 0);
            $cita->odc            = $data['odc'] ?? null;
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

        if (!$user->hasPermission('recepcion', 'editar')) {
            return $this->json($response, ['error' => true, 'message' => 'No tienes permiso.'], 403);
        }

        $cita = Cita::find($id);
        if (!$cita || $cita->empresa_id !== $user->empresa_id || $cita->sucursal_id !== $user->sucursal_id) {
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

        if (!$user->hasPermission('recepcion', 'eliminar')) {
            return $this->json($response, ['error' => true, 'message' => 'No tienes permiso.'], 403);
        }

        $cita = Cita::find($id);
        if (!$cita || $cita->empresa_id !== $user->empresa_id) {
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

        // Configuración: max citas por hora (podría venir de DB/config)
        $maxCitasPorHora = 2; 

        $ocupacion = Cita::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
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

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
