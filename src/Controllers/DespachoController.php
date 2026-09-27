<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Despacho;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\OrdenPicking;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * DespachoController — Preparación, certificación y cierre de despachos.
 * Flujo: Preparando → Certificado → Despachado → Entregado (liquidar)
 * Cada transición queda en audit_logs y movimiento_inventarios.
 */
class DespachoController extends BaseController
{
    // ── GET /api/despachos ────────────────────────────────────────────────────
    // Por defecto (sin fecha_inicio/fecha_fin) muestra SOLO el día actual — la vista
    // principal es operativa (qué se despacha HOY), no un histórico. El rango de
    // fechas y la sucursal (solo SuperAdmin, ver getEffectiveSucursalId) son filtros
    // explícitos que el usuario puede aplicar dinámicamente desde la UI.
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $hoy    = date('Y-m-d');
        $ini    = substr($params['fecha_inicio'] ?? $params['desde'] ?? $params['from'] ?? $hoy, 0, 10);
        $fin    = substr($params['fecha_fin']    ?? $params['hasta'] ?? $params['to']   ?? $hoy, 0, 10);
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $estadoParam = $params['estado'] ?? null;

        $despachos = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereBetween('fecha_movimiento', [$ini, $fin])
            // 'activas' es el filtro por defecto de la UI: oculta despachos ya
            // cerrados (Entregado/Cancelado) del listado operativo del día.
            ->when($estadoParam === 'activas', fn($q) => $q->whereNotIn('estado', ['Entregado', 'Cancelado']))
            ->when($estadoParam && $estadoParam !== 'activas', fn($q) => $q->where('estado', $estadoParam))
            ->with(['ordenes:id,numero_orden,estado_certificacion'])
            ->orderBy('fecha_movimiento', 'desc')
            ->get();

        // Resumen de certificación por despacho (para la columna "Estado Certificación" del listado)
        $despachos->each(function ($d) {
            $ordenes = $d->ordenes;
            $total   = $ordenes->count();
            $certificadas = $ordenes->where('estado_certificacion', 'Certificada')->count();
            $d->estado_certificacion_resumen = $total === 0
                ? 'Sin pedidos'
                : ($certificadas === $total ? 'Certificado' : "{$certificadas}/{$total} certificados");
            unset($d->ordenes);
        });

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# Despacho', 'Cliente', 'Ruta', 'Estado', 'Certificación', 'Bultos', 'Peso (kg)', 'Fecha'];
            $rows = $despachos->map(fn($d) => [
                $d->numero_despacho, $d->cliente ?? '—', $d->ruta ?? '—',
                $d->estado, $d->estado_certificacion_resumen, $d->total_bultos, $d->peso_total, $d->fecha_movimiento,
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'despachos_' . date('Y-m-d'));
        }

