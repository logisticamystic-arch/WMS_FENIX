<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Notificacion;
use App\Models\Personal;
use App\Models\NivelReposicion;
use App\Models\TareaReabastecimiento;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * ReplenishmentController — Gestión de Reabastecimiento Automático y Manual.
 *
 * runAutoReplenishment() compara el stock real de picking-face (ubicaciones tipo
 * 'Picking') contra los niveles configurados en NivelReposicion, y genera tareas
 * reales de traslado desde reserva/almacenamiento hacia picking-face — antes era
 * una simulación (contador fijo) que nunca leía stock ni niveles configurados.
 */
class ReplenishmentController extends BaseController
{
    /**
     * Ejecuta el proceso de reabastecimiento (Auto-Replenishment).
     * Genera TareaReabastecimiento reales para productos bajo su punto de reposición
     * en picking-face, y notifica a los Montacarguistas activos de la sucursal.
     */
    public function runAutoReplenishment(Request $req, Response $res): Response
    {
        $user       = $req->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $req);
        $sucursalId = $this->getEffectiveSucursalId($user, $req);

        $tareasCreadas = [];

        try {
            Capsule::transaction(function () use ($empresaId, $sucursalId, &$tareasCreadas) {
                $niveles = NivelReposicion::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('activo', true)
                    ->get();

                foreach ($niveles as $nivel) {
                    // Evitar duplicar: si ya hay una tarea pendiente para este producto, no crear otra.
                    $yaExiste = TareaReabastecimiento::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $nivel->producto_id)
                        ->where('estado', 'Pendiente')
                        ->exists();
                    if ($yaExiste) continue;

                    // Stock real disponible en ubicaciones de picking-face
                    $stockPicking = (float) Capsule::table('inventarios as i')
                        ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                        ->where('i.empresa_id', $empresaId)
                        ->where('i.sucursal_id', $sucursalId)
                        ->where('i.producto_id', $nivel->producto_id)
                        ->where('i.estado', 'Disponible')
                        ->where('u.tipo_ubicacion', 'Picking')
                        ->sum('i.cantidad');

                    $puntoReposicion = $nivel->punto_reposicion ?? $nivel->stock_minimo;
                    if ($puntoReposicion === null || $stockPicking > $puntoReposicion) {
                        continue; // no necesita reabastecimiento
                    }

                    // Cuánto reponer: llegar a stock_maximo si está configurado, si no al doble del mínimo
                    $objetivo = $nivel->stock_maximo ?? ($nivel->stock_minimo * 2);
                    $cantidadNecesaria = max(0, $objetivo - $stockPicking);
                    if ($cantidadNecesaria <= 0) continue;

                    // Origen: reserva/almacenamiento con stock disponible, FEFO (vence primero, primero)
                    $origen = Capsule::table('inventarios as i')
                        ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                        ->where('i.empresa_id', $empresaId)
                        ->where('i.sucursal_id', $sucursalId)
                        ->where('i.producto_id', $nivel->producto_id)
                        ->where('i.estado', 'Disponible')
                        ->where('i.cantidad', '>', 0)
                        ->where('u.tipo_ubicacion', '!=', 'Picking')
                        ->orderByRaw('CASE WHEN i.fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('i.fecha_vencimiento', 'ASC')
                        ->select('i.ubicacion_id', 'i.cantidad')
                        ->first();

                    if (!$origen) continue; // sin stock en reserva — no se puede generar la tarea

                    // Destino: alguna ubicación de picking ya usada para este producto, o cualquiera activa
                    $destinoId = Capsule::table('inventarios as i')
                        ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                        ->where('i.empresa_id', $empresaId)
                        ->where('i.sucursal_id', $sucursalId)
                        ->where('i.producto_id', $nivel->producto_id)
                        ->where('u.tipo_ubicacion', 'Picking')
                        ->value('i.ubicacion_id');

                    if (!$destinoId) {
                        $destinoId = Capsule::table('ubicaciones')
                            ->where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('tipo_ubicacion', 'Picking')
                            ->where('activo', true)
                            ->value('id');
                    }
                    if (!$destinoId) continue; // no hay ninguna ubicación de picking configurada

                    $cantidadTarea = min($cantidadNecesaria, (float)$origen->cantidad);
                    if ($cantidadTarea <= 0) continue;

                    $tarea = TareaReabastecimiento::create([
                        'empresa_id'           => $empresaId,
                        'sucursal_id'          => $sucursalId,
                        'producto_id'          => $nivel->producto_id,
                        'ubicacion_origen_id'  => $origen->ubicacion_id,
                        'ubicacion_destino_id' => $destinoId,
                        'cantidad'             => $cantidadTarea,
                        'estado'               => 'Pendiente',
                        'fecha_movimiento'     => date('Y-m-d'),
                        'hora_inicio'          => date('H:i:s'),
                    ]);
                    $tareasCreadas[] = $tarea;
                }
            });
        } catch (\Throwable $e) {
            return $this->error($res, 'Error generando reabastecimiento: ' . $e->getMessage());
        }

        $tareasEncontradas = count($tareasCreadas);

        if ($tareasEncontradas > 0) {
            $operarios = Personal::where('empresa_id', $empresaId)
                ->where('rol', 'Montacarguista')
                ->where('activo', 1)
                ->get();

            foreach ($operarios as $op) {
                Notificacion::create([
                    'empresa_id'  => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'personal_id' => $op->id,
                    'titulo'      => 'Reabastecimiento Necesario',
                    'mensaje'     => "Se han generado {$tareasEncontradas} tareas de reabastecimiento para su zona.",
                    'tipo'        => 'tarea',
                    'modulo'      => 'Inventarios',
                    'url'         => '/almacenamiento/putaway',
                ]);
            }
        }

        $this->audit($user, 'reabastecimiento', 'auto_run', 'tarea_reabastecimientos', null,
            null, ['tareas_creadas' => $tareasEncontradas], "Auto-reabastecimiento: {$tareasEncontradas} tareas generadas");

        return $this->ok($res, ['tareas_creadas' => $tareasEncontradas], 'Completado');
    }

    /**
     * GET /api/reabastecimiento/tareas — historial/listado de tareas (para que la
     * pantalla de Logística Avanzada tenga algo real que mostrar, en vez de solo
     * un botón sin ningún dato — ver auditoría previa).
     */
    public function listarTareas(Request $req, Response $res): Response
    {
        $user       = $req->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $req);
        $sucursalId = $this->getEffectiveSucursalId($user, $req);
        $params     = $req->getQueryParams();

        $tareas = TareaReabastecimiento::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with(['producto:id,nombre,codigo_interno', 'ubicacionOrigen:id,codigo', 'ubicacionDestino:id,codigo', 'montacarguista:id,nombre'])
            ->when($params['estado'] ?? null, fn($q, $e) => $q->where('estado', $e))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return $this->ok($res, $tareas);
    }
}
