<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenPicking;
use App\Models\PickingDetalle;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\TareaReabastecimiento;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * PickingController — FEFO estricto + auditoría completa.
 *
 * Flujo:
 *  1. crearBatch → genera OrdenPicking con detalles
 *  2. generateRoute → asigna ubicaciones FEFO a cada línea
 *  3. confirmLine → auxiliar confirma que tomó la cantidad
 *  4. completar → cierra la orden y descuenta inventario
 */
class PickingController extends BaseController
{
    // ── GET /api/picking ──────────────────────────────────────────────────────
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->when($params['estado'] ?? null, fn($q, $e) => $q->where('estado', $e))
            ->orderBy('prioridad')
            ->orderBy('created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# Orden', 'Cliente', 'Estado', 'Prioridad', 'Auxiliar', 'F.Requerida'];
            $rows = $ordenes->map(fn($o) => [
                $o->numero_orden, $o->cliente ?? '—', $o->estado,
                $o->prioridad, $o->auxiliar_id ?? '—', $o->fecha_requerida ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'picking_' . date('Y-m-d'));
        }

        return $this->ok($res, $ordenes);
    }

    // ── GET /api/picking/{id} ─────────────────────────────────────────────────
    public function detalle(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->with(['detalles.producto', 'detalles.ubicacion'])
            ->find($a['id']);

        if (!$orden) return $this->notFound($res);
        return $this->ok($res, $orden);
    }

    // ── POST /api/picking ─────────────────────────────────────────────────────
    public function crearBatch(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if (empty($data['detalles']) || !is_array($data['detalles'])) {
            return $this->error($res, 'Se requiere al menos una línea de productos');
        }

        try {
            $orden = Capsule::transaction(function () use ($data, $user) {
                $orden = OrdenPicking::create([
                    'empresa_id'     => $user->empresa_id,
                    'sucursal_id'    => $user->sucursal_id,
                    'numero_orden'   => 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'cliente'        => $data['cliente'] ?? null,
                    'estado'         => 'Pendiente',
                    'prioridad'      => $data['prioridad'] ?? 5,
                    'auxiliar_id'    => $data['auxiliar_id'] ?? null,
                    'fecha_movimiento'=> date('Y-m-d'),
                    'hora_inicio'    => date('H:i:s'),
                    'fecha_requerida'=> $data['fecha_requerida'] ?? null,
                ]);

                foreach ($data['detalles'] as $det) {
                    PickingDetalle::create([
                        'orden_picking_id'  => $orden->id,
                        'producto_id'       => $det['producto_id'],
                        'ubicacion_id'      => 0, // Se asignará en generateRoute
                        'cantidad_solicitada'=> $det['cantidad'],
                        'cantidad_pickeada' => 0,
                        'estado'            => 'Pendiente',
                    ]);
                }

                return $orden;
            });

            $this->audit($user, 'picking', 'crear', 'orden_pickings', $orden->id,
                null, $orden->toArray(), "Orden picking {$orden->numero_orden} creada");

            return $this->created($res, $orden->load('detalles'));
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/generar-ruta ─────────────────────────────
    // Asigna ubicaciones FEFO a cada línea de picking.
    public function generateRoute(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->with('detalles')
            ->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if ($orden->estado !== 'Pendiente') {
            return $this->error($res, "La orden ya está en estado {$orden->estado}");
        }

        $lineasAsignadas = 0;
        $alertas         = [];

        try {
            Capsule::transaction(function () use ($orden, $user, &$lineasAsignadas, &$alertas) {
                foreach ($orden->detalles as $linea) {
                    // FEFO: inventario disponible, ordenado por fecha_vencimiento ASC
                    $stocks = Inventario::where('empresa_id',  $user->empresa_id)
                        ->where('sucursal_id',  $user->sucursal_id)
                        ->where('producto_id',  $linea->producto_id)
                        ->where('estado',       'Disponible')
                        ->where('cantidad',     '>', 0)
                        ->orderByRaw('fecha_vencimiento IS NULL ASC') // nulls al final
                        ->orderBy('fecha_vencimiento')                // FEFO
                        ->orderBy('ubicacion_id')                     // secuencia de pasillo
                        ->get();

                    $totalDisponible = $stocks->sum('cantidad');

                    if ($totalDisponible < $linea->cantidad_solicitada) {
                        $alertas[] = [
                            'producto_id' => $linea->producto_id,
                            'solicitado'  => $linea->cantidad_solicitada,
                            'disponible'  => $totalDisponible,
                            'faltante'    => $linea->cantidad_solicitada - $totalDisponible,
                        ];
                        $linea->estado = 'Faltante';
                        $linea->save();
                        continue;
                    }

                    // Asignar primera ubicación con stock suficiente (FEFO)
                    $ubicacionAsignada = $stocks->first()->ubicacion_id;
                    $loteAsignado      = $stocks->first()->lote;
                    $fvencAsignada     = $stocks->first()->fecha_vencimiento;

                    $linea->ubicacion_id       = $ubicacionAsignada;
                    $linea->lote               = $loteAsignado;
                    $linea->fecha_vencimiento  = $fvencAsignada;
                    $linea->estado             = 'EnProceso';
                    $linea->save();

                    $lineasAsignadas++;
                }

                $orden->estado = 'EnProceso';
                $orden->save();
            });

            $this->audit($user, 'picking', 'generar_ruta', 'orden_pickings', $orden->id,
                ['estado' => 'Pendiente'], ['estado' => 'EnProceso'],
                "Ruta FEFO generada para {$orden->numero_orden}");

            return $this->ok($res, [
                'orden'           => $orden->load('detalles.ubicacion'),
                'lineas_asignadas'=> $lineasAsignadas,
                'alertas_stock'   => $alertas,
            ], 'Ruta generada con FEFO');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/confirmar-linea ──────────────────────────
    public function confirmLine(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if ($orden->estado !== 'EnProceso') {
            return $this->error($res, 'La orden no está en proceso');
        }

        $linea = PickingDetalle::where('orden_picking_id', $orden->id)
            ->find($data['linea_id'] ?? 0);

        if (!$linea) return $this->notFound($res, 'Línea no encontrada');

        $cantidadTomada = (int)($data['cantidad_tomada'] ?? 0);
        if ($cantidadTomada <= 0) return $this->error($res, 'Cantidad inválida');

        try {
            Capsule::transaction(function () use ($linea, $orden, $user, $cantidadTomada) {
                // Descontar inventario
                $inv = Inventario::where('empresa_id',  $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->first();

                if (!$inv || $inv->cantidad < $cantidadTomada) {
                    throw new \Exception('Stock insuficiente para confirmar el picking');
                }

                $inv->cantidad -= $cantidadTomada;
                if ($inv->cantidad === 0) $inv->delete();
                else $inv->save();

                // Registrar movimiento
                MovimientoInventario::create([
                    'empresa_id'           => $user->empresa_id,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $linea->producto_id,
                    'tipo_movimiento'      => 'SalidaPicking',
                    'cantidad'             => $cantidadTomada,
                    'ubicacion_origen_id'  => $linea->ubicacion_id,
                    'ubicacion_destino_id' => $linea->ubicacion_id,
                    'lote'                 => $linea->lote,
                    'fecha_vencimiento'    => $linea->fecha_vencimiento,
                    'usuario_id'           => $user->id,
                    'referencia'           => $orden->numero_orden,
                    'observaciones'        => "Picking orden {$orden->numero_orden}",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_movimiento'      => date('H:i:s'),
                ]);

                $linea->cantidad_pickeada = $cantidadTomada;
                $linea->estado = $cantidadTomada >= $linea->cantidad_solicitada
                    ? 'Completado' : 'Faltante';
                $linea->save();

                // Verificar si todas las líneas están completas
                $pendientes = PickingDetalle::where('orden_picking_id', $orden->id)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->count();

                if ($pendientes === 0) {
                    $orden->estado   = 'Completada';
                    $orden->hora_fin = date('H:i:s');
                    $orden->save();
                }
            });

            $this->audit($user, 'picking', 'confirmar_linea', 'picking_detalles', $linea->id,
                null, ['cantidad_tomada' => $cantidadTomada],
                "Línea picking confirmada para {$orden->numero_orden}");

            return $this->ok($res, $linea, 'Línea confirmada');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{id}/completar ─────────────────────────────────────
    public function completar(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $orden->estado   = 'Completada';
        $orden->hora_fin = date('H:i:s');
        $orden->save();

        $this->audit($user, 'picking', 'completar', 'orden_pickings', $orden->id,
            null, ['estado' => 'Completada'], "Orden picking {$orden->numero_orden} completada");

        return $this->ok($res, $orden, 'Orden de picking completada');
    }

    // ── DELETE /api/picking/{id} — solo Admin ─────────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);
        if ($orden->estado === 'Completada') {
            return $this->error($res, 'No se puede eliminar una orden completada');
        }

        $snapshot = $orden->toArray();
        $orden->detalles()->delete();
        $orden->delete();

        $this->audit($user, 'picking', 'eliminar', 'orden_pickings', $a['id'],
            $snapshot, null, "Orden {$snapshot['numero_orden']} eliminada por Admin");

        return $this->ok($res, null, 'Orden eliminada');
    }

    // ── GET /api/picking/dashboard ────────────────────────────────────────────
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $hoy  = date('Y-m-d');

        $stats = [
            'pendientes'  => OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('estado', 'Pendiente')->count(),
            'en_proceso'  => OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('estado', 'EnProceso')->count(),
            'completadas_hoy' => OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('estado', 'Completada')
                ->where('fecha_movimiento', $hoy)->count(),
            'con_faltantes' => PickingDetalle::whereHas('ordenPicking', fn($q) =>
                $q->where('empresa_id', $user->empresa_id)
                  ->where('fecha_movimiento', $hoy)
            )->where('estado', 'Faltante')->count(),
        ];

        return $this->ok($res, $stats);
    }

    // ── GET /api/picking/reabastecimientos ────────────────────────────────────
    public function reabastecimientos(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $tareas = TareaReabastecimiento::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('estado', 'Pendiente')
            ->with(['producto', 'ubicacionOrigen', 'ubicacionDestino'])
            ->get();
        return $this->ok($res, $tareas);
    }

    // ── POST /api/picking/reabastecimientos/{id}/completar ────────────────────
    public function completarReabast(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $tarea = TareaReabastecimiento::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$tarea) return $this->notFound($res);

        try {
            Capsule::transaction(function () use ($tarea, $user) {
                // Descontar origen
                $origen = Inventario::where([
                    'empresa_id'  => $user->empresa_id,
                    'sucursal_id' => $user->sucursal_id,
                    'producto_id' => $tarea->producto_id,
                    'ubicacion_id'=> $tarea->ubicacion_origen_id,
                    'estado'      => 'Disponible',
                ])->first();

                if (!$origen || $origen->cantidad < $tarea->cantidad) {
                    throw new \Exception('Stock insuficiente en origen para reabastecimiento');
                }

                $origen->cantidad -= $tarea->cantidad;
                if ($origen->cantidad === 0) $origen->delete();
                else $origen->save();

                // Acumular destino
                $destino = Inventario::firstOrCreate([
                    'empresa_id'   => $user->empresa_id,
                    'sucursal_id'  => $user->sucursal_id,
                    'producto_id'  => $tarea->producto_id,
                    'ubicacion_id' => $tarea->ubicacion_destino_id,
                    'estado'       => 'Disponible',
                ], ['cantidad' => 0]);
                $destino->cantidad += $tarea->cantidad;
                $destino->save();

                MovimientoInventario::create([
                    'empresa_id'           => $user->empresa_id,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $tarea->producto_id,
                    'tipo_movimiento'      => 'Reabastecimiento',
                    'cantidad'             => $tarea->cantidad,
                    'ubicacion_origen_id'  => $tarea->ubicacion_origen_id,
                    'ubicacion_destino_id' => $tarea->ubicacion_destino_id,
                    'usuario_id'           => $user->id,
                    'referencia'           => "REAB-{$tarea->id}",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_movimiento'      => date('H:i:s'),
                ]);

                $tarea->estado   = 'Completada';
                $tarea->hora_fin = date('H:i:s');
                $tarea->save();
            });

            $this->audit($user, 'picking', 'reabastecimiento', 'tarea_reabastecimientos', $tarea->id,
                null, ['estado' => 'Completada'], "Reabastecimiento completado");

            return $this->ok($res, $tarea, 'Reabastecimiento completado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── Marcar línea como faltante ────────────────────────────────────────────
    public function marcarFaltante(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $linea = PickingDetalle::whereHas('ordenPicking',
            fn($q) => $q->where('empresa_id', $user->empresa_id)
        )->find($a['id']);

        if (!$linea) return $this->notFound($res);
        $linea->estado = 'Faltante';
        $linea->save();

        return $this->ok($res, $linea, 'Marcado como faltante');
    }
}