        return $this->ok($res, $despachos);
    }

    // ── GET /api/despachos/{id} ───────────────────────────────────────────────
    public function ver(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $d    = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with([
                'certificaciones.producto',
                'ordenes:id,numero_orden,planilla_numero,planilla_lote,cliente,sucursal_entrega,estado,estado_certificacion,estado_despacho,fecha_movimiento',
                'rutaObj:id,nombre',
                'conductorObj:id,nombre,documento,telefono',
                'vehiculoObj:id,placa,tipo',
            ])
            ->find($a['id']);
        if (!$d) return $this->notFound($res);

        // Blindaje 2026-08-18 (a pedido explícito): para poder reimprimir la remisión
        // de un cargue hay que saber, por pedido, si se certificó vía sesión de
        // packing (remisión sale de packing/sesion/{id}/remision) o vía certificación
        // directa (remisión sale de picking/certificacion/remision-multiple) — mezclar
        // ambos caminos en el endpoint equivocado da HTTP 400 (ver certRemisionMultiple(),
        // que excluye a propósito las órdenes con packing_items). El frontend
        // (imprimirDocumentosCargue() en despacho.js) usa este campo para elegir el endpoint
        // correcto por cada pedido y fusionarlos en una sola remisión consolidada.
        $ordenIds = $d->ordenes->pluck('id')->toArray();
        if (!empty($ordenIds)) {
            $sesionesPorOrden = Capsule::table('picking_detalles as pd')
                ->join('packing_items as pi', 'pi.picking_detalle_id', '=', 'pd.id')
                ->join('packing_unidades as pu', 'pu.id', '=', 'pi.unidad_id')
                ->whereIn('pd.orden_picking_id', $ordenIds)
                ->select('pd.orden_picking_id', 'pu.sesion_id')
                ->distinct()
                ->get()
                ->groupBy('orden_picking_id');
            $d->ordenes->each(function ($o) use ($sesionesPorOrden) {
                $o->packing_sesion_id = $sesionesPorOrden->get($o->id)?->first()->sesion_id ?? null;
            });
        }

        return $this->ok($res, $d);
    }

    // ── POST /api/despachos ───────────────────────────────────────────────────
    public function store(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $data = $r->getParsedBody() ?? [];

        try {
            $despacho = Despacho::create([
                'empresa_id'      => $empresaId,
                'sucursal_id'     => $sucursalId,
                'numero_despacho' => Despacho::generarNumero($sucursalId),
                'cliente'         => $data['cliente']      ?? null,
                'ruta_id'         => !empty($data['ruta_id']) ? (int)$data['ruta_id'] : null,
                'ruta'            => $data['ruta'] ?? (
                    !empty($data['ruta_id'])
                        ? (\App\Models\Ruta::find((int)$data['ruta_id'])?->nombre ?? null)
                        : null
                ),
                'conductor_id'    => !empty($data['conductor_id']) ? (int)$data['conductor_id'] : null,
                'conductor'       => $data['conductor'] ?? (
                    !empty($data['conductor_id'])
                        ? (\App\Models\Conductor::find((int)$data['conductor_id'])?->nombre ?? null)
                        : null
                ),
                'vehiculo_id'     => !empty($data['vehiculo_id']) ? (int)$data['vehiculo_id'] : null,
                'placa'           => $data['placa'] ?? (
                    !empty($data['vehiculo_id'])
                        ? (\App\Models\Vehiculo::find((int)$data['vehiculo_id'])?->placa ?? null)
                        : null
                ),
                'muelle_id'       => $data['muelle_id']    ?? null,
                'total_bultos'    => $data['total_bultos'] ?? 0,
                'peso_total'      => $data['peso_total']   ?? 0,
                'observaciones'   => $data['observaciones'] ?? null,
                'auxiliar_id'     => $data['auxiliar_id']  ?? null,
                'fecha_movimiento'=> $data['fecha']        ?? date('Y-m-d'),
                'hora_inicio'     => date('H:i:s'),
                'estado'          => 'Preparando',
            ]);

            $this->audit($user, 'despacho', 'crear', 'despachos', $despacho->id,
                null, $despacho->toArray(), "Despacho {$despacho->numero_despacho} creado");

            return $this->created($res, $despacho);
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // NOTA: certify() (POST /despachos/{id}/certificar) fue eliminado — auditoría confirmó
    // que ningún frontend (desktop ni móvil) lo invocaba; la certificación real de despacho
    // pasa por Picking/Packing. Mantenerlo vivo era un riesgo de doble descuento de inventario
    // si algo llegaba a invocarlo de forma independiente.

    // ── POST /api/despachos/{id}/cerrar ───────────────────────────────────────
    public function close(Request $r, Response $res, array $a): Response
    {
        $user     = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $data     = $r->getParsedBody() ?? [];
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);

        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Despachado') {
            return $this->error($res, 'El despacho ya está cerrado');
        }

        $despacho->estado   = 'Despachado';
        $despacho->hora_fin = date('H:i:s');
        if (!empty($data['total_bultos'])) $despacho->total_bultos = $data['total_bultos'];
        if (!empty($data['peso_total']))   $despacho->peso_total   = $data['peso_total'];
        $despacho->save();

        $this->audit($user, 'despacho', 'cerrar', 'despachos', $despacho->id,
            ['estado' => 'Certificado'], ['estado' => 'Despachado'],
            "Despacho {$despacho->numero_despacho} cerrado");

        return $this->ok($res, $despacho, 'Despacho cerrado exitosamente');
    }

    // ── DELETE /api/despachos/{id} — solo Admin ───────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if (in_array($despacho->estado, ['Despachado', 'Entregado'], true)) {
            return $this->error($res, 'No se puede eliminar un despacho ya despachado o entregado');
        }

        $snapshot = $despacho->toArray();

        try {
            Capsule::transaction(function () use ($despacho, $user, $empresaId, $sucursalId) {
                // Si el despacho llegó a 'Certificado', certify() ya descontó inventario real.
                // Revertirlo antes de borrar — apoyándose en los MovimientoInventario 'Salida'
                // ya registrados para este despacho, igual que se hace para Devolución.
                $salidas = Capsule::table('movimiento_inventarios')
                    ->where('referencia_tipo', 'despachos')
                    ->where('referencia_id', $despacho->id)
                    ->where('tipo_movimiento', 'Salida')
                    ->get();

                foreach ($salidas as $mov) {
                    if (!$mov->ubicacion_origen_id) continue;

                    $inv = \App\Models\Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $mov->producto_id)
                        ->where('ubicacion_id', $mov->ubicacion_origen_id)
                        ->where('estado', 'Disponible')
                        ->when($mov->lote, fn($q) => $q->where('lote', $mov->lote))
                        ->when(!$mov->lote, fn($q) => $q->whereNull('lote'))
                        ->lockForUpdate()->first();

                    if ($inv) {
                        $inv->cantidad = (float)$inv->cantidad + (float)$mov->cantidad;
                        $inv->save();
                    } else {
                        \App\Models\Inventario::create([
                            'empresa_id'   => $empresaId,
                            'sucursal_id'  => $sucursalId,
                            'producto_id'  => $mov->producto_id,
                            'ubicacion_id' => $mov->ubicacion_origen_id,
                            'lote'         => $mov->lote,
                            'estado'       => 'Disponible',
                            'cantidad'     => $mov->cantidad,
                            'cantidad_reservada' => 0,
                        ]);
                    }

                    MovimientoInventario::create([
                        'empresa_id'           => $empresaId,
                        'sucursal_id'          => $sucursalId,
                        'producto_id'          => $mov->producto_id,
                        'tipo_movimiento'      => 'AjustePositivo',
                        'cantidad'             => $mov->cantidad,
                        'lote'                 => $mov->lote,
                        'ubicacion_destino_id' => $mov->ubicacion_origen_id,
                        'auxiliar_id'          => $user->id,
                        'referencia_tipo'      => 'despachos',
                        'referencia_id'        => $despacho->id,
                        'observaciones'        => "Eliminación de despacho {$despacho->numero_despacho} — reversión de stock certificado",
                        'fecha_movimiento'     => date('Y-m-d'),
                        'hora_inicio'          => date('H:i:s'),
                    ]);
                }

                $despacho->certificaciones()->delete();
                $despacho->delete();
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al eliminar despacho: ' . $e->getMessage());
        }

        $this->audit($user, 'despacho', 'eliminar', 'despachos', $a['id'],
            $snapshot, null, "Despacho {$snapshot['numero_despacho']} eliminado por Admin");

        return $this->ok($res, null, 'Despacho eliminado');
    }

    // ── POST /api/despachos/{id}/pedidos ─────────────────────────────────────
    // Asocia ordenes de picking al despacho y las marca como 'Despachado'.
    public function agregarPedidos(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId= $this->getEffectiveSucursalId($user, $r);
        $data      = $r->getParsedBody() ?? [];

        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Entregado') {
            return $this->error($res, 'El despacho ya fue liquidado');
        }

        $ordenIds = array_filter(array_map('intval', (array)($data['orden_ids'] ?? [])));
        if (empty($ordenIds)) {
            return $this->error($res, 'Debe seleccionar al menos un pedido');
        }

        // Verifica que las ordenes sean válidas y de la misma empresa/sucursal
        $ordenesCheck = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereIn('id', $ordenIds)
            ->get();
        foreach ($ordenesCheck as $orden) {
            if ($orden->estado !== 'Completada') {
                return $this->error($res, "La orden {$orden->numero_orden} no está Completada y no puede despacharse");
            }
            if (!empty($orden->estado_despacho)) {
                return $this->error($res, "La orden {$orden->numero_orden} ya fue {$orden->estado_despacho}");
            }
        }

        try {
            Capsule::transaction(function () use ($despacho, $ordenIds, $empresaId, $sucursalId) {
                $ordenes = OrdenPicking::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->whereIn('id', $ordenIds)
                    ->get();

                foreach ($ordenes as $orden) {
                    // Evita duplicados con sincronización sin detach
                    Capsule::table('despacho_ordenes')->insertOrIgnore([
                        'despacho_id'      => $despacho->id,
                        'orden_picking_id' => $orden->id,
                        'created_at'       => date('Y-m-d H:i:s'),
                        'updated_at'       => date('Y-m-d H:i:s'),
                    ]);
                    // Marca la orden como Despachada
                    $orden->estado_despacho = 'Despachado';
                    $orden->despacho_id     = $despacho->id;
                    $orden->save();
                }
            });

            $this->audit($user, 'despacho', 'agregar_pedidos', 'despachos', $despacho->id,
                null, ['orden_ids' => $ordenIds],
                "Pedidos " . implode(',', $ordenIds) . " asociados al despacho {$despacho->numero_despacho}");

            $despacho->load('ordenes');
            return $this->ok($res, $despacho, 'Pedidos asociados correctamente');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── DELETE /api/despachos/{id}/pedidos/{orden_id} ─────────────────────────
    public function eliminarPedido(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId= $this->getEffectiveSucursalId($user, $r);

        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Entregado') {
            return $this->error($res, 'No se puede modificar un despacho liquidado');
        }

        $ordenId = (int)$a['orden_id'];
        Capsule::table('despacho_ordenes')
            ->where('despacho_id', $despacho->id)
            ->where('orden_picking_id', $ordenId)
            ->delete();

        // Revierte estado_despacho si no está en otro despacho
        $enOtro = Capsule::table('despacho_ordenes')
            ->where('orden_picking_id', $ordenId)
            ->exists();
        if (!$enOtro) {
            OrdenPicking::where('id', $ordenId)
                ->update(['estado_despacho' => null, 'despacho_id' => null]);
        }

        return $this->ok($res, null, 'Pedido removido del despacho');
    }

    // ── POST /api/despachos/{id}/liquidar ─────────────────────────────────────
    // Liquida el despacho: marca estado='Entregado' y ordenes como 'Entregado'.
    public function liquidar(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId= $this->getEffectiveSucursalId($user, $r);

        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Entregado') {
            return $this->error($res, 'El despacho ya fue liquidado');
        }

        try {
            Capsule::transaction(function () use ($despacho) {
                $despacho->estado   = 'Entregado';
                $despacho->hora_fin = date('H:i:s');
                $despacho->save();

                // Marca todas las ordenes del despacho como Entregadas
                $ordenIds = Capsule::table('despacho_ordenes')
                    ->where('despacho_id', $despacho->id)
                    ->pluck('orden_picking_id')
                    ->toArray();

                if (!empty($ordenIds)) {
                    OrdenPicking::whereIn('id', $ordenIds)
                        ->update(['estado_despacho' => 'Entregado']);
                }
            });

            $this->audit($user, 'despacho', 'liquidar', 'despachos', $despacho->id,
                ['estado' => 'Despachado'], ['estado' => 'Entregado'],
                "Despacho {$despacho->numero_despacho} liquidado — pedidos marcados Entregado");

            return $this->ok($res, $despacho, 'Despacho liquidado. Los pedidos han sido marcados como Entregados.');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── GET /api/despachos/{id}/reporte ──────────────────────────────────────
    public function reporte(Request $r, Response $res, array $a): Response
    {
        $user     = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with('certificaciones.producto')
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);

        $headers = ['Producto', 'Código', 'Lote', 'Cantidad Certificada', 'Escaneado Por'];
        $rows = $despacho->certificaciones->map(fn($c) => [
            $c->producto->nombre          ?? '—',
            $c->producto->codigo_interno  ?? '—',
            $c->lote                      ?? '—',
            $c->cantidad_certificada,
            $c->escaneado_por,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows,
            'despacho_' . $despacho->numero_despacho);
    }

    // ── GET /api/despachos/{id}/planilla-cargue ───────────────────────────────
    // Documento "Planilla": resumen apaisado del cargue para que el conductor lo
    // lleve en ruta — una fila por sucursal con hora de llegada/salida y un
    // espacio grande para el sello de cada sucursal. A pedido explícito de
    // Camilo (2026-09-21). Apaisado (landscape) y con celdas amplias tanto de
    // ancho como de alto para que quepa un sello físico real.
    public function planillaCargue(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $d = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with([
                'ordenes:id,cliente,sucursal_entrega,numero_orden,planilla_numero',
                'rutaObj:id,nombre',
                'conductorObj:id,nombre,documento,telefono',
                'vehiculoObj:id,placa,tipo',
            ])
            ->find($a['id']);
        if (!$d) return $this->notFound($res);

        $sucursales = $d->ordenes->pluck('sucursal_entrega')->filter()->unique()->sort()->values();
        if ($sucursales->isEmpty()) {
            return $this->error($res, 'Esta planilla de cargue no tiene pedidos asociados todavía.');
        }

        // Total Canastas por sucursal (módulo "Canastas por Ambiente") — 0 si
        // nadie ha diligenciado canastas para esta planilla todavía. Se busca
        // por "planilla" (planilla_numero real o etiqueta sintética DOC-<id>,
        // mismo criterio que remisionCanastasPorAmbiente()), no por
        // despacho_id: las canastas se capturan apenas termina la
        // certificación, normalmente ANTES de que exista este despacho.
        $planillas = $d->ordenes->map(fn($o) => trim($o->planilla_numero ?? '') !== ''
            ? trim($o->planilla_numero)
            : ('DOC-' . str_pad($o->id, 5, '0', STR_PAD_LEFT)))->unique();
        $canastasPorSuc = \App\Models\CanastaPlanilla::whereIn('planilla', $planillas)
            ->selectRaw('sucursal_entrega, SUM(cantidad) as total')
            ->groupBy('sucursal_entrega')
            ->pluck('total', 'sucursal_entrega');
        $totalCanastasGeneral = $canastasPorSuc->sum();

        $empNombre = $this->remisionEmpresaNombre($empresaId);
        $logoHtml  = $this->remisionLogoHtml($empNombre);
        $fecha     = $d->fecha_movimiento ? date('d/m/Y', strtotime($d->fecha_movimiento)) : date('d/m/Y');
        $ruta      = htmlspecialchars($d->rutaObj->nombre ?? $d->ruta ?? '—');
        $conductor = htmlspecialchars($d->conductorObj->nombre ?? $d->conductor ?? '—');
        $placa     = htmlspecialchars($d->vehiculoObj->placa ?? $d->placa ?? '—');
        $tipoVeh   = htmlspecialchars($d->vehiculoObj->tipo ?? '');

        $filas = $sucursales->map(fn($suc) => "<tr>
            <td class='celda-suc'>" . htmlspecialchars($suc) . "</td>
            <td class='celda-canastas'>" . (int)($canastasPorSuc[$suc] ?? 0) . "</td>
            <td class='celda-hora'></td>
            <td class='celda-hora'></td>
            <td class='celda-sello'></td>
        </tr>")->implode('');
        $filas .= "<tr>
            <td class='celda-suc' style='text-align:right'>TOTAL GENERAL</td>
            <td class='celda-canastas'>{$totalCanastasGeneral}</td>
            <td colspan='3'></td>
        </tr>";

        // Este documento se fusiona con Remisión/Liberación en una sola pestaña
        // de impresión (_imprimirConsolidadoUnaPestana en despacho.js), que
        // combina todos los <style> en una sola hoja. Remisión/Liberación usan
        // remisionCss() (BaseController) con clases genéricas (.header,
        // .info-grid, table/th/td, .no-print, etc.) — si esta Planilla usara
        // las MISMAS clases sin espacio de nombres, sus reglas (pensadas para
        // hoja apaisada, con fuentes y paddings más grandes) pisarían las de
        // Remisión/Liberación (pensadas para A4 vertical) en el documento
        // fusionado: incluso con el @page correcto ya arreglado, el tamaño de
        // fuente/celdas de Remisión saldría mal. TODO selector va bajo
        // .planilla-doc para blindarlo, y el propio @page queda nombrado (ver
        // .planilla-doc{page:...} más abajo) para no forzar horizontal en todo
        // el trabajo de impresión.
        // Margen real de 1cm parejo en los 4 lados (ver nota en remisionCss()
        // sobre por qué margin:0 no era la forma correcta de evitar el pie de
        // página del navegador).
        $css = "@page planilla-cargue-page{size:A4 landscape;margin:1cm}
        .planilla-doc{page:planilla-cargue-page;font-family:Arial,Helvetica,sans-serif;color:#111}
        @media print{.no-print{display:none!important}}
        .planilla-doc .header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e3a5f;padding-bottom:8px;margin-bottom:10px}
        .planilla-doc .header-left p{margin:2px 0 0;font-size:15px;font-weight:800;color:#1e3a5f;letter-spacing:.3px}
        .planilla-doc .header-right{text-align:right;font-size:12px;color:#1e293b}
        .planilla-doc .info-grid{display:flex;flex-wrap:wrap;gap:6px 28px;margin-bottom:14px;background:#f8fafc;padding:8px 14px;border-radius:6px;border:1px solid #cbd5e1}
        .planilla-doc .info-grid .campo{font-size:12px;color:#0f172a}
        .planilla-doc .info-grid .lbl{font-weight:800;font-size:10.5px;color:#334155;text-transform:uppercase;letter-spacing:.3px;margin-right:4px}
        .planilla-doc table{width:100%;border-collapse:collapse}
        .planilla-doc th{background:#1e3a5f;color:#fff;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.3px;padding:8px 10px;text-align:left}
        .planilla-doc td{border:1px solid #cbd5e1;padding:6px 10px;font-size:13px;vertical-align:middle}
        .planilla-doc .celda-suc{font-weight:700;width:30%}
        .planilla-doc .celda-canastas{width:12%;text-align:center;font-weight:800;font-size:15px;color:#1e3a5f}
        .planilla-doc .celda-hora{width:13%;height:90px}
        .planilla-doc .celda-sello{width:210px;height:90px}
        .planilla-doc tr{page-break-inside:avoid}
        .no-print{padding:8px 0;margin-bottom:10px}
        .no-print button{padding:8px 20px;font-size:13px;font-weight:bold;cursor:pointer;background:#1e3a5f;color:#fff;border:none;border-radius:5px}";

        $html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Planilla de Cargue &mdash; {$d->numero_despacho}</title>
<style>{$css}</style></head><body>
<div class='no-print'>
  <button onclick='window.print()'>&#128424; Imprimir / Guardar PDF</button>
</div>
<div class='planilla-doc'>
<div class='header'>
  <div class='header-left'>{$logoHtml}<p>PLANILLA DE CARGUE</p></div>
  <div class='header-right'><strong>{$d->numero_despacho}</strong><br>Fecha: {$fecha}</div>
</div>
<div class='info-grid'>
  <span class='campo'><span class='lbl'>Ruta:</span>{$ruta}</span>
  <span class='campo'><span class='lbl'>Conductor:</span>{$conductor}</span>
  <span class='campo'><span class='lbl'>Veh&iacute;culo:</span>{$placa}" . ($tipoVeh ? " ({$tipoVeh})" : '') . "</span>
  <span class='campo'><span class='lbl'>N&ordm; Sucursales:</span>{$sucursales->count()}</span>
</div>
<table>
  <thead><tr><th>Sucursal / Cliente</th><th>Total Canastas</th><th>Hora Llegada</th><th>Hora Salida</th><th>Sello</th></tr></thead>
  <tbody>{$filas}</tbody>
</table>
</div>
</body></html>";

        $body = $res->getBody();
        $body->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    // ── GET /api/despachos/canastas?planilla=X ────────────────────────────────
    // Módulo "Canastas por Ambiente" — a pedido explícito de Camilo (2026-09-25),
    // extendido el mismo día para móvil: captura del total de canastas por
    // sucursal y ambiente, identificada por "planilla" (planilla_numero real o
    // etiqueta sintética DOC-<id>, MISMO criterio que
    // _agruparPedidosCarguePorPlanilla()/certRemisionMultiple) en vez de por
    // despacho_id — las canastas se cuentan apenas termina la certificación,
    // normalmente antes de que exista una Planilla de Cargue/Despacho. Ese
    // dato sale luego en Remisión (remisionCanastasPorAmbiente() en
    // BaseController) y en Planilla de Cargue (columna "Total Canastas" en
    // planillaCargue()) una vez esa planilla se convierta en un despacho.
    public function verCanastasPlanilla(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $planilla = trim($r->getQueryParams()['planilla'] ?? '');
        if ($planilla === '') return $this->error($res, 'Se requiere el parámetro planilla');

        $ordenes = $this->resolverOrdenesPorPlanilla($planilla, $empresaId, $sucursalId);
        if ($ordenes->isEmpty()) {
            return $this->error($res, "No se encontraron pedidos para la planilla \"{$planilla}\".");
        }

        $sucursales = $ordenes->pluck('sucursal_entrega')->filter()->unique()->sort()->values();
        $ambientes = \App\Models\Ambiente::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('descripcion')
            ->get(['id', 'codigo', 'descripcion', 'icono', 'color']);

        $guardadas = \App\Models\CanastaPlanilla::where('planilla', $planilla)->get();
        $valores = [];
        foreach ($guardadas as $g) {
            $valores[$g->sucursal_entrega][$g->ambiente_id] = (int)$g->cantidad;
        }

        return $this->ok($res, [
            'planilla' => $planilla,
            'sucursales' => $sucursales,
            'ambientes' => $ambientes,
            'valores' => $valores,
        ]);
    }

    // ── POST /api/despachos/canastas ──────────────────────────────────────────
    public function guardarCanastasPlanilla(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $data = $r->getParsedBody() ?? [];
        $planilla = trim($data['planilla'] ?? '');
        $filas = $data['filas'] ?? [];
        if ($planilla === '' || !is_array($filas) || empty($filas)) {
            return $this->error($res, 'Se requiere planilla y al menos una fila (sucursal_entrega, ambiente_id, cantidad)');
        }

        Capsule::transaction(function () use ($filas, $planilla, $empresaId, $user) {
            foreach ($filas as $fila) {
                $sucursal = trim((string)($fila['sucursal_entrega'] ?? ''));
                $ambienteId = (int)($fila['ambiente_id'] ?? 0);
                if ($sucursal === '' || $ambienteId <= 0) continue;
                \App\Models\CanastaPlanilla::updateOrCreate(
                    ['planilla' => $planilla, 'sucursal_entrega' => $sucursal, 'ambiente_id' => $ambienteId],
                    ['cantidad' => max(0, (int)($fila['cantidad'] ?? 0)), 'empresa_id' => $empresaId, 'created_by' => $user->id]
                );
            }
        });

        return $this->ok($res, ['guardado' => true]);
    }

    // Resuelve las órdenes reales detrás de una "planilla" (planilla_numero,
    // numero_orden, planilla_lote, o etiqueta sintética DOC-<id> para pedidos
    // montados manualmente sin CSV) — mismo criterio de resolución que ya usa
    // PickingController::certRemisionMultiple() para el parámetro ?planilla=.
    private function resolverOrdenesPorPlanilla(string $planilla, int $empresaId, int $sucursalId)
    {
        $q = OrdenPicking::where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId);
        if (preg_match('/^DOC-0*(\d+)$/', $planilla, $m)) {
            $q->where('id', (int)$m[1]);
        } else {
            $q->where(function ($sq) use ($planilla) {
                $sq->where('planilla_numero', $planilla)
                   ->orWhere('numero_orden', $planilla)
                   ->orWhere('planilla_lote', $planilla);
            });
        }
        return $q->get(['id', 'sucursal_entrega']);
    }
}
