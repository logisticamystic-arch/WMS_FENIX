<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenPicking;
use App\Models\PickingDetalle;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\TareaReabastecimiento;
use App\Models\Producto;
use App\Helpers\InventoryGuard;
use App\Helpers\FefoEngine;
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
    // Parámetros de filtro: estado, auxiliar_id, pasillo, marca_id, ubicacion,
    //                       planilla, sin_auxiliar, fecha_inicio, fecha_fin, limit,
    //                       solo_hoy, incluir_finalizados, sucursal_entrega, ruta, q
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $limit = min((int)($params['limit'] ?? 100), 500);

        $soloHoy           = !empty($params['solo_hoy']);
        $incluirFinalizados = !empty($params['incluir_finalizados']);

        $fechaDesdeFilter = $params['fecha_desde'] ?? null;
        $fechaHastaFilter = $params['fecha_hasta'] ?? null;

        $q = OrdenPicking::where('orden_pickings.empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('orden_pickings.sucursal_id', $user->sucursal_id)
            ->when($fechaDesdeFilter && $fechaHastaFilter, fn($q) =>
                $q->whereDate('orden_pickings.fecha_movimiento', '>=', $fechaDesdeFilter)
                  ->whereDate('orden_pickings.fecha_movimiento', '<=', $fechaHastaFilter))
            ->when(!$soloHoy && !($fechaDesdeFilter && $fechaHastaFilter),
                fn($q) => $q->whereBetween('orden_pickings.created_at', [$ini, $fin]))
            ->when($soloHoy && !($fechaDesdeFilter && $fechaHastaFilter),
                fn($q) => $q->where(fn($sq) => $sq
                    ->whereIn('orden_pickings.estado', ['Pendiente', 'EnProceso'])
                    ->orWhereDate('orden_pickings.fecha_movimiento', date('Y-m-d'))
                ))
            ->when($params['estado'] ?? null, function($q, $e) {
                if (strpos($e, ',') !== false) {
                    $q->whereIn('estado', explode(',', $e));
                } else {
                    $q->where('estado', $e);
                }
            })
            ->when($params['auxiliar_id']  ?? null, fn($q, $v) => $q->where('auxiliar_id', (int)$v))
            ->when($params['sin_auxiliar'] ?? null, fn($q)     => $q->whereNull('orden_pickings.auxiliar_id'))
            ->when($params['cliente']      ?? null, fn($q, $v) => $q->where('cliente', 'like', "%$v%"))
            ->when($params['sucursal_entrega'] ?? null, function($q, $v) {
                $q->where(fn($sq) =>
                    $sq->where('orden_pickings.sucursal_entrega', 'like', "%$v%")
                       ->orWhere(fn($sq2) =>
                           $sq2->whereNull('orden_pickings.sucursal_entrega')
                               ->where('orden_pickings.cliente', 'like', "%$v%")
                       )
                );
            })
            ->when($params['ruta'] ?? null, fn($q, $v) => $q->where('orden_pickings.ruta', 'like', "%$v%"))
            ->when($params['estado_certificacion'] ?? null, fn($q, $v) => $q->where('orden_pickings.estado_certificacion', $v))
            ->when(isset($params['sin_despacho']), fn($q) => $q->whereNull('orden_pickings.estado_despacho'))
            ->when($params['q'] ?? null, function($q, $v) {
                $q->where(fn($sq) => $sq
                    ->where('orden_pickings.numero_pedido', 'like', "%$v%")
                    ->orWhere('orden_pickings.cliente', 'like', "%$v%")
                    ->orWhere('orden_pickings.sucursal_entrega', 'like', "%$v%")
                    ->orWhere('orden_pickings.ruta', 'like', "%$v%")
                );
            });

        // Filtro específico para versión móvil: mostrar tareas del usuario conectado
        if (!empty($params['tiene_asignadas'])) {
            $q->where(fn($sq) => $sq
                ->where('orden_pickings.auxiliar_id', $user->id)
                ->orWhereHas('detalles', fn($dq) => $dq->where('auxiliar_id', $user->id))
            );
        }

        // Filtro por pasillo: requiere JOIN a detalles → ubicaciones
        if (!empty($params['pasillo'])) {
            $pasillo = $params['pasillo'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
                ->where(fn($sq) => $sq
                    ->where('ubicaciones.pasillo', $pasillo)
                    ->orWhere('ubicaciones.codigo', 'like', "$pasillo%")
                )
            );
        }

        // Filtro por marca
        if (!empty($params['marca_id'])) {
            $marcaId = (int)$params['marca_id'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->where('productos.marca_id', $marcaId)
            );
        }

        // Filtro por ubicación (código)
        if (!empty($params['ubicacion'])) {
            $ubic = $params['ubicacion'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones as ub2', 'picking_detalles.ubicacion_id', '=', 'ub2.id')
                ->where('ub2.codigo', 'like', "%$ubic%")
            );
        }

        // Filtro por número de planilla o ruta
        if (!empty($params['planilla'])) {
            $planilla = $params['planilla'];
            $q->where(fn($sq) => $sq
                ->where('orden_pickings.planilla_numero', $planilla)
                ->orWhere('orden_pickings.area_comercial', 'like', "%$planilla%")
                ->orWhere('orden_pickings.cliente', 'like', "%$planilla%")
            );
        }

        // [id => codigo] — permite fallback cuando picking_detalles.ambiente es NULL
        $ambientesMap = \App\Models\Ambiente::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->pluck('codigo', 'id')->toArray();
        $ambientesCodes = array_values($ambientesMap);

        $ordenes = $q->with(['auxiliar:id,nombre', 'detalles.producto:id,empresa_id,nombre,codigo_interno,unidades_caja,ambiente_id', 'detalles.auxiliar:id,nombre'])
            ->withCount(['detalles as total_count'])
            ->orderByRaw($this->isPg()
                ? '(orden_pickings.sucursal_entrega IS NULL) ASC, orden_pickings.sucursal_entrega ASC NULLS LAST'
                : 'ISNULL(orden_pickings.sucursal_entrega) ASC, orden_pickings.sucursal_entrega ASC')
            ->orderBy('orden_pickings.prioridad')
            ->orderBy('orden_pickings.created_at', 'desc')
            ->limit($limit)
            ->get();

        // Conteos por ambiente. Si detalle.ambiente es NULL (órdenes antiguas), clasifica desde producto.ambiente_id.
        $incluirSeco = !in_array('seco', array_map('strtolower', $ambientesCodes));
        foreach ($ordenes as $orden) {
            $byAmb = $orden->detalles->groupBy(function ($d) use ($ambientesMap) {
                if (!empty($d->ambiente)) return strtolower($d->ambiente);
                $ambId = $d->producto->ambiente_id ?? null;
                return ($ambId && isset($ambientesMap[$ambId]))
                    ? strtolower($ambientesMap[$ambId])
                    : 'seco';
            });
            foreach ($ambientesCodes as $code) {
                $key = strtolower(str_replace(' ', '_', $code)) . '_count';
                $orden->{$key} = ($byAmb->get(strtolower($code)) ?? collect())->count();
            }
            if ($incluirSeco) {
                $orden->seco_count = ($byAmb->get('seco') ?? collect())->count();
            }
        }

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# Orden', 'Cliente', 'Estado', 'Prioridad', 'Auxiliar', 'F.Requerida', 'Planilla'];
            $rows = $ordenes->map(fn($o) => [
                $o->numero_orden, $o->cliente ?? '—', $o->estado,
                $o->prioridad, $o->auxiliar->nombre ?? '—',
                $o->fecha_requerida ?? '—',
                $o->planilla_numero ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'picking_' . date('Y-m-d'));
        }

        return $this->ok($res, $ordenes);
    }

    // ── GET /api/picking/consolidados ─────────────────────────────────────────
    // Agrupa órdenes Pendientes/EnProceso por cliente para picking consolidado
    public function consolidados(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $hoy        = date('Y-m-d');

        $ordenes = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->whereDate('fecha_movimiento', $hoy)   // solo pedidos del día actual
            ->with(['detalles.producto', 'detalles.auxiliar:id,nombre', 'auxiliar:id,nombre'])
            ->orderBy('prioridad')
            ->orderBy('created_at', 'desc')
            ->get();

        // Cargar consolidados del día para enriquecer la respuesta
        $consolidadosHoy = Capsule::table('picking_consolidados')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereDate('fecha_consolidacion', $hoy)
            ->get()
            ->keyBy('cliente');

        // Agrupar por cliente
        $grupos = [];
        foreach ($ordenes as $o) {
            $key = $o->cliente ?? 'Sin Cliente';
            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'cliente'          => $key,
                    'total_ordenes'    => 0,
                    'ordenes_pendientes' => 0,
                    'ordenes_en_proceso' => 0,
                    'prioridad_max'    => 9,
                    'productos_unicos' => [],
                    'ordenes'          => [],
                ];
            }
            $grupos[$key]['total_ordenes']++;
            if ($o->estado === 'Pendiente')  $grupos[$key]['ordenes_pendientes']++;
            if ($o->estado === 'EnProceso')  $grupos[$key]['ordenes_en_proceso']++;
            $grupos[$key]['prioridad_max'] = min($grupos[$key]['prioridad_max'], $o->prioridad ?? 9);
            foreach ($o->detalles as $d) {
                $grupos[$key]['productos_unicos'][$d->producto_id] = true;
            }
            $grupos[$key]['ordenes'][] = [
                'id'            => $o->id,
                'numero_orden'  => $o->numero_orden,
                'estado'        => $o->estado,
                'prioridad'     => $o->prioridad,
                'auxiliar'      => $o->auxiliar->nombre ?? null,
                'auxiliar_id'   => $o->auxiliar_id,
                'fecha_requerida' => $o->fecha_requerida,
                'total_lineas'  => count($o->detalles),
            ];
        }

        // Transformar para respuesta, incluir info del consolidado guardado
        $result = array_values(array_map(function ($g) use ($consolidadosHoy) {
            $g['total_productos_unicos'] = count($g['productos_unicos']);
            unset($g['productos_unicos']);
            $consol = $consolidadosHoy[$g['cliente']] ?? null;
            $g['consolidado_id']     = $consol->id ?? null;
            $g['consolidado_estado'] = $consol->estado ?? null;
            return $g;
        }, $grupos));

        // Ordenar por prioridad_max
        usort($result, fn($a, $b) => $a['prioridad_max'] <=> $b['prioridad_max']);

        return $this->ok($res, $result);
    }

    // ── POST /api/picking/asignar-ruta ───────────────────────────────────────
    // Assign a route name to all orders matching given IDs (planilla batch)
    public function asignarRuta(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $ordenIds = $data['orden_ids'] ?? [];
        $ruta     = trim($data['ruta'] ?? '');

        if (empty($ordenIds) || empty($ruta)) {
            return $this->error($res, 'Seleccione órdenes y proporcione un nombre de ruta');
        }

        $updated = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->whereIn('id', $ordenIds)
            ->update(['area_comercial' => $ruta]);

        return $this->ok($res, [
            'message'  => "Ruta '{$ruta}' asignada a {$updated} orden(es)",
            'updated'  => $updated,
        ]);
    }

    // ── POST /api/picking/asignar-multiple ────────────────────────────────────
    // Asigna auxiliar, genera rutas FEFO, RESERVA inventario (cantidad_reservada)
    // y registra faltantes automáticamente. El descuento real del stock solo
    // ocurre cuando el auxiliar confirma la línea (confirmLine).
    public function asignarMultiple(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $ordenIds    = array_map('intval', $data['orden_ids']    ?? []);
        // Soporta tanto personal_id (frontend) como auxiliar_id (legacy/api)
        $auxiliarId  = isset($data['personal_id']) ? (int)$data['personal_id'] : (isset($data['auxiliar_id']) ? (int)$data['auxiliar_id'] : null);
        $generarRuta = (bool)($data['generar_ruta'] ?? false);
        $separarConsolidado = (bool)($data['separar_consolidado'] ?? false);

        if (empty($ordenIds)) {
            return $this->error($res, 'Se requiere al menos una orden');
        }

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        // ── SINCRONIZAR RESERVAS HISTÓRICAS ──────────────────────────────────
        // Limpia reservas huérfanas y libera reservas de días anteriores, 
        // manteniendo solo las de hoy (regla de negocio solicitada)
        $this->_sincronizarReservas($empresaId, $sucursalId);

        $ordenes = OrdenPicking::whereIn('id', $ordenIds)
            ->where('empresa_id', $empresaId)
            ->get();

        $resultados = [
            'asignadas'        => 0,
            'rutas_generadas'  => 0,
            'inventario_reservado' => 0,
            'faltantes_detectados' => 0,
            'errores'          => [],
        ];

        $now = date('Y-m-d H:i:s');

        /** @var OrdenPicking $orden */
        foreach ($ordenes as $orden) {
            try {
                Capsule::transaction(function () use ($orden, $auxiliarId, $separarConsolidado, $user, $now, &$resultados, $generarRuta, $r) {
                    // ── 1. Asignar auxiliar ──────────────────────────────────────
                    if ($auxiliarId !== null) {
                        $orden->auxiliar_id = $auxiliarId;
                        if ($separarConsolidado) {
                            $orden->tipo_picking = 'Consolidado Almacenamiento';
                        }
                        $orden->save();

                        // Sincronizar auxiliar en todas las líneas de la orden
                        Capsule::table('picking_detalles')
                            ->where('orden_picking_id', $orden->id)
                            ->update(['auxiliar_id' => $auxiliarId, 'updated_at' => $now]);

                        $resultados['asignadas']++;
                    }

                    // ── 2. Reservar inventario (compromiso atómico) ──────────────
                    // Cargamos todos los detalles pendientes de la orden
                    $detalles = PickingDetalle::where('orden_picking_id', $orden->id)
                        ->whereIn('estado', ['Pendiente', 'Creado'])
                        ->with('producto')
                        ->get();

                    $productoIds = $detalles->pluck('producto_id')->unique()->toArray();

                    // Pre-carga batch de inventario disponible con lock pesimista
                    // Excluir productos bloqueados y lotes bloqueados
                    $prodsBloqueados = \App\Models\Producto::withoutGlobalScopes()
                        ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->where('bloqueado', true)->pluck('id')->toArray();
                    $lotesBloqueados = \App\Models\BloqueoLote::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->get(['producto_id', 'lote']);

                    $stockDisponible = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->where('sucursal_id', $user->sucursal_id)
                        ->whereIn('producto_id', $productoIds)
                        ->whereNotIn('producto_id', $prodsBloqueados)
                        ->where('estado', 'Disponible')
                        ->whereRaw('(cantidad - cantidad_reservada) > 0')
                        ->lockForUpdate()
                        // FEFO: NULLs al final mediante CASE WHEN (ver _generarRutaFEFO)
                        ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->get()
                        ->filter(function ($inv) use ($lotesBloqueados) {
                            foreach ($lotesBloqueados as $bl) {
                                if ($inv->producto_id == $bl->producto_id && $inv->lote === $bl->lote) return false;
                            }
                            return true;
                        })->values();

                    $stockPorProducto = $stockDisponible->groupBy('producto_id');

                    foreach ($detalles as $linea) {
                        $upc          = max(1, (int)($linea->producto->unidades_caja ?? 1));
                        $cantCajas    = (float)$linea->cantidad_solicitada;
                        $cantUnidades = $cantCajas * $upc;

                        $stockProducto   = $stockPorProducto->get($linea->producto_id, collect());
                        $totalDisponible = $stockProducto->sum(fn($inv) => max(0, $inv->cantidad - $inv->cantidad_reservada));

                        if ($totalDisponible >= $cantUnidades) {
                            // ── Caso A: Hay stock suficiente → RESERVAR en unidades ──
                            $splitsCasoA = [];
                            $restante    = $cantUnidades;
                            foreach ($stockProducto as $inv) {
                                if ($restante <= 0) break;
                                $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                                if ($disp <= 0) continue;

                                $aReservar = min($disp, $restante);
                                $inv->cantidad_reservada += $aReservar;
                                $inv->save();
                                $restante -= $aReservar;

                                $splitsCasoA[] = [
                                    'ubicacion_id'      => $inv->ubicacion_id,
                                    'lote'              => $inv->lote,
                                    'fecha_vencimiento' => $inv->fecha_vencimiento,
                                    'unidades'          => $aReservar,
                                ];
                            }

                            $linea->estado = 'EnProceso';
                            $this->_splitPickingLinea($linea, $splitsCasoA, $cantCajas, $upc);
                            $linea->save();
                            $resultados['inventario_reservado'] += $cantCajas;
                        } else {
                            // ── Caso B: Stock insuficiente → Reservar parcial + Faltante ──
                            $cajasDisponibles  = $upc > 0 ? floor($totalDisponible / $upc) : 0;
                            $faltanteCajas     = max(0, $cantCajas - $cajasDisponibles);
                            $unidadesAReservar = $cajasDisponibles * $upc;

                            if ($unidadesAReservar > 0) {
                                $splitsCasoB = [];
                                $restante    = $unidadesAReservar;
                                foreach ($stockProducto as $inv) {
                                    if ($restante <= 0) break;
                                    $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                                    if ($disp <= 0) continue;

                                    $aReservar = min($disp, $restante);
                                    $inv->cantidad_reservada += $aReservar;
                                    $inv->save();
                                    $restante -= $aReservar;

                                    $splitsCasoB[] = [
                                        'ubicacion_id'      => $inv->ubicacion_id,
                                        'lote'              => $inv->lote,
                                        'fecha_vencimiento' => $inv->fecha_vencimiento,
                                        'unidades'          => $aReservar,
                                    ];
                                }
                                $this->_splitPickingLinea($linea, $splitsCasoB, (float)$cajasDisponibles, $upc);
                                $resultados['inventario_reservado'] += (int)$cajasDisponibles;
                            }

                            Capsule::table('picking_faltantes')->insert([
                                'empresa_id'          => $this->getEffectiveEmpresaId($user, $r),
                                'sucursal_id'         => $user->sucursal_id,
                                'orden_picking_id'    => $orden->id,
                                'producto_id'         => $linea->producto_id,
                                'planilla_lote'       => $orden->planilla_lote ?? $orden->planilla_numero,
                                'cantidad_solicitada' => $cantCajas,
                                'cantidad_faltante'   => $faltanteCajas,
                                'causa'               => 'Stock insuficiente al asignar planilla - Reserva automática',
                                'created_at'          => $now,
                                'updated_at'          => $now,
                            ]);

                            $linea->estado = 'Faltante';
                            $linea->save();
                            $resultados['faltantes_detectados']++;
                        }
                    }

                    // ── 3. Actualizar estado de la orden ─────────────────────────
                    $lineasEnProceso = PickingDetalle::where('orden_picking_id', $orden->id)
                        ->where('estado', 'EnProceso')->count();
                    $lineasFaltante  = PickingDetalle::where('orden_picking_id', $orden->id)
                        ->where('estado', 'Faltante')->count();

                    if ($lineasEnProceso > 0) {
                        $orden->estado = 'EnProceso';
                    } elseif ($lineasFaltante > 0) {
                        $orden->estado = 'Faltante';
                    }
                    $orden->save();

                    // Generar ruta FEFO adicional si se solicitó explícitamente
                    if ($generarRuta && $orden->estado === 'Pendiente') {
                        $this->_generarRutaFEFO($orden, $user, $r);
                        $resultados['rutas_generadas']++;
                    }
                });
            } catch (\Exception $e) {
                $resultados['errores'][] = "Orden {$orden->numero_orden}: {$e->getMessage()}";
            }
        }

        // ── Actualizar estado del consolidado del día ─────────────────────────
        if ($auxiliarId !== null && $resultados['asignadas'] > 0 && !empty($ordenIds)) {
            try {
                $hoyConsl    = date('Y-m-d');
                $empConsl    = $this->getEffectiveEmpresaId($user, $r);
                $sucConsl    = $user->sucursal_id;
                $consolidados = Capsule::table('picking_consolidados')
                    ->where('empresa_id', $empConsl)
                    ->where('sucursal_id', $sucConsl)
                    ->whereDate('fecha_consolidacion', $hoyConsl)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->get();
                foreach ($consolidados as $consol) {
                    $consolIds = json_decode($consol->orden_ids ?? '[]', true) ?: [];
                    // Si alguna de las órdenes asignadas pertenece a este consolidado
                    if (!empty(array_intersect($consolIds, $ordenIds))) {
                        Capsule::table('picking_consolidados')
                            ->where('id', $consol->id)
                            ->update([
                                'estado'                => 'EnProceso',
                                'auxiliar_principal_id' => $auxiliarId,
                                'updated_at'            => date('Y-m-d H:i:s'),
                            ]);
                    }
                }
            } catch (\Throwable $ignored) {}
        }

        // Notificar al auxiliar si hubo asignaciones
        if ($auxiliarId !== null && $resultados['asignadas'] > 0) {
            \App\Controllers\NotificacionesController::crear(
                $this->getEffectiveEmpresaId($user, $r),
                $auxiliarId,
                'Nuevas Órdenes de Picking',
                "Se le han asignado {$resultados['asignadas']} órdenes para alistamiento. " .
                "{$resultados['inventario_reservado']} unidades reservadas, " .
                "{$resultados['faltantes_detectados']} faltantes detectados.",
                'picking',
                $user->id,
                'Picking',
                null,
                'viewPicking',
                'Picking',
                true,
                $user->sucursal_id
            );
        }

        // @audit — Registro profesional de la asignación masiva
        $this->audit($user, 'picking', 'asignar_multiple', 'orden_pickings', null,
            null, $resultados,
            "Asignación masiva: {$resultados['asignadas']} órdenes, " .
            "{$resultados['inventario_reservado']} unidades reservadas, " .
            "{$resultados['faltantes_detectados']} faltantes registrados");

        $msg = "{$resultados['asignadas']} asignadas";
        if ($resultados['inventario_reservado'] > 0) $msg .= ", {$resultados['inventario_reservado']} unidades reservadas";
        if ($resultados['faltantes_detectados'] > 0) $msg .= ", {$resultados['faltantes_detectados']} faltantes";

        return $this->ok($res, $resultados, $msg);
    }

    /**
     * Sincroniza y recalcula las reservas de inventario.
     * Libera reservas históricas/huérfanas y aplica la regla de negocio:
     * "La reserva solo debe quedar aplicada para pedidos del día actual".
     */
    private function _sincronizarReservas(int $empresaId, int $sucursalId): void
    {
        try {
            Capsule::transaction(function () use ($empresaId, $sucursalId) {
                // 1. Limpiar todas las reservas actuales para esta sucursal
                Capsule::table('inventarios')
                    ->where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('cantidad_reservada', '>', 0)
                    ->update(['cantidad_reservada' => 0]);

                // 2. Calcular la reserva requerida SOLO para órdenes de HOY o futuras
                $hoy = date('Y-m-d');
                $reservasReales = Capsule::table('picking_detalles as pd')
                    ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
                    ->join('productos as p', 'p.id', '=', 'pd.producto_id')
                    ->whereIn('pd.estado', ['EnProceso', 'Pendiente', 'Creado'])
                    ->whereIn('op.estado', ['Asignada', 'EnProceso'])
                    ->whereDate('op.fecha_movimiento', '>=', $hoy)
                    ->where('op.empresa_id', $empresaId)
                    ->where('op.sucursal_id', $sucursalId)
                    ->select([
                        'pd.producto_id',
                        Capsule::raw('SUM((pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1)) - pd.cantidad_pickeada) as debe_reservar')
                    ])
                    ->groupBy('pd.producto_id')
                    ->havingRaw('SUM((pd.cantidad_solicitada * COALESCE(p.unidades_caja, 1)) - pd.cantidad_pickeada) > 0')
                    ->get();

                // 3. Re-aplicar reservas (FEFO) solo para el stock disponible necesario
                foreach ($reservasReales as $r) {
                    $cantAReservar = (float)$r->debe_reservar;
                    
                    $invs = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $r->producto_id)
                        ->where('estado', 'Disponible')
                        ->where('cantidad', '>', 0)
                        ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('fecha_vencimiento', 'asc')
                        ->get();

                    $restante = $cantAReservar;
                    foreach ($invs as $inv) {
                        if ($restante <= 0) break;
                        $disp = (float)$inv->cantidad;
                        $aReservar = min($disp, $restante);
                        $inv->cantidad_reservada = $aReservar;
                        $inv->save();
                        $restante -= $aReservar;
                    }
                }
            });
        } catch (\Throwable $e) {
            error_log('[WMS] Error sincronizando reservas: ' . $e->getMessage());
        }
    }

    // ── Método privado: lógica FEFO reutilizable ──────────────────────────────
    // OPTIMIZADO: pre-carga todo el inventario de los productos de la orden
    // en una sola query agrupada, evitando N+1 (una query por línea antes).
    private function _generarRutaFEFO(OrdenPicking $orden, $user, Request $r): array
    {
        $alertas = [];
        $orden->load(['detalles.producto']);
        $now = date('Y-m-d H:i:s');
        $soloAlmacenamiento = ($orden->tipo_picking === 'Consolidado Almacenamiento');

        // ── PRE-CARGA BATCH DE INVENTARIOS (anti-N+1) ────────────────────────
        // IMPORTANTE: la lectura Y el lockForUpdate deben ocurrir DENTRO de la
        // transacción para evitar que dos órdenes concurrentes lean el mismo
        // inventario disponible y reserven en exceso (race condition).
        $productoIds = $orden->detalles->pluck('producto_id')->unique()->toArray();

        Capsule::transaction(function () use ($orden, $user, &$alertas, $now,
                                              $soloAlmacenamiento, $productoIds, $r) {
            // Excluir productos bloqueados y lotes bloqueados — mismo criterio que
            // asignarMultiple(), replicado aquí para que un lote retirado por calidad
            // o vencimiento no pueda ser ruteado/despachado por esta vía.
            $prodsBloqueados = \App\Models\Producto::withoutGlobalScopes()
                ->where('empresa_id', $orden->empresa_id)
                ->where('bloqueado', true)->pluck('id')->toArray();
            $lotesBloqueados = \App\Models\BloqueoLote::where('empresa_id', $orden->empresa_id)
                ->get(['producto_id', 'lote']);

            // Leer y bloquear inventario DENTRO de la transacción
            $todosLosStock = Inventario::where('empresa_id', $orden->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereIn('producto_id', $productoIds)
                ->whereNotIn('producto_id', $prodsBloqueados)
                ->where('estado', 'Disponible')
                ->where('cantidad', '>', 0)
                ->with('ubicacion:id,tipo_ubicacion,codigo,zona')
                // FEFO: lotes con fecha_vencimiento primero (IS NULL → 1 va al final),
                // luego ascendente por fecha (el que vence antes, primero).
                // NOTA: "IS NULL ASC" pondría los NULLs PRIMERO (IS NULL = 1 > 0),
                // por eso se usa CASE WHEN explícito para garantizar NULLs al final.
                ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('fecha_vencimiento', 'ASC')
                ->lockForUpdate()
                ->get()
                ->filter(function ($inv) use ($lotesBloqueados) {
                    foreach ($lotesBloqueados as $bl) {
                        if ($inv->producto_id == $bl->producto_id && $inv->lote === $bl->lote) return false;
                    }
                    return true;
                })->values();

            // Indexar por producto_id para acceso O(1) en el foreach
            $stockPorProducto = $todosLosStock->groupBy('producto_id');
            foreach ($orden->detalles as $linea) {
                $qtyEnPicking = 0;
                $stockProducto = $stockPorProducto->get($linea->producto_id, collect());

                if (!$soloAlmacenamiento) {
                    // 1. Buscar en Zona de Picking (Prioridad 1) — desde cache batch
                    $pickingStock = $stockProducto->filter(
                        fn($i) => ($i->ubicacion->tipo_ubicacion ?? '') === 'Picking'
                    );

                    $qtyEnPicking = $pickingStock->sum('cantidad');

                    if ($qtyEnPicking >= $linea->cantidad_solicitada) {
                        // Caso A: Hay suficiente en Picking — split multi-ubicación FEFO
                        // Si el stock está repartido en varios registros/lotes, generamos
                        // sub-líneas (splits) para cubrir la cantidad completa.
                        $splitsA   = [];
                        $restanteA = (float)$linea->cantidad_solicitada;
                        foreach ($pickingStock as $invA) {
                            if ($restanteA <= 0) break;
                            $disp = max(0, $invA->cantidad - ($invA->cantidad_reservada ?? 0));
                            if ($disp <= 0) continue;
                            $tomar = min($disp, $restanteA);
                            $splitsA[] = [
                                'ubicacion_id'      => $invA->ubicacion_id,
                                'lote'              => $invA->lote,
                                'fecha_vencimiento' => $invA->fecha_vencimiento,
                                'unidades'          => $tomar,
                            ];
                            $restanteA -= $tomar;
                        }
                        $upcA = max(1, (int)($linea->producto->unidades_caja ?? 1));
                        $this->_splitPickingLinea($linea, $splitsA, (float)$linea->cantidad_solicitada, $upcA);
                        $linea->estado = 'EnProceso';
                        $linea->save();
                        continue;
                    }
                }

                // Caso B: Falta en Picking. Buscar en el resto de la bodega (Almacenamiento)
                if ($soloAlmacenamiento) {
                    $stockGlobal = $stockProducto->filter(
                        fn($i) => in_array($i->ubicacion->tipo_ubicacion ?? '',
                                           ['Almacenamiento', 'Rack', 'Estante'])
                    );
                } else {
                    $stockGlobal = $stockProducto->filter(
                        fn($i) => ($i->ubicacion->tipo_ubicacion ?? '') !== 'Picking'
                    );
                }

                // ── [resto del código sin cambios desde aquí] ─────────────────

                $totalDisponible = $qtyEnPicking + $stockGlobal->sum('cantidad');

                if ($totalDisponible < $linea->cantidad_solicitada) {
                    // Caso C: Faltante Global (Novedad)
                    $faltante = $linea->cantidad_solicitada - $totalDisponible;
                    
                    Capsule::table('picking_faltantes')->insert([
                        'empresa_id'          => $orden->empresa_id,
                        'sucursal_id'         => $user->sucursal_id,
                        'orden_picking_id'    => $orden->id,
                        'producto_id'         => $linea->producto_id,
                        'planilla_lote'       => $orden->planilla_lote,
                        'cantidad_solicitada' => $linea->cantidad_solicitada,
                        'cantidad_faltante'   => $faltante,
                        'causa'               => 'Stock insuficiente en toda la bodega',
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);

                    $linea->estado = 'Faltante';
                    $linea->save();

                    $alertas[] = "Faltante global: {$linea->producto->nombre} ({$faltante} unds)";
                } else {
                    if ($soloAlmacenamiento) {
                        // Montacarguista recoge de Almacenamiento — split multi-ubicación si hace falta
                        $splitsAlm   = [];
                        $restanteAlm = (float)$linea->cantidad_solicitada;
                        foreach ($stockGlobal as $invAlm) {
                            if ($restanteAlm <= 0) break;
                            $dispAlm = max(0, $invAlm->cantidad - ($invAlm->cantidad_reservada ?? 0));
                            if ($dispAlm <= 0) continue;
                            $tomarAlm = min($dispAlm, $restanteAlm);
                            $splitsAlm[] = [
                                'ubicacion_id'      => $invAlm->ubicacion_id,
                                'lote'              => $invAlm->lote,
                                'fecha_vencimiento' => $invAlm->fecha_vencimiento,
                                'unidades'          => $tomarAlm,
                            ];
                            $restanteAlm -= $tomarAlm;
                        }
                        $upcAlm = max(1, (int)($linea->producto->unidades_caja ?? 1));
                        $this->_splitPickingLinea($linea, $splitsAlm, (float)$linea->cantidad_solicitada, $upcAlm);
                        $linea->estado = 'EnProceso';
                        $linea->save();
                    } else {
                        // Caso D: Hay en la bodega pero no en Picking -> Reabastecimiento Automático
                        $qtyFaltanteEnPicking = $linea->cantidad_solicitada - $qtyEnPicking;
                        $source = $stockGlobal->first();

                        // Intentar encontrar una ubicación de picking destino para este producto
                        $destino = \App\Models\Ubicacion::where('sucursal_id', $user->sucursal_id)
                            ->where('tipo_ubicacion', 'Picking')
                            ->where('activo', 1)
                            ->first(); // TODO: Podría ser la ubicación habitual del producto

                        TareaReabastecimiento::create([
                            'empresa_id'           => $orden->empresa_id,
                            'sucursal_id'          => $user->sucursal_id,
                            'orden_picking_id'     => $orden->id,
                            'producto_id'          => $linea->producto_id,
                            'ubicacion_origen_id'  => $source->ubicacion_id,
                            'ubicacion_destino_id' => $destino ? $destino->id : $source->ubicacion_id,
                            'cantidad'             => $qtyFaltanteEnPicking,
                            'estado'               => 'Pendiente',
                            'fecha_movimiento'     => date('Y-m-d'),
                        ]);

                        // Marcamos la línea como pendiente de reabastecimiento para que no se pierda
                        $linea->estado = 'PendienteReabastecimiento';
                        $linea->save();
                        
                        $alertas[] = "Reabastecimiento generado para {$linea->producto->nombre}";
                    }
                }
            }

            $asignadas = 0;
            foreach ($orden->detalles as $linea) {
                if (in_array($linea->estado, ['EnProceso', 'PendienteReabastecimiento'])) {
                    $asignadas++;
                }
            }

            if ($asignadas === 0) {
                // Si ninguna línea pudo ser servida, la orden NO pasa a EnProceso
                $orden->estado = 'Pendiente'; 
                $orden->save();
                throw new \Exception('No se encontró stock para ninguna de las líneas de esta orden. La ruta no puede ser generada.');
            }

            $orden->estado = 'EnProceso';
            $orden->save();
        });

        return $alertas;
    }

    // ── GET /api/picking/novedades-stock ──────────────────────────────────────
    // Retorna faltantes de stock registrados durante la generación de rutas FEFO.
    // Deduplica por (producto_id, sucursal_entrega): una fila por producto+sucursal
    // con cantidades sumadas. Incluye stock actual en tiempo real.
    // Filtros: fecha_inicio, fecha_fin, numero_planilla, producto, limit, export=excel
    public function novedadesStockLegacy(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        // Fix 2: default a hoy en vez de rango de 30 días
        $fechaInicio = $params['fecha_inicio'] ?? date('Y-m-d');
        $fechaFin    = $params['fecha_fin']    ?? date('Y-m-d');

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        // Para admin/supervisor, permitir filtrar por sucursal específica
        $sucursalIdFiltro = $user->sucursal_id;
        if (!empty($params['sucursal_id']) && in_array($user->rol, ['Admin', 'Supervisor'])) {
            $sucursalIdFiltro = (int)$params['sucursal_id'];
        }

        // Subquery para stock actual en tiempo real (referencia f = alias del subgrupo)
        $stockSubquery = Capsule::raw("(
            SELECT COALESCE(SUM(i.cantidad - i.cantidad_reservada), 0)
            FROM inventarios i
            WHERE i.producto_id = f.producto_id
              AND i.empresa_id = f.empresa_id
              AND i.sucursal_id = f.sucursal_id
              AND i.estado = 'Disponible'
              AND (i.cantidad - i.cantidad_reservada) > 0
        ) as stock_actual");

        // Fix 1: agrupar por (producto_id, sucursal_entrega) para deduplicar
        $likeOp = (Capsule::connection()->getDriverName() === 'pgsql') ? 'ilike' : 'like';

        $query = Capsule::table('picking_faltantes as f')
            ->join('productos as p',         'f.producto_id',       '=', 'p.id')
            ->leftJoin('orden_pickings as o', 'f.orden_picking_id',  '=', 'o.id')
            ->leftJoin('causales_novedad as cn', 'cn.id',            '=', 'f.causal_id')
            ->where('f.empresa_id',  $empresaId)
            ->where('f.sucursal_id', $sucursalIdFiltro)
            ->whereBetween('f.created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
            ->when($params['numero_planilla'] ?? null, fn($q, $v) => $q->where('f.planilla_lote', $v))
            ->when($params['sucursal_entrega'] ?? null, fn($q, $v) => $q->where('o.sucursal_entrega', $likeOp, "%{$v}%"))
            ->when($params['producto'] ?? null, function($q, $v) use ($likeOp) {
                $q->where(fn($sub) => $sub
                    ->where('p.nombre', $likeOp, "%{$v}%")
                    ->orWhere('p.codigo_interno', $likeOp, "%{$v}%")
                );
            })
            ->groupBy(
                'f.producto_id',
                'o.sucursal_entrega',
                'f.empresa_id',
                'f.sucursal_id',
                'p.codigo_interno',
                'p.nombre',
                'p.unidades_caja'
            )
            ->select(
                'f.producto_id',
                'f.empresa_id',
                'f.sucursal_id',
                'o.sucursal_entrega',
                'p.codigo_interno as producto_codigo',
                'p.nombre as producto_nombre',
                'p.unidades_caja',
                Capsule::raw('MIN(f.created_at) as created_at'),
                Capsule::raw('SUM(f.cantidad_solicitada) as cantidad_solicitada'),
                Capsule::raw('SUM(f.cantidad_faltante) as cantidad_faltante'),
                // Lo que sí se logró separar de esta referencia (solicitado - faltante),
                // NO confundir con stock_actual (subquery abajo, es el inventario libre real).
                Capsule::raw('SUM(f.cantidad_solicitada) - SUM(f.cantidad_faltante) as cantidad_separada'),
                Capsule::raw('SUM(f.cantidad_solicitada) * COALESCE(p.unidades_caja, 1) as cantidad_solicitada_und'),
                Capsule::raw('(SUM(f.cantidad_solicitada) - SUM(f.cantidad_faltante)) * COALESCE(p.unidades_caja, 1) as cantidad_separada_und'),
                Capsule::raw('SUM(f.cantidad_faltante) * COALESCE(p.unidades_caja, 1) as cantidad_faltante_und'),
                Capsule::raw("STRING_AGG(DISTINCT f.planilla_lote::text, ', ' ORDER BY f.planilla_lote::text) as numero_planilla"),
                Capsule::raw("STRING_AGG(DISTINCT cn.nombre, ', ') as causal_nombre"),
                Capsule::raw("STRING_AGG(DISTINCT f.causa, ' | ') as causa"),
                $stockSubquery
            )
            ->orderBy(Capsule::raw('MIN(f.created_at)'), 'desc');

        // Obtener todos los registros (ya deduplicados por GROUP BY producto_id+sucursal_entrega)
        $allRows = $query->get();
        $total   = $allRows->count();

        // Aplicar límite si se solicita (por defecto sin límite para export)
        $limit = isset($params['limit']) ? (int)$params['limit'] : null;
        $rows = ($limit && ($params['export'] ?? '') !== 'excel')
            ? $allRows->take($limit)
            : $allRows;

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Fecha', 'Planillas', 'Sucursal Destino', 'Código', 'Producto',
                        'Solicitado (cj)', 'Solicitado (UND)', 'Separado (cj)', 'Separado (UND)',
                        'Faltante (cj)', 'Faltante (UND)', 'Stock Actual (UND)', 'Causal', 'Observación'];
            $data = $rows->map(fn($row) => [
                substr($row->created_at ?? '', 0, 10),
                $row->numero_planilla      ?? '—',
                $row->sucursal_entrega     ?? '—',
                $row->producto_codigo      ?? '—',
                $row->producto_nombre      ?? '—',
                $row->cantidad_solicitada,
                $row->cantidad_solicitada_und,
                $row->cantidad_separada,
                $row->cantidad_separada_und,
                $row->cantidad_faltante,
                $row->cantidad_faltante_und,
                $row->stock_actual ?? 0,
                $row->causal_nombre ?? '—',
                $row->causa ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $data, 'faltantes_picking_' . date('Y-m-d'));
        }

        return $this->ok($res, ['rows' => $rows, 'total' => $total]);
    }

    // ── DELETE /api/picking/faltantes ─────────────────────────────────────────
    // Limpia registros de picking_faltantes por rango de fechas y/o sucursal.
    // Solo Admin/Supervisor pueden ejecutar esta acción.
    public function limpiarFaltantes(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if (!in_array($user->rol, ['Admin', 'Supervisor'])) {
            return $this->err($res, 'No autorizado', 403);
        }

        $params      = $r->getQueryParams();
        $fechaInicio = $params['fecha_inicio'] ?? date('Y-m-d');
        $fechaFin    = $params['fecha_fin']    ?? date('Y-m-d');
        $empresaId   = $this->getEffectiveEmpresaId($user, $r);

        $query = Capsule::table('picking_faltantes')
            ->where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59']);

        // Filtro por sucursal_entrega via join con orden_pickings
        if (!empty($params['sucursal_entrega'])) {
            $sucursal = $params['sucursal_entrega'];
            $query->whereIn('orden_picking_id', function($sub) use ($sucursal) {
                $sub->select('id')
                    ->from('orden_pickings')
                    ->where('sucursal_entrega', 'like', "%{$sucursal}%");
            });
        }

        $eliminados = $query->delete();

        return $this->ok($res, [
            'eliminados' => $eliminados,
            'message'    => "Se eliminaron {$eliminados} registros de faltantes.",
        ]);
    }

    public function novedadesStock(Request $r, Response $res): Response
    {
        return $this->novedadesStockLegacy($r, $res);
    }

    // ── POST /api/picking/backorder ──────────────────────────────────────────
    // Proceso de Backorder: re-asigna faltantes al picking cuando el inventario
    // ha sido reabastecido. Reserva atómicamente, reactiva líneas de picking
    // y elimina los registros de faltantes procesados.
    public function procesarBackorder(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        // Acepta producto_ids (flujo agrupado) o faltante_ids (flujo legacy individual)
        $productoIds  = array_filter(array_map('intval', $data['producto_ids']  ?? []), fn($v) => $v > 0);
        $faltanteIds  = array_filter(array_map('intval', $data['faltante_ids']  ?? []), fn($v) => $v > 0);

        if (empty($productoIds) && empty($faltanteIds)) {
            return $this->error($res, 'Seleccione al menos un producto para procesar backorder');
        }

        $now = date('Y-m-d H:i:s');
        $hoy = date('Y-m-d');
        $resultados = [
            'procesados'              => 0,
            'sin_stock'               => 0,
            'reservados'              => 0,
            'eliminados'              => 0,
            'omitidos_ya_certificados'=> 0,
            'errores'                 => [],
            'detalle'                 => [],
        ];

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        // Solo procesar faltantes de órdenes recientes (últimos 3 días)
        // Evita reactivar pedidos viejos; cubre picking nocturno iniciado el día anterior
        $fechaMinBackorder = date('Y-m-d', strtotime('-2 days'));
        $faltantesQuery = Capsule::table('picking_faltantes as pf')
            ->join('orden_pickings as op', 'pf.orden_picking_id', '=', 'op.id')
            ->where('pf.empresa_id', $empresaId)
            ->where('pf.sucursal_id', $sucursalId)
            ->where(fn($q) => $q
                ->whereDate('op.fecha_movimiento', '>=', $fechaMinBackorder)
                ->orWhereDate('op.created_at', '>=', $fechaMinBackorder)
            )
            ->select('pf.*');

        if (!empty($productoIds)) {
            $faltantesQuery->whereIn('pf.producto_id', $productoIds);
        } else {
            $faltantesQuery->whereIn('pf.id', $faltanteIds);
        }

        $faltantes = $faltantesQuery->get();

        if ($faltantes->isEmpty()) {
            return $this->error($res, 'No hay faltantes recientes para procesar. El backorder solo aplica a pedidos de los últimos 3 días.');
        }

        foreach ($faltantes as $falt) {
            try {
                Capsule::transaction(function () use ($falt, $user, $now, $hoy, &$resultados, $r) {
                    // Obtener producto y convertir cajas → unidades para comparar inventario
                    $producto = \App\Models\Producto::find($falt->producto_id);
                    $nombreProducto = $producto->nombre ?? $producto->descripcion ?? "ID:{$falt->producto_id}";
                    $upc = max(1, (int)($producto->unidades_caja ?? 1));
                    $cantidadNecesaria = (float)$falt->cantidad_faltante * $upc; // UNIDADES

                    // ── 0. No tocar órdenes ya certificadas en un día distinto a hoy, o
                    // que YA fueron despachadas/entregadas (estado_despacho) sin importar
                    // la fecha de certificación ──
                    // BUG CORREGIDO: esta guarda solo miraba "certificada en un día
                    // distinto a hoy", pero una orden certificada Y despachada/entregada
                    // EL MISMO DÍA (p.ej. limpieza de un lote de pedidos atrasados) pasaba
                    // este chequeo sin problema y quedaba reabierta si aún existía un
                    // registro viejo en picking_faltantes para ese producto — esto reactivó
                    // planillas ya "Entregado" (ej. Planillas 111-169 Industriales, 171
                    // Visitación) y las volvía a mostrar como pendientes en Despacho.
                    // Ahora: si estado_despacho ya tiene cualquier valor (Despachado o
                    // Entregado), NUNCA se reabre — sin importar cuándo se certificó.
                    $ordenPrevia = OrdenPicking::find($falt->orden_picking_id);
                    if ($ordenPrevia && !empty($ordenPrevia->estado_despacho)) {
                        $resultados['omitidos_ya_certificados']++;
                        $resultados['detalle'][] = [
                            'faltante_id' => $falt->id,
                            'producto'    => $nombreProducto,
                            'orden_id'    => $ordenPrevia->id,
                            'estado'      => 'omitido_ya_despachado',
                            'motivo'      => "La orden #{$ordenPrevia->id} ya fue {$ordenPrevia->estado_despacho} — no se reabre automáticamente. Gestione el faltante manualmente si aplica.",
                        ];
                        return;
                    }
                    if ($ordenPrevia
                        && $ordenPrevia->estado_certificacion === 'Certificada'
                        && $ordenPrevia->fecha_certificacion
                        && substr($ordenPrevia->fecha_certificacion, 0, 10) !== $hoy
                    ) {
                        $resultados['omitidos_ya_certificados']++;
                        $resultados['detalle'][] = [
                            'faltante_id' => $falt->id,
                            'producto'    => $nombreProducto,
                            'orden_id'    => $ordenPrevia->id,
                            'estado'      => 'omitido_ya_certificado',
                            'motivo'      => "La orden #{$ordenPrevia->id} ya fue certificada el " . substr($ordenPrevia->fecha_certificacion, 0, 10) . " — no se reabre automáticamente.",
                        ];
                        return;
                    }

                    // ── 1. Verificar stock actual disponible (con lock pesimista) ──
                    $stockDisponible = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $falt->producto_id)
                        ->where('estado', 'Disponible')
                        ->whereRaw('(cantidad - cantidad_reservada) > 0')
                        ->lockForUpdate()
                        ->orderByRaw('fecha_vencimiento IS NULL ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->get();

                    $totalDisponible = $stockDisponible->sum(fn($inv) => max(0, $inv->cantidad - $inv->cantidad_reservada));

                    if ($totalDisponible < $cantidadNecesaria) {
                        // Sin stock suficiente todavía
                        $resultados['sin_stock']++;
                        $resultados['detalle'][] = [
                            'faltante_id' => $falt->id,
                            'producto'    => $nombreProducto,
                            'necesario'   => $falt->cantidad_faltante,
                            'disponible'  => $upc > 0 ? floor($totalDisponible / $upc) : 0,
                            'estado'      => 'sin_stock',
                        ];
                        return;
                    }

                    // ── 2. Reservar inventario FEFO (en unidades) ────────────────
                    $restante = $cantidadNecesaria;
                    $ubicacionAsignada = null;
                    $loteAsignado = null;
                    $fechaVencAsignada = null;
                    $reservasHechas = []; // [[ubicacion_id, lote, cantidad], ...] para poder revertir con precisión

                    foreach ($stockDisponible as $inv) {
                        if ($restante <= 0) break;
                        $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                        if ($disp <= 0) continue;

                        $aReservar = min($disp, $restante);
                        $inv->cantidad_reservada += $aReservar;
                        $inv->save();
                        $restante -= $aReservar;
                        $reservasHechas[] = [$inv->ubicacion_id, $inv->lote, $aReservar];

                        // Tomar la primera ubicación FEFO
                        if (!$ubicacionAsignada) {
                            $ubicacionAsignada  = $inv->ubicacion_id;
                            $loteAsignado       = $inv->lote;
                            $fechaVencAsignada  = $inv->fecha_vencimiento;
                        }
                    }

                    $resultados['reservados'] += $falt->cantidad_faltante; // en cajas

                    // ── 3. Reactivar la línea de picking ─────────────────────────
                    // Cargar la orden PRIMERO para obtener la asignación actual de auxiliar
                    $orden = OrdenPicking::find($falt->orden_picking_id);

                    $lineaFaltante = PickingDetalle::where('orden_picking_id', $falt->orden_picking_id)
                        ->where('producto_id', $falt->producto_id)
                        ->where('estado', 'Faltante')
                        ->first();

                    // Segunda barrera de seguridad (además del guard del paso 0): si la
                    // orden ya quedó despachada/entregada, no reactivar nada — liberar la
                    // reserva hecha en el paso 2 (si no, quedaría stock reservado huérfano
                    // sin ninguna línea de picking que lo vaya a consumir) y abortar.
                    if ($orden && !empty($orden->estado_despacho)) {
                        foreach ($reservasHechas as [$ubiRes, $loteRes, $cantRes]) {
                            $this->_releaseReserva(
                                $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
                                $falt->producto_id, $ubiRes, $loteRes, $cantRes
                            );
                        }
                        $resultados['omitidos_ya_certificados']++;
                        $resultados['detalle'][] = [
                            'faltante_id' => $falt->id,
                            'producto'    => $nombreProducto,
                            'orden_id'    => $orden->id,
                            'estado'      => 'omitido_ya_despachado',
                            'motivo'      => "La orden #{$orden->id} ya fue {$orden->estado_despacho} — no se reabre automáticamente.",
                        ];
                        return;
                    }

                    if ($lineaFaltante && $orden) {
                        $lineaFaltante->estado            = 'EnProceso';
                        $lineaFaltante->ubicacion_id      = $ubicacionAsignada;
                        $lineaFaltante->lote              = $loteAsignado;
                        $lineaFaltante->fecha_vencimiento = $fechaVencAsignada;
                        // Se mantiene el auxiliar original que estaba asignado a la línea
                        // según requerimiento, para que vuelva a aparecer a dicho auxiliar.
                        // $lineaFaltante->auxiliar_id = $orden->auxiliar_id;
                        $lineaFaltante->save();

                        if (in_array($orden->estado, ['Faltante', 'Completada'])) {
                            $orden->estado = 'EnProceso';
                            if ($orden->estado_certificacion === 'Certificada') {
                                $orden->estado_certificacion = 'Pendiente';
                                $orden->fecha_certificacion  = null;
                            }
                            $orden->save();
                        }
                    }

                    // ── 4. Eliminar el registro de faltante ─────────────────────
                    Capsule::table('picking_faltantes')->where('id', $falt->id)->delete();
                    $resultados['eliminados']++;

                    $resultados['procesados']++;
                    $resultados['detalle'][] = [
                        'faltante_id' => $falt->id,
                        'producto'    => $nombreProducto,
                        'necesario'   => $cantidadNecesaria,
                        'disponible'  => (int)$totalDisponible,
                        'estado'      => 'backorder_ok',
                    ];
                });
            } catch (\Exception $e) {
                $resultados['errores'][] = "Faltante #{$falt->id}: {$e->getMessage()}";
            }
        }

        // @audit — Registro profesional del backorder
        $this->audit($user, 'picking', 'backorder', 'picking_faltantes', null,
            ['faltante_ids' => $faltanteIds],
            $resultados,
            "Backorder: {$resultados['procesados']} procesados, " .
            "{$resultados['reservados']} unidades reservadas, " .
            "{$resultados['sin_stock']} sin stock aún");

        $msg = "{$resultados['procesados']} faltante(s) procesados";
        if ($resultados['sin_stock'] > 0) $msg .= ", {$resultados['sin_stock']} sin stock";
        if ($resultados['reservados'] > 0) $msg .= ", {$resultados['reservados']} unidades reservadas";
        if ($resultados['omitidos_ya_certificados'] > 0) {
            $msg .= ", {$resultados['omitidos_ya_certificados']} omitido(s) por pertenecer a pedidos ya certificados en otra fecha (requieren revisión manual)";
        }

        return $this->ok($res, $resultados, $msg);
    }

    // ── GET /api/picking/{id} ─────────────────────────────────────────────────
    public function detalle(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->find($a['id']);

        if (!$orden) return $this->notFound($res);

        // ── CTE anti-N+1: carga detalles + stock disponible en una sola query ─
        // Sustituye el with(['detalles.producto','detalles.ubicacion']) que
        // generaba una query extra por cada línea para obtener el stock.
        if ($this->isPg()) {
            $detalles = Capsule::select("
                WITH stock_agg AS (
                    SELECT
                        producto_id,
                        SUM(cantidad) FILTER (WHERE estado = 'Disponible') AS stock_disponible,
                        MIN(fecha_vencimiento) FILTER (WHERE estado = 'Disponible'
                                                        AND fecha_vencimiento IS NOT NULL
                                                        AND fecha_vencimiento >= CURRENT_DATE) AS proximo_vencer,
                        SUM(cantidad) FILTER (WHERE estado = 'Disponible'
                                              AND fecha_vencimiento IS NOT NULL
                                              AND fecha_vencimiento < CURRENT_DATE) AS stock_vencido
                    FROM inventarios
                    WHERE empresa_id = ? AND sucursal_id = ? AND cantidad > 0
                    GROUP BY producto_id
                )
                SELECT
                    pd.*,
                    p.nombre            AS producto_nombre,
                    p.codigo_interno    AS producto_codigo,
                    p.unidades_caja     AS producto_unidades_caja,
                    ub.codigo           AS ubicacion_codigo,
                    ub.pasillo          AS ubicacion_pasillo,
                    ub.zona             AS ubicacion_zona,
                    COALESCE(sa.stock_disponible, 0) AS stock_disponible,
                    sa.proximo_vencer,
                    COALESCE(sa.stock_vencido, 0) AS stock_vencido,
                    COALESCE(pd.ambiente, am.codigo, 'Seco') AS ambiente
                FROM picking_detalles pd
                JOIN productos p         ON pd.producto_id  = p.id
                LEFT JOIN ambientes am   ON am.id = p.ambiente_id
                LEFT JOIN ubicaciones ub ON pd.ubicacion_id = ub.id
                LEFT JOIN stock_agg sa   ON sa.producto_id  = pd.producto_id
                WHERE pd.orden_picking_id = ?
                ORDER BY ub.pasillo ASC NULLS LAST, ub.posicion ASC NULLS LAST, ub.nivel ASC NULLS LAST, ub.codigo ASC NULLS LAST, pd.id ASC
            ", [$this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $orden->id]);
        } else {
            // MySQL fallback: eager loading estándar
            $detalles = $orden->load(['detalles.producto', 'detalles.ubicacion'])->detalles;
        }

        // Normaliza estructura: añade objeto 'producto' anidado para que el
        // frontend pueda acceder a d.producto?.nombre / d.producto?.codigo_interno
        if ($this->isPg()) {
            $detalles = array_map(function ($d) {
                $row = (array) $d;
                $row['producto'] = [
                    'id'            => $row['producto_id'] ?? null,
                    'nombre'        => $row['producto_nombre'] ?? null,
                    'codigo_interno'=> $row['producto_codigo'] ?? null,
                    'unidades_caja' => $row['producto_unidades_caja'] ?? null,
                ];
                return $row;
            }, $detalles);
        }

        return $this->ok($res, array_merge($orden->toArray(), ['detalles' => $detalles]));
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
            $orden = Capsule::transaction(function () use ($data, $user, $r) {
                $numeroOrden = !empty($data['numero_pedido'])
                    ? trim($data['numero_pedido'])
                    : 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

                if (OrdenPicking::where('numero_orden', $numeroOrden)->exists()) {
                    throw new \RuntimeException(
                        "Ya existe una orden con el número '{$numeroOrden}'. Use un número de pedido diferente."
                    );
                }

                $orden = OrdenPicking::create([
                    'empresa_id'      => $this->getEffectiveEmpresaId($user, $r),
                    'sucursal_id'     => $user->sucursal_id,
                    'numero_orden'    => $numeroOrden,
                    'numero_pedido'   => $data['numero_pedido'] ?? null,
                    'cliente'         => $data['cliente'] ?? null,
                    'sucursal_entrega'=> $data['sucursal_entrega'] ?? $data['cliente'] ?? null,
                    'ruta'            => $data['ruta'] ?? null,
                    'estado'          => 'Pendiente',
                    'prioridad'       => $data['prioridad'] ?? 5,
                    'auxiliar_id'     => $data['auxiliar_id'] ?? null,
                    'fecha_movimiento'=> date('Y-m-d'),
                    'hora_inicio'     => date('H:i:s'),
                    'fecha_requerida' => date('Y-m-d'),
                    'observaciones'   => !empty($data['observaciones']) ? trim($data['observaciones']) : null,
                ]);

                foreach ($data['detalles'] as $det) {
                    $cantidadDet = (float)($det['cantidad'] ?? 0);
                    $prod = Producto::find((int)($det['producto_id'] ?? 0));
                    if (!empty($det['en_udm']) && !empty($det['cantidad_ue']) && $prod && $prod->tieneUdm()) {
                        $cantidadDet = $prod->calcularUnidades((float)$det['cantidad_ue']);
                    }
                    PickingDetalle::create([
                        'orden_picking_id'   => $orden->id,
                        'producto_id'        => $det['producto_id'],
                        'ubicacion_id'       => null,
                        'cantidad_solicitada'=> $cantidadDet,
                        'cantidad_pickeada'  => 0,
                        'estado'             => 'Pendiente',
                        'ambiente'           => $this->_clasificarAmbiente($prod ?: ''),
                    ]);
                }

                return $orden;
            });

            $this->audit($user, 'picking', 'crear', 'orden_pickings', $orden->id,
                null, $orden->toArray(), "Orden picking {$orden->numero_orden} creada");

            return $this->created($res, $orden->load('detalles'));
        } catch (\RuntimeException $e) {
            return $this->error($res, $e->getMessage());
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->error($res, "Ya existe una orden con ese número de pedido. Use un número diferente.");
            }
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/generar-ruta ─────────────────────────────
    public function generateRoute(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->with('detalles')
            ->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if ($orden->estado !== 'Pendiente') {
            return $this->error($res, "La orden ya está en estado {$orden->estado}");
        }

        // Asignar auxiliar si viene en el body
        if (!empty($data['auxiliar_id'])) {
            $auxId = (int)$data['auxiliar_id'];
            $orden->auxiliar_id = $auxId;
            $orden->save();

            // Notify the auxiliary
            \App\Controllers\NotificacionesController::crear(
                $this->getEffectiveEmpresaId($user, $r),
                $auxId,
                'Nueva Ruta de Picking',
                "Se le ha asignado la ruta para la orden {$orden->numero_orden}. Por favor inicie el picking.",
                'picking',
                $user->id,
                'Picking',
                $orden->id,
                'viewPicking',
                'Picking',
                true,
                $user->sucursal_id
            );
        }

        try {
            $alertas = $this->_generarRutaFEFO($orden, $user, $r);

            $this->audit($user, 'picking', 'generar_ruta', 'orden_pickings', $orden->id,
                ['estado' => 'Pendiente'], ['estado' => 'EnProceso'],
                "Ruta FEFO generada para {$orden->numero_orden}");

            $lineasAsignadas = $orden->fresh()->detalles->where('estado', 'EnProceso')->count();

            return $this->ok($res, [
                'orden'            => $orden->load('detalles.ubicacion'),
                'lineas_asignadas' => $lineasAsignadas,
                'alertas_stock'    => $alertas,
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
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if (!in_array($orden->estado, ['EnProceso', 'Pendiente'])) {
            return $this->error($res, 'La orden no está en el estado correcto para picking');
        }

        // Si estaba en Pendiente, mover a EnProceso
        if ($orden->estado === 'Pendiente') {
            $orden->estado = 'EnProceso';
            $orden->save();
        }

        $linea = PickingDetalle::where('orden_picking_id', $orden->id)
            ->find($data['linea_id'] ?? 0);

        if (!$linea) return $this->notFound($res, 'Línea no encontrada');

        // R07 — idempotencia: sin este guard, un doble clic/reintento de red/replay sobre
        // una línea que YA fue confirmada volvía a descontar inventario físico real una
        // segunda vez (el fallback FEFO multi-ubicación de abajo ni siquiera fallaría por
        // "stock insuficiente" en muchos casos, ocultando el doble descuento).
        if (!in_array($linea->estado, ['Pendiente', 'EnProceso'], true)) {
            return $this->error($res,
                "Esta línea ya fue confirmada (estado actual: {$linea->estado}). Recargue el picking antes de continuar.",
                409);
        }

        $cantidadTomada = (float)($data['cantidad_tomada'] ?? 0);
        if ($cantidadTomada <= 0) return $this->error($res, 'Cantidad inválida');

        // Validar que la línea tenga ubicación asignada (FEFO debe haberse generado antes)
        if (empty($linea->ubicacion_id)) {
            return $this->error($res,
                'La línea de picking no tiene ubicación asignada. Genere la ruta FEFO primero.',
                422);
        }

        // ── Guard de integridad pre-transacción ──────────────────────────────
        $guard = new InventoryGuard($this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $user->id);
        $check = $guard->canPick(
            $linea->producto_id,
            $cantidadTomada,
            $linea->lote,
            $linea->ubicacion_id
        );
        if (!$check['ok']) {
            if (!empty($check['pending_approval'])) {
                $body = $res->getBody();
                $body->write(json_encode([
                    'error'         => false,
                    'status'        => 'pending_approval',
                    'aprobacion_id' => $check['aprobacion_id'],
                    'message'       => $check['message'],
                    'dias_restantes'=> $check['dias_restantes'],
                ], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(202);
            }
            return $this->error($res, $check['message'], 422);
        }
        if (!empty($check['fefo_warning'])) {
            // Registrar aviso FEFO sin bloquear la operación
            error_log('[FEFO-WARN] Orden ' . $orden->numero_orden . ': ' . $check['fefo_warning']);
        }

        try {
            Capsule::transaction(function () use ($linea, $orden, $user, $cantidadTomada, $r) {
                // ── Fase 1: Liberar reserva (cantidad_reservada) ─────────────
                // La reserva se creó en asignarMultiple; ahora la liberamos
                // porque vamos a descontar el stock real.
                $invReservas = Inventario::where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('estado',       'Disponible')
                    ->where('cantidad_reservada', '>', 0)
                    ->when($linea->ubicacion_id, fn($q) => $q->where('ubicacion_id', $linea->ubicacion_id))
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->lockForUpdate()
                    ->get();

                $reservaPorLiberar = $cantidadTomada;
                foreach ($invReservas as $invR) {
                    if ($reservaPorLiberar <= 0) break;
                    $aLiberar = min($invR->cantidad_reservada, $reservaPorLiberar);
                    $invR->cantidad_reservada -= $aLiberar;
                    $invR->save();
                    $reservaPorLiberar -= $aLiberar;
                }

                // ── Fase 2: Descontar inventario real (FEFO con fallback multi-ubicación) ──
                $empresaId  = $this->getEffectiveEmpresaId($user, $r);
                $sucursalId = $user->sucursal_id;
                $upcProd    = \App\Models\Producto::where('id', $linea->producto_id)->value('unidades_caja');
                $upc        = (float)($upcProd ?: 1);

                // Búsqueda primaria: ubicación/lote exactos asignados por FEFO
                $invRegistros = Inventario::where('empresa_id',  $empresaId)
                    ->where('sucursal_id',  $sucursalId)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->where('cantidad',     '>', 0)
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->lockForUpdate()
                    ->get();

                // Fallback FEFO global: si no hay stock suficiente en la ubicación asignada,
                // buscar en toda la sucursal (cubre líneas de rutas divididas/split donde
                // la ubicación original fue vaciada por otro picking concurrente)
                if ($invRegistros->isEmpty() || $invRegistros->sum('cantidad') < $cantidadTomada) {
                    $invRegistros = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $linea->producto_id)
                        ->where('estado',      'Disponible')
                        ->where('cantidad',    '>', 0)
                        ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                        ->when((int)$linea->ubicacion_id, fn($q) => $q->orderByRaw(
                            'CASE WHEN ubicacion_id = ? THEN 0 ELSE 1 END',
                            [(int)$linea->ubicacion_id]
                        ))
                        ->orderByRaw('fecha_vencimiento IS NULL ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->orderBy('id', 'ASC')
                        ->lockForUpdate()
                        ->get();
                }

                $stockTotal = $invRegistros->sum('cantidad');
                if ($invRegistros->isEmpty() || $stockTotal < $cantidadTomada) {
                    throw new \Exception(
                        "Stock insuficiente para confirmar el picking. Disponible: {$stockTotal}, requerido: {$cantidadTomada}"
                    );
                }

                // Descontar en orden FEFO (la colección ya viene ordenada)
                $porDescontar       = $cantidadTomada;
                $primeraUbicacion   = null;
                $primeraVencimiento = null;
                $primerLote         = null;

                foreach ($invRegistros as $inv) {
                    if ($porDescontar <= 0) break;

                    $descuento = min((float)$inv->cantidad, $porDescontar);
                    if ($descuento <= 0) continue;

                    if ($primeraUbicacion === null) {
                        $primeraUbicacion   = $inv->ubicacion_id;
                        $primeraVencimiento = $inv->fecha_vencimiento;
                        $primerLote         = $inv->lote;
                    }

                    $inv->cantidad -= $descuento;
                    if ((float)$inv->cantidad <= 0) {
                        $inv->delete();
                    } else {
                        $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $upc);
                        $inv->saldos        = round(fmod((float)$inv->cantidad, $upc), 2);
                        $inv->save();
                    }

                    MovimientoInventario::create([
                        'empresa_id'           => $empresaId,
                        'sucursal_id'          => $sucursalId,
                        'producto_id'          => $linea->producto_id,
                        'tipo_movimiento'      => 'Picking',
                        'cantidad'             => $descuento,
                        // Desglose UND/TOTAL → cajas+saldos, igual que en entradas/ajustes —
                        // antes quedaba en 0/0 para picking y el Kardex mostraba "Cajas"/
                        // "Saldos" vacíos solo en estas filas.
                        'cantidad_cajas'       => (int)floor($descuento / $upc),
                        'saldos'               => round(fmod($descuento, $upc), 2),
                        'ubicacion_origen_id'  => $inv->ubicacion_id,
                        'ubicacion_destino_id' => $inv->ubicacion_id,
                        'lote'                 => $inv->lote,
                        'fecha_vencimiento'    => $inv->fecha_vencimiento,
                        'auxiliar_id'          => $user->id,
                        'referencia_tipo'      => 'OrdenPicking',
                        'referencia_id'        => $orden->id,
                        'observaciones'        => "Picking orden {$orden->numero_orden} — Confirmación auxiliar"
                            . ($inv->ubicacion_id !== $linea->ubicacion_id ? ' (fallback FEFO)' : ''),
                        'fecha_movimiento'     => date('Y-m-d'),
                        'hora_inicio'          => date('H:i:s'),
                    ]);

                    $porDescontar -= $descuento;
                }

                // ── Verificación post-decremento (auditoría de no-regresión) ────
                $invRestante = \App\Models\Inventario::where('producto_id', $linea->producto_id)
                    ->where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->sum('cantidad');
                error_log("PICKING_CONFIRM: producto={$linea->producto_id} linea={$linea->id} cantidad_descontada={$cantidadTomada} inv_restante={$invRestante}");

                // Stamp fecha_vencimiento y lote desde el inventario real descontado (siempre,
                // para cubrir fallback FEFO donde la ubicación original fue vaciada por
                // otro picking concurrente y la fecha/lote real pueden diferir de lo asignado).
                if ($primeraVencimiento !== null) {
                    $linea->fecha_vencimiento = $primeraVencimiento;
                }
                if ($primerLote !== null && !$linea->lote) {
                    $linea->lote = $primerLote;
                }

                $linea->cantidad_pickeada = $cantidadTomada;
                $linea->estado = $cantidadTomada >= $linea->cantidad_solicitada
                    ? 'Completado' : 'Faltante';
                $linea->save();

                // Si la línea quedó Completado, eliminar el faltante correspondiente
                // (puede existir si este producto pasó por backorder o fue marcado agotado antes)
                if ($linea->estado === 'Completado') {
                    Capsule::table('picking_faltantes')
                        ->where('orden_picking_id', $linea->orden_picking_id)
                        ->where('producto_id', $linea->producto_id)
                        ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->delete();
                }

                // Verificar si todas las líneas están completas
                $pendientes = PickingDetalle::where('orden_picking_id', $orden->id)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->count();

                if ($pendientes === 0) {
                    $orden->estado   = 'Completada';
                    $orden->hora_fin = date('H:i:s');
                    // Si la orden volvió a Completada tras un backorder post-certificación, re-encolar
                    if ($orden->estado_certificacion === 'Certificada') {
                        $orden->estado_certificacion = 'Pendiente';
                        $orden->fecha_certificacion  = null;
                    }
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
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['id']);
        if (!$orden) return $this->notFound($res);

        // Bloquear si existen líneas sin resolver
        $lineasAbiertas = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->count();
        if ($lineasAbiertas > 0) {
            return $this->error($res,
                "No se puede cerrar: hay {$lineasAbiertas} línea(s) pendiente(s). " .
                "Marca cada una como Faltante/Agotado o confirma la cantidad antes de cerrar.", 422);
        }

        try {
            Capsule::transaction(function () use ($orden) {
                $orden->estado   = 'Completada';
                $orden->hora_fin = date('H:i:s');
                $orden->save();
            });
        } catch (\Exception $e) {
            error_log('PickingController::completar error: ' . $e->getMessage());
            return $this->error($res, 'Error al completar orden: ' . $e->getMessage(), 500);
        }

        $this->audit($user, 'picking', 'completar', 'orden_pickings', $orden->id,
            null, ['estado' => 'Completada'], "Orden picking {$orden->numero_orden} completada");

        // If this order belongs to a planilla archivo, check if all orders are done
        if ($orden->archivo_id) {
            $totalOrdenes = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->where('archivo_id', $orden->archivo_id)->count();
            $completadas  = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->where('archivo_id', $orden->archivo_id)
                ->where('estado', 'Completada')->count();
            if ($totalOrdenes > 0 && $completadas >= $totalOrdenes) {
                \Illuminate\Database\Capsule\Manager::table('archivos_planilla')
                    ->where('id', $orden->archivo_id)
                    ->update(['estado' => 'Separado', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }

        return $this->ok($res, $orden, 'Orden de picking completada');
    }

    // ── POST /api/picking/{id}/reabrir ────────────────────────────────────────
    public function reabrir(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['id']);
        if (!$orden) return $this->notFound($res);

        if ($orden->estado !== 'Completada') {
            return $this->error($res, 'Solo se pueden reabrir órdenes en estado Completada.', 422);
        }
        if ($orden->estado_certificacion === 'Certificada') {
            return $this->error($res, 'No se puede reabrir una orden ya certificada.', 422);
        }
        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se puede reabrir una orden ya {$orden->estado_despacho}.", 422);
        }

        try {
            Capsule::transaction(function () use ($orden) {
                $orden->estado   = 'EnProceso';
                $orden->hora_fin = null;
                $orden->save();
            });
        } catch (\Exception $e) {
            error_log('PickingController::reabrir error: ' . $e->getMessage());
            return $this->error($res, 'Error al reabrir orden: ' . $e->getMessage(), 500);
        }

        $this->audit($user, 'picking', 'reabrir', 'orden_pickings', $orden->id,
            null, ['estado' => 'EnProceso'], "Orden picking {$orden->numero_orden} reabierta");

        return $this->ok($res, $orden, 'Orden reabierta correctamente');
    }

    // ── POST /api/picking/assign ──────────────────────────────────────────────
    // Asignación granular por pasillo o categoría
    public function assignLines(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $now  = date('Y-m-d H:i:s');

        $ordenIds           = array_map('intval', $data['orden_ids'] ?? []);
        $auxiliarId         = (int)($data['auxiliar_id'] ?? 0);
        $pasillos           = (array)($data['pasillos'] ?? []);
        $categorias         = (array)($data['categorias'] ?? []);
        $separarConsolidado = (bool)($data['separar_consolidado'] ?? false);

        if (empty($ordenIds) || !$auxiliarId) {
            return $this->error($res, 'Ordenes y Auxiliar son requeridos');
        }

        try {
            $totalAsignadas = Capsule::transaction(function () use ($ordenIds, $auxiliarId, $pasillos, $categorias, $separarConsolidado, $now) {
                $q = PickingDetalle::whereIn('orden_picking_id', $ordenIds)
                    ->whereIn('estado', ['Pendiente', 'PendienteReabastecimiento', 'EnProceso'])
                    ->where(fn($sub) => $sub->whereNull('auxiliar_id')->orWhere('auxiliar_id', 0));

                if (!empty($pasillos)) {
                    $q->whereHas('ubicacion', fn($uq) => $uq->whereIn('pasillo', $pasillos));
                }

                if (!empty($categorias)) {
                    $q->whereHas('producto', fn($pq) => $pq->whereIn('categoria_id', $categorias));
                }

                $asignadas = $q->update([
                    'auxiliar_id' => $auxiliarId,
                    'estado'      => 'EnProceso',
                    'updated_at'  => $now
                ]);

                // Actualizar las órdenes involucradas
                $updateData = ['estado' => 'EnProceso'];
                if ($separarConsolidado) {
                    $updateData['tipo_picking'] = 'Consolidado Almacenamiento';
                }
                OrdenPicking::whereIn('id', $ordenIds)->update($updateData);

                return $asignadas;
            });

            // Notificar al auxiliar si se asignaron líneas
            if ($totalAsignadas > 0) {
                $ordenes = OrdenPicking::whereIn('id', $ordenIds)
                    ->select('numero_orden', 'planilla_numero', 'planilla_lote', 'cliente')
                    ->limit(3)->get();
                $ref     = $ordenes->pluck('numero_orden')->implode(', ');
                $planRef = $ordenes->first()?->planilla_numero ?? $ordenes->first()?->planilla_lote ?? '';
                $titulo  = $planRef ? "Picking asignado — Planilla {$planRef}" : "Picking asignado";
                $mensaje = "{$totalAsignadas} línea(s) de separación asignadas. Clientes: " .
                           $ordenes->pluck('cliente')->filter()->unique()->implode(', ');
                \App\Controllers\NotificacionesController::crear(
                    $this->getEffectiveEmpresaId($user, $r),
                    $auxiliarId,
                    $titulo,
                    $mensaje,
                    'picking',
                    $user->id,
                    'Picking',
                    null,
                    'viewPicking',
                    'Picking',
                    true,
                    $user->sucursal_id
                );
            }

            return $this->ok($res, ['asignadas' => $totalAsignadas], "Se asignaron $totalAsignadas líneas al auxiliar.");
        } catch (\Exception $e) {
            return $this->error($res, 'Error en asignación: ' . $e->getMessage());
        }
    }

    // ── POST /api/picking/transfer ───────────────────────────────────────────
    // Herencia de tareas: de un auxiliar a otro
    public function transferTasks(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $fromId  = (int)($data['from_auxiliar_id'] ?? 0);
        $toId    = (int)($data['to_auxiliar_id'] ?? 0);
        $ordenId = (int)($data['orden_id'] ?? 0);

        if (!$fromId || !$toId) {
            return $this->error($res, 'Auxiliares origen y destino son requeridos');
        }

        try {
            $movidas = Capsule::transaction(function () use ($fromId, $toId, $ordenId) {
                $q = PickingDetalle::where('auxiliar_id', $fromId)
                    ->whereIn('estado', ['Pendiente', 'EnProceso', 'PendienteReabastecimiento']);
                
                if ($ordenId > 0) {
                    $q->where('orden_picking_id', $ordenId);
                }

                return $q->update(['auxiliar_id' => $toId]);
            });

            return $this->ok($res, ['movidas' => $movidas], "Se transfirieron $movidas tareas exitosamente.");
        } catch (\Exception $e) {
            return $this->error($res, 'Error en transferencia: ' . $e->getMessage());
        }
    }

    // ── GET /api/picking/{orden_id}/siguiente-linea ───────────────────────────
    // Flujo de separación guiado: devuelve la siguiente línea pendiente con toda
    // la información que el auxiliar necesita para separar (ubicación, lote, vencimiento).
    public function siguienteLinea(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['orden_id']);
        if (!$orden) return $this->notFound($res);

        // Siguiente línea no confirmada — FEFO primero, luego ruta óptima de bodega:
        // 1° fecha_vencimiento ASC → vencimiento más próximo primero (FEFO)
        // 2° pasillo ASC           → recorre pasillos en orden físico
        // 3° posicion ASC          → dentro del pasillo, columna de izquierda a derecha
        // 4° nivel ASC             → de nivel 1 al 7/8
        // 5° codigo ASC            → fallback por código completo
        $lineaQuery = PickingDetalle::where('picking_detalles.orden_picking_id', $orden->id)
            ->whereIn('picking_detalles.estado', ['Pendiente', 'EnProceso'])
            ->join('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
            ->select('picking_detalles.*')
            ->with(['producto', 'ubicacion'])
            ->orderByRaw('picking_detalles.fecha_vencimiento ASC NULLS LAST')
            ->orderByRaw('ubicaciones.pasillo ASC NULLS LAST, ubicaciones.posicion ASC NULLS LAST, ubicaciones.nivel ASC NULLS LAST, ubicaciones.codigo ASC NULLS LAST');
        
        // Si el usuario no es admin/supervisor, forzar su propio auxiliar_id
        if ($user->rol === 'Auxiliar') {
            $lineaQuery->where('auxiliar_id', $user->id);
        }

        $linea = $lineaQuery->first();

        // Progreso
        $total       = PickingDetalle::where('orden_picking_id', $orden->id)->count();
        $confirmadas = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereNotIn('estado', ['Pendiente', 'EnProceso'])
            ->count();

        if (!$linea) {
            return $this->ok($res, [
                'completada' => true,
                'mensaje'    => 'Todas las líneas han sido confirmadas.',
                'progreso'   => ['confirmadas' => $confirmadas, 'total' => $total, 'pct' => 100],
            ]);
        }

        // EAN principal del producto
        $ean = \App\Models\ProductoEan::where('producto_id', $linea->producto_id)
            ->orderBy('es_principal', 'desc')->value('codigo_ean');

        // Stock real de la ubicación asignada (para mostrar cajas+sueltos disponibles al auxiliar)
        $invStock = null;
        if ($linea->ubicacion_id) {
            $invStock = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $linea->producto_id)
                ->where('ubicacion_id', $linea->ubicacion_id)
                ->where('estado', 'Disponible')
                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                ->first();
        }

        return $this->ok($res, [
            'completada' => false,
            'linea' => [
                'id'                  => $linea->id,
                'producto_id'         => $linea->producto_id,
                'producto_nombre'     => $linea->producto->nombre          ?? '—',
                'producto_codigo'     => $linea->producto->codigo_interno  ?? '—',
                'producto_ean'        => $ean,
                'imagen_url'          => $linea->producto->imagen_url      ?? null,
                'ubicacion_id'        => $linea->ubicacion_id,
                'ubicacion_codigo'    => $linea->ubicacion->codigo         ?? '—',
                'pasillo'             => $linea->ubicacion->pasillo        ?? null,
                'nivel'               => $linea->ubicacion->nivel          ?? null,
                'cantidad_solicitada' => $linea->cantidad_solicitada,
                'cantidad_pickeada'   => $linea->cantidad_pickeada         ?? 0,
                'lote'                => $linea->lote,
                'fecha_vencimiento'   => $linea->fecha_vencimiento,
            ],
            'progreso' => [
                'confirmadas' => $confirmadas,
                'total'        => $total,
                'pct'          => $total > 0 ? (int)round($confirmadas / $total * 100) : 0,
            ],
            'orden' => [
                'id'           => $orden->id,
                'numero_orden' => $orden->numero_orden,
                'cliente'      => $orden->cliente,
            ],
            'empaque' => [
                'unidades_caja'          => $linea->producto->unidades_caja ?: 1,
                'cajas'                  => floor($linea->cantidad_solicitada / ($linea->producto->unidades_caja ?: 1)),
                'picos'                  => fmod((float)$linea->cantidad_solicitada, (float)($linea->producto->unidades_caja ?: 1)),
                'factor_udm'             => $linea->producto->factor_udm ? (float)$linea->producto->factor_udm : null,
                'unidad_contenido'       => $linea->producto->unidad_contenido,
                'cantidad_solicitada_ue' => $linea->producto->tieneUdm()
                    ? $linea->producto->calcularUdm((float)$linea->cantidad_solicitada)
                    : null,
            ],
            'stock_ubicacion' => [
                'stock_und_total' => $invStock ? (float)$invStock->cantidad         : null,
                'stock_cajas'     => $invStock ? (int)($invStock->cantidad_cajas ?? 0) : null,
                'stock_sueltos'   => $invStock ? (float)($invStock->saldos        ?? 0) : null,
            ],
        ]);
    }

    // ── DELETE /api/picking/{id} — solo Admin ─────────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['id']);
        if (!$orden) return $this->notFound($res);
        if (in_array($orden->estado, ['Completada', 'Completado'])) {
            return $this->error($res, 'No se puede anular una orden ya completada');
        }
        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se puede anular una orden ya {$orden->estado_despacho}");
        }

        $snapshot = $orden->toArray();

        try {
            Capsule::transaction(function () use ($orden, $user, $r) {
                $empresaId  = $this->getEffectiveEmpresaId($user, $r);
                $sucursalId = $user->sucursal_id;
                $detalles   = PickingDetalle::where('orden_picking_id', $orden->id)->get();

                foreach ($detalles as $linea) {
                    // Saltar líneas ya sin movimiento que revertir
                    if (in_array($linea->estado, ['Anulado', 'Cancelado'])) continue;

                    $prod   = \App\Models\Producto::find($linea->producto_id);
                    $upcRev = (float)(($prod->unidades_caja ?? null) ?: 1);

                    // 1. Revertir inventario real pickeado (cantidad_pickeada en UNIDADES)
                    if ($linea->cantidad_pickeada > 0) {
                        $cantRev = (float)$linea->cantidad_pickeada;

                        $invReal = null;
                        if ($linea->ubicacion_id) {
                            $invReal = Inventario::where('empresa_id', $empresaId)
                                ->where('sucursal_id', $sucursalId)
                                ->where('producto_id', $linea->producto_id)
                                ->where('ubicacion_id', $linea->ubicacion_id)
                                ->where('estado', 'Disponible')
                                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                                ->lockForUpdate()->first();
                        }

                        if (!$invReal) {
                            $invReal = Inventario::where('empresa_id', $empresaId)
                                ->where('sucursal_id', $sucursalId)
                                ->where('producto_id', $linea->producto_id)
                                ->whereNotNull('ubicacion_id')
                                ->where('estado', 'Disponible')
                                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                                ->lockForUpdate()->orderByDesc('cantidad')->first();
                        }

                        if ($invReal) {
                            $invReal->cantidad      += $cantRev;
                            $invReal->cantidad_cajas = (int)floor((float)$invReal->cantidad / $upcRev);
                            $invReal->saldos         = round(fmod((float)$invReal->cantidad, $upcRev), 2);
                            $invReal->save();
                        } elseif ($linea->ubicacion_id) {
                            Inventario::create([
                                'empresa_id'         => $empresaId,
                                'sucursal_id'        => $sucursalId,
                                'producto_id'        => $linea->producto_id,
                                'ubicacion_id'       => $linea->ubicacion_id,
                                'lote'               => $linea->lote,
                                'fecha_vencimiento'  => $linea->fecha_vencimiento ?? null,
                                'cantidad'           => $cantRev,
                                'cantidad_cajas'     => (int)floor($cantRev / $upcRev),
                                'saldos'             => round(fmod($cantRev, $upcRev), 2),
                                'cantidad_reservada' => 0,
                                'estado'             => 'Disponible',
                                'numero_pallet'      => null,
                            ]);
                        }
                    }

                    // 2. Liberar reserva pendiente
                    // cantidad_solicitada en CAJAS → convertir a UNIDADES
                    $solicitadaUnd = (float)$linea->cantidad_solicitada * $upcRev;
                    $pendiente     = $solicitadaUnd - (float)$linea->cantidad_pickeada;
                    if ($pendiente > 0) {
                        $invs = Inventario::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('producto_id', $linea->producto_id)
                            ->where('cantidad_reservada', '>', 0)
                            ->when($linea->ubicacion_id, fn($q) => $q->where('ubicacion_id', $linea->ubicacion_id))
                            ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                            ->lockForUpdate()->get();

                        foreach ($invs as $inv) {
                            if ($pendiente <= 0) break;
                            $aRevertir = min($inv->cantidad_reservada, $pendiente);
                            $inv->cantidad_reservada -= $aRevertir;
                            $inv->save();
                            $pendiente -= $aRevertir;
                        }
                    }
                }

                // 3. Borrar picking_faltantes de esta orden
                Capsule::table('picking_faltantes')
                    ->where('orden_picking_id', $orden->id)
                    ->delete();

                // 4. Borrar sesiones de packing asociadas a esta sucursal que aún no
                //    estén Completadas (sesiones Completadas se preservan para historial)
                $sesionesPend = Capsule::table('packing_sesiones')
                    ->where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('sucursal_entrega', $orden->sucursal_entrega)
                    ->whereIn('estado', ['EnProceso', 'Pendiente'])
                    ->pluck('id');

                if ($sesionesPend->isNotEmpty()) {
                    $unidadIds = Capsule::table('packing_unidades')
                        ->whereIn('sesion_id', $sesionesPend)->pluck('id');
                    if ($unidadIds->isNotEmpty()) {
                        Capsule::table('packing_items')->whereIn('unidad_id', $unidadIds)->delete();
                        Capsule::table('packing_unidades')->whereIn('id', $unidadIds)->delete();
                    }
                    Capsule::table('packing_sesiones')->whereIn('id', $sesionesPend)->delete();
                }

                // 5. Hard-delete detalles y orden (siempre, sin importar el estado)
                Capsule::table('picking_detalles')
                    ->where('orden_picking_id', $orden->id)
                    ->delete();
                $orden->delete();
            });

            $this->audit($user, 'picking', 'eliminar', 'orden_pickings', $a['id'],
                $snapshot, null, "Orden {$snapshot['numero_orden']} eliminada por Admin (estado previo: {$snapshot['estado']})");

            return $this->ok($res, null, 'Orden procesada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al anular orden: ' . $e->getMessage());
        }
    }

    // ── PUT /api/picking/{id} ─────────────────────────────────────────────────
    public function actualizar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['id']);
        if (!$orden) return $this->notFound($res);

        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se puede editar una orden ya {$orden->estado_despacho}");
        }

        $snapshot = $orden->toArray();

        if (isset($data['cliente'])) $orden->cliente = trim($data['cliente']);
        if (isset($data['prioridad'])) $orden->prioridad = (int)$data['prioridad'];
        if (isset($data['fecha_requerida'])) $orden->fecha_requerida = $data['fecha_requerida'];
        if (isset($data['area_comercial'])) $orden->area_comercial = trim($data['area_comercial']);
        if (isset($data['observaciones'])) $orden->observaciones = trim($data['observaciones']);

        $orden->save();
        $this->audit($user, 'picking', 'editar', 'orden_pickings', $orden->id, $snapshot, $orden->toArray(), 'Orden editada');

        return $this->ok($res, $orden, 'Orden actualizada');
    }

    // ── POST /api/picking/{id}/despachado-directo ──────────────────────────────
    // Marca (o desmarca) un pedido que el cliente retiró directamente en bodega.
    // NO es lo mismo que estado_despacho='Despachado' (asignado a ruta de reparto,
    // ver DespachoController.php:300) — este es un retiro directo fuera del flujo
    // normal de cargue. Los pedidos marcados se excluyen de certCertificadas(),
    // certRemisionDirecta() y certRemisionMultiple() para que no se mezclen con
    // la remisión de la planilla.
    public function marcarDespachadoDirecto(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $marcar = !array_key_exists('marcar', $data) || (bool)$data['marcar'];

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $snapshot = $orden->toArray();

        $orden->despachado_directo     = $marcar;
        $orden->despachado_directo_at  = $marcar ? date('Y-m-d H:i:s') : null;
        $orden->despachado_directo_por = $marcar ? $user->id : null;
        $orden->save();

        $this->audit($user, 'picking', $marcar ? 'marcar_despachado_directo' : 'desmarcar_despachado_directo',
            'orden_pickings', $orden->id, $snapshot, $orden->toArray(),
            $marcar
                ? "Pedido {$orden->numero_orden} marcado como retirado directamente — excluido de remisión"
                : "Pedido {$orden->numero_orden} desmarcado — vuelve a incluirse en remisión");

        return $this->ok($res, $orden, $marcar
            ? 'Pedido marcado como retiro directo. No se incluirá en la remisión.'
            : 'Pedido desmarcado, vuelve a incluirse en la remisión.');
    }

    // ── POST /api/picking/{id}/lineas ─────────────────────────────────────────
    public function agregarLinea(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['id']);
        if (!$orden) return $this->notFound($res);

        if (in_array($orden->estado, ['Completada', 'Completado', 'Anulado'])) {
            return $this->error($res, "No se pueden agregar líneas a una orden en estado {$orden->estado}");
        }
        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se pueden agregar líneas a una orden ya {$orden->estado_despacho}");
        }

        $prodId = (int)($data['producto_id'] ?? 0);
        if (!$prodId) return $this->error($res, 'Producto o cantidad inválida');

        $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($prodId);
        if (!$prod) return $this->error($res, 'Producto no encontrado');

        // Si viene en U/E, convertir a unidades
        if (!empty($data['en_udm']) && !empty($data['cantidad_ue']) && $prod->tieneUdm()) {
            $cantidad = $prod->calcularUnidades((float)$data['cantidad_ue']);
        } else {
            $cantidad = (float)($data['cantidad_solicitada'] ?? $data['cantidad'] ?? 0);
        }
        if ($cantidad <= 0) return $this->error($res, 'Producto o cantidad inválida');

        try {
            $linea = Capsule::transaction(function () use ($orden, $prod, $cantidad, $user, $r) {
                $nl = PickingDetalle::create([
                    'orden_picking_id'    => $orden->id,
                    'producto_id'         => $prod->id,
                    'cantidad_solicitada' => $cantidad,
                    'cantidad_pickeada'   => 0,
                    'devolucion_qty'      => 0,
                    'estado'              => 'Pendiente',
                    'ambiente'            => $this->_clasificarAmbiente($prod),
                    'costo_unitario'      => $prod->costo_unitario ?? $prod->precio_compra ?? 0,
                ]);

                if (in_array($orden->estado, ['Asignado', 'EnProceso'])) {
                    $this->_reservarStockLineaNueva($nl, $prod, $cantidad, $orden, $user, $r);
                }

                if (!in_array($orden->estado, ['EnProceso', 'Asignado'])) {
                    $orden->estado = 'Pendiente';
                    $orden->save();
                }

                return $nl;
            });

            $this->audit($user, 'picking', 'agregar_linea', 'picking_detalles', $linea->id, null, $linea->toArray(), 'Línea agregada a orden ' . $orden->numero_orden);

            return $this->created($res, $linea, 'Línea agregada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al agregar línea: ' . $e->getMessage());
        }
    }

    /**
     * Reserva stock disponible (FEFO, excluyendo productos/lotes bloqueados por
     * calidad — mismo criterio que _generarRutaFEFO) para una línea de picking
     * recién creada sobre una orden que ya está en curso. Asigna ubicacion_id/
     * lote/fecha_vencimiento de la primera reserva encontrada, y si el stock no
     * alcanza registra picking_faltantes con el remanente. Debe invocarse DENTRO
     * de una transacción ya abierta por el caller.
     *
     * $cantidadCajas está en la misma unidad que PickingDetalle.cantidad_solicitada
     * (cajas cuando el producto tiene empaque, unidades si unidades_caja=1).
     *
     * $loteForzado: si el caller ya especificó manualmente un lote (ej. formulario
     * de agregar referencia a planilla), se restringe la reserva a ese lote en vez
     * de recorrer FEFO por fecha de vencimiento.
     */
    private function _reservarStockLineaNueva(
        PickingDetalle $nl, Producto $prod, float $cantidadCajas, OrdenPicking $orden, $user, Request $r,
        ?string $loteForzado = null
    ): void {
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $upc = max(1, (int)($prod->unidades_caja ?? 1));
        $restante = $cantidadCajas * $upc;

        $prodBloqueado = (bool) \App\Models\Producto::withoutGlobalScopes()
            ->where('id', $prod->id)->where('empresa_id', $empresaId)
            ->where('bloqueado', true)->exists();

        $loteForzadoBloqueado = $loteForzado && \App\Models\BloqueoLote::where('empresa_id', $empresaId)
            ->where('producto_id', $prod->id)->where('lote', $loteForzado)->exists();

        if (!$prodBloqueado && !$loteForzadoBloqueado) {
            $lotesBloqueados = \App\Models\BloqueoLote::where('empresa_id', $empresaId)
                ->where('producto_id', $prod->id)
                ->pluck('lote')->toArray();

            $stockDisponible = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $prod->id)
                ->where('estado', 'Disponible')
                ->whereRaw('(cantidad - cantidad_reservada) > 0')
                ->when($loteForzado, fn($q) => $q->where('lote', $loteForzado))
                ->when(!$loteForzado && !empty($lotesBloqueados), fn($q) => $q->whereNotIn('lote', $lotesBloqueados))
                ->lockForUpdate()
                ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('fecha_vencimiento', 'ASC')
                ->get();

            foreach ($stockDisponible as $inv) {
                if ($restante <= 0) break;
                $disp = max(0, $inv->cantidad - $inv->cantidad_reservada);
                if ($disp <= 0) continue;

                $aReservar = min($disp, $restante);
                $inv->cantidad_reservada += $aReservar;
                $inv->save();
                $restante -= $aReservar;

                if (!$nl->ubicacion_id) {
                    $nl->ubicacion_id = $inv->ubicacion_id;
                    $nl->lote = $inv->lote;
                    $nl->fecha_vencimiento = $inv->fecha_vencimiento;
                }
            }
        }

        if ($restante > 0) {
            $now = now();
            Capsule::table('picking_faltantes')->insert([
                'empresa_id'          => $empresaId,
                'sucursal_id'         => $user->sucursal_id,
                'orden_picking_id'    => $orden->id,
                'producto_id'         => $nl->producto_id,
                'planilla_lote'       => $orden->planilla_lote ?? $orden->planilla_numero,
                'cantidad_solicitada' => $cantidadCajas,
                'cantidad_faltante'   => (int)ceil($restante / $upc),
                'causa'               => $prodBloqueado
                    ? 'Producto bloqueado por calidad'
                    : ($loteForzadoBloqueado ? 'Lote bloqueado por calidad' : 'Stock insuficiente al agregar línea'),
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $nl->estado = 'Faltante';
        } else {
            $nl->estado = 'EnProceso';
        }
        $nl->save();
    }

    /**
     * Ajusta la reserva de inventario de una línea YA asignada (estado EnProceso,
     * con ubicacion_id/lote propios) o parcialmente Faltante, cuando se edita su
     * cantidad_solicitada. Si la línea nunca llegó a reservar nada (Faltante sin
     * ubicacion_id), no toca inventario — solo cambia lo que se necesita.
     *
     * Debe invocarse DENTRO de una transacción ya abierta por el caller.
     *
     * @return array|null ['conflict'=>true, 'ubicacion'=>, 'stock_disponible'=>, 'requerido'=>]
     *                     si no hay stock suficiente para cubrir el incremento (no se
     *                     aplica nada en ese caso); null si se ajustó correctamente.
     */
    private function _ajustarReservaEdicionLinea(
        PickingDetalle $linea, float $nuevaCantidad, $user, Request $r, string $motivo = ''
    ): ?array {
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $factor     = max(1, (int)(Capsule::table('productos')->where('id', $linea->producto_id)->value('unidades_caja') ?? 1));

        if ($nuevaCantidad < (float)$linea->cantidad_pickeada) {
            return ['error' => "No se puede reducir a {$nuevaCantidad}: ya hay {$linea->cantidad_pickeada} separadas físicamente. Ajuste primero lo separado o elimine la línea."];
        }

        $viejaQty     = (float)$linea->cantidad_solicitada;
        $diffCajas    = $nuevaCantidad - $viejaQty;
        $diffUnidades = round($diffCajas * $factor, 3);

        if ($diffUnidades < 0 && $linea->ubicacion_id) {
            $this->_releaseReserva($empresaId, $sucursalId, $linea->producto_id, $linea->ubicacion_id, $linea->lote, abs($diffUnidades));
            MovimientoInventario::create([
                'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId,
                'producto_id' => $linea->producto_id, 'ubicacion_id' => $linea->ubicacion_id,
                'cantidad' => abs($diffUnidades), 'tipo_movimiento' => MovimientoInventario::TIPO_CORRECCION,
                'referencia_id' => $linea->id, 'referencia_tipo' => 'picking_detalle',
                'auxiliar_id' => $user->id,
                'observaciones' => $motivo ?: 'Edición de cantidad — liberación de reserva',
                'fecha_movimiento' => date('Y-m-d'), 'hora_inicio' => date('H:i:s'),
            ]);
        } elseif ($diffUnidades > 0 && $linea->ubicacion_id) {
            $inv = Inventario::where('empresa_id',  $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('producto_id', $linea->producto_id)
                ->where('ubicacion_id', $linea->ubicacion_id)
                ->where('estado', 'Disponible')
                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                ->lockForUpdate()
                ->first();

            $stockDisp = $inv ? max(0, (float)$inv->cantidad - (float)($inv->cantidad_reservada ?? 0)) : 0;

            if ($stockDisp < $diffUnidades) {
                return ['conflict' => true, 'ubicacion' => $linea->ubicacion_id, 'stock_disponible' => $stockDisp, 'requerido' => $diffUnidades];
            }

            $inv->cantidad_reservada = (float)($inv->cantidad_reservada ?? 0) + $diffUnidades;
            $inv->save();

            MovimientoInventario::create([
                'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId,
                'producto_id' => $linea->producto_id, 'ubicacion_id' => $linea->ubicacion_id,
                'cantidad' => $diffUnidades, 'tipo_movimiento' => MovimientoInventario::TIPO_CORRECCION,
                'referencia_id' => $linea->id, 'referencia_tipo' => 'picking_detalle',
                'auxiliar_id' => $user->id,
                'observaciones' => $motivo ?: 'Edición de cantidad — refuerzo de reserva',
                'fecha_movimiento' => date('Y-m-d'), 'hora_inicio' => date('H:i:s'),
            ]);
        }
        // Si $linea->ubicacion_id es null (completamente Faltante, nunca se reservó
        // nada), no hay inventario que tocar — solo cambia cuánto falta.

        if ($linea->estado === 'Faltante') {
            // Reservado real en este momento (0 si nunca se asignó ubicación) —
            // se consulta en vez de asumir, para no arrastrar errores de cálculo.
            $reservadoActual = $linea->ubicacion_id
                ? (float) (Inventario::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('producto_id', $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->value('cantidad_reservada') ?? 0)
                : 0.0;

            $faltanteUnidades = max(0, ($nuevaCantidad * $factor) - $reservadoActual);

            Capsule::table('picking_faltantes')
                ->where('orden_picking_id', $linea->orden_picking_id)
                ->where('producto_id', $linea->producto_id)
                ->orderByDesc('id')
                ->limit(1)
                ->update([
                    'cantidad_solicitada' => $nuevaCantidad,
                    'cantidad_faltante'   => (int) ceil($faltanteUnidades / $factor),
                    'updated_at'          => now(),
                ]);
        }

        $linea->cantidad_solicitada = $nuevaCantidad;
        $linea->cantidad_pickeada   = min((float)$linea->cantidad_pickeada, $nuevaCantidad);
        $linea->save();

        return null;
    }

    // ── GET /api/picking/dashboard ────────────────────────────────────────────
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        // Base query para filtros del dashboard
        $baseQ = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->when($params['estado']  ?? null, fn($q, $e) => $q->where('estado', $e))
            ->when($params['auxiliar_id'] ?? null, fn($q, $a) => $q->where('auxiliar_id', $a))
            ->when($params['planilla'] ?? null, function($q, $p) {
                $q->where(fn($sq) => $sq
                    ->where('planilla_numero', $p)
                    ->orWhere('area_comercial', 'like', "%$p%")
                );
            })
            ->when($params['ruta'] ?? null, fn($q, $ruta) => $q->where('area_comercial', $ruta));

        // KPIs Básicos — single query instead of 4
        $counts = (clone $baseQ)->selectRaw(
            $this->isPg()
                ? "COUNT(*) as total_ordenes,
                   COUNT(*) FILTER (WHERE estado = 'Pendiente')  as pendientes,
                   COUNT(*) FILTER (WHERE estado = 'EnProceso')  as en_proceso,
                   COUNT(*) FILTER (WHERE estado = 'Completada') as completadas"
                : "COUNT(*) as total_ordenes,
                   SUM(estado = 'Pendiente')  as pendientes,
                   SUM(estado = 'EnProceso')  as en_proceso,
                   SUM(estado = 'Completada') as completadas"
        )->first();
        $stats = [
            'total_ordenes' => (int)($counts->total_ordenes ?? 0),
            'pendientes'    => (int)($counts->pendientes    ?? 0),
            'en_proceso'    => (int)($counts->en_proceso    ?? 0),
            'completadas'   => (int)($counts->completadas   ?? 0),
        ];

        // Líneas activas (pendientes + en proceso)
        $ordenesActivasIds = (clone $baseQ)->whereIn('estado', ['Pendiente', 'EnProceso'])->pluck('id');
        $stats['total_lineas_activas']    = PickingDetalle::whereIn('orden_picking_id', $ordenesActivasIds)->count();
        $stats['lineas_pendientes']       = PickingDetalle::whereIn('orden_picking_id', $ordenesActivasIds)
                                                ->whereIn('estado', ['Pendiente', 'Creado', 'Asignado', 'EnProceso'])->count();
        $stats['unidades_pendientes']     = (int) PickingDetalle::whereIn('orden_picking_id', $ordenesActivasIds)
                                                ->whereIn('estado', ['Pendiente', 'Creado', 'Asignado', 'EnProceso'])
                                                ->sum('cantidad_solicitada');

        // Planillas activas en vivo (agrupadas por planilla_numero)
        $ordenesActivas = (clone $baseQ)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->withCount([
                'detalles as total_lineas',
                'detalles as lineas_completadas' => fn($q) => $q->whereIn('estado', ['Completada', 'Completado', 'Faltante']),
                'detalles as tiene_faltante_count' => fn($q) => $q->where('estado', 'Faltante'),
            ])
            ->with([
                'detalles' => fn($q) => $q->select('id', 'orden_picking_id', 'auxiliar_id')
                                           ->with('auxiliar:id,nombre'),
            ])
            ->get();

        $stats['planillas_activas'] = $ordenesActivas
            ->groupBy(fn($o) => $o->planilla_numero ?? $o->numero_orden)
            ->map(function ($orders, $planillaKey) {
                $first       = $orders->first();
                $totalLineas = $orders->sum('total_lineas');
                $lineasComp  = $orders->sum('lineas_completadas');
                $estado      = $orders->contains(fn($o) => $o->estado === 'EnProceso') ? 'EnProceso' : 'Pendiente';
                $horaInicio  = $orders->where('hora_inicio', '!=', '00:00:00')
                                      ->min('hora_inicio');
                $auxiliares  = $orders->flatMap(fn($o) => $o->detalles->pluck('auxiliar.nombre'))
                                      ->filter()->unique()->values();
                $tieneFalt   = $orders->sum('tiene_faltante_count') > 0;

                return [
                    'planilla_numero'    => $planillaKey,
                    'estado'             => $estado,
                    'ruta'               => $first->area_comercial,
                    'hora_inicio'        => $horaInicio,
                    'total_lineas'       => $totalLineas,
                    'lineas_completadas' => $lineasComp,
                    'auxiliares'         => $auxiliares,
                    'tiene_faltante'     => $tieneFalt,
                ];
            })
            ->sortBy(fn($p) => $p['estado'] === 'EnProceso' ? 0 : 1)
            ->values();

        // Faltantes Críticos (Alertas)
        $stats['alertas_faltantes'] = PickingDetalle::whereHas('ordenPicking', function($q) use ($ini, $fin, $empresaId, $params, $user) {
            $q->where('empresa_id', $empresaId)
              ->where('sucursal_id', $user->sucursal_id)
              ->whereBetween('created_at', [$ini, $fin])
              ->when($params['planilla'] ?? null, function($sq, $p) {
                  $sq->where(fn($ssq) => $ssq->where('planilla_numero', $p)->orWhere('area_comercial', 'like', "%$p%"));
              });
        })
        ->where('estado', 'Faltante')
        ->with(['producto:id,nombre,codigo_interno', 'ordenPicking:id,planilla_numero'])
        ->limit(30)
        ->get()
        ->map(fn($f) => [
            'id'       => $f->id,
            'producto' => $f->producto->nombre ?? '–',
            'ean'      => $f->producto->codigo_interno ?? '–',
            'solic'    => $f->cantidad_solicitada,
            'disp'     => $f->cantidad_pickeada,
            'dif'      => $f->cantidad_solicitada - $f->cantidad_pickeada,
            'planilla' => $f->ordenPicking->planilla_numero ?? '–'
        ]);

        // Ranking de Auxiliares (Pedidos, Líneas, Unidades, Tiempo Promedio)
        $stats['ranking_auxiliares'] = Capsule::table('personal as aux')
            ->join('picking_detalles as d', 'aux.id', '=', 'd.auxiliar_id')
            ->join('orden_pickings as o', 'd.orden_picking_id', '=', 'o.id')
            ->where('o.empresa_id', $empresaId)
            ->where('o.sucursal_id', $user->sucursal_id)
            ->whereBetween('o.created_at', [$ini, $fin])
            ->when($params['auxiliar_id'] ?? null, fn($q, $a) => $q->where('aux.id', $a))
            ->select(
                'aux.id',
                'aux.nombre',
                Capsule::raw('COUNT(DISTINCT o.id) as pedidos'),
                Capsule::raw('COUNT(d.id) as lineas'),
                Capsule::raw('SUM(d.cantidad_pickeada) as unidades'),
                Capsule::raw(
                    $this->isPg()
                    ? "ROUND((SELECT GREATEST(0, EXTRACT(EPOCH FROM (MAX(COALESCE(NULLIF(op.hora_fin, '00:00:00')::time, CURRENT_TIME)) - MIN(op.hora_inicio::time)))) / 60 
                        FROM orden_pickings op 
                        WHERE op.id IN (SELECT d2.orden_picking_id FROM picking_detalles d2 WHERE d2.auxiliar_id = aux.id) 
                        AND op.empresa_id = {$empresaId}
                        AND op.created_at BETWEEN '{$ini}' AND '{$fin}'
                        AND op.hora_inicio IS NOT NULL
                        AND op.hora_inicio != '00:00:00'), 1) as avg_minutos"
                    : "ROUND((SELECT GREATEST(0, TIME_TO_SEC(TIMEDIFF(MAX(COALESCE(NULLIF(op.hora_fin, '00:00:00'), CURRENT_TIME())), MIN(op.hora_inicio)))) / 60 
                        FROM orden_pickings op 
                        WHERE op.id IN (SELECT d2.orden_picking_id FROM picking_detalles d2 WHERE d2.auxiliar_id = aux.id) 
                        AND op.empresa_id = {$empresaId}
                        AND op.created_at BETWEEN '{$ini}' AND '{$fin}'
                        AND op.hora_inicio IS NOT NULL
                        AND op.hora_inicio != '00:00:00'), 1) as avg_minutos"
                )
            )
            ->groupBy('aux.id', 'aux.nombre')
            ->orderByDesc('lineas')
            ->limit(10)
            ->get();

        // Series para gráfico de productividad
        $stats['series'] = [
            'diario'    => $this->_getSeriesProductividad($empresaId, 'diario'),
            'semanal'   => $this->_getSeriesProductividad($empresaId, 'semanal'),
        ];

        return $this->ok($res, $stats);
    }

    /**
     * Agregación de unidades pickeadas por periodo para reportes dinámicos.
     */
    private function _getSeriesProductividad($empresaId, $periodo)
    {
        $isPg = $this->isPg();
        $query = Capsule::table('picking_detalles as d')
            ->join('orden_pickings as o', 'd.orden_picking_id', '=', 'o.id')
            ->where('o.empresa_id', $empresaId)
            ->whereIn('d.estado', ['Completada', 'Completado']);

        switch ($periodo) {
            case 'diario':
                // Últimos 15 días
                $query->selectRaw('o.fecha_movimiento as label, SUM(d.cantidad_pickeada) as total')
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-15 days')))
                    ->groupBy('o.fecha_movimiento')
                    ->orderBy('o.fecha_movimiento');
                break;
            case 'semanal':
                // Últimas 8 semanas
                $weekSql = $isPg ? "EXTRACT(WEEK FROM COALESCE(o.fecha_movimiento, o.created_at))" : "WEEK(COALESCE(o.fecha_movimiento, o.created_at))";
                
                $query->selectRaw("CONCAT('Sem ', $weekSql) as label, SUM(d.cantidad_pickeada) as total")
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-60 days')))
                    ->whereNotNull('o.fecha_movimiento');
                
                if ($isPg) {
                    $query->groupBy(Capsule::raw($weekSql));
                } else {
                    $query->groupBy(Capsule::raw("CONCAT('Sem ', $weekSql)"));
                }
                
                $query->orderBy(Capsule::raw($weekSql));
                break;
            case 'mensual':
                // Últimos 6 meses
                $monthSql = $isPg ? "TO_CHAR(COALESCE(o.fecha_movimiento, o.created_at), 'YYYY-MM')" : "DATE_FORMAT(COALESCE(o.fecha_movimiento, o.created_at), '%Y-%m')";
                $query->selectRaw("$monthSql as label, SUM(d.cantidad_pickeada) as total")
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-6 months')))
                    ->groupBy(Capsule::raw($monthSql))
                    ->orderBy(Capsule::raw($monthSql));
                break;
            case 'trimestral':
                // Por trimestre (último año)
                $quarterSql = $isPg ? "EXTRACT(QUARTER FROM COALESCE(o.fecha_movimiento, o.created_at))" : "QUARTER(COALESCE(o.fecha_movimiento, o.created_at))";
                $yearSql    = $isPg ? "EXTRACT(YEAR FROM COALESCE(o.fecha_movimiento, o.created_at))" : "YEAR(COALESCE(o.fecha_movimiento, o.created_at))";
                
                $labelSql = "CONCAT('Trim ', $quarterSql, '-', $yearSql)";
                $query->selectRaw("$labelSql as label, SUM(d.cantidad_pickeada) as total")
                    ->where('o.fecha_movimiento', '>=', date('Y-m-d', strtotime('-1 year')));
                
                if ($isPg) {
                    $query->groupBy(Capsule::raw($yearSql), Capsule::raw($quarterSql));
                } else {
                    $query->groupBy(Capsule::raw($labelSql));
                }

                $query->orderBy(Capsule::raw($yearSql), Capsule::raw($quarterSql));
                break;
        }

        return $query->get();
    }

    // ── GET /api/picking/reabastecimientos ────────────────────────────────────
    public function reabastecimientosLegacy(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $tareas = TareaReabastecimiento::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->with(['ordenPicking:id,planilla'])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        return $this->ok($res, $tareas);
    }

    public function reabastecimientos(Request $r, Response $res): Response
    {
        return $this->reabastecimientosLegacy($r, $res);
    }

    // ── GET /api/picking/template ─────────────────────────────────────────────
    // Plantilla con los campos requeridos para importación masiva
    public function getTemplate(Request $r, Response $res): Response
    {
        $headers = [
            'Num Pedido', 'Sucursal Entrega', 'Referencia',
            'UNID Pedido', 'UNID Pedido Empaque', 'UNID Pedido Total'
        ];
        $sample1 = ['PED-00123', 'BODEGA NORTE', '7702006207881', '12', '2', '24'];
        $sample2 = ['PED-00123', 'BODEGA NORTE', '7703001140022', '6',  '1', '6' ];
        $sample3 = ['PED-00124', 'BODEGA SUR',   '7701234567890', '24', '4', '48'];

        $content = "\xEF\xBB\xBF"; // UTF-8 BOM
        $content .= "# MAPEO DE CAMPOS (Sistema <- Archivo)\r\n";
        $content .= "# Numero Factura <- Num Pedido\r\n";
        $content .= "# Cliente        <- Sucursal Entrega\r\n";
        $content .= "# Planilla       <- Num Pedido (automatico)\r\n";
        $content .= "# Producto       <- Referencia (codigo EAN / barras)\r\n";
        $content .= "# Cantidad       <- UNID Pedido\r\n";
        $content .= "#\r\n";
        $content .= "# NOTA: Los pedidos se agrupan por 'Num Pedido'.\r\n";
        $content .= "# Filas 1 y 2: mismo pedido PED-00123, dos referencias distintas.\r\n";
        $content .= "#\r\n";
        $content .= implode(';', $headers) . "\r\n";
        $content .= implode(';', $sample1) . "\r\n";
        $content .= implode(';', $sample2) . "\r\n";
        $content .= implode(';', $sample3) . "\r\n";

        if (ob_get_length()) ob_clean();
        $res->getBody()->write($content);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="plantilla_pedidos_picking.csv"');
    }

    // ── POST /api/picking/importar ────────────────────────────────────────────
    // Importación masiva desde archivo plano con mapeo inteligente de columnas.
    // Agrupa por sucursal_entrega → una Planilla por sucursal.
    // Si la planilla ya existe en estado Pendiente: ACTUALIZA cantidades y agrega líneas nuevas.
    // Productos no encontrados en catálogo → tabla picking_productos_pendientes.
    public function importarPedidos(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $files = $r->getUploadedFiles();
        $file  = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'Archivo no válido o no enviado');
        }

        $contents = $file->getStream()->getContents();
        if (!mb_detect_encoding($contents, 'UTF-8', true)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
        $allLines = array_values(array_filter($allLines, fn($l) => trim($l) !== ''));
        if (count($allLines) < 2) return $this->error($res, 'El archivo no contiene datos');

        $sep       = str_contains($allLines[0], ';') ? ';' : ',';
        $rawHdr    = str_getcsv($allLines[0], $sep);
        $headers   = array_map(fn($h) => strtolower(trim($h, " \t\r\n\xEF\xBB\xBF")), $rawHdr);
        $dataLines = array_slice($allLines, 1);

        $ALIASES = [
            'numero_factura'   => ['num pedido', 'numero pedido', 'nro pedido', 'num factura', 'numero factura', 'nro factura', 'pedido', 'factura'],
            'sucursal_entrega' => ['sucursal entrega', 'sucursal_entrega', 'sucursal', 'punto entrega', 'destino', 'cliente entrega'],
            'ubicacion'        => ['ubicacion', 'ubicación', 'zona almacen', 'zona', 'ambiente archivo'],
            'producto'         => ['referencia', 'ean', 'codigo barras', 'codigo_barras', 'codigo producto', 'cod producto'],
            'descripcion'      => ['descripcion', 'descripcion producto', 'nombre producto', 'detalle'],
            'cantidad'         => ['unid pedido', 'unid_pedido', 'cantidad', 'cant', 'qty', 'unidades pedido', 'unidades'],
            'unid_pedido_empaque' => ['unid pedido empaque', 'unid_pedido_empaque', 'cajas pedidas', 'cajas pedido', 'unidades empaque'],
            'unid_pedido_total'   => ['unid pedido total', 'unid_pedido_total', 'unid total pedidas', 'total unidades pedidas'],
            'observaciones'       => ['observaciones', 'observacion', 'notas', 'comentarios'],
        ];

        $colMap = [];
        foreach ($ALIASES as $field => $aliases) {
            foreach ($headers as $idx => $h) {
                $hl = strtolower(trim($h));
                foreach ($aliases as $alias) {
                    if ($hl === $alias || str_contains($hl, $alias)) {
                        $colMap[$field] = $idx;
                        break 2;
                    }
                }
            }
        }

        if (!isset($colMap['producto']) || !isset($colMap['cantidad'])) {
            return $this->error($res, 'No se pudieron detectar las columnas de Producto y Cantidad en el archivo. Verifique los encabezados.');
        }

        // ── Pre-audit totals + collect facturas ──────────────────────────────
        $auditArchivo = [
            'lineas_archivo'   => count($dataLines),
            'clientes_archivo' => 0,
            'cantidad_archivo' => 0,
            'valor_archivo'    => 0,
        ];
        $clientesSet     = [];
        $porSucursalArch = [];
        $facturasSet     = [];
        foreach ($dataLines as $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($colMap as $field => $idx) {
                $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            if (empty(array_filter($row))) continue;
            if (!empty($row['cliente'])) $clientesSet[$row['cliente']] = true;
            $cant  = max(0.01, (float)($row['cantidad'] ?? 1));
            $costo = (float) str_replace(',', '.', str_replace('.', '', $row['costo'] ?? '0'));
            $auditArchivo['cantidad_archivo'] += $cant;
            $auditArchivo['valor_archivo']    += $cant * $costo;
            $suc = trim($row['sucursal_entrega'] ?? $row['cliente'] ?? '') ?: '(Sin sucursal)';
            $porSucursalArch[$suc] = ($porSucursalArch[$suc] ?? 0) + 1;
            $nf = trim($row['numero_factura'] ?? $row['planilla'] ?? '');
            if ($nf) $facturasSet[$nf] = true;
        }
        $auditArchivo['clientes_archivo'] = count($clientesSet);

        // ── Pre-cargar huellas globales de líneas ya importadas ─────────────────
        // Huella: "nf_ref|producto_id|cantidad" — coincidencia exacta de los tres = duplicado.
        // Se incluyen TODOS los estados salvo Anulada/Cancelada (incluyendo Completada/Separada)
        // para evitar reimportar pedidos ya despachados.
        // El set es plano (sin agrupar por sucursal) para detectar duplicados aunque cambie la
        // sucursal de entrega entre importaciones.
        $fpGlobal = [];
        try {
            $fpRows = Capsule::table('picking_detalles as pd')
                ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
                ->where('op.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('op.sucursal_id', $user->sucursal_id)
                ->whereNotIn('op.estado', ['Anulado', 'Cancelada'])
                ->whereNotNull('pd.numero_pedido_ref')
                ->select('pd.numero_pedido_ref', 'pd.producto_id', 'pd.cantidad_solicitada')
                ->get();
            foreach ($fpRows as $row) {
                $fp = $row->numero_pedido_ref . '|' . $row->producto_id . '|' . (int)round((float)$row->cantidad_solicitada);
                $fpGlobal[$fp] = true;
            }
        } catch (\Throwable $ignored) {}

        $summary = [
            'total'                    => 0,
            'total_lineas'             => 0,
            'importadas'               => 0,   // pedidos (órdenes) creados
            'grupos_creados'           => 0,   // grupos de cliente creados o ampliados
            'actualizadas'             => 0,   // planillas existentes actualizadas
            'lineas_nuevas'            => 0,   // líneas añadidas a planillas existentes
            'lineas_actualizadas'      => 0,   // líneas actualizadas (cantidad cambió)
            'lineas_sin_cambio'        => 0,   // líneas ya existentes sin diferencia
            'errores'                  => [],
            'productos_pendientes'     => [],  // EANs no encontrados → staging
            'productos_no_encontrados' => 0,
            'campos_detectados'        => array_keys($colMap),
            'cantidad_sistema'         => 0,
            'valor_sistema'            => 0,
            'clientes_sistema'         => [],
            'por_sucursal_sistema'     => [],
        ];

        // ── Whitelist y mapa de clientes (razon_social → id) ─────────────────
        $clientesRaw = Capsule::table('clientes')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->get(['id', 'razon_social']);
        $clientesValidos = [];
        $clienteIdMap    = [];   // lowercase(razon_social) → cliente_id
        foreach ($clientesRaw as $c) {
            $k = strtolower(trim($c->razon_social));
            $clientesValidos[$k] = true;
            $clienteIdMap[$k]    = (int)$c->id;
        }

        // ── Group lines: outer by cliente (sucursal_entrega), inner by numero_factura ──
        // Todos los pedidos del mismo cliente reciben el mismo planilla_numero compartido
        // → aparecen como UNA planilla en picking móvil/escritorio.
        // Si ya existe una planilla del cliente para HOY se reutiliza (importaciones parciales).
        $grupos         = [];   // [sucursal => [nfRef => [rows...]]]
        $sucursalesOmit = [];
        foreach ($dataLines as $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($colMap as $field => $idx) {
                $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            if (empty(array_filter($row))) continue;
            $groupKey = trim($row['sucursal_entrega'] ?? '') ?: '(Sin identificar)';
            $nfRef    = trim($row['numero_factura'] ?? '') ?: '(Sin pedido)';
            if (!empty($clientesValidos) && !isset($clientesValidos[strtolower($groupKey)])) {
                $sucursalesOmit[$groupKey] = ($sucursalesOmit[$groupKey] ?? 0) + 1;
            }
            $grupos[$groupKey][$nfRef][] = $row;
            $summary['total']++;
        }
        $importEmpresaId  = $this->getEffectiveEmpresaId($user, $r);
        $importSucursalId = $user->sucursal_id;
        $importHoy        = date('Y-m-d');

        if (!empty($sucursalesOmit)) {
            $summary['sucursales_no_registradas'] = $sucursalesOmit;
        }

        if (empty($grupos)) {
            return $this->error($res, 'No se encontraron filas de datos en el archivo');
        }

        // ── Sequential planilla number (sólo para planillas NUEVAS) ──────────
        // Se recalcula después de cada creación exitosa para evitar huecos.
        $getNextSeq = function() use ($user, $r): int {
            $max = (int) OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('numero_orden', 'like', 'Planilla %')
                ->selectRaw($this->isPg()
                    ? "COALESCE(MAX(CAST(SUBSTRING(numero_orden FROM 10) AS INTEGER)), 0) as max_seq"
                    : "COALESCE(MAX(CAST(SUBSTRING(numero_orden, 10) AS UNSIGNED)), 0) as max_seq")
                ->value('max_seq');
            return $max + 1;
        };

        $cleanNumber = function($val) {
            if (empty($val)) return 0.0;
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
            return (float) $val;
        };

        // Convierte la columna Ubicación del archivo al ambiente del sistema.
        // Retorna '' cuando no hay keyword → el ?: en la asignación usa ambiente_id del producto.
        $ambienteDeUbicacion = function(string $ub): string {
            $u = strtolower(trim($ub));
            if (str_contains($u, 'congel')) return 'Congelado';
            if (str_contains($u, 'refrig') || str_contains($u, 'frio') || str_contains($u, 'frío') || str_contains($u, 'lacteo')) return 'Refrigerado';
            return '';
        };

        // Helper: buscar producto por EAN/código interno
        $buscarProducto = function(string $ean) use ($user, $r): ?\App\Models\Producto {
            if ($ean === '') return null;
            $eanRec = \App\Models\ProductoEan::where('codigo_ean', $ean)->first();
            if ($eanRec) {
                $p = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($eanRec->producto_id);
                if ($p) return $p;
            }
            $p = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('codigo_interno', $ean)->first();
            if ($p) return $p;
            if (strlen($ean) > 6) {
                $eanRec = \App\Models\ProductoEan::where('codigo_ean', 'like', '%' . substr($ean, -10))->first();
                if ($eanRec) {
                    return \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($eanRec->producto_id);
                }
            }
            return null;
        };

        // Helper: guardar en staging los productos no encontrados
        $staging = function(string $ean, string $nfRef, string $sucursal, float $cantidad, string $descripcion = '') use ($user, $r) {
            if ($ean === '') return;
            try {
                $values = [
                    'cantidad'          => $cantidad,
                    'numero_factura'    => $nfRef ?: null,
                    'sucursal_entrega'  => $sucursal,
                    'importado_por'     => $user->id,
                    'fecha_importacion' => date('Y-m-d'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                    'created_at'        => date('Y-m-d H:i:s'),
                ];
                if ($descripcion !== '') $values['descripcion'] = $descripcion;
                Capsule::table('picking_productos_pendientes')->updateOrInsert(
                    ['empresa_id' => $this->getEffectiveEmpresaId($user, $r), 'sucursal_id' => $user->sucursal_id, 'ean_codigo' => $ean],
                    $values
                );
            } catch (\Throwable $ignored) {}
        };

        // ── Process each cliente group → un planilla_numero compartido por cliente+día ─
        // Si ya existe una planilla HOY para el mismo cliente, los nuevos pedidos se incorporan
        // a esa planilla (mismo planilla_numero) en vez de crear una nueva.
        foreach ($grupos as $sucursal => $facturas) {
            $clienteIdParaOrden = $clienteIdMap[strtolower(trim($sucursal))] ?? null;

            // ¿Ya existe una planilla hoy para este cliente? → reutilizarla.
            $planillaExistente = Capsule::table('orden_pickings')
                ->where('empresa_id', $importEmpresaId)
                ->where('sucursal_id', $importSucursalId)
                ->where('sucursal_entrega', $sucursal)
                ->where('fecha_movimiento', $importHoy)
                ->whereNotIn('estado', ['Anulado', 'Cancelada'])
                ->whereNull('estado_despacho')
                ->whereNotNull('planilla_numero')
                ->where('planilla_numero', 'like', 'Planilla %')
                ->value('planilla_numero');

            if ($planillaExistente) {
                // Planilla del cliente ya existía HOY → reutilizar su número compartido
                $planillaNumeroCompartido = $planillaExistente;
            } else {
                // Primera importación del cliente hoy → crear planilla nueva
                $seqGrupo = $getNextSeq();
                $planillaNumeroCompartido = 'Planilla ' . $seqGrupo;
            }

            $ordenesCreadasIds = [];

            foreach ($facturas as $nfRef => $filas) {
                $nfRefClean = ($nfRef === '(Sin pedido)') ? '' : $nfRef;

                $lineasNuevas = [];
                foreach ($filas as $fila) {
                    $ean        = trim($fila['producto'] ?? '');
                    $cantidad   = max(0, (int)round($cleanNumber($fila['cantidad'] ?? '0')));
                    $descripcion= trim($fila['descripcion'] ?? '');

                    if ($ean === '' || $cantidad <= 0) continue;

                    $prod = $buscarProducto($ean);
                    if (!$prod) {
                        $summary['productos_no_encontrados']++;
                        $summary['productos_pendientes'][] = [
                            'ean' => $ean, 'numero_factura' => $nfRefClean, 'sucursal' => $sucursal, 'cantidad' => $cantidad,
                        ];
                        $staging($ean, $nfRefClean, $sucursal, (float)$cantidad, $descripcion);
                        continue;
                    }

                    if ($nfRefClean !== '') {
                        $fp = $nfRefClean . '|' . $prod->id . '|' . $cantidad;
                        if (isset($fpGlobal[$fp])) {
                            $summary['lineas_sin_cambio']++;
                            continue;
                        }
                    }

                    $lineasNuevas[] = [
                        'fila'     => $fila,
                        'prod'     => $prod,
                        'nfRef'    => $nfRefClean,
                        'cantidad' => $cantidad,
                    ];
                }

                if (empty($lineasNuevas)) continue;

                // numero_orden único por orden; el primero reutiliza el seq del grupo si es nuevo,
                // los siguientes obtienen su propio seq para evitar colisiones.
                $esElPrimero       = empty($ordenesCreadasIds) && !$planillaExistente;
                $seqOrden          = ($esElPrimero && isset($seqGrupo)) ? $seqGrupo : $getNextSeq();
                $numeroOrdenActual = 'Planilla ' . $seqOrden;

                $ordenId = null;
                try {
                    Capsule::transaction(function () use (
                        $sucursal, $nfRefClean, $lineasNuevas,
                        $planillaNumeroCompartido, $numeroOrdenActual,
                        $clienteIdParaOrden, $importEmpresaId, $importSucursalId,
                        &$summary, &$ordenId, $cleanNumber, $ambienteDeUbicacion
                    ) {
                        $orden = OrdenPicking::create([
                            'empresa_id'        => $importEmpresaId,
                            'sucursal_id'       => $importSucursalId,
                            'numero_orden'      => $numeroOrdenActual,
                            'numero_factura'    => $nfRefClean ?: null,
                            'planilla_numero'   => $planillaNumeroCompartido,
                            'planilla_lote'     => null,
                            'cliente'           => $sucursal,
                            'cliente_id'        => $clienteIdParaOrden,
                            'sucursal_entrega'  => $sucursal,
                            'direccion_cliente' => '',
                            'asesor_comercial'  => '',
                            'estado'            => 'Pendiente',
                            'fecha_movimiento'  => date('Y-m-d'),
                            'hora_inicio'       => date('H:i:s'),
                            'prioridad'         => 5,
                            'auxiliar_id'       => null,
                            // El CSV rara vez trae observaciones (es fila-por-línea, no por
                            // pedido) — se toma de la primera línea del grupo si viene; el
                            // caso normal es capturarla/editarla después de cargado (endpoint
                            // PUT /picking/{id}/observaciones).
                            'observaciones'     => trim($lineasNuevas[0]['fila']['observaciones'] ?? '') ?: null,
                        ]);

                        $lineasCreadas = 0;
                        foreach ($lineasNuevas as $item) {
                            PickingDetalle::create([
                                'orden_picking_id'    => $orden->id,
                                'producto_id'         => $item['prod']->id,
                                'cantidad_solicitada' => $item['cantidad'],
                                'cantidad_pickeada'   => 0,
                                'devolucion_qty'      => 0,
                                'costo_unitario'      => 0,
                                'descuento_porc'      => 0,
                                'estado'              => 'Pendiente',
                                'ambiente'            => $ambienteDeUbicacion($item['fila']['ubicacion'] ?? '') ?: $this->_clasificarAmbiente($item['prod']),
                                'numero_pedido_ref'   => $item['nfRef'] ?: null,
                                'unid_pedido_empaque' => $cleanNumber($item['fila']['unid_pedido_empaque'] ?? '0'),
                                'unid_pedido_total'   => $cleanNumber($item['fila']['unid_pedido_total'] ?? '0'),
                            ]);
                            $lineasCreadas++;
                            $summary['total_lineas']++;
                            $summary['cantidad_sistema'] += $item['cantidad'];
                            $summary['clientes_sistema'][$sucursal] = true;
                        }

                        $summary['lineas_nuevas'] += $lineasCreadas;
                        $summary['por_sucursal_sistema'][$sucursal] =
                            ($summary['por_sucursal_sistema'][$sucursal] ?? 0) + $lineasCreadas;

                        $ordenId = (int)$orden->id;
                    });
                    $ordenesCreadasIds[] = $ordenId;
                    $summary['importadas']++;
                } catch (\Exception $e) {
                    $summary['errores'][] = "Cliente '{$sucursal}' / Pedido '{$nfRefClean}': " . $e->getMessage();
                }
            }

            if (empty($ordenesCreadasIds)) continue;
            $summary['grupos_creados']++;

            // ── Consolidado del día: upsert atómico UNA VEZ por grupo de cliente ──────
            try {
                Capsule::transaction(function () use (
                    $sucursal, $clienteIdParaOrden,
                    $importEmpresaId, $importSucursalId, $importHoy, $ordenesCreadasIds
                ) {
                    $consolidado = Capsule::table('picking_consolidados')
                        ->where('empresa_id', $importEmpresaId)
                        ->where('sucursal_id', $importSucursalId)
                        ->where('cliente', $sucursal)
                        ->where('fecha_consolidacion', $importHoy)
                        ->lockForUpdate()->first();

                    if ($consolidado) {
                        $ids = json_decode($consolidado->orden_ids ?? '[]', true) ?: [];
                        foreach ($ordenesCreadasIds as $oid) {
                            if (!in_array((int)$oid, $ids, true)) $ids[] = (int)$oid;
                        }
                        Capsule::table('picking_consolidados')
                            ->where('id', $consolidado->id)
                            ->update([
                                'orden_ids'  => json_encode($ids),
                                'cliente_id' => $clienteIdParaOrden ?? $consolidado->cliente_id,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                    } else {
                        try {
                            Capsule::table('picking_consolidados')->insert([
                                'empresa_id'          => $importEmpresaId,
                                'sucursal_id'         => $importSucursalId,
                                'cliente'             => $sucursal,
                                'cliente_id'          => $clienteIdParaOrden,
                                'fecha_consolidacion' => $importHoy,
                                'estado'              => 'Pendiente',
                                'orden_ids'           => json_encode(array_map('intval', $ordenesCreadasIds)),
                                'created_at'          => date('Y-m-d H:i:s'),
                                'updated_at'          => date('Y-m-d H:i:s'),
                            ]);
                        } catch (\Throwable $eDup) {
                            $consolidado = Capsule::table('picking_consolidados')
                                ->where('empresa_id', $importEmpresaId)
                                ->where('sucursal_id', $importSucursalId)
                                ->where('cliente', $sucursal)
                                ->where('fecha_consolidacion', $importHoy)->first();
                            if ($consolidado) {
                                $ids = json_decode($consolidado->orden_ids ?? '[]', true) ?: [];
                                foreach ($ordenesCreadasIds as $oid) {
                                    if (!in_array((int)$oid, $ids, true)) $ids[] = (int)$oid;
                                }
                                Capsule::table('picking_consolidados')
                                    ->where('id', $consolidado->id)
                                    ->update(['orden_ids' => json_encode($ids), 'updated_at' => date('Y-m-d H:i:s')]);
                            }
                        }
                    }
                });
            } catch (\Throwable $ignored) {}
        }

        // ── Mensaje de resumen ────────────────────────────────────────────────
        $partes = [];
        if ($summary['importadas'] > 0) {
            $grupos = $summary['grupos_creados'] ?? 0;
            if ($grupos > 0 && $grupos < $summary['importadas']) {
                $partes[] = "{$grupos} planilla(s) con {$summary['importadas']} pedido(s) importado(s)";
            } else {
                $partes[] = "{$summary['importadas']} planilla(s) / pedido(s) creado(s)";
            }
        }
        if ($summary['lineas_sin_cambio'] > 0)
            $partes[] = "{$summary['lineas_sin_cambio']} línea(s) duplicadas omitidas";
        if ($summary['productos_no_encontrados'] > 0)
            $partes[] = "{$summary['productos_no_encontrados']} producto(s) sin codificar (guardados en pendientes)";
        if (!empty($summary['errores']))
            $partes[] = count($summary['errores']) . ' error(es)';
        $msg = 'Importación completada: ' . implode(', ', $partes ?: ['todo duplicado — sin cambios']);

        $audit = [
            'archivo' => $auditArchivo,
            'sistema' => [
                'lineas_sistema'   => $summary['total_lineas'],
                'clientes_sistema' => count($summary['clientes_sistema']),
                'cantidad_sistema' => $summary['cantidad_sistema'],
                'valor_sistema'    => round($summary['valor_sistema'], 2),
            ],
        ];
        $audit['diferencias'] = [
            'lineas'   => $auditArchivo['lineas_archivo'] - $summary['total_lineas'],
            'clientes' => $auditArchivo['clientes_archivo'] - count($summary['clientes_sistema']),
            'cantidad' => $auditArchivo['cantidad_archivo'] - $summary['cantidad_sistema'],
            'valor'    => round($auditArchivo['valor_archivo'] - $summary['valor_sistema'], 2),
        ];
        $audit['por_sucursal'] = [
            'archivo' => $porSucursalArch,
            'sistema' => $summary['por_sucursal_sistema'],
        ];
        unset($summary['clientes_sistema'], $summary['por_sucursal_sistema']);

        $response = $res;
        $response->getBody()->write(json_encode([
            'error'      => false,
            'importadas' => $summary['importadas'],
            'message'    => $msg,
            'data'       => $summary,
            'audit'      => $audit,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── GET /api/picking/reservas ─────────────────────────────────────────────
    // Análisis inventario vs pedidos activos: detecta agotados potenciales.
    // Retorna agrupado por sucursal con detalle de referencias y soporte exportación.
    // Filtros: sucursal_id, fecha_desde, fecha_hasta, estado, page, per_page, exportar
    public function reservas(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $params     = $r->getQueryParams();

        $filtroSucursalId = isset($params['sucursal_id']) ? (int)$params['sucursal_id'] : null;
        $fechaDesde       = $params['fecha_desde'] ?? null;
        $fechaHasta       = $params['fecha_hasta'] ?? null;
        $filtroEstado     = $params['estado'] ?? null;
        $exportar         = ($params['exportar'] ?? 'false') === 'true';
        $page             = max(1, (int)($params['page'] ?? 1));
        $perPage          = min(200, max(10, (int)($params['per_page'] ?? 50)));

        // Construir condiciones adicionales para fecha y sucursal_entrega
        $extraWhere = '';
        $extraBinds = [];
        if ($fechaDesde) {
            $extraWhere .= ' AND op.fecha_movimiento >= ?';
            $extraBinds[] = $fechaDesde;
        }
        if ($fechaHasta) {
            $extraWhere .= ' AND op.fecha_movimiento <= ?';
            $extraBinds[] = $fechaHasta;
        }
        // sucursal_entrega_id no existe en orden_pickings; filtrado por nombre no aplica aquí
        if ($filtroEstado) {
            $extraWhere .= ' AND pd.estado = ?';
            $extraBinds[] = $filtroEstado;
        }

        // Para Admin sin sucursal fija, buscar en toda la empresa
        $sucursalWhere = $sucursalId
            ? 'AND op.sucursal_id = ' . (int)$sucursalId
            : '';

        // Los únicos ? del query son los del LEFT JOIN ON (inv.empresa_id, inv.sucursal_id)
        // Los del WHERE usan literales; los de $extraWhere usan sus propios $extraBinds
        $binds = array_merge([$empresaId, $sucursalId ?? $empresaId], $extraBinds);

        $rows = Capsule::select("
            SELECT
                p.id                                                                    AS producto_id,
                p.codigo_interno                                                        AS codigo_producto,
                p.nombre                                                                AS nombre_producto,
                op.sucursal_entrega                                                     AS nombre_sucursal,
                pd.ambiente                                                             AS ambiente,
                pd.estado                                                               AS estado_linea,
                COALESCE(SUM(pd.cantidad_solicitada), 0)                               AS cantidad_solicitada,
                COALESCE(SUM(pd.cantidad_pickeada),  0)                               AS cantidad_separada,
                COALESCE(SUM(pd.cantidad_solicitada), 0)
                    - COALESCE(SUM(pd.cantidad_pickeada), 0)                          AS pendiente,
                MAX(inv.fecha_vencimiento)                                              AS fecha_vencimiento,
                COALESCE(SUM(inv.cantidad), 0)                                         AS stock_total,
                COALESCE(SUM(GREATEST(inv.cantidad - COALESCE(inv.cantidad_reservada,0), 0)), 0)
                                                                                       AS stock_disponible
            FROM picking_detalles pd
            JOIN orden_pickings op  ON op.id = pd.orden_picking_id
            JOIN productos p        ON p.id  = pd.producto_id
            LEFT JOIN inventarios inv
                   ON inv.producto_id = p.id
                  AND inv.empresa_id  = ?
                  AND inv.sucursal_id = ?
                  AND inv.estado      = 'Disponible'
            WHERE op.empresa_id  = " . (int)$empresaId . "
              {$sucursalWhere}
              AND op.estado NOT IN ('Completada','Cancelada','Anulado')
              AND pd.estado NOT IN ('Completado','Anulado')
              {$extraWhere}
            GROUP BY p.id, p.codigo_interno, p.nombre,
                     op.sucursal_entrega,
                     pd.ambiente, pd.estado
            ORDER BY op.sucursal_entrega ASC, pendiente DESC, p.nombre ASC
        ", $binds);

        if ($exportar) {
            // Formato plano para Excel/CSV
            $headers = ['Sucursal','Código','Producto','Ambiente','Ubicación','Sol.','Separado','Pendiente','Stock Total','Stock Disponible','Vencimiento'];
            $csvRows = [];
            foreach ($rows as $row) {
                $csvRows[] = [
                    $row->nombre_sucursal ?? '',
                    $row->codigo_producto ?? '',
                    $row->nombre_producto ?? '',
                    $row->ambiente ?? '',
                    $row->ubicacion_asignada ?? '',
                    (float)$row->cantidad_solicitada,
                    (float)$row->cantidad_separada,
                    (float)$row->pendiente,
                    (float)$row->stock_total,
                    (float)$row->stock_disponible,
                    $row->fecha_vencimiento ?? '',
                ];
            }
            return $this->exportCsv($res, $headers, $csvRows, 'reservas_picking_' . date('Ymd_His') . '.csv');
        }

        // Agrupar por sucursal
        $porSucursal = [];
        foreach ($rows as $row) {
            $key = $row->nombre_sucursal ?? '(Sin sucursal)';
            if (!isset($porSucursal[$key])) {
                $porSucursal[$key] = [
                    'nombre_sucursal'           => $key,
                    'total_referencias'          => 0,
                    'total_unidades_solicitadas' => 0,
                    'total_unidades_separadas'   => 0,
                    'pendiente_total'            => 0,
                    'referencias'               => [],
                ];
            }
            $porSucursal[$key]['total_referencias']++;
            $porSucursal[$key]['total_unidades_solicitadas'] += (float)$row->cantidad_solicitada;
            $porSucursal[$key]['total_unidades_separadas']   += (float)$row->cantidad_separada;
            $porSucursal[$key]['pendiente_total']            += (float)$row->pendiente;
            $porSucursal[$key]['referencias'][] = [
                'producto_id'        => $row->producto_id,
                'codigo_producto'    => $row->codigo_producto,
                'nombre_producto'    => $row->nombre_producto,
                'ambiente'           => $row->ambiente,
                'ubicacion_asignada' => $row->ubicacion_asignada,
                'estado_linea'       => $row->estado_linea,
                'cantidad_solicitada'=> (float)$row->cantidad_solicitada,
                'cantidad_separada'  => (float)$row->cantidad_separada,
                'pendiente'          => (float)$row->pendiente,
                'stock_total'        => (float)$row->stock_total,
                'stock_disponible'   => (float)$row->stock_disponible,
                'fecha_vencimiento'  => $row->fecha_vencimiento,
            ];
        }

        $sucursales   = array_values($porSucursal);
        $totalItems   = count($sucursales);
        $offset       = ($page - 1) * $perPage;
        $paginados    = array_slice($sucursales, $offset, $perPage);

        return $this->ok($res, [
            'data'       => $paginados,
            'pagination' => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $totalItems,
                'last_page'  => (int)ceil($totalItems / $perPage),
            ],
            'totales' => [
                'sucursales'  => $totalItems,
                'referencias' => array_sum(array_column($sucursales, 'total_referencias')),
                'solicitadas' => array_sum(array_column($sucursales, 'total_unidades_solicitadas')),
                'separadas'   => array_sum(array_column($sucursales, 'total_unidades_separadas')),
                'pendiente'   => array_sum(array_column($sucursales, 'pendiente_total')),
            ],
        ]);
    }

    // ── GET /api/picking/productos-pendientes ─────────────────────────────────
    public function listarProductosPendientes(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $items = Capsule::table('picking_productos_pendientes')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->orderBy('updated_at', 'desc')
            ->get();
        return $this->ok($res, $items);
    }

    // ── DELETE /api/picking/productos-pendientes/{id} ─────────────────────────
    public function eliminarProductoPendiente(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $deleted = Capsule::table('picking_productos_pendientes')
            ->where('id', $a['id'])
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->delete();
        if (!$deleted) return $this->notFound($res, 'Registro no encontrado');
        return $this->ok($res, null, 'Eliminado');
    }

    // ── DELETE /api/picking/productos-pendientes ──────────────────────────────
    public function limpiarProductosPendientes(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;
        Capsule::table('picking_productos_pendientes')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->delete();
        return $this->ok($res, null, 'Tabla limpiada');
    }

    // ── PATCH /api/picking/{id}/linea/{lineaId} ──────────────────────────────
    public function actualizarLinea(Request $r, Response $res, array $a): Response
    {
        $user    = $r->getAttribute('user');
        $ordenId = (int)($a['id'] ?? 0);
        $lineaId = (int)($a['lineaId'] ?? 0);
        $body    = (array)($r->getParsedBody() ?? []);

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find($ordenId);
        if (!$orden) return $this->notFound($res);
        if (!in_array($orden->estado, ['Pendiente', 'EnProceso'])) {
            return $this->error($res, 'Solo se pueden editar órdenes en estado Pendiente o EnProceso');
        }
        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se puede editar una orden ya {$orden->estado_despacho}");
        }

        // Pendiente: Supervisor o Admin pueden editar; EnProceso: solo Admin
        if ($orden->estado === 'Pendiente') {
            if ($deny = $this->requireSupervisor($user, $res)) return $deny;
        } else {
            if ($deny = $this->requireAdmin($user, $res)) return $deny;
        }

        $linea = PickingDetalle::where('orden_picking_id', $ordenId)->find($lineaId);
        if (!$linea) return $this->notFound($res);
        if (!in_array($linea->estado, ['Pendiente', 'EnProceso', 'Faltante'])) {
            return $this->error($res, 'Solo se pueden editar líneas en estado Pendiente, EnProceso o Faltante');
        }

        $cantidad = (float)($body['cantidad_solicitada'] ?? 0);
        if ($cantidad < 1) return $this->error($res, 'La cantidad debe ser mayor a 0');

        $costoUnitario = isset($body['costo_unitario']) && $body['costo_unitario'] !== ''
            ? (float)$body['costo_unitario'] : null;

        $result = null;
        try {
            Capsule::transaction(function () use ($linea, $cantidad, $costoUnitario, $user, $r, &$result) {
                if ($linea->estado === 'Pendiente') {
                    // Sin ubicación/reserva asignada todavía — solo actualiza la solicitud.
                    $linea->cantidad_solicitada = $cantidad;
                    $linea->save();
                } else {
                    // EnProceso (reserva completa) o Faltante (reserva parcial o nula):
                    // ajusta la reserva de inventario según la diferencia antes de guardar.
                    $result = $this->_ajustarReservaEdicionLinea($linea, $cantidad, $user, $r, 'Edición manual de cantidad (escritorio)');
                }

                if ($costoUnitario !== null) {
                    $linea->costo_unitario = $costoUnitario;
                    $linea->save();
                }
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al actualizar la línea: ' . $e->getMessage());
        }

        if (!empty($result['error'])) {
            return $this->error($res, $result['error']);
        }
        if (!empty($result['conflict'])) {
            return $this->json($res, [
                'error'            => true,
                'message'          => "Stock insuficiente en ubicación {$result['ubicacion']}: disponible {$result['stock_disponible']}, requerido {$result['requerido']}",
                'stock_disponible' => $result['stock_disponible'],
                'ubicacion_id'     => $result['ubicacion'],
            ], 409);
        }

        $linea->refresh();
        return $this->ok($res, ['linea' => $linea->toArray()], 'Cantidad actualizada');
    }

    // ── DELETE /api/picking/{id}/linea/{lineaId} ─────────────────────────────
    public function eliminarLinea(Request $r, Response $res, array $a): Response
    {
        $user    = $r->getAttribute('user');
        $ordenId = (int)($a['id'] ?? 0);
        $lineaId = (int)($a['lineaId'] ?? 0);

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find($ordenId);
        if (!$orden) return $this->notFound($res);
        if (!in_array($orden->estado, ['Pendiente', 'EnProceso'])) {
            return $this->error($res, 'Solo se pueden eliminar líneas de órdenes en estado Pendiente o EnProceso');
        }
        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se pueden eliminar líneas de una orden ya {$orden->estado_despacho}");
        }

        // Pendiente: Supervisor o Admin pueden eliminar; EnProceso: solo Admin
        if ($orden->estado === 'Pendiente') {
            if ($deny = $this->requireSupervisor($user, $res)) return $deny;
        } else {
            if ($deny = $this->requireAdmin($user, $res)) return $deny;
        }

        $linea = PickingDetalle::where('orden_picking_id', $ordenId)->find($lineaId);
        if (!$linea) return $this->notFound($res);
        if ((float)$linea->cantidad_pickeada > 0) {
            return $this->error($res, 'No se puede eliminar una línea con cantidades ya pickeadas');
        }

        try {
            $ordenEliminada = Capsule::transaction(function () use ($linea, $orden, $ordenId, $user, $r) {
                // Liberar reserva de inventario antes de eliminar
                if ($linea->producto_id && $linea->cantidad_solicitada > 0 && in_array($linea->estado, ['Pendiente', 'EnProceso'])) {
                    $upcDel = max(1, (int)(Capsule::table('productos')->where('id', $linea->producto_id)->value('unidades_caja') ?? 1));
                    $this->_releaseReserva(
                        $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
                        $linea->producto_id, $linea->ubicacion_id, $linea->lote,
                        (float)$linea->cantidad_solicitada * $upcDel
                    );
                }

                // Desvincular (no borrar) cualquier packing_item que pudiera referenciar esta
                // línea — no hay FK en el esquema, así que sin esto quedarían huérfanos
                // apuntando a un picking_detalle_id que ya no existe.
                Capsule::table('packing_items')->where('picking_detalle_id', $linea->id)
                    ->update(['picking_detalle_id' => null]);

                $linea->delete();

                $restantes = PickingDetalle::where('orden_picking_id', $ordenId)->count();
                if ($restantes === 0) {
                    $orden->delete();
                    return true;
                }
                return false;
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al eliminar línea: ' . $e->getMessage());
        }

        if ($ordenEliminada) {
            return $this->ok($res, ['orden_eliminada' => true], 'Línea eliminada. La planilla quedó vacía y fue eliminada.');
        }

        return $this->ok($res, ['orden_eliminada' => false], 'Línea eliminada');
    }

    // ── POST /api/picking/{id}/marcar-faltante ───────────────────────────────
    public function marcarFaltante(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $lineaId = (int)($data['linea_id'] ?? 0);
        $obs     = trim($data['observacion'] ?? '');

        $linea = PickingDetalle::where('orden_picking_id', $orden->id)->find($lineaId);
        if (!$linea) return $this->notFound($res, 'Línea no encontrada');

        try {
            Capsule::transaction(function () use ($linea, $obs, $orden, $user, $r) {
                if ($linea->producto_id && $linea->cantidad_solicitada > 0) {
                    $upcFalt = max(1, (int)(Capsule::table('productos')->where('id', $linea->producto_id)->value('unidades_caja') ?? 1));
                    $this->_releaseReserva(
                        $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
                        $linea->producto_id, $linea->ubicacion_id, $linea->lote,
                        (float)$linea->cantidad_solicitada * $upcFalt
                    );
                }

                $linea->estado  = 'Faltante';
                $linea->novedad = $obs ?: 'Sin stock disponible';
                $linea->save();

                // Registrar en picking_faltantes para módulo de backorder (si no existe ya)
                $yaEnFaltantes = Capsule::table('picking_faltantes')
                    ->where('orden_picking_id', $orden->id)
                    ->where('producto_id', $linea->producto_id)
                    ->exists();
                if (!$yaEnFaltantes) {
                    $nowFalt = date('Y-m-d H:i:s');
                    Capsule::table('picking_faltantes')->insert([
                        'empresa_id'          => $this->getEffectiveEmpresaId($user, $r),
                        'sucursal_id'         => $user->sucursal_id,
                        'orden_picking_id'    => $orden->id,
                        'producto_id'         => $linea->producto_id,
                        'planilla_lote'       => $orden->planilla_lote ?? $orden->planilla_numero ?? null,
                        'cantidad_solicitada' => $linea->cantidad_solicitada,
                        'cantidad_faltante'   => $linea->cantidad_solicitada,
                        'causa'               => $obs ?: 'Sin stock disponible',
                        'created_at'          => $nowFalt,
                        'updated_at'          => $nowFalt,
                    ]);
                }

                // Si todas las líneas están resueltas, cerrar la orden
                $abiertas = PickingDetalle::where('orden_picking_id', $orden->id)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->count();
                if ($abiertas === 0) {
                    $orden->estado   = 'Completada';
                    $orden->hora_fin = date('H:i:s');
                    $orden->save();
                }
            });
        } catch (\Exception $e) {
            error_log('PickingController::marcarFaltante error: ' . $e->getMessage());
            return $this->error($res, 'Error al marcar faltante: ' . $e->getMessage(), 500);
        }

        $this->audit($user, 'picking', 'marcar_faltante', 'picking_detalles', $linea->id,
            null, ['observacion' => $obs], "Línea marcada como faltante en orden {$orden->numero_orden}");

        return $this->ok($res, $linea, 'Línea marcada como faltante');
    }

    // ── POST /api/picking/reabast/{id}/completar ─────────────────────────────
    public function completarReabastLegacy(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $tarea = TareaReabastecimiento::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find($a['id']);
        if (!$tarea) return $this->notFound($res);

        if ($tarea->estado === 'Completada') {
            return $this->error($res, 'La tarea ya fue completada');
        }

        try {
            Capsule::transaction(function () use ($tarea, $user, $r) {
                // Move stock from source to destination
                if ($tarea->ubicacion_origen_id && $tarea->ubicacion_destino_id && $tarea->producto_id) {
                    // FEFO: toma la partida que vence antes (no una fila arbitraria), y
                    // luego preserva su lote/fecha_vencimiento al mover el stock al destino
                    // — fecha_vencimiento es el diferenciador real entre partidas, no el lote.
                    $origen = Inventario::where('empresa_id',  $this->getEffectiveEmpresaId($user, $r))
                        ->where('sucursal_id',  $user->sucursal_id)
                        ->where('producto_id',  $tarea->producto_id)
                        ->where('ubicacion_id', $tarea->ubicacion_origen_id)
                        ->where('estado', 'Disponible')
                        ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('fecha_vencimiento', 'ASC')
                        ->lockForUpdate()
                        ->first();

                    if (!$origen || $origen->cantidad < $tarea->cantidad) {
                        throw new \Exception('Stock insuficiente en origen para completar el reabastecimiento');
                    }

                    if ($origen->cantidad >= $tarea->cantidad) {
                        $loteOrigen  = $origen->lote;
                        $fvencOrigen = $origen->fecha_vencimiento;

                        $origen->cantidad -= $tarea->cantidad;
                        if ((float)$origen->cantidad <= 0) $origen->delete();
                        else $origen->save();

                        $destinoKey = [
                            'empresa_id'   => $this->getEffectiveEmpresaId($user, $r),
                            'sucursal_id'  => $user->sucursal_id,
                            'producto_id'  => $tarea->producto_id,
                            'ubicacion_id' => $tarea->ubicacion_destino_id,
                            'estado'       => 'Disponible',
                            'lote'         => $loteOrigen,
                        ];
                        if ($fvencOrigen) {
                            $destinoKey['fecha_vencimiento'] = $fvencOrigen;
                        }
                        $destino = Inventario::firstOrNew($destinoKey);
                        $destino->cantidad = ($destino->cantidad ?? 0) + $tarea->cantidad;
                        if (!$destino->fecha_vencimiento && $fvencOrigen) {
                            $destino->fecha_vencimiento = $fvencOrigen;
                        }
                        $destino->save();

                        MovimientoInventario::create([
                            'empresa_id'           => $this->getEffectiveEmpresaId($user, $r),
                            'sucursal_id'          => $user->sucursal_id,
                            'producto_id'          => $tarea->producto_id,
                            'tipo_movimiento'      => 'Traslado',
                            'cantidad'             => $tarea->cantidad,
                            'ubicacion_origen_id'  => $tarea->ubicacion_origen_id,
                            'ubicacion_destino_id' => $tarea->ubicacion_destino_id,
                            'auxiliar_id'          => $user->id,
                            'referencia_tipo'      => 'Reabastecimiento',
                            'referencia_id'        => $tarea->id,
                            'observaciones'        => 'Reabastecimiento completado',
                            'fecha_movimiento'     => date('Y-m-d'),
                            'hora_inicio'          => date('H:i:s'),
                        ]);
                    }
                }

                $tarea->estado   = 'Completada';
                $tarea->auxiliar_id = $user->id;
                $tarea->save();
            });

            $this->audit($user, 'picking', 'completar_reabast', 'tareas_reabastecimiento', $tarea->id,
                null, [], "Reabastecimiento #{$tarea->id} completado");

            return $this->ok($res, $tarea, 'Reabastecimiento completado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }
    // ── PICKING PROFESIONAL (Basado en Planillas) ───────────────────────────

    public function misPlanillas(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        
        $esAuxiliar = $user->rol === 'Auxiliar';
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);

        // COALESCE ensures orders without planilla_numero get their own entry using numero_orden
        $query = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            // Defensa adicional: una orden ya despachada/entregada no debe reaparecer
            // como tarea activa de picking (ver bug de reactivación en procesarBackorder).
            ->whereNull('estado_despacho');

        // Auxiliares solo ven sus planillas; supervisores/admins ven todas
        if ($esAuxiliar) {
            $query->where(function($q) use ($user) {
                $q->where('auxiliar_id', $user->id)
                  ->orWhereHas('detalles', fn($dq) => $dq->where('auxiliar_id', $user->id));
            });
        }

        // Observaciones es por PEDIDO, no por planilla — una planilla puede agrupar
        // varios pedidos con observaciones distintas, así que se agregan (distintas,
        // no vacías) para que el auxiliar las vea sin tener que abrir cada pedido.
        $obsAgg = $this->isPg()
            ? "STRING_AGG(DISTINCT NULLIF(TRIM(observaciones), ''), ' | ')"
            : "GROUP_CONCAT(DISTINCT NULLIF(TRIM(observaciones), ''))";

        $planillas = $query
            ->selectRaw('COALESCE(planilla_numero, numero_orden) as planilla_numero')
            ->selectRaw('MAX(area_comercial) as ruta')
            ->selectRaw('MAX(hora_inicio) as hora_inicio')
            ->selectRaw('MAX(sucursal_entrega) as sucursal_entrega')
            ->selectRaw("$obsAgg as observaciones")
            ->groupByRaw('COALESCE(planilla_numero, numero_orden)')
            ->orderByRaw('COALESCE(planilla_numero, numero_orden) DESC')
            ->get();

        // Calcular métricas para cada planilla
        foreach ($planillas as $p) {
            // Search by both planilla_numero and numero_orden (fallback)
            $detalles = PickingDetalle::whereHas('ordenPicking', fn($q) =>
                $q->where('empresa_id', $empresaId)
                  ->where(function($sq) use ($p) {
                      $sq->where('planilla_numero', $p->planilla_numero)
                         ->orWhere('numero_orden', $p->planilla_numero);
                  })
            )
                ->join('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->when($esAuxiliar, fn($q) => $q->where('picking_detalles.auxiliar_id', $user->id))
                ->whereIn('picking_detalles.estado', ['Pendiente', 'EnProceso', 'Completada', 'Completado', 'Faltante'])
                ->select('picking_detalles.*', 'productos.unidades_caja')
                ->get();
            
            $p->total_lineas   = $detalles->count();
            $p->total_unidades = (float)$detalles->sum('cantidad_solicitada');
            
            // Cálculo de cajas totales
            $cajasTotal = 0;
            foreach ($detalles as $d) {
                $factor = (int)($d->unidades_caja ?: 1);
                $cajasTotal += $d->cantidad_solicitada / $factor;
            }
            $p->total_cajas        = round($cajasTotal, 1);
            $p->empezada           = !empty($p->hora_inicio) && $p->hora_inicio !== '00:00:00';
            $p->lineas_completadas = $detalles->filter(
                fn($d) => in_array($d->estado, ['Completada', 'Completado', 'Faltante'])
            )->count();
        }

        // Remover de la vista del operario si ya completó su parte
        $planillasActivas = [];
        foreach ($planillas as $p) {
            if ($esAuxiliar && $p->total_lineas > 0 && $p->lineas_completadas >= $p->total_lineas) {
                continue;
            }
            $planillasActivas[] = $p;
        }

        return $this->ok($res, array_values($planillasActivas));
    }

    public function planillaDetalles(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $numero = $a['numero'] ?? null;
        $estado = $r->getQueryParams()['estado'] ?? 'Pendiente,EnProceso';
        $estados = explode(',', $estado);

        if (!$numero || $numero === 'null') return $this->error($res, 'Número de planilla requerido.', 400);

        try {
            $esAuxiliar = $user->rol === 'Auxiliar';
            // Consolidar por Producto + Ubicación; busca por planilla_numero o numero_orden (fallback)
            $detalles = PickingDetalle::join('orden_pickings', 'picking_detalles.orden_picking_id', '=', 'orden_pickings.id')
                ->leftJoin('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->leftJoin('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
                ->where('orden_pickings.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where(function($q) use ($numero) {
                    $q->where('orden_pickings.planilla_numero', $numero)
                      ->orWhere('orden_pickings.numero_orden', $numero);
                })
                ->when($esAuxiliar, fn($q) => $q->where('picking_detalles.auxiliar_id', $user->id))
                ->whereIn('picking_detalles.estado', $estados)
                ->select(
                    'picking_detalles.producto_id',
                    'productos.nombre as producto_nombre',
                    'productos.codigo_interno as producto_codigo',
                    'productos.unidades_caja',
                    'ubicaciones.codigo as ubicacion_codigo',
                    'ubicaciones.id as ubicacion_id',
                    'picking_detalles.lote',
                    'picking_detalles.fecha_vencimiento',
                    Capsule::raw('MAX(orden_pickings.sucursal_entrega) as sucursal_entrega'),
                    Capsule::raw('MAX(picking_detalles.ambiente) as ambiente'),
                    Capsule::raw('SUM(picking_detalles.cantidad_solicitada) as cantidad_total'),
                    Capsule::raw('SUM(picking_detalles.cantidad_pickeada) as cantidad_pick'),
                    Capsule::raw($this->isPg() ? 'STRING_AGG(picking_detalles.id::text, \',\') as ids' : 'GROUP_CONCAT(picking_detalles.id) as ids')
                )
                ->groupBy(
                    'picking_detalles.producto_id',
                    'productos.id',
                    'productos.nombre',
                    'productos.codigo_interno',
                    'productos.unidades_caja',
                    'ubicaciones.id',
                    'ubicaciones.codigo',
                    'ubicaciones.pasillo',
                    'ubicaciones.modulo',
                    'ubicaciones.posicion',
                    'ubicaciones.nivel',
                    'picking_detalles.lote',
                    'picking_detalles.fecha_vencimiento'
                )
                // Ruta física de recorrido en bodega: ambiente (zona de temperatura) →
                // pasillo → módulo → nivel, para que el auxiliar no zigzaguee entre
                // pasillos/módulos. El FEFO se conserva como desempate dentro del mismo
                // tramo de ruta, y se resuelve por ubicación específica en el split de
                // stock alternativo más abajo (fifo_split) cuando falta stock en el sitio asignado.
                ->orderByRaw('MAX(picking_detalles.ambiente) ASC NULLS LAST')
                ->orderByRaw('ubicaciones.pasillo ASC NULLS LAST, ubicaciones.modulo ASC NULLS LAST, ubicaciones.nivel ASC NULLS LAST, ubicaciones.posicion ASC NULLS LAST, ubicaciones.codigo ASC NULLS LAST')
                ->orderByRaw('picking_detalles.fecha_vencimiento ASC NULLS LAST')
                ->orderBy('productos.codigo_interno', 'asc')
                ->get();

            // Fallback de ubicación: líneas sin ubicacion_id asignada → buscar en stock actual
            $sinUbic = $detalles->filter(fn($d) => !$d->ubicacion_id)
                                ->pluck('producto_id')->unique()->values();
            if ($sinUbic->isNotEmpty()) {
                $stockMap = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where('sucursal_id', $user->sucursal_id)
                    ->whereIn('producto_id', $sinUbic)
                    ->where('estado', 'Disponible')
                    ->where('cantidad', '>', 0)
                    ->with('ubicacion:id,codigo')
                    ->orderByRaw('fecha_vencimiento IS NULL ASC')
                    ->orderBy('fecha_vencimiento', 'ASC')
                    ->get()
                    ->groupBy('producto_id')
                    ->map(fn($g) => $g->first()->ubicacion?->codigo);

                $detalles->transform(function ($it) use ($stockMap) {
                    if (!$it->ubicacion_id) {
                        $it->ubicacion_codigo = $stockMap->get($it->producto_id);
                    }
                    return $it;
                });
            }

            // Formatear para el Wizard Móvil (Cajas / Picos) y adjuntar fotos
            $prodIds = $detalles->pluck('producto_id')->unique()->values();
            $fotosMap = \App\Models\ProductoFoto::whereIn('producto_id', $prodIds)
                ->get()
                ->groupBy('producto_id');

            $detalles->transform(function($it) use ($fotosMap) {
                $it->fotos = $fotosMap->get($it->producto_id, []);
                if ($it->ubicacion_codigo && str_contains($it->ubicacion_codigo, '/')) {
                    $parts = explode('/', $it->ubicacion_codigo);
                    $it->ubicacion_codigo = end($parts);
                }
                $factor = (int)($it->unidades_caja ?: 0);
                if ($factor <= 1) {
                    // Sin empaque definido: todo se muestra como unidades sueltas
                    $it->cajas = 0;
                    $it->picos = (int)$it->cantidad_total;
                } else {
                    $it->cajas = (int)floor($it->cantidad_total / $factor);
                    $it->picos = (int)($it->cantidad_total % $factor);
                }
                return $it;
            });

            // ── FIFO Split proactivo ──────────────────────────────────────────────
            // Para cada ítem: verificar si el stock físico en la ubicación asignada
            // alcanza para cubrir la cantidad pedida. Si no, calcular las ubicaciones
            // FEFO siguientes y cuántas unidades tomar de cada una.
            $empresaId  = $this->getEffectiveEmpresaId($user, $r);
            $sucursalId = $user->sucursal_id;
            $prodIds    = $detalles->pluck('producto_id')->unique()->values()->toArray();

            if (!empty($prodIds)) {
                // Una sola query: todo el stock disponible de los productos de la planilla
                // ordenado FEFO + ruta física para respetar la misma prioridad
                $todosStocks = Inventario::where('inventarios.empresa_id', $empresaId)
                    ->where('inventarios.sucursal_id', $sucursalId)
                    ->whereIn('inventarios.producto_id', $prodIds)
                    ->where('inventarios.estado', 'Disponible')
                    ->where('inventarios.cantidad', '>', 0)
                    ->leftJoin('ubicaciones as ui', 'inventarios.ubicacion_id', '=', 'ui.id')
                    ->select(
                        'inventarios.*',
                        'ui.codigo as ubic_codigo',
                        'ui.pasillo as ubic_pasillo',
                        'ui.posicion as ubic_posicion',
                        'ui.nivel as ubic_nivel',
                        'ui.zona as ubic_zona'
                    )
                    ->orderByRaw('inventarios.fecha_vencimiento ASC NULLS LAST')
                    ->orderByRaw('ui.pasillo ASC NULLS LAST, ui.posicion ASC NULLS LAST, ui.nivel ASC NULLS LAST, ui.codigo ASC NULLS LAST')
                    ->get()
                    ->groupBy('producto_id');

                $detalles->transform(function ($it) use ($todosStocks) {
                    $necesario = (float)($it->cantidad_total ?? 0);
                    $stocks    = $todosStocks->get($it->producto_id, collect());

                    // Stock físico en la ubicación asignada
                    $dispAsig = 0;
                    if ($it->ubicacion_id) {
                        $invAsig  = $stocks->first(fn($s) =>
                            $s->ubicacion_id == $it->ubicacion_id &&
                            ($it->lote ? $s->lote === $it->lote : true)
                        );
                        $dispAsig = $invAsig ? (float)$invAsig->cantidad : 0;
                    }
                    $it->stock_disponible = round($dispAsig, 2);

                    if ($dispAsig >= $necesario) {
                        // Suficiente en la ubicación asignada — sin split
                        $it->fifo_split = [];
                        return $it;
                    }

                    // Construir split FEFO: repartir $necesario entre las ubicaciones
                    // con stock ordenadas por vencimiento + ruta física
                    $split     = [];
                    $pendiente = $necesario;
                    foreach ($stocks as $inv) {
                        if ($pendiente <= 0.001) break;
                        $disp = (float)$inv->cantidad;
                        if ($disp <= 0.001) continue;
                        $tomar = min($disp, $pendiente);
                        $split[] = [
                            'ubicacion_id'      => $inv->ubicacion_id,
                            'ubicacion_codigo'  => $inv->ubic_codigo ?? '—',
                            'pasillo'           => $inv->ubic_pasillo,
                            'posicion'          => $inv->ubic_posicion,
                            'nivel'             => $inv->ubic_nivel,
                            'zona'              => $inv->ubic_zona,
                            'lote'              => $inv->lote,
                            'fecha_vencimiento' => $inv->fecha_vencimiento,
                            'disponible'        => round($disp, 2),
                            'tomar'             => round($tomar, 2),
                            'es_asignada'       => ($inv->ubicacion_id == $it->ubicacion_id),
                        ];
                        $pendiente -= $tomar;
                    }
                    $it->fifo_split      = $split;
                    $it->fifo_pendiente  = round(max(0, $pendiente), 2); // unidades sin cobertura
                    return $it;
                });
            }
            // ─────────────────────────────────────────────────────────────────────

            return $this->ok($res, $detalles);
        } catch (\Exception $e) {
            error_log('PickingController::planillaDetalles error: ' . $e->getMessage());
            return $this->error($res, 'Error al cargar detalles de planilla.', 500);
        }
    }

    /**
     * POST /api/picking/planilla/{numero}/iniciar
     * Marca la hora de inicio de la planilla para el auxiliar.
     */
    public function iniciarPlanilla(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $numero = $a['numero'] ?? null;

        if (!$numero || $numero === 'null') {
            return $this->error($res, 'Número de planilla requerido.', 400);
        }

        try {
            $esAuxiliar = $user->rol === 'Auxiliar';

            // Auxiliares: solo sus órdenes asignadas.
            // Admin/Supervisor/SuperAdmin: cualquier orden de la planilla
            // (incluyendo las sin auxiliar asignado).
            $updated = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where(function($q) use ($numero) {
                    $q->where('planilla_numero', $numero)
                      ->orWhere('numero_orden', $numero);
                })
                ->when($esAuxiliar, function($q) use ($user) {
                    $q->where(function($sq) use ($user) {
                        $sq->where('auxiliar_id', $user->id)
                           ->orWhereHas('detalles', fn($dq) => $dq->where('auxiliar_id', $user->id));
                    });
                })
                ->whereNull('hora_inicio')
                ->update(['hora_inicio' => date('H:i:s'), 'estado' => 'EnProceso']);

            if ($updated === 0) {
                // Si ya había hora_inicio, está bien — simplemente devolver éxito
                $exists = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where(function($q) use ($numero) {
                        $q->where('planilla_numero', $numero)
                          ->orWhere('numero_orden', $numero);
                    })->exists();
                if (!$exists) {
                    return $this->notFound($res, 'Planilla no encontrada.');
                }
            }

            return $this->ok($res, ['planilla_numero' => $numero, 'hora_inicio' => date('H:i:s')],
                'Planilla iniciada');
        } catch (\Exception $e) {
            error_log('PickingController::iniciarPlanilla error: ' . $e->getMessage());
            return $this->error($res, 'Error al iniciar planilla.', 500);
        }
    }

    /**
     * POST /api/picking/confirmar-consolidado
     * Confirma que un consolidado ha sido verificado y cerrado.
     */
    public function confirmarConsolidado(Request $r, Response $res): Response
    {
        $user           = $r->getAttribute('user');
        $body           = $r->getParsedBody() ?? [];
        $idsRaw         = $body['ids']            ?? null;
        $cantidadTomada = (float)($body['cantidad_tomada'] ?? -1);
        $cajasTomadas   = isset($body['cajas_tomadas'])  ? (float)$body['cajas_tomadas']  : null;
        $saldosTomados  = isset($body['saldos_tomados']) ? (float)$body['saldos_tomados'] : null;

        if (!$idsRaw) {
            return $this->error($res, 'ids requerido.', 400);
        }
        if ($cantidadTomada <= 0) {
            return $this->error($res, 'cantidad_tomada debe ser mayor a 0.', 400);
        }

        $ids = is_array($idsRaw)
            ? array_filter(array_map('intval', $idsRaw))
            : array_filter(array_map('intval', explode(',', (string)$idsRaw)));

        if (empty($ids)) {
            return $this->error($res, 'ids inválidos.', 400);
        }

        // Load detalles scoped to this empresa (outside transaction — read only)
        $detalles = PickingDetalle::whereIn('id', $ids)
            ->whereHas('ordenPicking', fn($q) => $q->where('empresa_id', $this->getEffectiveEmpresaId($user, $r)))
            ->with('producto')
            ->orderBy('id')
            ->get();

        if ($detalles->isEmpty()) {
            return $this->error($res, 'Líneas no encontradas.', 404);
        }

        // R07 — idempotencia: mismo guard que confirmLine(). Sin esto, un doble
        // tap/reintento de red sobre "Confirmar" en el picking consolidado móvil
        // volvía a descontar inventario físico real una segunda vez sobre líneas
        // ya confirmadas.
        $yaConfirmadas = $detalles->filter(fn($d) => !in_array($d->estado, ['Pendiente', 'EnProceso'], true));
        if ($yaConfirmadas->isNotEmpty()) {
            return $this->error($res,
                'Una o más líneas ya fueron confirmadas (estado: ' . $yaConfirmadas->pluck('estado')->unique()->implode(', ') . '). Recargue el picking antes de continuar.',
                409);
        }

        // Todos los detalles de un confirmarConsolidado son del mismo producto → mismo upc.
        $upcGlobal = max(1, (int)($detalles->first()?->producto?->unidades_caja ?? 1));

        // BUG CORREGIDO: este bloque asumía que 'cantidad_tomada' siempre llegaba en CAJAS
        // y lo multiplicaba por upc. Pero el modal de escritorio (picking.js:_dlgConfirmarLinea,
        // etiquetado explícitamente "unidades") y el modal móvil con edición de cantidad
        // (confirmarPKActual, que ya envía el total en unidades reales: cajas×upc+saldos)
        // envían el valor YA en UNIDADES REALES — la multiplicación duplicaba el descuento
        // por un factor upc para cualquier producto con más de 1 unidad por caja.
        // Ahora: si vienen cajas_tomadas/saldos_tomados explícitos, son la fuente de verdad
        // (siempre inequívocos); si no, 'cantidad_tomada' se trata directamente como
        // unidades reales — nunca se reinterpreta como cajas.
        $cantidadTomadaUnd = ($cajasTomadas !== null || $saldosTomados !== null)
            ? (($cajasTomadas ?? 0) * $upcGlobal + ($saldosTomados ?? 0))
            : $cantidadTomada;

        // Distribuir UNIDADES entre las sub-líneas (splits) en orden FIFO
        $restante    = $cantidadTomadaUnd;
        $asignaciones = [];
        foreach ($detalles as $det) {
            $upc          = max(1, (int)($det->producto->unidades_caja ?? 1));
            $necesitaUnd  = (float)$det->cantidad_solicitada * $upc; // solicitada en CAJAS → UNIDADES
            $tomar        = min($restante, $necesitaUnd);             // UNIDADES vs UNIDADES ✓
            $asignaciones[$det->id] = ['tomar' => $tomar, 'upc' => $upc];
            $restante = max(0, $restante - $tomar);
        }

        $now = date('Y-m-d H:i:s');

        try {
            Capsule::transaction(function () use ($detalles, $asignaciones, $user, $r, $now) {

                $ordenIds = [];

                foreach ($detalles as $det) {
                    $tomarInventario = $asignaciones[$det->id]['tomar']; // unidades físicamente tomadas
                    $upcConf         = $asignaciones[$det->id]['upc'];
                    $liberarReserva  = (float)$det->cantidad_solicitada * $upcConf; // reserva en unidades

                    // ── Fase 1: Liberar reserva completa ───────────────────────────────────
                    $this->_releaseReserva(
                        $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
                        $det->producto_id, $det->ubicacion_id, $det->lote,
                        $liberarReserva
                    );

                    // ── Fase 2 + 3: Descuento FEFO estricto multi-ubicación ───────────────
                    // Prioridad: ubicación/lote asignado primero; si no alcanza → siguiente
                    // ubicación FEFO hasta cubrir tomarInventario o agotar stock global.
                    // Cada ubicación genera su propio MovimientoInventario para trazabilidad.
                    $realmenteDescontado = 0;

                    if ($tomarInventario > 0) {
                        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
                        $sucursalId = $user->sucursal_id;

                        // FEFO sin filtro de lote: el lote asignado se prioriza por ubicacion
                        // pero si está agotado el sistema continúa con el lote más próximo a
                        // vencer (FEFO estricto). Filtrar por lote suprimiría lotes más urgentes.
                        $filasFEFO = Inventario::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('producto_id', $det->producto_id)
                            ->where('estado',      'Disponible')
                            ->where('cantidad',    '>', 0)
                            ->lockForUpdate()
                            ->when((int)$det->ubicacion_id, fn($q) => $q->orderByRaw(
                                'CASE WHEN ubicacion_id = ? THEN 0 ELSE 1 END',
                                [(int)$det->ubicacion_id]
                            ))
                            ->orderByRaw('fecha_vencimiento IS NULL ASC')
                            ->orderBy('fecha_vencimiento', 'ASC')
                            ->orderBy('id', 'ASC')
                            ->get();

                        $restante = $tomarInventario;
                        foreach ($filasFEFO as $inv) {
                            if ($restante <= 0) break;

                            $descuento = min($restante, (float)$inv->cantidad);
                            if ($descuento <= 0) continue;

                            $inv->cantidad -= $descuento;
                            if ($inv->cantidad <= 0) {
                                $inv->delete();
                            } else {
                                // Recalcular desglose UND/TOTAL → cajas+saldos tras el
                                // descuento (antes quedaba desactualizado en la fila).
                                $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $upcConf);
                                $inv->saldos         = round(fmod((float)$inv->cantidad, $upcConf), 2);
                                $inv->save();
                            }

                            $realmenteDescontado += $descuento;
                            $restante            -= $descuento;

                            MovimientoInventario::create([
                                'empresa_id'          => $empresaId,
                                'sucursal_id'         => $sucursalId,
                                'producto_id'         => $det->producto_id,
                                'tipo_movimiento'     => 'Picking',
                                'cantidad'            => $descuento,
                                // Desglose UND/TOTAL → cajas+saldos para el Kardex (antes 0/0).
                                'cantidad_cajas'      => (int)floor($descuento / $upcConf),
                                'saldos'              => round(fmod($descuento, $upcConf), 2),
                                'ubicacion_origen_id' => $inv->ubicacion_id,
                                'lote'                => $inv->lote,
                                'fecha_vencimiento'   => $inv->fecha_vencimiento,
                                'auxiliar_id'         => $user->id,
                                'referencia_tipo'     => 'OrdenPicking',
                                'referencia_id'       => $det->orden_picking_id,
                                'observaciones'       => 'Picking FEFO ubi:' . ($inv->ubicacion_id ?? 'N/A'),
                                'fecha_movimiento'    => date('Y-m-d'),
                                'hora_inicio'         => date('H:i:s'),
                            ]);
                        }

                        if ($realmenteDescontado < $tomarInventario) {
                            error_log("[confirmarConsolidado] Stock insuficiente producto_id={$det->producto_id}"
                                . " ubi={$det->ubicacion_id} lote={$det->lote}"
                                . " solicitado={$tomarInventario} descontado={$realmenteDescontado}");
                        }
                    }

                    // ── Fase 4: Actualizar detalle ──────────────────────────────────────────
                    // cantidad_pickeada: se almacena en CAJAS (misma unidad que cantidad_solicitada)
                    $pickeadaCajas = $upcConf > 0 ? ($realmenteDescontado / $upcConf) : $realmenteDescontado;
                    $det->cantidad_pickeada = $pickeadaCajas;
                    $det->estado = ($realmenteDescontado >= (float)$det->cantidad_solicitada * $upcConf)
                        ? 'Completado' : 'Faltante';
                    $det->save();

                    // Registrar faltante por bajo picking (en CAJAS, igual que cantidad_solicitada)
                    if ($det->estado === 'Faltante') {
                        $faltanteCant = max(0, (float)$det->cantidad_solicitada - $pickeadaCajas);
                        if ($faltanteCant > 0) {
                            $ord = OrdenPicking::find($det->orden_picking_id);
                            Capsule::table('picking_faltantes')->insert([
                                'empresa_id'          => $this->getEffectiveEmpresaId($user, $r),
                                'sucursal_id'         => $user->sucursal_id,
                                'orden_picking_id'    => $det->orden_picking_id,
                                'producto_id'         => $det->producto_id,
                                'planilla_lote'       => $ord->planilla_lote ?? $ord->planilla_numero,
                                'cantidad_solicitada' => $det->cantidad_solicitada,
                                'cantidad_faltante'   => $faltanteCant,
                                'causa'               => 'Bajo picking — cantidad separada menor a la solicitada',
                                'created_at'          => $now,
                                'updated_at'          => $now,
                            ]);
                        }
                    }

                    $ordenIds[] = $det->orden_picking_id;
                }

                // ── Fase 5: Actualizar órdenes padre ───────────────────────────────────────
                foreach (array_unique($ordenIds) as $ordenId) {
                    $orden = OrdenPicking::where('id', $ordenId)
                        ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->lockForUpdate()
                        ->first();
                    if (!$orden) continue;
                    $this->_closeOrdenIfDone($orden, true);
                }
            });

            $this->audit($user, 'picking', 'confirmar_consolidado', 'picking_detalles', null,
                null, ['ids' => implode(',', $ids), 'cantidad_tomada' => $cantidadTomada],
                'Picking consolidado confirmado — ' . count($detalles) . ' líneas');

            return $this->ok($res, ['confirmados' => count($detalles)], 'Picking confirmado');

        } catch (\Exception $e) {
            error_log('PickingController::confirmarConsolidado error: ' . $e->getMessage());
            return $this->error($res, 'Error al confirmar picking: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/picking/marcar-agotado-consolidado
     * El auxiliar confirma que físicamente no hay stock para las líneas indicadas.
     * Libera la reserva, marca las líneas como Faltante, registra en picking_faltantes
     * con causa='Agotado' y cierra la orden si quedan 0 líneas abiertas.
     */
    public function marcarAgotadoConsolidado(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $body   = $r->getParsedBody() ?? [];
        $idsRaw   = $body['ids']        ?? null;
        $obs      = trim($body['observacion'] ?? 'Agotado — sin stock físico en ubicación');
        $causalId = isset($body['causal_id']) ? (int)$body['causal_id'] : null;

        if (!$idsRaw) {
            return $this->error($res, 'ids requerido.', 400);
        }

        $ids = is_array($idsRaw)
            ? array_filter(array_map('intval', $idsRaw))
            : array_filter(array_map('intval', explode(',', (string)$idsRaw)));

        if (empty($ids)) {
            return $this->error($res, 'ids inválidos.', 400);
        }

        $detalles = PickingDetalle::whereIn('id', $ids)
            ->whereHas('ordenPicking', fn($q) => $q->where('empresa_id', $this->getEffectiveEmpresaId($user, $r)))
            ->get();

        if ($detalles->isEmpty()) {
            return $this->error($res, 'Líneas no encontradas.', 404);
        }

        $now = date('Y-m-d H:i:s');

        try {
            Capsule::transaction(function () use ($detalles, $obs, $causalId, $user, $r, $now) {
                $ordenIds = [];

                foreach ($detalles as $det) {
                    if (!in_array($det->estado, ['Pendiente', 'EnProceso'])) {
                        continue; // ya procesada
                    }

                    // 1. Liberar reserva de inventario (en unidades)
                    if ($det->producto_id && $det->cantidad_solicitada > 0) {
                        $upcAgo = max(1, (int)(Capsule::table('productos')->where('id', $det->producto_id)->value('unidades_caja') ?? 1));
                        $this->_releaseReserva(
                            $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id,
                            $det->producto_id, $det->ubicacion_id, $det->lote,
                            (float)$det->cantidad_solicitada * $upcAgo
                        );
                    }

                    // 2. Marcar línea como Faltante con causa Agotado
                    $det->estado            = 'Faltante';
                    $det->novedad           = $obs;
                    $det->cantidad_pickeada = 0;
                    $det->save();

                    // 3. Registrar en picking_faltantes
                    Capsule::table('picking_faltantes')->insert([
                        'empresa_id'          => $this->getEffectiveEmpresaId($user, $r),
                        'sucursal_id'         => $user->sucursal_id,
                        'orden_picking_id'    => $det->orden_picking_id,
                        'producto_id'         => $det->producto_id,
                        'planilla_lote'       => $det->ordenPicking->planilla_lote
                                                 ?? $det->ordenPicking->planilla_numero
                                                 ?? null,
                        'cantidad_solicitada' => $det->cantidad_solicitada,
                        'cantidad_faltante'   => $det->cantidad_solicitada,
                        'causa'               => 'Agotado — ' . $obs,
                        'causal_id'           => $causalId,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);

                    $ordenIds[] = $det->orden_picking_id;
                }

                // 4. Cerrar órdenes padre si todas sus líneas quedaron resueltas
                foreach (array_unique($ordenIds) as $ordenId) {
                    $orden = OrdenPicking::where('id', $ordenId)
                        ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                        ->lockForUpdate()
                        ->first();
                    if ($orden) {
                        $this->_closeOrdenIfDone($orden, true);
                    }
                }
            });

            $this->audit($user, 'picking', 'marcar_agotado', 'picking_detalles', null,
                null, ['ids' => implode(',', $ids), 'observacion' => $obs],
                'Picking marcado como agotado — ' . count($detalles) . ' líneas');

            return $this->ok($res, ['agotados' => count($detalles)],
                count($detalles) . ' línea(s) marcadas como agotadas y removidas del pendiente.');

        } catch (\Exception $e) {
            error_log('PickingController::marcarAgotadoConsolidado error: ' . $e->getMessage());
            return $this->error($res, 'Error al marcar agotado: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/picking/asignar-consolidado
     * Asigna un auxiliar a un grupo de órdenes (consolidado).
     */
    public function asignarConsolidado(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $body = $r->getParsedBody() ?? [];
        $ids       = $body['orden_ids']   ?? [];
        $auxiliarId = (int)($body['auxiliar_id'] ?? 0);

        if (empty($ids) || !$auxiliarId) {
            return $this->error($res, 'orden_ids y auxiliar_id son requeridos.', 400);
        }

        try {
            $updated = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->whereIn('id', $ids)
                ->update(['auxiliar_id' => $auxiliarId]);

            $this->audit($user, 'picking', 'asignar_consolidado', 'ordenes_picking', null,
                null, [], "Consolidado asignado a auxiliar #{$auxiliarId} — {$updated} órdenes");

            return $this->ok($res, ['updated' => $updated], 'Consolidado asignado');
        } catch (\Exception $e) {
            error_log('PickingController::asignarConsolidado error: ' . $e->getMessage());
            return $this->error($res, 'Error al asignar consolidado.', 500);
        }
    }

    /**
     * GET /api/picking/reporte
     * Retorna resumen operativo del picking con filtros de fecha, ruta y sucursal_entrega.
     */
    public function reporte(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $fechaDesde = $params['fecha_desde'] ?? null;
        $fechaHasta = $params['fecha_hasta'] ?? null;

        if (!$fechaDesde || !$fechaHasta) {
            return $this->ok($res, [
                'ordenes'     => [],
                'resumen'     => ['total'=>0,'completadas'=>0,'faltantes'=>0,'duracion_prom_min'=>0],
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ]);
        }

        try {
            $q = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->whereBetween('fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->when($params['ruta'] ?? null,
                    fn($q, $v) => $q->where('ruta', 'like', "%$v%"))
                ->when($params['sucursal_entrega'] ?? null,
                    fn($q, $v) => $q->where('sucursal_entrega', 'like', "%$v%"))
                ->withCount([
                    'detalles as completadas_count' => fn($q) => $q->whereIn('estado', ['Completada', 'Completado']),
                    'detalles as faltantes_count'   => fn($q) => $q->where('estado', 'Faltante'),
                    'detalles as total_lineas_count',
                ])
                ->with([
                    'detalles.auxiliar:id,nombre',
                    'auxiliar:id,nombre',
                ])
                ->orderBy('fecha_movimiento', 'DESC')
                ->orderBy('created_at', 'DESC');

            $ordenes = $q->get();

            $rows = $ordenes->map(function($o) {
                $auxNombres = $o->detalles->pluck('auxiliar.nombre')
                    ->filter()->unique()->values()->join(', ');
                if (!$auxNombres && $o->auxiliar) $auxNombres = $o->auxiliar->nombre;

                $durMin = null;
                if ($o->hora_inicio && $o->hora_fin) {
                    $ini = strtotime($o->fecha_movimiento . ' ' . $o->hora_inicio);
                    $fin = strtotime($o->fecha_movimiento . ' ' . $o->hora_fin);
                    if ($fin > $ini) $durMin = round(($fin - $ini) / 60);
                }

                $total = $o->total_lineas_count ?: 0;
                $comp  = $o->completadas_count  ?: 0;
                return [
                    'id'               => $o->id,
                    'fecha'            => $o->fecha_movimiento,
                    'numero_orden'     => $o->numero_orden,
                    'numero_pedido'    => $o->numero_pedido,
                    'cliente'          => $o->cliente,
                    'sucursal_entrega' => $o->sucursal_entrega,
                    'ruta'             => $o->ruta,
                    'estado'           => $o->estado,
                    'total_lineas'     => $total,
                    'completadas'      => $comp,
                    'faltantes'        => $o->faltantes_count ?: 0,
                    'pct_cumplimiento' => $total > 0 ? round($comp / $total * 100, 1) : 0,
                    'auxiliares'       => $auxNombres ?: '—',
                    'hora_inicio'      => $o->hora_inicio,
                    'hora_fin'         => $o->hora_fin,
                    'duracion_min'     => $durMin,
                ];
            });

            $duraciones  = $rows->pluck('duracion_min')->filter();
            $durPromedio = $duraciones->isNotEmpty() ? round($duraciones->avg()) : 0;

            return $this->ok($res, [
                'ordenes'     => $rows->values(),
                'resumen'     => [
                    'total'             => $rows->count(),
                    'completadas'       => $rows->where('estado','Completada')->count(),
                    'faltantes'         => $rows->sum('faltantes'),
                    'duracion_prom_min' => $durPromedio,
                ],
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ]);
        } catch (\Exception $e) {
            error_log('reporte error: ' . $e->getMessage());
            return $this->error($res, 'Error generando reporte.', 500);
        }
    }

    private function _clasificarAmbiente($productoOrZona = '', string $categoria = ''): string
    {
        if (is_object($productoOrZona) && !empty($productoOrZona->ambiente_id)) {
            $ambiente = \App\Models\Ambiente::find($productoOrZona->ambiente_id);
            if ($ambiente) return $ambiente->codigo;
        }

        $zona = is_object($productoOrZona) ? '' : (string)$productoOrZona;
        $z = strtolower($zona);
        $c = is_object($categoria) ? '' : strtolower((string)$categoria);
        if (str_contains($z, 'congel') || str_contains($c, 'congel')) return 'Congelado';
        if (str_contains($z, 'refrig') || str_contains($z, 'frio') || str_contains($z, 'frío') ||
            str_contains($z, 'lácteo') || str_contains($z, 'lacteo') ||
            str_contains($c, 'refrig') || str_contains($c, 'frio') || str_contains($c, 'lácteo') ||
            str_contains($c, 'lacteo')) return 'Refrigerado';
        return 'Seco';
    }

    private function _splitPickingLinea(PickingDetalle $linea, array $splits, float $cantCajas, int $upc): void
    {
        if (empty($splits)) return;

        $linea->ubicacion_id      = $splits[0]['ubicacion_id'];
        if (!$linea->lote)              $linea->lote             = $splits[0]['lote'];
        if (!$linea->fecha_vencimiento) $linea->fecha_vencimiento = $splits[0]['fecha_vencimiento'];

        if (count($splits) === 1) return;

        $totalUnidades  = array_sum(array_column($splits, 'unidades'));
        $cajasRestantes = (int)$cantCajas;

        foreach ($splits as $i => $split) {
            $esUltimo = ($i === count($splits) - 1);

            if ($esUltimo) {
                $cajasEste = $cajasRestantes;
            } else {
                $cajasEste = max(1, (int)round(($split['unidades'] / $totalUnidades) * $cantCajas));
                $cajasEste = min($cajasEste, $cajasRestantes - (count($splits) - $i - 1));
            }
            $cajasRestantes -= $cajasEste;

            if ($i === 0) {
                $linea->cantidad_solicitada = $cajasEste;
            } else {
                PickingDetalle::create([
                    'orden_picking_id'    => $linea->orden_picking_id,
                    'producto_id'         => $linea->producto_id,
                    'auxiliar_id'         => $linea->auxiliar_id,
                    'ambiente'            => $linea->ambiente,
                    'ubicacion_id'        => $split['ubicacion_id'],
                    'lote'                => $split['lote'],
                    'fecha_vencimiento'   => $split['fecha_vencimiento'],
                    'cantidad_solicitada' => $cajasEste,
                    'cantidad_pickeada'   => 0,
                    'estado'              => 'EnProceso',
                ]);
            }
        }
    }

    private function _reservarInventarioBatch(array $ordenIds, object $user, Request $r): void
    {
        $now      = date('Y-m-d H:i:s');
        $detalles = PickingDetalle::whereIn('orden_picking_id', $ordenIds)
            ->where('estado', 'EnProceso')
            ->whereNotNull('auxiliar_id')
            ->with('producto:id,unidades_caja')
            ->get();

        if ($detalles->isEmpty()) return;

        $productoIds     = $detalles->pluck('producto_id')->unique()->toArray();
        $stockDisponible = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('producto_id', $productoIds)
            ->where('estado', 'Disponible')
            ->whereRaw('(cantidad - cantidad_reservada) > 0')
            ->lockForUpdate()
            // FEFO: NULLs al final mediante CASE WHEN (ver _generarRutaFEFO)
            ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('fecha_vencimiento', 'ASC')
            ->get();

        $stockPorProducto = $stockDisponible->groupBy('producto_id');
        foreach ($detalles as $linea) {
            // cantidad_solicitada está en CAJAS — convertir a unidades para comparar con inventario
            $upc      = max(1, (int)($linea->producto->unidades_caja ?? 1));
            $restante = (float)$linea->cantidad_solicitada * $upc;
            foreach ($stockPorProducto->get($linea->producto_id, collect()) as $inv) {
                if ($restante <= 0) break;
                $disponible = max(0, $inv->cantidad - $inv->cantidad_reservada);
                if ($disponible <= 0) continue;
                $reservar                = min($disponible, $restante);
                $inv->cantidad_reservada += $reservar;
                $inv->save();
                $restante -= $reservar;
            }
        }
    }

    /**
     * POST /picking/validar-cobertura
     * Pre-flight: recibe orden_ids + config y devuelve qué líneas quedarían sin auxiliar.
     * Permite al frontend mostrar advertencias ANTES de confirmar la asignación.
     */
    public function validarCobertura(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $ordenIds = array_map('intval', $data['orden_ids'] ?? []);
        $modo     = $data['modo'] ?? 'ambiente';
        $config   = $data['config'] ?? [];

        if (empty($ordenIds)) return $this->error($res, 'Se requieren orden_ids');

        // Carga líneas pendientes sin auxiliar
        $lineas = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->leftJoin('ubicaciones as u', 'pd.ubicacion_id', '=', 'u.id')
            ->leftJoin('productos as pr', 'pd.producto_id', '=', 'pr.id')
            ->leftJoin('categoria_productos as cat', 'pr.categoria_id', '=', 'cat.id')
            ->where('op.empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('op.sucursal_id', $user->sucursal_id)
            ->whereIn('pd.orden_picking_id', $ordenIds)
            ->whereIn('pd.estado', ['Pendiente', 'Creado'])
            ->whereNull('pd.auxiliar_id')
            ->select(['pd.id','pd.orden_picking_id','pd.estado','u.pasillo','cat.nombre as categoria','pr.ambiente_id','pr.nombre as producto_nombre'])
            ->get();

        $cobertura = [
            'total_lineas_pendientes' => $lineas->count(),
            'asignadas'               => 0,
            'sin_cubrir'              => 0,
            'detalle_sin_cubrir'      => [],
            'ambientes_cubiertos'     => [],
            'ambientes_sin_auxiliar'  => [],
        ];

        $ambientesSinAux = [];
        foreach ($lineas as $linea) {
            $amb   = $this->_clasificarAmbiente($linea, $linea->categoria ?? '');
            $auxId = null;

            if ($modo === 'ambiente') {
                $auxId = $config[$amb]['auxiliar_id'] ?? null;
            } else {
                foreach (($config['rangos'] ?? []) as $rng) {
                    if (($linea->pasillo ?? '') >= ($rng['pasillo_desde'] ?? '') &&
                        ($linea->pasillo ?? '') <= ($rng['pasillo_hasta'] ?? '')) {
                        $auxId = $rng['auxiliar_id'] ?? null;
                        break;
                    }
                }
            }

            if ($auxId) {
                $cobertura['asignadas']++;
                $cobertura['ambientes_cubiertos'][$amb] = true;
            } else {
                $cobertura['sin_cubrir']++;
                $ambientesSinAux[$amb] = true;
                $cobertura['detalle_sin_cubrir'][] = [
                    'linea_id'       => $linea->id,
                    'orden_id'       => $linea->orden_picking_id,
                    'ambiente'       => $amb,
                    'producto_nombre'=> $linea->producto_nombre,
                ];
            }
        }

        $cobertura['ambientes_cubiertos']     = array_keys($cobertura['ambientes_cubiertos']);
        $cobertura['ambientes_sin_auxiliar']  = array_keys($ambientesSinAux);
        $cobertura['cobertura_completa']      = $cobertura['sin_cubrir'] === 0;

        return $this->ok($res, $cobertura,
            $cobertura['cobertura_completa']
                ? 'Cobertura completa — todas las líneas tendrán auxiliar asignado'
                : "Atención: {$cobertura['sin_cubrir']} línea(s) sin auxiliar configurado"
        );
    }

    public function asignarPorAmbiente(Request $r, Response $res): Response
    {
        $user     = $r->getAttribute('user');
        $data     = $r->getParsedBody() ?? [];
        $ordenIds          = array_map('intval', $data['orden_ids'] ?? []);
        $modo              = $data['modo'] ?? 'ambiente';
        $config            = $data['config'] ?? [];
        $ruta              = trim($data['ruta'] ?? '');
        $fallbackAuxiliarId = isset($data['auxiliar_fallback_id']) ? (int)$data['auxiliar_fallback_id'] : null;

        if (empty($ordenIds))           return $this->error($res, 'Se requieren orden_ids');
        if (!in_array($modo, ['ambiente','pasillo']))
                                        return $this->error($res, 'Modo inválido: use "ambiente" o "pasillo"');

        try {
            $resultado = Capsule::transaction(function () use ($ordenIds, $modo, $config, $ruta, $fallbackAuxiliarId, $user, $r) {
                $now = date('Y-m-d H:i:s');

                // 1+2. Cargar TODAS las líneas con lock pesimista y detectar colisiones en PHP
                // PostgreSQL no permite FOR UPDATE con LEFT JOINs → lock solo sobre picking_detalles,
                // luego traer la info de productos/ubicaciones en query separada sin lock.
                $pdIds = Capsule::table('picking_detalles as pd')
                    ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
                    ->where('op.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where('op.sucursal_id', $user->sucursal_id)
                    ->whereIn('pd.orden_picking_id', $ordenIds)
                    ->select('pd.id')
                    ->lockForUpdate()
                    ->pluck('pd.id')
                    ->toArray();

                $todasLineas = Capsule::table('picking_detalles as pd')
                    ->leftJoin('ubicaciones as u', 'pd.ubicacion_id', '=', 'u.id')
                    ->leftJoin('productos as pr', 'pd.producto_id', '=', 'pr.id')
                    ->leftJoin('categoria_productos as cat', 'pr.categoria_id', '=', 'cat.id')
                    ->whereIn('pd.id', $pdIds)
                    ->select(['pd.id','pd.orden_picking_id','pd.auxiliar_id','pd.estado','u.zona','u.pasillo','cat.nombre as categoria','pr.ambiente_id','pr.nombre as producto_nombre','pr.codigo_interno as producto_codigo'])
                    ->get();

                // Contar líneas ya asignadas (informativo — no bloquea la operación)
                $yaAsignadas = $todasLineas->filter(fn($l) => $l->auxiliar_id !== null)->count();

                // Solo procesar líneas pendientes sin auxiliar
                $lineas = $todasLineas->filter(fn($l) => in_array($l->estado, ['Pendiente', 'Creado']) && $l->auxiliar_id === null);

                // 3. Clasificar cada línea por ambiente (prioridad: ambiente_id del producto)
                foreach ($lineas as $linea) {
                    $linea->amb = $this->_clasificarAmbiente($linea, $linea->categoria ?? '');
                }

                // 4. Determinar auxiliar por línea
                $porAuxiliar      = [];  // [auxId => [lineaId,...]]
                $porAmbiente      = ['Seco' => 0, 'Refrigerado' => 0, 'Congelado' => 0];
                $sinAuxiliar      = 0;
                $lineasSinAuxiliar = [];

                foreach ($lineas as $linea) {
                    if ($modo === 'ambiente') {
                        $auxId = $config[$linea->amb]['auxiliar_id'] ?? null;
                    } else {
                        $auxId = null;
                        foreach (($config['rangos'] ?? []) as $rng) {
                            if (($linea->pasillo ?? '') >= ($rng['pasillo_desde'] ?? '') &&
                                ($linea->pasillo ?? '') <= ($rng['pasillo_hasta'] ?? '')) {
                                $auxId = $rng['auxiliar_id'] ?? null;
                                break;
                            }
                        }
                    }
                    if ($auxId) {
                        $porAuxiliar[$auxId][] = $linea->id;
                        $porAmbiente[$linea->amb] = ($porAmbiente[$linea->amb] ?? 0) + 1;
                    } elseif ($fallbackAuxiliarId) {
                        // Auxiliar de respaldo: garantiza 0 líneas sin asignar
                        $porAuxiliar[$fallbackAuxiliarId][] = $linea->id;
                        $porAmbiente['Fallback'] = ($porAmbiente['Fallback'] ?? 0) + 1;
                    } else {
                        // Sin auxiliar configurado y sin fallback: actualiza ambiente y registra
                        Capsule::table('picking_detalles')
                            ->where('id', $linea->id)
                            ->update(['ambiente' => $linea->amb, 'updated_at' => $now]);
                        $lineasSinAuxiliar[] = [
                            'linea_id'        => $linea->id,
                            'orden_picking_id'=> $linea->orden_picking_id,
                            'ambiente'        => $linea->amb,
                            'producto_nombre' => $linea->producto_nombre ?? 'Sin nombre',
                            'producto_codigo' => $linea->producto_codigo ?? '',
                        ];
                        $sinAuxiliar++;
                    }
                }

                // 5. UPDATE picking_detalles por auxiliar
                $totalAsignadas = 0;
                $ambPorId = collect($lineas)->pluck('amb', 'id')->toArray();
                foreach ($porAuxiliar as $auxId => $ids) {
                    foreach ($ids as $lineaId) {
                        Capsule::table('picking_detalles')
                            ->where('id', $lineaId)
                            ->update([
                                'auxiliar_id' => $auxId,
                                'ambiente'    => $ambPorId[$lineaId] ?? 'Seco',
                                'estado'      => 'EnProceso',
                                'updated_at'  => $now,
                            ]);
                    }
                    $totalAsignadas += count($ids);
                }

                // 6. Actualizar orden_pickings: estado + ruta + orden_logico
                foreach ($ordenIds as $i => $ordId) {
                    $upd = ['estado' => 'EnProceso', 'updated_at' => $now, 'orden_logico' => $i + 1];
                    if ($ruta) $upd['ruta'] = $ruta;
                    Capsule::table('orden_pickings')->where('id', $ordId)->update($upd);
                }

                // 7. Reservar inventario
                $this->_reservarInventarioBatch($ordenIds, $user, $r);

                // 8. Log de auditoría
                Capsule::table('picking_asignaciones_log')->insert([
                    'empresa_id'   => $this->getEffectiveEmpresaId($user, $r),
                    'sucursal_id'  => $user->sucursal_id,
                    'ordenes_json' => json_encode($ordenIds),
                    'modo'         => $modo,
                    'config_json'  => json_encode($config),
                    'lineas_total' => $totalAsignadas,
                    'ruta'         => $ruta ?: null,
                    'asignado_por' => $user->id,
                    'created_at'   => $now,
                ]);

                return [
                    'asignadas'          => $totalAsignadas,
                    'ya_asignadas'       => $yaAsignadas,
                    'por_ambiente'       => $porAmbiente,
                    'sin_auxiliar'       => $sinAuxiliar,
                    'lineas_sin_auxiliar'=> $lineasSinAuxiliar,
                    'ordenes'            => count($ordenIds),
                ];
            });

            return $this->ok($res, $resultado, 'Asignación completada');

        } catch (\RuntimeException $e) {
            $decoded = json_decode($e->getMessage(), true);
            if (($decoded['tipo'] ?? '') === 'colision') {
                $payload = json_encode(['error' => 'Algunos pedidos ya tienen líneas asignadas.', 'orden_ids_en_conflicto' => $decoded['orden_ids']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $res->getBody()->write($payload);
                return $res->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            return $this->error($res, $e->getMessage(), 500);
        } catch (\Exception $e) {
            error_log('asignarPorAmbiente error: ' . $e->getMessage());
            return $this->error($res, 'Error en asignación: ' . $e->getMessage(), 500);
        }
    }

    public function asignarRutaOrden(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $ruta  = trim($data['ruta'] ?? '');
        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$orden) return $this->notFound($res);
        $orden->ruta = $ruta ?: null;
        $orden->save();
        return $this->ok($res, ['id' => $orden->id, 'ruta' => $orden->ruta], 'Ruta actualizada');
    }

    // ── CERTIFICACIÓN POR SUCURSAL ───────────────────────────────────────────

    // ── GET /api/picking/certificacion/vista-hoy ─────────────────────────────
    // Devuelve TODOS los pedidos del día sin importar estado de picking/cert.
    // Permite al supervisor ver el avance global: qué está en picking, listo, o certificado.
    public function certVistaHoy(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $qp         = $r->getQueryParams();
        $fecha      = $qp['fecha'] ?? date('Y-m-d');

        $strAgg = $this->isPg()
            ? "STRING_AGG(DISTINCT COALESCE(amb.descripcion,'Sin ambiente'), ', ')"
            : "GROUP_CONCAT(DISTINCT COALESCE(amb.descripcion,'Sin ambiente'))";

        $ordenes = Capsule::table('orden_pickings as op')
            ->leftJoin('picking_detalles as pd', 'pd.orden_picking_id', '=', 'op.id')
            ->leftJoin('productos as p', 'p.id', '=', 'pd.producto_id')
            ->leftJoin('ambientes as amb', function ($j) use ($empresaId) {
                $j->on('amb.id', '=', 'p.ambiente_id')
                  ->where('amb.empresa_id', $empresaId);
            })
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where(function ($q) use ($fecha) {
                $q->whereDate('op.fecha_movimiento', $fecha)
                  ->orWhereDate('op.created_at', $fecha);
            })
            ->whereNotIn('op.estado', ['Anulado'])
            ->select(
                'op.sucursal_entrega',
                'op.planilla_numero',
                'op.estado',
                'op.estado_certificacion',
                Capsule::raw('COUNT(DISTINCT op.id) as total_ordenes'),
                Capsule::raw('COUNT(DISTINCT pd.id) as total_lineas'),
                Capsule::raw("$strAgg as ambientes")
            )
            ->groupBy('op.sucursal_entrega', 'op.planilla_numero', 'op.estado', 'op.estado_certificacion')
            ->orderBy('op.sucursal_entrega')
            ->get();

        // Agrupar por sucursal_entrega para vista consolidada
        $result = [];
        foreach ($ordenes as $o) {
            $suc = $o->sucursal_entrega;
            if (!isset($result[$suc])) {
                $result[$suc] = [
                    'sucursal_entrega' => $suc,
                    'planilla_numero'  => $o->planilla_numero,
                    'ambientes'        => $o->ambientes,
                    'total_ordenes'    => 0,
                    'total_lineas'     => 0,
                    'estados'          => [],
                ];
            }
            $result[$suc]['total_ordenes'] += (int)$o->total_ordenes;
            $result[$suc]['total_lineas']  += (int)$o->total_lineas;

            $estadoKey = $o->estado . '/' . $o->estado_certificacion;
            if (!isset($result[$suc]['estados'][$estadoKey])) {
                $result[$suc]['estados'][$estadoKey] = [
                    'picking'  => $o->estado,
                    'cert'     => $o->estado_certificacion,
                    'cantidad' => 0,
                ];
            }
            $result[$suc]['estados'][$estadoKey]['cantidad'] += (int)$o->total_ordenes;
        }

        // Determinar estado global por sucursal para ordenar
        foreach ($result as &$entry) {
            $entry['estados'] = array_values($entry['estados']);
            $allCert   = true;
            $anyComp   = false;
            $anyProc   = false;
            foreach ($entry['estados'] as $e) {
                if ($e['cert'] !== 'Certificada') $allCert = false;
                if ($e['picking'] === 'Completada') $anyComp = true;
                if (in_array($e['picking'], ['EnProceso','Pendiente'])) $anyProc = true;
            }
            if ($allCert)             $entry['estado_global'] = 'Certificado';
            elseif ($anyComp)         $entry['estado_global'] = 'ListoCert';
            elseif ($anyProc)         $entry['estado_global'] = 'EnPicking';
            else                      $entry['estado_global'] = 'Otro';
        }
        unset($entry);

        // Ordenar: primero ListoCert (urgentes), luego EnPicking, luego Certificado
        $orden = ['ListoCert' => 0, 'EnPicking' => 1, 'Certificado' => 2, 'Otro' => 3];
        usort($result, fn($a, $b) => ($orden[$a['estado_global']] ?? 9) <=> ($orden[$b['estado_global']] ?? 9));

        return $this->ok($res, array_values($result));
    }

    public function certPendientes(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $strAgg = $this->isPg()
            ? "STRING_AGG(DISTINCT per.nombre, ', ')"
            : "GROUP_CONCAT(DISTINCT per.nombre)";

        // Filtro de fecha: null = sin filtro (devuelve todos)
        $qp          = $r->getQueryParams();
        $fechaInicio = $qp['fecha_inicio'] ?? null;
        $fechaFin    = $qp['fecha_fin']    ?? null;

        // Step 1: órdenes listas (usa idx_op_cert) — ligero, sin JOIN a picking_detalles
        $ordenes = Capsule::table('orden_pickings as op')
            ->leftJoin('personal as per', 'per.id', '=', 'op.auxiliar_id')
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->whereIn('op.estado', ['Completada', 'EnProceso'])
            ->whereIn('op.estado_certificacion', ['Pendiente', 'Parcial'])
            // Defensa adicional: una orden ya despachada/entregada NUNCA debe volver a
            // aparecer como pendiente de certificar, sin importar cómo haya quedado su
            // estado/estado_certificacion (ver bug de reactivación en procesarBackorder).
            ->whereNull('op.estado_despacho')
            ->whereExists(function($query) {
                $query->select(Capsule::raw(1))
                      ->from('picking_detalles as pd2')
                      ->join('productos as p2', 'p2.id', '=', 'pd2.producto_id')
                      ->whereColumn('pd2.orden_picking_id', 'op.id')
                      ->groupByRaw('COALESCE(p2.ambiente_id, 0)')
                      ->havingRaw("SUM(CASE WHEN pd2.estado IN ('Pendiente', 'EnProceso') THEN 1 ELSE 0 END) = 0")
                      ->havingRaw("SUM(CASE WHEN COALESCE(pd2.cantidad_certificada, 0) < pd2.cantidad_pickeada THEN 1 ELSE 0 END) > 0")
                      ->havingRaw("COUNT(pd2.id) > 0");
            })
            ->when($fechaInicio, fn($q) => $q->where('op.fecha_movimiento', '>=', $fechaInicio))
            ->when($fechaFin, fn($q) => $q->where('op.fecha_movimiento', '<=', $fechaFin))
            ->select(
                'op.id',
                'op.sucursal_entrega',
                'op.planilla_numero',
                'op.numero_orden',
                'op.numero_factura',
                'op.observaciones',
                Capsule::raw("$strAgg as auxiliar_nombre")
            )
            ->groupBy('op.id', 'op.sucursal_entrega', 'op.planilla_numero', 'op.numero_orden', 'op.numero_factura', 'op.observaciones')
            ->get();

        // Step 1b: órdenes con re-picks dentro del rango (backorder post-certificación).
        // Solo se ejecuta cuando hay un rango de fechas definido y ese rango incluye
        // fechas pasadas o presentes (fecha_fin <= hoy). Si el filtro apunta al futuro
        // no hay re-picks posibles y este bloque se omite para evitar falsos positivos.
        $hoy = date('Y-m-d');
        if ($fechaInicio && $fechaFin && $fechaFin <= $hoy) {
            $rePicksRango = Capsule::table('orden_pickings as op')
                ->leftJoin('personal as per', 'per.id', '=', 'op.auxiliar_id')
                ->join('picking_detalles as pd', 'pd.orden_picking_id', '=', 'op.id')
                ->where('op.empresa_id', $empresaId)
                ->where('op.sucursal_id', $sucursalId)
                ->whereIn('op.estado', ['Completada', 'EnProceso'])
                ->whereIn('op.estado_certificacion', ['Pendiente', 'Parcial'])
                ->whereNull('op.estado_despacho')
                // El re-pick (updated_at de la línea) debe caer dentro del rango buscado
                ->where('pd.updated_at', '>=', $fechaInicio . ' 00:00:00')
                ->where('pd.updated_at', '<=', $fechaFin   . ' 23:59:59')
                ->whereIn('pd.estado', ['Completada', 'Completado'])
                ->where('pd.cantidad_pickeada', '>', 0)
                // Excluir las ya incluidas por el rango de fecha_movimiento (no duplicar)
                ->where(function ($q) use ($fechaInicio, $fechaFin) {
                    $q->where('op.fecha_movimiento', '<', $fechaInicio)
                      ->orWhere('op.fecha_movimiento', '>', $fechaFin);
                })
                ->select(
                    'op.id',
                    'op.sucursal_entrega',
                    'op.planilla_numero',
                    'op.numero_orden',
                    'op.numero_factura',
                    'op.observaciones',
                    Capsule::raw("$strAgg as auxiliar_nombre")
                )
                ->groupBy('op.id', 'op.sucursal_entrega', 'op.planilla_numero', 'op.numero_orden', 'op.numero_factura', 'op.observaciones')
                ->get();

            if ($rePicksRango->isNotEmpty()) {
                $idsYaIncluidos = $ordenes->pluck('id')->toArray();
                foreach ($rePicksRango as $rp) {
                    if (!in_array($rp->id, $idsYaIncluidos)) {
                        $ordenes->push($rp);
                    }
                }
            }
        }

        if ($ordenes->isEmpty()) return $this->ok($res, []);

        // Step 2: agrega picking_detalles por sucursal (usa idx_pd_orden)
        $ids = $ordenes->pluck('id');
        $ambAgg = $this->isPg()
            ? "STRING_AGG(DISTINCT COALESCE(amb.descripcion, 'Sin ambiente'), ', ') as ambientes"
            : "GROUP_CONCAT(DISTINCT COALESCE(amb.descripcion, 'Sin ambiente') SEPARATOR ', ') as ambientes";
            
        $aggs = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->leftJoin('ambientes as amb', function ($j) use ($empresaId) {
                $j->on('amb.id', '=', 'p.ambiente_id')
                  ->where('amb.empresa_id', $empresaId);
            })
            ->whereIn('pd.orden_picking_id', $ids)
            ->whereExists(function($q) {
                 $q->select(Capsule::raw(1))
                   ->from('picking_detalles as pd3')
                   ->join('productos as p3', 'p3.id', '=', 'pd3.producto_id')
                   ->whereColumn('pd3.orden_picking_id', 'pd.orden_picking_id')
                   ->whereRaw('COALESCE(p3.ambiente_id, 0) = COALESCE(p.ambiente_id, 0)')
                   ->groupByRaw('COALESCE(p3.ambiente_id, 0)')
                   ->havingRaw("SUM(CASE WHEN pd3.estado IN ('Pendiente', 'EnProceso') THEN 1 ELSE 0 END) = 0");
            })
            ->select(
                'op.sucursal_entrega',
                Capsule::raw('COUNT(pd.id) as total_lineas_cert'),
                Capsule::raw('COUNT(DISTINCT pd.producto_id) as total_refs'),
                Capsule::raw('COALESCE(SUM(pd.cantidad_pickeada), 0) as total_unidades'),
                Capsule::raw($ambAgg)
            )
            ->groupBy('op.sucursal_entrega')
            ->get()->keyBy('sucursal_entrega');

        // Step 3: merge en PHP por sucursal
        $result = [];
        foreach ($ordenes as $o) {
            $suc = $o->sucursal_entrega;
            if (!isset($result[$suc])) {
                $result[$suc] = [
                    'sucursal_entrega'  => $suc,
                    'planilla_numero'   => $o->planilla_numero ?: $o->numero_orden,
                    'total_pedidos'     => 0,
                    'total_lineas'      => (int)($aggs[$suc]->total_refs      ?? 0),
                    'total_lineas_cert' => (int)($aggs[$suc]->total_lineas_cert ?? 0),
                    'total_unidades'    => $aggs[$suc]->total_unidades ?? 0,
                    'ambientes'         => $aggs[$suc]->ambientes      ?? 'Desconocido',
                    'auxiliares'        => $o->auxiliar_nombre,
                    'planillas'         => [],
                    'ordenes_ids'       => [],
                ];
            }
            $result[$suc]['total_pedidos']++;
            if (!empty($o->numero_factura)) {
                $result[$suc]['planillas'][] = $o->numero_factura;
            }
            $result[$suc]['ordenes_ids'][] = $o->id;
        }

        // Deduplica planillas por sucursal
        foreach ($result as &$entry) {
            $entry['planillas'] = array_values(array_unique($entry['planillas']));
        }
        unset($entry);

        return $this->ok($res, array_values($result));
    }

    public function resetearCertificacion(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $sucursal  = urldecode($a['sucursal']);
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucId     = $user->sucursal_id;

        return Capsule::transaction(function () use ($sucursal, $empresaId, $sucId, $user, $res) {
            $ordenes = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucId)
                ->where('sucursal_entrega', $sucursal)
                ->where('estado_certificacion', 'Certificada')
                ->get();

            if ($ordenes->isEmpty()) {
                return $this->error($res, 'No hay certificaciones completadas para esta sucursal', 404);
            }

            foreach ($ordenes as $o) {
                $o->estado_certificacion = 'Pendiente';
                $o->fecha_certificacion  = null;
                $o->certificador_id      = null;
                $o->save();

                Capsule::table('picking_detalles')
                    ->where('orden_picking_id', $o->id)
                    ->update([
                        'cantidad_certificada' => 0,
                        'estado_certificacion'  => 'Pendiente',
                        'updated_at'            => date('Y-m-d H:i:s'),
                    ]);
            }

            // Cancelar packing_sesiones completadas para esta sucursal (si las hay)
            $sesiones = Capsule::table('packing_sesiones')
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucId)
                ->where('sucursal_entrega', $sucursal)
                ->where('estado', 'Completada')
                ->pluck('id');

            if ($sesiones->isNotEmpty()) {
                $unidadIds = Capsule::table('packing_unidades')
                    ->whereIn('sesion_id', $sesiones)->pluck('id');
                if ($unidadIds->isNotEmpty()) {
                    Capsule::table('packing_items')->whereIn('unidad_id', $unidadIds)->delete();
                    Capsule::table('packing_unidades')->whereIn('id', $unidadIds)->delete();
                }
                Capsule::table('packing_sesiones')
                    ->whereIn('id', $sesiones)
                    ->update(['estado' => 'Cancelada', 'updated_at' => date('Y-m-d H:i:s')]);
            }

            $this->audit($user, 'packing', 'reset_certificacion', 'orden_pickings', null,
                ['sucursal' => $sucursal, 'ordenes' => $ordenes->count()],
                ['estado_certificacion' => 'Pendiente']);

            return $this->ok($res, [
                'ordenes_reseteadas' => $ordenes->count(),
                'sucursal'           => $sucursal,
            ], "Certificación de \"{$sucursal}\" reseteada. {$ordenes->count()} orden(es) vuelven a Pendiente.");
        });
    }

    public function certDetalle(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $sucursal   = urldecode($a['sucursal']);
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        // Flat JOIN — 1 sola query en lugar de 4+ (whereHas + eager loads)
        $rows = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->leftJoin('ambientes as amb', function ($j) use ($empresaId) {
                $j->on('amb.id', '=', 'p.ambiente_id')
                  ->where('amb.empresa_id', $empresaId);
            })
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.sucursal_entrega', $sucursal)
            ->whereIn('op.estado', ['Completada', 'EnProceso'])
            ->whereIn('op.estado_certificacion', ['Pendiente', 'Parcial'])
            ->whereExists(function($q) {
                $q->select(Capsule::raw(1))
                  ->from('picking_detalles as pd3')
                  ->join('productos as p3', 'p3.id', '=', 'pd3.producto_id')
                  ->whereColumn('pd3.orden_picking_id', 'pd.orden_picking_id')
                  ->whereRaw('COALESCE(p3.ambiente_id, 0) = COALESCE(p.ambiente_id, 0)')
                  ->groupByRaw('COALESCE(p3.ambiente_id, 0)')
                  ->havingRaw("SUM(CASE WHEN pd3.estado IN ('Pendiente', 'EnProceso') THEN 1 ELSE 0 END) = 0");
            })
            ->select(
                'pd.id',
                'pd.producto_id',
                'pd.cantidad_pickeada',
                'pd.cantidad_certificada',
                'p.nombre',
                'p.codigo_interno as codigo',
                'p.ambiente_id',
                Capsule::raw("COALESCE(amb.descripcion, 'Sin ambiente') as ambiente_nombre"),
                Capsule::raw("COALESCE(amb.codigo, 'otros') as ambiente_codigo"),
                Capsule::raw("COALESCE(amb.color, '#64748b') as ambiente_color")
            )
            ->orderBy('amb.descripcion')
            ->orderBy('p.nombre')
            ->get();

        $consolidado = [];
        foreach ($rows as $d) {
            $pid = $d->producto_id;
            if (!isset($consolidado[$pid])) {
                $consolidado[$pid] = [
                    'producto_id'          => $pid,
                    'nombre'               => $d->nombre,
                    'codigo'               => $d->codigo,
                    'ambiente_id'          => $d->ambiente_id,
                    'ambiente_nombre'      => $d->ambiente_nombre,
                    'ambiente_codigo'      => $d->ambiente_codigo,
                    'ambiente_color'       => $d->ambiente_color,
                    'cantidad_pickeada'    => 0,
                    'cantidad_certificada' => 0,
                    'detalles_ids'         => [],
                ];
            }
            $consolidado[$pid]['cantidad_pickeada']    += (float)$d->cantidad_pickeada;
            $consolidado[$pid]['cantidad_certificada'] += (float)$d->cantidad_certificada;
            $consolidado[$pid]['detalles_ids'][]       = $d->id;
        }

        return $this->ok($res, array_values($consolidado));
    }

    // ── GET /api/picking/certificacion/admin-detalle/{sucursal} ──────────────
    // Devuelve órdenes Completada agrupadas por orden con sus líneas.
    // Estructura: [{id, numero_pedido, estado_certificacion, lineas:[{id, producto_nombre, ...}]}]
    // Solo Admin
    public function certAdminDetalle(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $sucursal   = urldecode($a['sucursal']);
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        $rows = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as pr', 'pr.id', '=', 'pd.producto_id')
            ->where('op.empresa_id',      $empresaId)
            ->where('op.sucursal_id',     $sucursalId)
            ->where('op.sucursal_entrega', $sucursal)
            ->where('op.estado', 'Completada')
            ->whereNotIn('pd.estado', ['Anulado'])
            ->select(
                'op.id as orden_id',
                'op.numero_pedido',
                'op.estado_certificacion',
                'pd.id as detalle_id',
                'pd.cantidad_pickeada',
                'pd.cantidad_certificada',
                'pd.estado',
                'pr.nombre as producto_nombre',
                'pr.codigo_interno as producto_codigo'
            )
            ->orderBy('op.id')
            ->orderBy('pr.nombre')
            ->get();

        $pedidos = [];
        foreach ($rows as $d) {
            $oid = $d->orden_id;
            if (!isset($pedidos[$oid])) {
                $pedidos[$oid] = [
                    'id'                   => $oid,
                    'numero_pedido'        => $d->numero_pedido,
                    'estado_certificacion' => $d->estado_certificacion,
                    'lineas'               => [],
                ];
            }
            // Si no hay cert explícita (certFinalizar no escribe detalles),
            // usar cantidad_pickeada como valor certificado efectivo.
            $cantCert = (float)$d->cantidad_certificada;
            if ($cantCert < 0.001 && $d->estado_certificacion === 'Certificada') {
                $cantCert = (float)$d->cantidad_pickeada;
            }

            $pedidos[$oid]['lineas'][] = [
                'id'                     => $d->detalle_id,
                'producto_nombre'        => $d->producto_nombre,
                'producto_codigo'        => $d->producto_codigo,
                'cantidad_pickeada'      => (float)$d->cantidad_pickeada,
                'cantidad_certificada'   => $cantCert,
                'cert_explicita'         => (float)$d->cantidad_certificada > 0.001,
                'estado'                 => $d->estado,
                'estado_certificacion'   => $d->estado_certificacion,
            ];
        }

        return $this->ok($res, array_values($pedidos));
    }

    public function certConfirmar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();
        
        $productoId = $data['producto_id'];
        $sucursal   = $data['sucursal_entrega'];
        $cantidad   = (float)$data['cantidad'];
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        $detalles = PickingDetalle::where('producto_id', $productoId)
            ->whereHas('ordenPicking', function($q) use ($empresaId, $sucursalId, $sucursal) {
                $q->where('empresa_id',  $empresaId)
                  ->where('sucursal_id', $sucursalId)
                  ->where('sucursal_entrega', $sucursal)
                  ->where('estado', 'Completada')
                  ->where('estado_certificacion', 'Pendiente');
            })
            ->orderBy('id', 'asc')
            ->get();
            
        if ($detalles->isEmpty()) return $this->error($res, 'No se encontraron líneas pendientes para certificar');

        Capsule::transaction(function() use ($detalles, $cantidad) {
            $restante = $cantidad;
            foreach ($detalles as $d) {
                $capacidad = (float)$d->cantidad_pickeada;
                $tomar = min($restante, $capacidad);
                $d->cantidad_certificada = $tomar;
                $d->estado_certificacion = ($tomar >= $capacidad) ? 'Certificado' : 'Diferencia';
                $d->save();
                $restante -= $tomar;
            }
        });
        
        return $this->ok($res, null, 'Certificación de producto registrada');
    }

    public function certFinalizar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();
        $sucursal = $data['sucursal_entrega'];
        
        $ordenes = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $sucursal)
            ->whereIn('estado', ['Completada', 'EnProceso'])
            ->whereIn('estado_certificacion', ['Pendiente', 'Parcial'])
            ->get();

        if ($ordenes->isEmpty()) return $this->error($res, 'No hay órdenes pendientes para finalizar');

        $todosIds = $ordenes->pluck('id');

        // Validar que TODO lo que esté LISTO (ambiente completo) esté CERTIFICADO
        $listasSinCertificar = Capsule::table('picking_detalles as pd')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->whereIn('pd.orden_picking_id', $todosIds)
            ->where('pd.cantidad_pickeada', '>', 0)
            ->whereRaw('COALESCE(pd.cantidad_certificada, 0) < pd.cantidad_pickeada')
            ->whereExists(function($q) {
                $q->select(Capsule::raw(1))
                  ->from('picking_detalles as pd2')
                  ->join('productos as p2', 'p2.id', '=', 'pd2.producto_id')
                  ->whereColumn('pd2.orden_picking_id', 'pd.orden_picking_id')
                  ->whereRaw('COALESCE(p2.ambiente_id, 0) = COALESCE(p.ambiente_id, 0)')
                  ->groupBy('pd2.orden_picking_id', Capsule::raw('COALESCE(p2.ambiente_id, 0)'))
                  ->havingRaw("SUM(CASE WHEN pd2.estado IN ('Pendiente', 'EnProceso') THEN 1 ELSE 0 END) = 0");
            })
            ->count();

        if ($listasSinCertificar > 0) {
            return $this->error($res, 'Aún hay referencias listas sin certificar en los ambientes completados');
        }

        Capsule::transaction(function() use ($ordenes, $user) {
            $ids = $ordenes->pluck('id');
            $now = date('Y-m-d H:i:s');

            foreach ($ordenes as $o) {
                $nuevoEstado = ($o->estado === 'Completada') ? 'Certificada' : 'Parcial';
                Capsule::table('orden_pickings')
                    ->where('id', $o->id)
                    ->update([
                        'estado_certificacion' => $nuevoEstado,
                        'fecha_certificacion'  => $now,
                        'certificador_id'      => $user->id,
                    ]);
            }

            // Cargar todos los detalles de golpe — evita N+1 lazy loads
            $detalles  = Capsule::table('picking_detalles')->whereIn('orden_picking_id', $ids)->get();
            $ordenMap  = $ordenes->keyBy('id');

            foreach ($detalles as $d) {
                $diff = (float)$d->cantidad_pickeada - (float)($d->cantidad_certificada ?? 0);
                if (abs($diff) > 0.001) {
                    $o = $ordenMap[$d->orden_picking_id];
                    $this->audit($user, 'picking', 'novedad_certificacion', 'picking_detalles', $d->id,
                        ['pick' => $d->cantidad_pickeada], ['cert' => $d->cantidad_certificada],
                        "Diferencia en certificación: Pedido {$o->numero_orden}, Producto ID {$d->producto_id}. Faltan " . abs($diff));
                }
            }
        });

        // Sincronizar cert_planillas y archivos_planilla con la certificación completada.
        // certFinalizar solo toca orden_pickings; si el mismo lote pasó por el flujo de
        // archivo (importar CSV), las tablas cert_planillas y archivos_planilla deben
        // marcarse Completada para que viewCert no los vuelva a mostrar.
        $planillaNumerosCert = $ordenes->pluck('planilla_numero')->filter()->unique()->values()->toArray();
        if (!empty($planillaNumerosCert)) {
            try {
                $nowHora     = date('H:i:s');          // cert_planillas.hora_fin es TIME, no TIMESTAMP
                $nowTs       = date('Y-m-d H:i:s');
                $empresaSync = $this->getEffectiveEmpresaId($user, $r);

                // Obtener archivo_ids afectados ANTES de actualizar — con filtro de empresa
                $archivoIdsSync = Capsule::table('cert_planillas')
                    ->where('empresa_id', $empresaSync)
                    ->whereIn('numero_planilla', $planillaNumerosCert)
                    ->pluck('archivo_id')
                    ->unique()
                    ->values()
                    ->toArray();

                if (!empty($archivoIdsSync)) {
                    // Marcar cert_planillas como Completada — filtrar también por archivo_id
                    // para no contaminar otros archivos que tengan el mismo numero_planilla
                    Capsule::table('cert_planillas')
                        ->where('empresa_id', $empresaSync)
                        ->whereIn('archivo_id', $archivoIdsSync)
                        ->whereIn('numero_planilla', $planillaNumerosCert)
                        ->update(['estado' => 'Completada', 'hora_fin' => $nowHora, 'updated_at' => $nowTs]);

                    // Si TODAS las planillas del archivo quedan Completadas, marcar el archivo
                    foreach ($archivoIdsSync as $archivoIdSync) {
                        $total = Capsule::table('cert_planillas')->where('archivo_id', $archivoIdSync)->count();
                        $compl = Capsule::table('cert_planillas')->where('archivo_id', $archivoIdSync)->where('estado', 'Completada')->count();
                        if ($total > 0 && $total === $compl) {
                            Capsule::table('archivos_planilla')
                                ->where('id', $archivoIdSync)
                                ->update(['estado' => 'Completada', 'updated_at' => $nowTs]);
                        }
                    }
                }
            } catch (\Exception $syncEx) {
                // El sync de cert_planillas es secundario — no debe romper certFinalizar
                if (function_exists('wmsLog')) {
                    wmsLog('WARNING', 'certFinalizar: error sync cert_planillas: ' . $syncEx->getMessage());
                }
            }
        }

        // Detectar faltantes certificados e insertarlos en picking_faltantes
        $empresaIdCert = $this->getEffectiveEmpresaId($user, $r);
        $idsOrdenes    = $ordenes->pluck('id');

        $faltantesPend = Capsule::table('picking_detalles as pd')
            ->whereIn('pd.orden_picking_id', $idsOrdenes)
            ->where('pd.estado', 'Faltante')
            ->whereNotExists(function ($q) {
                $q->select(Capsule::raw(1))
                  ->from('picking_faltantes as pf')
                  ->whereColumn('pf.orden_picking_id', 'pd.orden_picking_id')
                  ->whereColumn('pf.producto_id', 'pd.producto_id');
            })
            ->select('pd.orden_picking_id', 'pd.producto_id', 'pd.cantidad_solicitada')
            ->get();

        if ($faltantesPend->isNotEmpty()) {
            $ordenMapCert = $ordenes->keyBy('id');
            $nowCert      = date('Y-m-d H:i:s');
            foreach ($faltantesPend as $fd) {
                $ord = $ordenMapCert[$fd->orden_picking_id] ?? null;
                Capsule::table('picking_faltantes')->insert([
                    'empresa_id'          => $empresaIdCert,
                    'sucursal_id'         => $user->sucursal_id,
                    'orden_picking_id'    => $fd->orden_picking_id,
                    'producto_id'         => $fd->producto_id,
                    'planilla_lote'       => $ord ? ($ord->planilla_lote ?? $ord->planilla_numero ?? null) : null,
                    'cantidad_solicitada' => $fd->cantidad_solicitada,
                    'cantidad_faltante'   => $fd->cantidad_solicitada,
                    'causa'               => 'Faltante certificado — sucursal: ' . $sucursal,
                    'created_at'          => $nowCert,
                    'updated_at'          => $nowCert,
                ]);
            }
        }

        $totalFaltantes = Capsule::table('picking_faltantes')
            ->whereIn('orden_picking_id', $idsOrdenes)
            ->count();

        $msg = $totalFaltantes > 0
            ? "Certificación finalizada. {$totalFaltantes} faltante(s) registrados en módulo de backorder."
            : 'Certificación de sucursal finalizada correctamente';

        return $this->ok($res, [
            'faltantes_detectados' => $totalFaltantes,
            'nuevos_faltantes'     => $faltantesPend->count(),
        ], $msg);
    }

    // ── GET /api/picking/certificacion/certificadas ───────────────────────────
    // Órdenes certificadas vía flujo directo (sin sesión de packing)
    public function certCertificadas(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $strAgg = $this->isPg()
            ? "STRING_AGG(DISTINCT cert.nombre, ', ')"
            : "GROUP_CONCAT(DISTINCT cert.nombre)";

        $qp = $r->getQueryParams();
        $fechaInicio = $qp['fecha_inicio'] ?? null;
        $fechaFin    = $qp['fecha_fin']    ?? null;

        // Step 1: órdenes certificadas (usa idx_op_cert) — sin JOIN a picking_detalles
        $ordenes = Capsule::table('orden_pickings as op')
            ->leftJoin('personal as cert', 'cert.id', '=', 'op.certificador_id')
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.estado', 'Completada')
            ->where('op.estado_certificacion', 'Certificada')
            // Pedidos con retiro directo (cliente ya lo recogió en bodega) se excluyen
            // de la certificación/remisión para que no se mezclen con la planilla.
            ->where('op.despachado_directo', false)
            // Una orden que ya fue despachada/entregada (flujo TMS) no debe volver a
            // aparecer aquí para re-imprimir/agrupar en una nueva remisión.
            ->whereNull('op.estado_despacho')
            ->when($fechaInicio, fn($q) => $q->where('op.fecha_movimiento', '>=', $fechaInicio))
            ->when($fechaFin, fn($q) => $q->where('op.fecha_movimiento', '<=', $fechaFin))
            ->select(
                'op.id',
                'op.sucursal_entrega',
                'op.planilla_numero',
                'op.numero_orden',
                'op.numero_factura',
                'op.observaciones',
                'op.fecha_movimiento',
                'op.fecha_certificacion',
                Capsule::raw("$strAgg as certificador_nombre")
            )
            ->groupBy('op.id', 'op.sucursal_entrega', 'op.planilla_numero', 'op.numero_orden',
                'op.numero_factura', 'op.observaciones', 'op.fecha_movimiento', 'op.fecha_certificacion')
            ->get();

        if ($ordenes->isEmpty()) return $this->ok($res, []);

        // Step 2: conteos de detalles + ambientes por orden (se agregan por grupo en Step 3)
        $ids  = $ordenes->pluck('id');
        $strAggAmb = $this->isPg()
            ? "STRING_AGG(DISTINCT COALESCE(a.descripcion, 'Sin ambiente'), ', ')"
            : "GROUP_CONCAT(DISTINCT COALESCE(a.descripcion, 'Sin ambiente'))";
        $aggs = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->leftJoin('ambientes as a', 'a.id', '=', 'p.ambiente_id')
            ->whereIn('pd.orden_picking_id', $ids)
            ->select(
                'op.id as orden_id',
                Capsule::raw('COUNT(DISTINCT pd.producto_id) as total_refs'),
                Capsule::raw('COALESCE(SUM(pd.cantidad_pickeada), 0) as total_unidades'),
                Capsule::raw("$strAggAmb as ambientes")
            )
            ->groupBy('op.id')
            ->get()->keyBy('orden_id');

        // Step 3: merge por (sucursal + planilla) — antes se agrupaba solo por sucursal,
        // así que dos planillas certificadas el mismo día para el mismo cliente quedaban
        // mezcladas en una sola fila, y al imprimir la remisión salían juntas sin que el
        // usuario lo pidiera. Cada (sucursal, planilla) es ahora su propia fila, con sus
        // propios orden_ids para poder imprimir por separado o seleccionar cuáles combinar.
        $result = [];
        foreach ($ordenes as $o) {
            $planillaLabel = trim($o->planilla_numero ?? '') ?: 'Sin planilla';
            $key = $o->sucursal_entrega . '||' . $planillaLabel;
            $agg = $aggs[$o->id] ?? null;

            if (!isset($result[$key])) {
                $result[$key] = [
                    'sucursal_entrega'    => $o->sucursal_entrega,
                    'planilla_numero'     => $planillaLabel,
                    'orden_ids'           => [],
                    'pedidos_numeros'     => [],
                    'observaciones_set'   => [],
                    'total_pedidos'       => 0,
                    'total_lineas'        => 0,
                    'total_unidades'      => 0,
                    'ambientes_set'       => [],
                    'fecha_movimiento'    => $o->fecha_movimiento,
                    'fecha_certificacion' => $o->fecha_certificacion,
                    'certificadores'      => $o->certificador_nombre,
                ];
            }
            $result[$key]['orden_ids'][]       = $o->id;
            $result[$key]['pedidos_numeros'][] = trim($o->numero_factura ?: $o->numero_orden ?: '');
            if (!empty(trim($o->observaciones ?? ''))) {
                $result[$key]['observaciones_set'][trim($o->observaciones)] = true;
            }
            $result[$key]['total_pedidos']++;
            $result[$key]['total_lineas']      += (int)($agg->total_refs ?? 0);
            $result[$key]['total_unidades']    += (float)($agg->total_unidades ?? 0);
            if (!empty($agg->ambientes)) {
                foreach (explode(', ', $agg->ambientes) as $amb) {
                    $result[$key]['ambientes_set'][$amb] = true;
                }
            }
            // Fecha del pedido: la más antigua del grupo (fecha en que fue montado)
            if ($o->fecha_movimiento < $result[$key]['fecha_movimiento']) {
                $result[$key]['fecha_movimiento'] = $o->fecha_movimiento;
            }
            if ($o->fecha_certificacion > $result[$key]['fecha_certificacion']) {
                $result[$key]['fecha_certificacion'] = $o->fecha_certificacion;
            }
        }

        foreach ($result as &$row) {
            $row['ambientes']        = implode(', ', array_keys($row['ambientes_set']));
            $row['pedidos_numeros']  = array_values(array_filter($row['pedidos_numeros']));
            $row['observaciones']    = implode(' | ', array_keys($row['observaciones_set']));
            unset($row['ambientes_set'], $row['observaciones_set']);
        }
        unset($row);

        uasort($result, fn($a, $b) => strcmp($b['fecha_certificacion'] ?? '', $a['fecha_certificacion'] ?? ''));

        return $this->ok($res, array_values($result));
    }

    // ── GET /api/picking/certificacion/despachados-directo ───────────────────
    // Pedidos marcados como retiro directo (ver marcarDespachadoDirecto) — excluidos
    // de certCertificadas()/remisión, pero deben seguir siendo visibles en el módulo
    // de despachos para que quede constancia de por qué no se imprimieron.
    public function certDespachadosDirecto(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $qp         = $r->getQueryParams();

        $ordenes = OrdenPicking::leftJoin('personal as marc', 'marc.id', '=', 'orden_pickings.despachado_directo_por')
            ->where('orden_pickings.empresa_id', $empresaId)
            ->where('orden_pickings.sucursal_id', $sucursalId)
            ->where('orden_pickings.despachado_directo', true)
            ->when($qp['fecha_inicio'] ?? null, fn($q, $v) => $q->where('orden_pickings.fecha_movimiento', '>=', $v))
            ->when($qp['fecha_fin'] ?? null, fn($q, $v) => $q->where('orden_pickings.fecha_movimiento', '<=', $v))
            ->select(
                'orden_pickings.id',
                'orden_pickings.sucursal_entrega',
                'orden_pickings.planilla_numero',
                'orden_pickings.numero_orden',
                'orden_pickings.numero_factura',
                'orden_pickings.observaciones',
                'orden_pickings.fecha_movimiento',
                'orden_pickings.despachado_directo_at',
                Capsule::raw('marc.nombre as marcado_por')
            )
            ->orderBy('orden_pickings.despachado_directo_at', 'desc')
            ->get();

        return $this->ok($res, $ordenes);
    }

    // ── GET /api/picking/certificacion/remision-multiple ─────────────────────
    // Remisión consolidada + por pedido para múltiples sucursales (certificación móvil)
    public function certRemisionMultiple(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $qp         = $r->getQueryParams();

        $sucursales  = array_values(array_filter(array_map('trim', (array)($qp['sucursales'] ?? []))));
        // orden_ids: selección explícita y exacta de pedidos (nuevo). Tiene prioridad
        // sobre 'sucursales', que agrupaba TODO lo certificado de un cliente en el día
        // sin distinguir planilla — si un cliente tenía 2 planillas certificadas el
        // mismo día, ambas se mezclaban en una sola remisión. Con orden_ids el usuario
        // elige dinámicamente, desde el listado de certificación, exactamente qué
        // pedidos/planillas deben salir juntos.
        $ordenIdsSel = array_values(array_filter(array_map('intval', (array)($qp['orden_ids'] ?? []))));
        if (empty($sucursales) && empty($ordenIdsSel)) {
            return $this->error($res, 'Se requiere al menos una sucursal o una selección de pedidos (orden_ids)');
        }

        $empresa   = Capsule::table('empresas')->find($empresaId);
        $empNombre = $empresa->nombre ?? 'WMS Fénix';
        $logoFile  = dirname(__DIR__, 2) . '/logo.jpg';
        $logoHtml  = file_exists($logoFile)
            ? "<img src='data:image/jpeg;base64," . base64_encode(file_get_contents($logoFile)) . "' style='height:52px;object-fit:contain;display:block;margin-bottom:4px;' alt='Logo'>"
            : "<strong style='font-size:16px;color:#1e3a5f;'>{$empNombre}</strong>";

        // ── Closure: convierte cantidad_pickeada (formato fraccionario de cajas) a cajas/saldo/und ──
        $calcItem = function ($it) {
            $upc     = max(1, (int)($it->unidades_caja ?? 1));
            $cantRaw = (float)$it->cantidad;
            if ($upc > 1) {
                $cajas = (int)floor($cantRaw);
                $saldo = round(($cantRaw - floor($cantRaw)) * $upc, 3);
                $und   = round($cajas * $upc + $saldo, 3);
            } else {
                $cajas = $cantRaw; $saldo = 0; $und = $cantRaw;
            }
            $fv = $it->fecha_vencimiento ? date('d/m/Y', strtotime($it->fecha_vencimiento)) : '&mdash;';
            $fc = $it->fecha_vencimiento ? '#b91c1c' : '#94a3b8';
            return compact('cajas', 'saldo', 'und', 'fv', 'fc');
        };

        // ── Closure: genera HTML de bloques de ambiente ──────────────────────
        $buildAmbs = function ($grouped) use ($calcItem) {
            $totalUnd = 0; $totalCj = 0; $html = '';
            foreach ($grouped as $ambNombre => $ambItems) {
                $subUnd = 0; $subCj = 0; $rows = '';
                foreach ($ambItems as $it) {
                    ['cajas' => $cajas, 'saldo' => $saldo, 'und' => $und, 'fv' => $fv, 'fc' => $fc] = $calcItem($it);
                    $subUnd += $und; $subCj += $cajas;
                    $rows .= "<tr>"
                        . "<td style='white-space:nowrap'>{$it->codigo}</td><td>{$it->nombre}</td>"
                        . "<td style='text-align:right;font-weight:700'>{$cajas}</td>"
                        . "<td style='text-align:right;color:#1e3a5f'>{$saldo}</td>"
                        . "<td style='text-align:right'>{$und}</td>"
                        . "<td style='text-align:center;color:{$fc}'>{$fv}</td></tr>";
                }
                $totalUnd += $subUnd; $totalCj += $subCj;
                $ambEsc = htmlspecialchars($ambNombre);
                $html .= "<div class='ambiente-block'>"
                    . "<div class='ambiente-header'>{$ambEsc} &mdash; {$subCj} cj / {$subUnd} und</div>"
                    . "<table style='table-layout:fixed;width:100%;'><colgroup>"
                    . "<col style='width:12%;'><col style='width:45%;'><col style='width:9%;'><col style='width:9%;'><col style='width:9%;'><col style='width:16%;'>"
                    . "</colgroup><thead><tr>"
                    . "<th>C&oacute;digo</th><th>Producto</th>"
                    . "<th style='text-align:right'>Cajas</th><th style='text-align:right'>Saldo</th>"
                    . "<th style='text-align:right'>Und.</th><th style='text-align:center'>F. Venc.</th>"
                    . "</tr></thead><tbody>{$rows}</tbody></table></div>";
            }
            return ['html' => $html, 'und' => $totalUnd, 'cj' => $totalCj];
        };

        // ── Closure: query de ítems por lista de orden IDs ───────────────────
        // ── Closure: agotados (faltantes de picking) por lista de orden IDs,
        // mostrando a qué pedido pertenece cada uno — antes esta remisión no tenía
        // ninguna sección de agotados en absoluto.
        $buildAgotados = function ($ordenIds) {
            $rows = Capsule::table('picking_faltantes as pf')
                ->join('productos as p', 'p.id', '=', 'pf.producto_id')
                ->leftJoin('orden_pickings as op', 'op.id', '=', 'pf.orden_picking_id')
                ->leftJoin('causales_novedad as cn', 'cn.id', '=', 'pf.causal_id')
                ->whereIn('pf.orden_picking_id', $ordenIds)
                ->select([
                    'p.codigo_interno as codigo',
                    'p.nombre',
                    Capsule::raw('SUM(pf.cantidad_faltante) as faltante'),
                    Capsule::raw("COALESCE(NULLIF(op.numero_factura, ''), op.numero_orden, '—') as pedido"),
                    Capsule::raw("STRING_AGG(DISTINCT COALESCE(pf.causa, 'Sin stock'), ', ') as causa"),
                    Capsule::raw("STRING_AGG(DISTINCT cn.nombre, ', ') as causal_nombre"),
                ])
                ->groupBy('p.codigo_interno', 'p.nombre', 'op.numero_factura', 'op.numero_orden')
                ->orderBy('p.nombre')
                ->get();

            if ($rows->isEmpty()) return '';

            $filas = '';
            foreach ($rows as $r) {
                $motivo = $r->causal_nombre
                    ? "<b>{$r->causal_nombre}</b>" . ($r->causa ? " — {$r->causa}" : '')
                    : ($r->causa ?: 'Sin causa registrada');
                $filas .= "<tr>"
                    . "<td style='white-space:nowrap'>{$r->codigo}</td>"
                    . "<td>{$r->nombre}</td>"
                    . "<td style='white-space:nowrap;font-weight:700'>{$r->pedido}</td>"
                    . "<td style='text-align:right'>{$r->faltante}</td>"
                    . "<td>{$motivo}</td>"
                    . "</tr>";
            }
            return "<div class='agotados-section'><div class='agotados-header'>PRODUCTOS AGOTADOS / FALTANTES</div>"
                . "<table style='table-layout:fixed;width:100%;'><colgroup>"
                . "<col style='width:12%;'><col style='width:36%;'><col style='width:18%;'><col style='width:12%;'><col style='width:22%;'></colgroup>"
                . "<thead><tr><th>C&oacute;digo</th><th>Producto</th><th>Pedido</th><th style='text-align:right;'>Faltante</th><th>Motivo</th></tr></thead>"
                . "<tbody>{$filas}</tbody></table></div>";
        };

        $queryItems = function ($ordenIds) {
            return Capsule::table('picking_detalles as pd')
                ->join('productos as p', 'p.id', '=', 'pd.producto_id')
                ->leftJoin('ambientes as a', 'a.id', '=', 'p.ambiente_id')
                ->whereIn('pd.orden_picking_id', $ordenIds)
                ->where('pd.cantidad_pickeada', '>', 0)
                ->select([
                    Capsule::raw("COALESCE(a.descripcion, 'Sin ambiente') as ambiente_nombre"),
                    Capsule::raw("COALESCE(a.color, '#1e3a5f') as ambiente_color"),
                    'p.id as producto_id', 'p.codigo_interno as codigo', 'p.nombre', 'p.unidades_caja',
                    Capsule::raw('SUM(pd.cantidad_pickeada) as cantidad'),
                    Capsule::raw("MAX(COALESCE(pd.fecha_vencimiento, (SELECT MIN(inv.fecha_vencimiento) FROM inventarios inv WHERE inv.producto_id = p.id AND inv.fecha_vencimiento IS NOT NULL AND inv.cantidad > 0 LIMIT 1))) as fecha_vencimiento"),
                ])
                ->groupBy('a.descripcion', 'a.color', 'p.id', 'p.codigo_interno', 'p.nombre', 'p.unidades_caja')
                ->orderByRaw("COALESCE(a.descripcion, 'Sin ambiente'), p.nombre")
                ->get()->groupBy('ambiente_nombre');
        };

        // ── Recopilar datos por sucursal + acumular consolidado ──────────────
        $pages      = [];
        $consoMap   = []; // [ambNombre][prodId] => stdClass

        $fechaFiltro = $qp['fecha'] ?? date('Y-m-d');

        if (!empty($ordenIdsSel)) {
            // Modo exacto: solo los pedidos que el usuario seleccionó, agrupados por
            // sucursal para conservar la misma estructura de páginas por cliente.
            $todasOrdenes = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->whereIn('id', $ordenIdsSel)
                ->where('estado_certificacion', 'Certificada')
                ->where('despachado_directo', false)
                ->get();
            $gruposPorSucursal = $todasOrdenes->groupBy('sucursal_entrega');
        } else {
            // Modo legacy: todo lo certificado del cliente en la fecha indicada.
            $gruposPorSucursal = collect();
            foreach ($sucursales as $suc) {
                $ordenesSuc = OrdenPicking::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('sucursal_entrega', $suc)
                    ->where('estado_certificacion', 'Certificada')
                    // Retiro directo (cliente ya lo recogió) — no se mezcla con la remisión.
                    ->where('despachado_directo', false)
                    ->whereDate('fecha_movimiento', $fechaFiltro)
                    ->get();
                if ($ordenesSuc->isNotEmpty()) $gruposPorSucursal->put($suc, $ordenesSuc);
            }
        }

        foreach ($gruposPorSucursal as $suc => $ordenes) {
            if ($ordenes->isEmpty()) continue;

            $ordenIds = $ordenes->pluck('id')->toArray();
            $rows     = $queryItems($ordenIds);

            foreach ($rows as $ambNombre => $ambItems) {
                foreach ($ambItems as $it) {
                    $pid = $it->producto_id;
                    if (!isset($consoMap[$ambNombre][$pid])) {
                        $consoMap[$ambNombre][$pid] = clone $it;
                    } else {
                        $consoMap[$ambNombre][$pid]->cantidad += $it->cantidad;
                        $fvNew = $it->fecha_vencimiento;
                        $fvOld = $consoMap[$ambNombre][$pid]->fecha_vencimiento;
                        if ($fvNew && (!$fvOld || $fvNew < $fvOld)) {
                            $consoMap[$ambNombre][$pid]->fecha_vencimiento = $fvNew;
                        }
                    }
                }
            }

            $certNombre = '';
            if ($ordenes->first()->certificador_id) {
                $cert = Capsule::table('personal')->find($ordenes->first()->certificador_id);
                $certNombre = $cert ? trim($cert->nombre) : '';
            }
            $pages[] = [
                'sucursal'   => $suc,
                'rows'       => $rows,
                'certNombre' => $certNombre,
                'fechaMov'   => $ordenes->min('fecha_movimiento'),
                'planilla'   => $ordenes->pluck('planilla_numero')->filter()->unique()->implode(', '),
                // numero_factura es el N° de pedido real del cliente; numero_orden puede ser
                // solo la etiqueta interna de planilla ("Planilla 200") — mostrar eso aquí
                // duplicaba el campo "Planilla" y dejaba de mostrarse el pedido real.
                'pedidos'    => $ordenes->map(fn($o) => trim($o->numero_factura ?: $o->numero_orden ?: ''))->filter()->unique()->implode(', '),
                'agotadosHtml' => $buildAgotados($ordenIds),
            ];
        }

        if (empty($pages)) return $this->error($res, 'No se encontraron órdenes certificadas para las sucursales indicadas');

        // ── CSS compartido ────────────────────────────────────────────────────
        $css = "@page{size:A4 portrait;margin:15mm 18mm}
        @media print{.no-print{display:none!important} body{margin:0} .pg-break{page-break-after:always}}
        body{font-family:Arial,sans-serif;font-size:10.5px;color:#1a1a1a;margin:0;padding:10px}
        .pg-break{page-break-after:always;margin-bottom:20px}
        .header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #1e3a5f;padding-bottom:8px;margin-bottom:10px}
        .header-left p{margin:0;font-size:9.5px;color:#555}
        .header-right{text-align:right;font-size:10px;color:#333}
        .info-grid{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 20px;margin-bottom:10px;background:#f8fafc;padding:6px 12px;border-radius:4px;border:1px solid #e2e8f0;page-break-after:avoid}
        .info-grid .campo{white-space:nowrap;font-size:10.5px;color:#1e293b}
        .info-grid .lbl{font-weight:700;font-size:9px;color:#334155;text-transform:uppercase;letter-spacing:.3px;margin-right:4px}
        .ambientes-grid{display:grid;grid-template-columns:1fr;gap:10px}
        .ambiente-block{border:1px solid #cbd5e1;border-radius:4px;overflow:hidden;page-break-inside:avoid}
        .ambiente-header{background:#000;color:#fff;padding:5px 10px;font-weight:700;font-size:10.5px;letter-spacing:.2px}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #e2e8f0;padding:4px 6px;font-size:9.5px;text-align:left;vertical-align:middle}
        th{background:#f1f5f9;font-weight:700;color:#334155;white-space:nowrap}
        tr:nth-child(even) td{background:#f8fafc}
        .totales{border-top:3px solid #1e3a5f;padding:8px 0;font-weight:700;font-size:12px;margin-top:10px;color:#1e3a5f}
        .novedades-section{margin-top:12px;border:2px solid #1e3a5f;border-radius:4px;overflow:hidden;page-break-inside:avoid}
        .novedades-header{background:#1e3a5f;color:#fff;padding:5px 10px;font-weight:700;font-size:10.5px;letter-spacing:.3px}
        .novedades-section td{height:22px}
        .agotados-section{margin-top:10px;border:2px solid #b91c1c;border-radius:4px;overflow:hidden;page-break-inside:avoid}
        .agotados-header{background:#b91c1c;color:#fff;padding:5px 10px;font-weight:700;font-size:10.5px;letter-spacing:.3px}
        .firmas{display:grid;grid-template-columns:1fr 1fr 1fr;gap:40px;margin-top:30px;page-break-inside:avoid}
        .firma-line{border-top:2px solid #1e3a5f;padding-top:5px;text-align:center;font-size:10px;color:#334155}
        .no-print{padding:8px 0;margin-bottom:10px}
        .no-print button{padding:7px 20px;font-size:13px;cursor:pointer;background:#1e3a5f;color:#fff;border:none;border-radius:6px;margin-right:8px}";

        $novedadesHtml = "<div class='novedades-section'><div class='novedades-header'>NOVEDADES DE RECEPCI&Oacute;N</div>"
            . "<table style='table-layout:fixed;width:100%;'><colgroup>"
            . "<col style='width:12%;'><col style='width:38%;'><col style='width:10%;'><col style='width:40%;'></colgroup>"
            . "<thead><tr><th>C&oacute;digo</th><th>Descripci&oacute;n</th><th style='text-align:right;'>Cantidad</th><th>Motivo</th></tr></thead>"
            . "<tbody>" . str_repeat("<tr style='height:22px'><td></td><td></td><td></td><td></td></tr>", 5)
            . "</tbody></table></div>";

        // ── Página consolidada ────────────────────────────────────────────────
        ksort($consoMap);
        $consoGrouped = [];
        foreach ($consoMap as $ambNombre => $prods) {
            uasort($prods, fn($a, $b) => strcmp($a->nombre, $b->nombre));
            $consoGrouped[$ambNombre] = array_values($prods);
        }
        $cr      = $buildAmbs($consoGrouped);
        $fechaHoy = date('d/m/Y');
        $nSucs   = count($pages);
        $listaClientes = implode(', ', array_column($pages, 'sucursal'));

        // Planillas únicas y tabla resumen por cliente
        $planillasSet = [];
        $clientesRows = '';
        foreach ($pages as $pg) {
            foreach (array_filter(array_map('trim', explode(',', $pg['planilla']))) as $pl) {
                $planillasSet[$pl] = true;
            }
            $clientesRows .= "<tr>"
                . "<td style='padding:3px 6px;border:1px solid #e2e8f0;font-weight:700'>" . htmlspecialchars($pg['sucursal']) . "</td>"
                . "<td style='padding:3px 6px;border:1px solid #e2e8f0'>" . htmlspecialchars($pg['planilla'] ?: '-') . "</td>"
                . "<td style='padding:3px 6px;border:1px solid #e2e8f0'>" . htmlspecialchars($pg['pedidos'] ?: '-') . "</td>"
                . "</tr>";
        }
        $todasPlanillas = implode(', ', array_keys($planillasSet)) ?: '-';
        $clientesTable  = "<div style='font-weight:700;font-size:9px;color:#334155;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;'>DETALLE POR CLIENTE</div>"
            . "<table style='width:100%;border-collapse:collapse;font-size:9px;margin-bottom:10px;'>"
            . "<thead><tr>"
            . "<th style='background:#f1f5f9;padding:3px 6px;border:1px solid #e2e8f0;text-align:left'>Cliente</th>"
            . "<th style='background:#f1f5f9;padding:3px 6px;border:1px solid #e2e8f0;text-align:left'>Planilla</th>"
            . "<th style='background:#f1f5f9;padding:3px 6px;border:1px solid #e2e8f0;text-align:left'>Pedido(s)</th>"
            . "</tr></thead><tbody>{$clientesRows}</tbody></table>";

        $consolidadoPage = "<div class='pg-break'>"
            . "<div class='header'>"
            . "  <div class='header-left'>{$logoHtml}<p>REMISI&Oacute;N CONSOLIDADA &mdash; CERTIFICACI&Oacute;N M&Oacute;VIL</p></div>"
            . "  <div class='header-right'><strong>Planilla: {$todasPlanillas}</strong><br>Fecha: {$fechaHoy}</div>"
            . "</div>"
            . "<div class='info-grid'>"
            . "  <span class='campo'><span class='lbl'>Planilla(s):</span>{$todasPlanillas}</span>"
            . "  <span class='campo'><span class='lbl'>Total cajas:</span>{$cr['cj']}</span>"
            . "  <span class='campo'><span class='lbl'>Total unidades:</span>{$cr['und']}</span>"
            . "  <span class='campo'><span class='lbl'>N&ordm; Clientes:</span>{$nSucs}</span>"
            . "  <span class='campo'><span class='lbl'>Fecha:</span>{$fechaHoy}</span>"
            . "</div>"
            . $clientesTable
            . "<div class='ambientes-grid'>{$cr['html']}</div>"
            . "<div class='totales'>CONSOLIDADO: {$cr['cj']} cajas &mdash; {$cr['und']} unidades</div>"
            . "</div>";

        // ── Páginas individuales ──────────────────────────────────────────────
        $individualPages = '';
        foreach ($pages as $idx => $page) {
            $pr       = $buildAmbs($page['rows']);
            $fechaStr = $page['fechaMov'] ? date('d/m/Y', strtotime($page['fechaMov'])) : $fechaHoy;
            $sucEsc   = htmlspecialchars($page['sucursal']);
            $isLast   = ($idx === $nSucs - 1);
            $wrapOpen = $isLast ? "<div>" : "<div class='pg-break'>";

            $individualPages .= $wrapOpen
                . "<div class='header'>"
                . "  <div class='header-left'>{$logoHtml}<p>REMISI&Oacute;N DE CERTIFICACI&Oacute;N</p></div>"
                . "  <div class='header-right'><strong>{$sucEsc}</strong><br>Fecha pedido: {$fechaStr}</div>"
                . "</div>"
                . "<div class='info-grid'>"
                . "  <span class='campo'><span class='lbl'>Planilla:</span>{$page['planilla']}</span>"
                . "  <span class='campo'><span class='lbl'>Cliente / Sucursal:</span>{$sucEsc}</span>"
                . "  <span class='campo'><span class='lbl'>Pedido(s):</span>{$page['pedidos']}</span>"
                . "  <span class='campo'><span class='lbl'>Certificador:</span>{$page['certNombre']}</span>"
                . "  <span class='campo'><span class='lbl'>Fecha pedido:</span>{$fechaStr}</span>"
                . "  <span class='campo'><span class='lbl'>Total unidades:</span>{$pr['und']}</span>"
                . "</div>"
                . "<div class='ambientes-grid'>{$pr['html']}</div>"
                . ($page['agotadosHtml'] ?? '')
                . $novedadesHtml
                . "<div class='totales'>TOTAL: {$pr['cj']} cj &mdash; {$pr['und']} und certificadas</div>"
                . "<div class='firmas'>"
                . "  <div class='firma-line'>Firma Certificador<br><strong>{$page['certNombre']}</strong></div>"
                . "  <div class='firma-line'>Firma Transportador</div>"
                . "  <div class='firma-line'>Firma Recibido</div>"
                . "</div></div>";
        }

        // La página consolidada resume/repite lo mismo que la única página individual
        // cuando solo hay un grupo (una planilla o un cliente) seleccionado — mostrarla
        // igual producía el efecto de "la remisión sale doble". Solo tiene sentido
        // cuando se combinan 2+ grupos distintos.
        $incluirConsolidado = $nSucs > 1;
        $subtitulo = $incluirConsolidado
            ? "{$nSucs} pedido(s): consolidado + remisi&#243;n individual por pedido"
            : "1 pedido/planilla seleccionado";

        $html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>"
            . "<title>Remisi&#243;n M&#250;ltiple &mdash; {$nSucs} pedidos</title>"
            . "<style>{$css}</style></head><body>"
            . "<div class='no-print'>"
            . "  <button onclick='window.print()'>&#128424; Imprimir / Guardar PDF</button>"
            . "  <small style='color:#666'>{$subtitulo}</small>"
            . "</div>"
            . ($incluirConsolidado ? $consolidadoPage : '')
            . $individualPages
            . "</body></html>";

        $body = $res->getBody();
        $body->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    // ── GET /api/picking/certificacion/remision/{sucursal} ───────────────────
    // Remisión HTML para órdenes certificadas sin sesión de packing (flujo directo)
    public function certRemisionDirecta(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $sucursal   = urldecode($a['sucursal']);

        $empresa  = Capsule::table('empresas')->find($empresaId);
        $empNombre = $empresa->nombre ?? 'WMS Fénix';

        $qpDirect    = $r->getQueryParams();
        $fechaDirect = $qpDirect['fecha'] ?? date('Y-m-d');

        $ordenes = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado_certificacion', 'Certificada')
            // Retiro directo (cliente ya lo recogió) — no se mezcla con la remisión.
            ->where('despachado_directo', false)
            ->whereDate('fecha_movimiento', $fechaDirect)
            ->get();

        if ($ordenes->isEmpty()) return $this->error($res, 'No se encontraron órdenes certificadas para la fecha indicada');

        $ordenIds   = $ordenes->pluck('id')->toArray();
        $planillaStr = $ordenes->pluck('planilla_numero')->filter()->unique()->implode(', ');
        // numero_factura es el N° de pedido real del cliente; numero_orden puede ser solo
        // la etiqueta interna de planilla — mostrar eso aquí duplicaba "Planilla" y dejaba
        // de mostrarse el pedido real en el encabezado.
        $numOrdenes  = $ordenes->map(fn($o) => trim($o->numero_factura ?: $o->numero_orden ?: ''))->filter()->unique()->implode(', ');
        $certNombre = '';
        if ($ordenes->first()->certificador_id) {
            $cert = Capsule::table('personal')->find($ordenes->first()->certificador_id);
            $certNombre = $cert ? trim($cert->nombre) : 'N/A';
        }
        $fechaMov   = $ordenes->min('fecha_movimiento');
        $fechaCert  = $ordenes->max('fecha_certificacion');
        $fechaStr   = $fechaMov ? date('d/m/Y', strtotime($fechaMov)) : date('d/m/Y');

        // Items por ambiente desde picking_detalles
        $rows = Capsule::table('picking_detalles as pd')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->leftJoin('ambientes as a', 'a.id', '=', 'p.ambiente_id')
            ->whereIn('pd.orden_picking_id', $ordenIds)
            ->where('pd.cantidad_pickeada', '>', 0)
            ->select([
                Capsule::raw("COALESCE(a.descripcion, 'Sin ambiente') as ambiente_nombre"),
                Capsule::raw("COALESCE(a.color, '#1e3a5f') as ambiente_color"),
                'p.id as producto_id',
                'p.codigo_interno as codigo',
                'p.nombre',
                'p.unidades_caja',
                Capsule::raw('SUM(pd.cantidad_pickeada) as cantidad'),
                Capsule::raw("MAX(COALESCE(pd.fecha_vencimiento, (SELECT MIN(inv.fecha_vencimiento) FROM inventarios inv WHERE inv.producto_id = p.id AND inv.fecha_vencimiento IS NOT NULL AND inv.cantidad > 0 LIMIT 1))) as fecha_vencimiento"),
            ])
            ->groupBy('a.descripcion', 'a.color', 'p.id', 'p.codigo_interno', 'p.nombre', 'p.unidades_caja')
            ->orderByRaw("COALESCE(a.descripcion, 'Sin ambiente'), p.nombre")
            ->get()
            ->groupBy('ambiente_nombre');

        $logoFile = dirname(__DIR__, 2) . '/logo.jpg';
        $logoHtml = file_exists($logoFile)
            ? "<img src='data:image/jpeg;base64," . base64_encode(file_get_contents($logoFile)) . "' style='height:52px;object-fit:contain;display:block;margin-bottom:4px;' alt='Logo'>"
            : "<strong style='font-size:16px;color:#1e3a5f;'>{$empNombre}</strong>";

        $totalUnd    = 0;
        $totalCajas  = 0;
        $ambientesHtml = '';
        foreach ($rows as $ambNombre => $ambItems) {
            $subtotalUnd = 0;
            $subtotalCj  = 0;
            $rowsHtml    = '';
            foreach ($ambItems as $it) {
                $upc     = max(1, (int)($it->unidades_caja ?? 1));
                $cantRaw = (float)$it->cantidad;
                // cantidad_pickeada usa el mismo formato fraccionario que packing_items.cantidad:
                // parte entera = cajas completas, parte decimal × upc = unidades sueltas
                if ($upc > 1) {
                    $cajas    = (int)floor($cantRaw);
                    $saldo    = round(($cantRaw - floor($cantRaw)) * $upc, 3);
                    $undTotal = round($cajas * $upc + $saldo, 3);
                } else {
                    $cajas    = $cantRaw;
                    $saldo    = 0;
                    $undTotal = $cantRaw;
                }
                $fv      = $it->fecha_vencimiento ? date('d/m/Y', strtotime($it->fecha_vencimiento)) : '&mdash;';
                $fvColor = $it->fecha_vencimiento ? '#b91c1c' : '#94a3b8';
                $subtotalUnd += $undTotal;
                $subtotalCj  += $cajas;
                $rowsHtml .= "<tr>
                  <td style='white-space:nowrap'>{$it->codigo}</td>
                  <td>{$it->nombre}</td>
                  <td style='text-align:right;font-weight:700'>{$cajas}</td>
                  <td style='text-align:right;color:#1e3a5f'>{$saldo}</td>
                  <td style='text-align:right'>{$undTotal}</td>
                  <td style='text-align:center;color:{$fvColor}'>{$fv}</td>
                </tr>";
            }
            $totalUnd   += $subtotalUnd;
            $totalCajas += $subtotalCj;
            $ambientesHtml .= "
            <div class='ambiente-block'>
              <div class='ambiente-header'>{$ambNombre} &mdash; {$subtotalCj} cj / {$subtotalUnd} und</div>
              <table style='table-layout:fixed;width:100%;'>
                <colgroup>
                  <col style='width:12%;'>
                  <col style='width:45%;'>
                  <col style='width:9%;'>
                  <col style='width:9%;'>
                  <col style='width:9%;'>
                  <col style='width:16%;'>
                </colgroup>
                <thead><tr>
                  <th>C&oacute;digo</th><th>Producto</th>
                  <th style='text-align:right'>Cajas</th>
                  <th style='text-align:right'>Saldo</th>
                  <th style='text-align:right'>Und.</th>
                  <th style='text-align:center'>F. Venc.</th>
                </tr></thead>
                <tbody>{$rowsHtml}</tbody>
              </table>
            </div>";
        }

        $novedadesHtml = "
<div class='novedades-section'>
  <div class='novedades-header'>NOVEDADES DE RECEPCI&Oacute;N</div>
  <table style='table-layout:fixed;width:100%;'>
    <colgroup><col style='width:12%;'><col style='width:38%;'><col style='width:10%;'><col style='width:40%;'></colgroup>
    <thead><tr><th>C&oacute;digo</th><th>Descripci&oacute;n</th><th style='text-align:right;'>Cantidad</th><th>Motivo</th></tr></thead>
    <tbody>
      <tr style='height:22px'><td></td><td></td><td></td><td></td></tr>
      <tr style='height:22px'><td></td><td></td><td></td><td></td></tr>
      <tr style='height:22px'><td></td><td></td><td></td><td></td></tr>
      <tr style='height:22px'><td></td><td></td><td></td><td></td></tr>
      <tr style='height:22px'><td></td><td></td><td></td><td></td></tr>
    </tbody>
  </table>
</div>";

        $html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Remisi&#243;n &mdash; " . htmlspecialchars($sucursal) . "</title>
<style>
  @page{size:A4 portrait;margin:15mm 18mm}
  @media print{.no-print{display:none!important} body{margin:0}}
  body{font-family:Arial,sans-serif;font-size:10.5px;color:#1a1a1a;margin:0;padding:10px}
  .header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #1e3a5f;padding-bottom:8px;margin-bottom:10px}
  .header-left p{margin:0;font-size:9.5px;color:#555}
  .header-right{text-align:right;font-size:10px;color:#333}
  .info-grid{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 20px;margin-bottom:10px;background:#f8fafc;padding:6px 12px;border-radius:4px;border:1px solid #e2e8f0;page-break-after:avoid}
  .info-grid .campo{white-space:nowrap;font-size:10.5px;color:#1e293b}
  .info-grid .lbl{font-weight:700;font-size:9px;color:#334155;text-transform:uppercase;letter-spacing:.3px;margin-right:4px}
  .ambientes-grid{display:grid;grid-template-columns:1fr;gap:10px}
  .ambiente-block{border:1px solid #cbd5e1;border-radius:4px;overflow:hidden;page-break-inside:avoid}
  .ambiente-header{background:#000;color:#fff;padding:5px 10px;font-weight:700;font-size:10.5px;letter-spacing:.2px}
  table{width:100%;border-collapse:collapse}
  th,td{border:1px solid #e2e8f0;padding:4px 6px;font-size:9.5px;text-align:left;vertical-align:middle}
  th{background:#f1f5f9;font-weight:700;color:#334155;white-space:nowrap}
  tr:nth-child(even) td{background:#f8fafc}
  .totales{border-top:3px solid #1e3a5f;padding:8px 0;font-weight:700;font-size:12px;margin-top:10px;color:#1e3a5f}
  .novedades-section{margin-top:12px;border:2px solid #1e3a5f;border-radius:4px;overflow:hidden;page-break-inside:avoid}
  .novedades-header{background:#1e3a5f;color:#fff;padding:5px 10px;font-weight:700;font-size:10.5px;letter-spacing:.3px}
  .novedades-section td{height:22px}
  .firmas{display:grid;grid-template-columns:1fr 1fr 1fr;gap:40px;margin-top:30px;page-break-inside:avoid}
  .firma-line{border-top:2px solid #1e3a5f;padding-top:5px;text-align:center;font-size:10px;color:#334155}
  .no-print{padding:8px 0;margin-bottom:10px}
  .no-print button{padding:7px 20px;font-size:13px;cursor:pointer;background:#1e3a5f;color:#fff;border:none;border-radius:6px;margin-right:8px}
</style></head><body>
<div class='no-print'>
  <button onclick='window.print()'>&#128424; Imprimir / Guardar PDF</button>
  <small style='color:#666'>Usa &ldquo;Guardar como PDF&rdquo; en el di&#225;logo de impresi&#243;n para exportar</small>
</div>
<div class='header'>
  <div class='header-left'>
    {$logoHtml}
    <p>REMISI&Oacute;N DE CERTIFICACI&Oacute;N</p>
  </div>
  <div class='header-right'>
    <strong>" . htmlspecialchars($sucursal) . "</strong><br>
    Fecha pedido: {$fechaStr}
  </div>
</div>
<div class='info-grid'>
  <span class='campo'><span class='lbl'>Planilla:</span>{$planillaStr}</span>
  <span class='campo'><span class='lbl'>Cliente / Sucursal:</span>" . htmlspecialchars($sucursal) . "</span>
  <span class='campo'><span class='lbl'>Pedido(s):</span>{$numOrdenes}</span>
  <span class='campo'><span class='lbl'>Certificador:</span>{$certNombre}</span>
  <span class='campo'><span class='lbl'>Fecha pedido:</span>{$fechaStr}</span>
  <span class='campo'><span class='lbl'>Total unidades:</span>{$totalUnd}</span>
</div>
<div class='ambientes-grid'>{$ambientesHtml}</div>
{$novedadesHtml}
<div class='totales'>TOTAL GENERAL: {$totalCajas} cj &mdash; {$totalUnd} und certificadas</div>
<div class='firmas'>
  <div class='firma-line'>Firma Certificador<br><strong>{$certNombre}</strong></div>
  <div class='firma-line'>Firma Transportador</div>
  <div class='firma-line'>Firma Recibido</div>
</div>
</body></html>";

        $body = $res->getBody();
        $body->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    // ── PUT /api/picking/{id}/auxiliar ───────────────────────────────────────
    // Cambia el auxiliar cuando ninguna línea ha iniciado picking
    public function cambiarAuxiliar(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $data       = $r->getParsedBody() ?? [];
        $nuevoAuxId = isset($data['auxiliar_id']) ? (int)$data['auxiliar_id'] : null;

        if (!$nuevoAuxId) {
            return $this->error($res, 'Se requiere auxiliar_id');
        }

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);

        if (!$orden) return $this->notFound($res);

        $lineasActivas = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereNotIn('estado', ['Pendiente', 'Creado'])
            ->count();

        if ($lineasActivas > 0) {
            return $this->error($res,
                'No se puede cambiar el auxiliar: el picking ya inició en algunas líneas. Use "Agregar Auxiliar" para distribuir las líneas restantes.',
                422);
        }

        $anteriorAuxId = $orden->auxiliar_id;
        $now = date('Y-m-d H:i:s');

        Capsule::transaction(function () use ($orden, $nuevoAuxId, $now) {
            $orden->auxiliar_id = $nuevoAuxId;
            $orden->save();
            Capsule::table('picking_detalles')
                ->where('orden_picking_id', $orden->id)
                ->update(['auxiliar_id' => $nuevoAuxId, 'updated_at' => $now]);
        });

        \App\Controllers\NotificacionesController::crear(
            $this->getEffectiveEmpresaId($user, $r), $nuevoAuxId,
            'Orden de Picking Asignada',
            "Se le ha asignado la orden {$orden->numero_orden} para alistamiento.",
            'picking', $user->id, 'Picking', $orden->id, 'viewPicking', 'Picking',
            true, $user->sucursal_id
        );

        $this->audit($user, 'picking', 'cambiar_auxiliar', 'orden_pickings', $orden->id,
            ['auxiliar_id' => $anteriorAuxId], ['auxiliar_id' => $nuevoAuxId],
            "Auxiliar cambiado en orden {$orden->numero_orden}");

        return $this->ok($res, null, 'Auxiliar actualizado correctamente');
    }

    // ── POST /api/picking/{id}/auxiliar ──────────────────────────────────────
    // Agrega auxiliar y le asigna la mitad de las líneas pendientes (sin duplicar productos)
    public function agregarAuxiliar(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $data       = $r->getParsedBody() ?? [];
        $nuevoAuxId = isset($data['auxiliar_id']) ? (int)$data['auxiliar_id'] : null;

        if (!$nuevoAuxId) {
            return $this->error($res, 'Se requiere auxiliar_id');
        }

        $orden = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);

        if (!$orden) return $this->notFound($res);

        if (!in_array($orden->estado, ['EnProceso', 'Pendiente', 'Asignado'])) {
            return $this->error($res, 'El pedido no está en un estado que permita agregar auxiliares');
        }

        // Líneas pendientes ordenadas por pasillo ASC + nivel DESC para respetar el recorrido de bodega
        $lineasPendientes = Capsule::table('picking_detalles as pd')
            ->leftJoin('ubicaciones as u', 'pd.ubicacion_id', '=', 'u.id')
            ->where('pd.orden_picking_id', $orden->id)
            ->whereIn('pd.estado', ['Pendiente', 'Creado'])
            ->select(['pd.id'])
            ->orderByRaw('u.pasillo ASC NULLS LAST, u.posicion ASC NULLS LAST, u.nivel ASC NULLS LAST, u.codigo ASC NULLS LAST')
            ->pluck('pd.id');

        if ($lineasPendientes->isEmpty()) {
            return $this->error($res, 'No hay líneas pendientes para distribuir', 422);
        }

        $total = $lineasPendientes->count();
        // Soporta porcentaje configurable (default 50%). El nuevo auxiliar recibe ese % del total.
        $pct   = max(10, min(90, (int)($data['porcentaje'] ?? 50)));
        $inicio = (int)round($total * (100 - $pct) / 100);
        // La porción según porcentaje va al nuevo auxiliar
        $paraNuevo = $lineasPendientes->slice($inicio)->values()->toArray();

        $now = date('Y-m-d H:i:s');

        Capsule::transaction(function () use ($paraNuevo, $nuevoAuxId, $now) {
            Capsule::table('picking_detalles')
                ->whereIn('id', $paraNuevo)
                ->update(['auxiliar_id' => $nuevoAuxId, 'updated_at' => $now]);
        });

        \App\Controllers\NotificacionesController::crear(
            $this->getEffectiveEmpresaId($user, $r), $nuevoAuxId,
            'Tareas de Picking Asignadas',
            "Se le han asignado " . count($paraNuevo) . " líneas pendientes en el pedido {$orden->numero_orden}.",
            'picking', $user->id, 'Picking', $orden->id, 'viewPicking', 'Picking',
            true, $user->sucursal_id
        );

        $this->audit($user, 'picking', 'agregar_auxiliar', 'orden_pickings', $orden->id,
            null,
            ['nuevo_auxiliar_id' => $nuevoAuxId, 'lineas_asignadas' => count($paraNuevo), 'porcentaje' => $pct],
            "Auxiliar adicional en orden {$orden->numero_orden}: " . ($total - count($paraNuevo)) . " existente + " . count($paraNuevo) . " nuevas ({$pct}%)");

        return $this->ok($res, [
            'lineas_reasignadas'       => count($paraNuevo),
            'lineas_auxiliar_original' => $total - count($paraNuevo),
            'total_pendientes'         => $total,
        ], count($paraNuevo) . ' líneas asignadas al nuevo auxiliar');
    }

    public function imprimirCertificado(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $sucursal = urldecode($a['sucursal']);
        
        // 1. Get info to print
        $ordenes = OrdenPicking::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $sucursal)
            ->where('fecha_movimiento', date('Y-m-d'))
            ->where('estado_certificacion', 'Certificada')
            ->whereNull('estado_despacho')
            ->get();

        if ($ordenes->isEmpty()) return $this->error($res, 'No se encontraron órdenes certificadas para esta sucursal');

        $totalLineas = PickingDetalle::whereIn('orden_picking_id', $ordenes->pluck('id'))->count();
        
        // 2. Get printers assigned to the 'certificacion' module
        $pRotulos = \App\Models\Impresora::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('modulos', 'LIKE', '%certificacion%')
            ->where('tipo', 'Rotulos')
            ->where('activo', true)
            ->first();
            
        $pDespacho = \App\Models\Impresora::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('modulos', 'LIKE', '%certificacion%')
            ->where('tipo', 'Despacho')
            ->where('activo', true)
            ->first();

        $results = [];

        // 3. Print Label (ZPL)
        if ($pRotulos) {
            $zpl = \App\Helpers\PrintHelper::generateZPL($sucursal, [
                'pedidos' => $ordenes->count(),
                'lineas'  => $totalLineas
            ]);
            $results['label'] = \App\Helpers\PrintHelper::sendToPrinter($pRotulos->ip, $pRotulos->puerto, $zpl);
        } else {
            $results['label'] = ['error' => true, 'message' => 'No hay impresora de rótulos configurada'];
        }

        // 4. Print Document (Mockup or ESC/POS if applicable)
        if ($pDespacho) {
            $text = "--- DOCUMENTO DE DESPACHO ---\n";
            $text .= "Sucursal: $sucursal\n";
            $text .= "Fecha: " . date('Y-m-d H:i') . "\n";
            $text .= "-----------------------------\n";
            foreach($ordenes as $o) {
                $pedText = $o->numero_factura ? " (Ped: {$o->numero_factura})" : "";
                $text .= "Planilla: {$o->planilla_numero}{$pedText}\n";
            }
            $text .= "-----------------------------\n";
            $text .= "Total Pedidos: " . $ordenes->count() . "\n";
            $text .= "Total Lineas: $totalLineas\n";
            $text .= "\n\n\n\n"; // Space for cutter
            
            $results['document'] = \App\Helpers\PrintHelper::sendToPrinter($pDespacho->ip, $pDespacho->puerto, $text);
        } else {
            $results['document'] = ['error' => true, 'message' => 'No hay impresora de despacho configurada'];
        }

        return $this->ok($res, $results, 'Proceso de impresión completado');
    }

    // ── PRIVATE HELPERS ──────────────────────────────────────────────────────

    /**
     * Release cantidad_reservada rows inside an open transaction.
     * Releases up to $cantidad units from matching inventario rows (lockForUpdate).
     */
    private function _releaseReserva(
        int $empresaId, int $sucursalId, int $productoId,
        ?int $ubicacionId, ?string $lote, float $cantidad
    ): void {
        if ($cantidad <= 0) return;

        $rows = Inventario::where('empresa_id',        $empresaId)
            ->where('sucursal_id',        $sucursalId)
            ->where('producto_id',        $productoId)
            ->where('cantidad_reservada', '>', 0)
            ->when($ubicacionId, fn($q) => $q->where('ubicacion_id', $ubicacionId))
            ->when($lote,        fn($q) => $q->where('lote',         $lote))
            ->lockForUpdate()
            ->get();

        $porLiberar = $cantidad;
        foreach ($rows as $row) {
            if ($porLiberar <= 0) break;
            $aLiberar = min((float)$row->cantidad_reservada, $porLiberar);
            $row->cantidad_reservada -= $aLiberar;
            $row->save();
            $porLiberar -= $aLiberar;
        }
    }

    // ── POST /api/picking/planilla/{numero}/completar ─────────────────────────
    public function completarPlanilla(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $numero    = $a['numero'];
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        // Fetch open orders AND already-Completada orders that may have orphaned open lines
        $ordenes = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->where(fn($q) => $q->where('planilla_numero', $numero)->orWhere('numero_orden', $numero))
            ->whereNotIn('estado', ['Anulado'])
            ->get();

        if ($ordenes->isEmpty()) {
            return $this->ok($res, [], "Planilla {$numero} no encontrada o anulada");
        }

        $ordenIds = $ordenes->pluck('id');

        // ── Determinar scope del usuario ─────────────────────────────────────────
        // Si el usuario tiene líneas asignadas en esta planilla → valida solo las suyas.
        // Si no tiene líneas asignadas (admin sin asignación directa) → valida todas.
        $tieneLineasPropias = Capsule::table('picking_detalles')
            ->whereIn('orden_picking_id', $ordenIds)
            ->where('auxiliar_id', $user->id)
            ->exists();

        // ── Validar líneas dentro del scope ─────────────────────────────────────
        // Líneas BLOQUEANTES = no fueron procesadas correctamente:
        //   1. estado IN ('Pendiente','EnProceso') → no confirmadas por el auxiliar
        //   2. estado = 'Completado' AND cantidad_pickeada <= 0 → huérfanas
        // Líneas VÁLIDAS (no bloquean):
        //   - estado = 'Faltante' → sin stock, forzado o capturado como faltante
        //   - estado = 'Completado' AND cantidad_pickeada > 0 → realmente separadas
        $params = $r->getQueryParams();
        $forzar = !empty($params['forzar']) && in_array($user->rol, ['Admin', 'Supervisor']);

        // Solo revisar órdenes activas (NO Completada) para evitar que cierres
        // anteriores parciales bloqueen el proceso con líneas de otra sesión.
        $ordenesActivasIds = $ordenes->filter(fn($o) => $o->estado !== 'Completada')->pluck('id');
        $checkIds = $ordenesActivasIds->isNotEmpty() ? $ordenesActivasIds : $ordenIds;

        $qBlocking = Capsule::table('picking_detalles as pd')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->whereIn('pd.orden_picking_id', $checkIds)
            ->where(fn($q) => $q
                ->whereIn('pd.estado', ['Pendiente', 'EnProceso'])
                ->orWhere(fn($q2) => $q2
                    ->whereIn('pd.estado', ['Completada', 'Completado'])
                    ->where('pd.cantidad_pickeada', '<=', 0)
                )
            );

        if ($tieneLineasPropias) {
            $qBlocking->where('pd.auxiliar_id', $user->id);
        }

        $lineasBlock = $qBlocking->select([
            'pd.id',
            'pd.orden_picking_id',
            'pd.producto_id',
            'pd.estado',
            'pd.cantidad_solicitada',
            'pd.cantidad_pickeada',
            Capsule::raw("COALESCE(p.codigo_interno, CAST(p.id AS VARCHAR)) as codigo"),
            Capsule::raw("COALESCE(p.nombre, '') as nombre_producto"),
            'op.numero_orden',
        ])->get();

        if ($lineasBlock->isNotEmpty() && !$forzar) {
            $scope      = $tieneLineasPropias ? ' (tu ambiente)' : '';
            $puedeForz  = in_array($user->rol, ['Admin', 'Supervisor']);
            $resumen    = $lineasBlock->groupBy('numero_orden')->map(fn($ls, $nro) =>
                "{$nro} (" . $ls->count() . " línea(s))"
            )->values()->all();

            $detalleLineas = $lineasBlock->map(fn($l) => [
                'id'                 => $l->id,
                'orden_picking_id'   => $l->orden_picking_id,
                'numero_orden'       => $l->numero_orden,
                'codigo'             => $l->codigo,
                'nombre_producto'    => $l->nombre_producto,
                'estado'             => $l->estado,
                'cantidad_solicitada'=> (float)$l->cantidad_solicitada,
                'cantidad_pickeada'  => (float)$l->cantidad_pickeada,
            ])->values()->all();

            return $this->json($res, [
                'error'   => true,
                'message' => "No se puede cerrar{$scope}: hay " . $lineasBlock->count() .
                    " línea(s) sin separar en " . implode(', ', $resumen) .
                    ". Confirma, marca como Agotado o registra el faltante antes de cerrar.",
                'data'    => [
                    'lineas_bloqueadas' => $detalleLineas,
                    'resumen'           => $resumen,
                    'puede_forzar'      => $puedeForz,
                ],
            ], 422);
        }

        // Forzar=1 (Admin/Supervisor): marcar bloqueantes como Faltante y continuar
        if ($lineasBlock->isNotEmpty() && $forzar) {
            $idsForz = $lineasBlock->pluck('id')->all();
            Capsule::table('picking_detalles')
                ->whereIn('id', $idsForz)
                ->update(['estado' => 'Faltante', 'updated_at' => date('Y-m-d H:i:s')]);

            // Liberar cantidad_reservada de las líneas forzadas
            $lineasBlock->each(function ($l) use ($empresaId, $user) {
                if ((float)$l->cantidad_solicitada <= 0) return;
                Inventario::where('empresa_id',       $empresaId)
                    ->where('sucursal_id',             $user->sucursal_id)
                    ->where('producto_id',             $l->producto_id)
                    ->where('cantidad_reservada', '>', 0)
                    ->get()
                    ->each(function ($inv) use ($l) {
                        $liberar = min((float)$inv->cantidad_reservada, (float)$l->cantidad_solicitada);
                        if ($liberar > 0) {
                            $inv->cantidad_reservada = max(0, (float)$inv->cantidad_reservada - $liberar);
                            $inv->save();
                        }
                    });
            });
        }

        // ── Si el usuario tiene scope propio, verificar si otros ambientes siguen activos ──
        if ($tieneLineasPropias) {
            $pendsOtrosAmbientes = Capsule::table('picking_detalles')
                ->whereIn('orden_picking_id', $ordenIds)
                ->where(fn($q) => $q
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->orWhere(fn($q2) => $q2->whereIn('estado', ['Completada', 'Completado'])->where('cantidad_pickeada', '<=', 0))
                )
                ->where(fn($q) => $q->whereNull('auxiliar_id')->orWhere('auxiliar_id', '!=', $user->id))
                ->count();

            if ($pendsOtrosAmbientes > 0) {
                $this->audit($user, 'picking', 'cerrar_ambiente', 'orden_pickings', null,
                    null, ['planilla_numero' => $numero, 'auxiliar_id' => $user->id],
                    "Usuario #{$user->id} ({$user->rol}) cerró su ambiente en planilla {$numero}");

                return $this->ok($res, ['parcial' => true],
                    "Tu ambiente está listo. La planilla quedará abierta hasta que los otros ambientes terminen.");
            }
        }

        try {
            Capsule::transaction(function () use ($ordenes, $empresaId) {
                $archivoIds   = [];
                $porCompletar = $ordenes->where('estado', '!=', 'Completada')->pluck('id');

                if ($porCompletar->isNotEmpty()) {
                    Capsule::table('orden_pickings')
                        ->whereIn('id', $porCompletar)
                        ->update(['estado' => 'Completada', 'hora_fin' => date('H:i:s')]);
                }

                foreach ($ordenes as $orden) {
                    if ($orden->archivo_id) $archivoIds[] = $orden->archivo_id;
                }

                foreach (array_unique($archivoIds) as $archivoId) {
                    $total     = OrdenPicking::where('archivo_id', $archivoId)->count();
                    $completas = OrdenPicking::where('archivo_id', $archivoId)->where('estado', 'Completada')->count();
                    if ($total > 0 && $completas >= $total) {
                        Capsule::table('archivos_planilla')
                            ->where('id', $archivoId)
                            ->update(['estado' => 'Separado', 'updated_at' => date('Y-m-d H:i:s')]);
                    }
                }
            });
        } catch (\Exception $e) {
            error_log('PickingController::completarPlanilla error: ' . $e->getMessage());
            return $this->error($res, 'Error al completar planilla: ' . $e->getMessage(), 500);
        }

        $this->audit($user, 'picking', 'completar_planilla', 'orden_pickings', null,
            null, ['planilla_numero' => $numero], "Planilla {$numero} completada");

        return $this->ok($res, [], "Planilla {$numero} completada — disponible para certificación");
    }

    // ── POST /api/picking/planilla/{numero}/liberar-vacias ───────────────────
    // Admin/Supervisor: libera líneas con estado='Completada' pero cantidad_pickeada=0
    // (huérfanas) para que puedan ser separadas de nuevo.
    public function liberarLineasVacias(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $numero    = $a['numero'];
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $ordenes = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->where(fn($q) => $q->where('planilla_numero', $numero)->orWhere('numero_orden', $numero))
            ->whereNotIn('estado', ['Anulado'])
            ->get();

        if ($ordenes->isEmpty()) {
            return $this->error($res, "Planilla {$numero} no encontrada", 404);
        }

        $ordenIds = $ordenes->pluck('id');

        return Capsule::transaction(function () use ($ordenIds, $ordenes, $numero, $empresaId, $user, $res) {
            // 1. Identificar líneas huérfanas: Completada con 0 pickeado (no Agotado/Faltante)
            $lineasHuerfanas = Capsule::table('picking_detalles as pd')
                ->join('productos as p', 'p.id', '=', 'pd.producto_id')
                ->whereIn('pd.orden_picking_id', $ordenIds)
                ->whereIn('pd.estado', ['Completada', 'Completado'])
                ->where('pd.cantidad_pickeada', '<=', 0)
                ->select([
                    'pd.id',
                    'pd.orden_picking_id',
                    'pd.producto_id',
                    'pd.cantidad_solicitada',
                    Capsule::raw("COALESCE(p.codigo_interno, CAST(p.id AS VARCHAR)) as codigo"),
                    Capsule::raw("COALESCE(p.nombre, '') as nombre"),
                    Capsule::raw("COALESCE(p.unidades_caja, 1) as unidades_caja"),
                ])
                ->get();

            if ($lineasHuerfanas->isEmpty()) {
                return $this->ok($res, ['liberadas' => 0],
                    "No hay líneas huérfanas en planilla {$numero} — todo está en orden.");
            }

            $idsHuerfanas = $lineasHuerfanas->pluck('id')->all();

            // 2. Cambiar líneas huérfanas de Completada → Pendiente
            Capsule::table('picking_detalles')
                ->whereIn('id', $idsHuerfanas)
                ->update([
                    'estado'            => 'Pendiente',
                    'cantidad_pickeada' => 0,
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);

            // 2b. Re-reservar inventario FEFO para las líneas liberadas
            // También actualiza ubicacion_id/lote/fecha_vencimiento en la línea
            // para que el auxiliar vea la ubicación correcta según el stock actual.
            foreach ($lineasHuerfanas as $linea) {
                $upc          = max(1, (int)($linea->unidades_caja ?? 1));
                $cantUnidades = (float)$linea->cantidad_solicitada * $upc;
                if ($cantUnidades <= 0) continue;

                $stock = Inventario::where('empresa_id',   $empresaId)
                    ->where('sucursal_id',                  $user->sucursal_id)
                    ->where('producto_id',                  $linea->producto_id)
                    ->where('estado',                       'Disponible')
                    ->whereRaw('(cantidad - cantidad_reservada) > 0')
                    ->orderByRaw('fecha_vencimiento IS NULL ASC')
                    ->orderBy('fecha_vencimiento', 'ASC')
                    ->lockForUpdate()
                    ->get();

                $porReservar   = $cantUnidades;
                $primeraUbicId = null;
                $primerLote    = null;
                $primeraFV     = null;

                foreach ($stock as $inv) {
                    if ($porReservar <= 0) break;
                    $disponible = max(0, (float)$inv->cantidad - (float)$inv->cantidad_reservada);
                    $aReservar  = min($disponible, $porReservar);
                    if ($aReservar > 0) {
                        $inv->cantidad_reservada += $aReservar;
                        $inv->save();
                        $porReservar -= $aReservar;
                        if ($primeraUbicId === null) {
                            $primeraUbicId = $inv->ubicacion_id;
                            $primerLote    = $inv->lote;
                            $primeraFV     = $inv->fecha_vencimiento;
                        }
                    }
                }

                // Actualizar la línea con la nueva ubicación FEFO asignada
                if ($primeraUbicId !== null) {
                    PickingDetalle::where('id', $linea->id)->update([
                        'ubicacion_id'      => $primeraUbicId,
                        'lote'              => $primerLote,
                        'fecha_vencimiento' => $primeraFV,
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // 3. Reabrir órdenes afectadas que estaban 'Completada'
            $ordenesAfectadas = $lineasHuerfanas->pluck('orden_picking_id')->unique()->all();
            Capsule::table('orden_pickings')
                ->whereIn('id', $ordenesAfectadas)
                ->where('estado', 'Completada')
                ->whereIn('estado_certificacion', ['Pendiente'])
                ->update([
                    'estado'     => 'EnProceso',
                    'hora_fin'   => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $this->audit($user, 'picking', 'liberar_lineas_vacias', 'picking_detalles', null,
                null, ['planilla' => $numero, 'ids' => $idsHuerfanas],
                "Liberadas " . count($idsHuerfanas) . " líneas huérfanas en planilla {$numero}");

            return $this->ok($res, [
                'liberadas' => count($idsHuerfanas),
                'ordenes_reabiertas' => count($ordenesAfectadas),
                'lineas' => $lineasHuerfanas->map(fn($l) => [
                    'codigo'             => $l->codigo,
                    'nombre'             => $l->nombre,
                    'cantidad_solicitada'=> (float)$l->cantidad_solicitada,
                ])->values()->all(),
            ], "Se liberaron " . count($idsHuerfanas) . " línea(s) — ya pueden ser separadas.");
        });
    }

    // ── POST /api/picking/planilla/{numero}/liberar-linea ───────────────────
    public function liberarLineaSeparada(Request $req, Response $res, $args) {
        $user = $req->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $req);
        $numero = trim($args['numero'] ?? '');
        $body = $req->getParsedBody() ?? [];
        $productoId = $body['producto_id'] ?? null;

        if (!$numero || !$productoId) return $this->error($res, "Faltan datos requeridos.", 400);

        $ordenes = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
            ->where('planilla_numero', $numero)
            ->where('empresa_id', $empresaId)
            ->pluck('id')->all();

        if (empty($ordenes)) return $this->error($res, "Planilla no encontrada.", 404);

        $lineas = PickingDetalle::whereIn('orden_picking_id', $ordenes)
            ->where('producto_id', $productoId)
            ->whereIn('estado', ['Completado', 'Faltante'])
            ->get();

        if ($lineas->isEmpty()) {
            return $this->error($res, "No se encontraron líneas de este producto en estado Completado o Faltante.", 404);
        }

        $cantidadDevuelta = 0;
        $lineasAfectadas = 0;

        try {
            \Illuminate\Database\Capsule\Manager::transaction(function() use ($numero, $productoId, $empresaId, $user, $lineas, &$cantidadDevuelta, &$lineasAfectadas) {

            foreach ($lineas as $l) {
                $cantidadPickeada = (float)$l->cantidad_pickeada;
                if ($cantidadPickeada > 0) {
                    $cantidadDevuelta += $cantidadPickeada;
                    
                    $ubicacionId = $l->ubicacion_id;
                    if (!$ubicacionId) {
                        $existingInv = Inventario::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $user->sucursal_id)
                            ->where('producto_id', $l->producto_id)
                            ->whereNotNull('ubicacion_id')
                            ->orderBy('cantidad', 'desc')
                            ->first();
                        
                        if ($existingInv) {
                            $ubicacionId = $existingInv->ubicacion_id;
                        } else {
                            $anyUbicacion = \Illuminate\Database\Capsule\Manager::table('ubicaciones')
                                ->where('empresa_id', $empresaId)
                                ->where('sucursal_id', $user->sucursal_id)
                                ->where('estado', 'Activa')
                                ->first();
                            $ubicacionId = $anyUbicacion ? $anyUbicacion->id : null;
                        }
                    }

                    if (!$ubicacionId) {
                        throw new \Exception("No se encontró una ubicación en la sucursal para devolver el producto.");
                    }

                    $inv = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $l->producto_id)
                        ->where('ubicacion_id', $ubicacionId)
                        ->where('lote', $l->lote)
                        ->first();

                    if (!$inv) {
                        $inv = new Inventario([
                            'empresa_id' => $empresaId,
                            'sucursal_id' => $user->sucursal_id,
                            'producto_id' => $l->producto_id,
                            'ubicacion_id' => $ubicacionId,
                            'lote' => $l->lote,
                            'fecha_vencimiento' => $l->fecha_vencimiento,
                            'estado' => 'Disponible',
                            'cantidad' => 0,
                            'cantidad_reservada' => 0
                        ]);
                    }
                    
                    $inv->cantidad += $cantidadPickeada;
                    $inv->cantidad_reservada += $cantidadPickeada;
                    $upcProd = \App\Models\Producto::where('id', $l->producto_id)->value('unidades_caja') ?: 1;
                    $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $upcProd);
                    $inv->saldos = round(fmod((float)$inv->cantidad, $upcProd), 2);
                    $inv->save();

                    \App\Models\MovimientoInventario::create([
                        'empresa_id'           => $empresaId,
                        'sucursal_id'          => $user->sucursal_id,
                        'producto_id'          => $l->producto_id,
                        'tipo_movimiento'      => 'CorreccionAdmin',
                        'cantidad'             => $cantidadPickeada,
                        'cantidad_cajas'       => (int)floor($cantidadPickeada / $upcProd),
                        'saldos'               => round(fmod($cantidadPickeada, $upcProd), 2),
                        'ubicacion_origen_id'  => $ubicacionId,
                        'ubicacion_destino_id' => $ubicacionId,
                        'lote'                 => $l->lote,
                        'fecha_vencimiento'    => $l->fecha_vencimiento,
                        'auxiliar_id'          => $user->id,
                        'referencia_tipo'      => 'PlanillaPicking',
                        'referencia_id'        => 0, // Using 0 because $numero is string
                        'observaciones'        => 'Reverso línea planilla ' . $numero,
                        'fecha_movimiento'     => date('Y-m-d'),
                        'hora_inicio'          => date('H:i:s'),
                    ]);
                }

                $l->estado = 'EnProceso'; 
                $l->cantidad_pickeada = 0;
                $l->updated_at = date('Y-m-d H:i:s');
                $l->save();
                $lineasAfectadas++;
            }

            $ordenesAfectadasIds = $lineas->pluck('orden_picking_id')->unique()->all();
            \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->whereIn('id', $ordenesAfectadasIds)
                ->where('estado', 'Completada')
                ->update([
                    'estado' => 'EnProceso',
                    'hora_fin' => null,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            $this->audit($user, 'picking', 'liberar_linea', 'picking_detalles', null, null, [
                'planilla' => $numero, 'producto_id' => $productoId, 'lineas' => $lineasAfectadas, 'cantidad' => $cantidadDevuelta
            ], "Línea de producto {$productoId} reversada en la planilla {$numero}");

        });
        } catch (\Exception $e) {
            return $this->error($res, 'Error interno al reversar línea: ' . $e->getMessage(), 500);
        }

        return $this->ok($res, [
            'lineas' => $lineasAfectadas,
            'cantidad_reversada' => $cantidadDevuelta
        ], "Línea liberada correctamente. Puede ser separada nuevamente.");
    }

    // ── POST /api/picking/planilla/{numero}/agregar-linea ─────────────────────
    public function agregarLineaPlanilla(Request $req, Response $res, array $a): Response
    {
        $user      = $req->getAttribute('user');
        $numero    = $a['numero'];
        $body      = $req->getParsedBody() ?? [];
        $empresaId = $this->getEffectiveEmpresaId($user, $req);

        $productoId  = $body['producto_id']   ?? null;
        $cantCajas   = (int)($body['cantidad_cajas'] ?? 0);
        $saldos      = (int)($body['saldos']         ?? 0);
        $lote        = $body['lote']                 ?? null;
        $fechaVenc   = $body['fecha_vencimiento']    ?? null;

        if (!$productoId)                return $this->error($res, 'producto_id requerido', 422);
        if ($cantCajas <= 0 && $saldos <= 0) return $this->error($res, 'Ingrese al menos una caja o saldo', 422);

        $orden = OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->where(fn($q) => $q->where('planilla_numero', $numero)->orWhere('numero_orden', $numero))
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->first();

        if (!$orden) return $this->error($res, 'Planilla no encontrada o ya completada', 404);
        if (!empty($orden->estado_despacho)) {
            return $this->error($res, "No se pueden agregar líneas a una orden ya {$orden->estado_despacho}");
        }

        $producto = \App\Models\Producto::find($productoId);
        if (!$producto) return $this->error($res, 'Producto no encontrado', 404);

        $ambienteCodigo = $this->_clasificarAmbiente($producto);

        try {
            $detalle = Capsule::transaction(function () use (
                $orden, $productoId, $cantCajas, $lote, $fechaVenc, $ambienteCodigo, $producto, $user, $req
            ) {
                $nl = new PickingDetalle([
                    'orden_picking_id'     => $orden->id,
                    'producto_id'          => $productoId,
                    'cantidad_solicitada'  => $cantCajas,      // unidad de pedido = cajas
                    'cantidad_pickeada'    => 0,
                    'cantidad_certificada' => 0,
                    'estado'               => 'Pendiente',
                    'auxiliar_id'          => $orden->auxiliar_id ?? $user->id,
                    'lote'                 => $lote ?: null,
                    'fecha_vencimiento'    => $fechaVenc ?: null,
                    'ambiente'             => $ambienteCodigo,
                ]);
                $nl->save();

                if ($orden->estado === 'EnProceso') {
                    $this->_reservarStockLineaNueva($nl, $producto, (float)$cantCajas, $orden, $user, $req, $lote ?: null);
                }

                return $nl;
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al agregar referencia: ' . $e->getMessage());
        }

        $this->audit($user, 'picking', 'agregar_linea', 'picking_detalles', $detalle->id, null, [
            'planilla'    => $numero,
            'producto_id' => $productoId,
            'cajas'       => $cantCajas,
            'saldos'      => $saldos,
            'ubicacion_id'=> $detalle->ubicacion_id,
            'estado'      => $detalle->estado,
        ]);

        $mensaje = $detalle->estado === 'Faltante'
            ? 'Producto agregado a la planilla, pero sin stock suficiente — quedó registrado como faltante.'
            : 'Producto agregado a la planilla' . ($detalle->ubicacion_id ? ' y reservado en ubicación.' : '.');

        return $this->ok($res, [
            'detalle_id'   => $detalle->id,
            'estado'       => $detalle->estado,
            'ubicacion_id' => $detalle->ubicacion_id,
        ], $mensaje);
    }

    // ── POST /api/picking/planilla/{numero}/reemplazar-linea ──────────────────
    public function reemplazarLineaPlanilla(Request $req, Response $res, array $a): Response
    {
        $user      = $req->getAttribute('user');
        $numero    = $a['numero'];
        $body      = $req->getParsedBody() ?? [];
        $empresaId = $this->getEffectiveEmpresaId($user, $req);

        $idsRaw         = $body['ids']              ?? '';
        $nuevoProdId    = $body['nuevo_producto_id'] ?? null;
        $cantCajas      = (int)($body['cantidad_cajas'] ?? 0);
        $saldos         = (int)($body['saldos']         ?? 0);

        if (!$nuevoProdId)               return $this->error($res, 'nuevo_producto_id requerido', 422);
        if ($cantCajas <= 0 && $saldos <= 0) return $this->error($res, 'Ingrese al menos una caja o saldo', 422);

        $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) return $this->error($res, 'IDs de línea requeridos', 422);

        $nuevoProd = \App\Models\Producto::find($nuevoProdId);
        if (!$nuevoProd) return $this->error($res, 'Nuevo producto no encontrado', 404);

        return Capsule::transaction(function() use ($ids, $nuevoProd, $cantCajas, $saldos, $user, $numero, $empresaId, $req, $res) {
            $detalles = PickingDetalle::whereIn('id', $ids)
                ->whereHas('ordenPicking', fn($q) => $q->where('empresa_id', $empresaId)
                    ->where('sucursal_id', $user->sucursal_id))
                ->get();

            if ($detalles->isEmpty()) return $this->error($res, 'Líneas no encontradas o sin acceso', 404);

            $ordenId    = $detalles->first()->orden_picking_id;
            $auxiliarId = $detalles->first()->auxiliar_id ?? $user->id;

            foreach ($detalles as $d) {
                if ($d->producto_id && $d->cantidad_solicitada > 0) {
                    $upcRemp = max(1, (int)(Capsule::table('productos')->where('id', $d->producto_id)->value('unidades_caja') ?? 1));
                    $this->_releaseReserva(
                        $empresaId, $user->sucursal_id,
                        $d->producto_id, $d->ubicacion_id, $d->lote,
                        (float)$d->cantidad_solicitada * $upcRemp
                    );
                }
                $d->estado  = 'Faltante';
                $d->novedad = 'Reemplazado por administrador';
                $d->save();
            }

            $nuevo = new PickingDetalle([
                'orden_picking_id'     => $ordenId,
                'producto_id'          => $nuevoProd->id,
                'cantidad_solicitada'  => $cantCajas,
                'cantidad_pickeada'    => 0,
                'cantidad_certificada' => 0,
                'estado'               => 'Pendiente',
                'auxiliar_id'          => $auxiliarId,
            ]);
            $nuevo->save();

            $this->audit($user, 'picking', 'reemplazar_linea', 'picking_detalles', $nuevo->id, null, [
                'planilla'         => $numero,
                'ids_reemplazados' => implode(',', $ids),
                'nuevo_producto'   => $nuevoProd->id,
                'cajas'            => $cantCajas,
                'saldos'           => $saldos,
            ]);

            return $this->ok($res, ['nuevo_detalle_id' => $nuevo->id], "Producto reemplazado en planilla {$numero}");
        });
    }

    // ── GET  /api/picking/parametros ─────────────────────────────────────────
    public function getParametrosPicking(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $sucursalId = $user->sucursal_id;

        $rows = \App\Models\Parametro::where('sucursal_id', $sucursalId)
            ->whereIn('clave', ['tolerancia_bajo_picking'])
            ->get(['clave', 'valor'])
            ->keyBy('clave');

        return $this->ok($res, [
            'tolerancia_bajo_picking' => (float)($rows->get('tolerancia_bajo_picking')?->valor ?? 0),
        ]);
    }

    // ── PUT  /api/picking/parametros ─────────────────────────────────────────
    public function setParametrosPicking(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $sucursalId = $user->sucursal_id;
        $body       = $r->getParsedBody() ?? [];

        if (array_key_exists('tolerancia_bajo_picking', $body)) {
            $valor = max(0, min(100, (float)$body['tolerancia_bajo_picking']));
            \App\Models\Parametro::updateOrCreate(
                ['sucursal_id' => $sucursalId, 'clave' => 'tolerancia_bajo_picking'],
                [
                    'valor'       => (string)$valor,
                    'descripcion' => '% máximo permitido de bajo picking para cerrar la línea con faltante',
                ]
            );
        }

        return $this->ok($res, [], 'Parámetros de picking actualizados');
    }

    /**
     * Check whether all lines of an order are resolved and close it if so.
     * Single-query check (COUNT + conditional SUM) instead of two separate COUNTs.
     * Saves the order. Must be called inside an open transaction with lockForUpdate on $orden.
     */
    private function _closeOrdenIfDone(OrdenPicking $orden, bool $setHoraInicio = false): void
    {
        if ($setHoraInicio && (empty($orden->hora_inicio) || $orden->hora_inicio === '00:00:00')) {
            $orden->hora_inicio = date('H:i:s');
        }

        // Solo marcar como EnProceso (iniciada). La orden NUNCA se auto-cierra aquí:
        // únicamente completarPlanilla() puede poner estado='Completada', cuando el
        // auxiliar pulsa "Finalizar Picking" de forma explícita.
        if (in_array($orden->estado, ['Pendiente', 'EnProceso'])) {
            $orden->estado = 'EnProceso';
        }
        $orden->save();
    }

    /**
     * GET /api/picking/agotados
     * Módulo de agotados: lista picking_faltantes con filtros por fecha, sucursal_entrega
     * y referencia/producto. Soporta exportación CSV con ?export=csv.
     */
    public function getAgotados(Request $r, Response $res): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $params    = $r->getQueryParams();

        $fIni      = $params['fecha_inicio']    ?? date('Y-m-d', strtotime('-30 days'));
        $fFin      = $params['fecha_fin']       ?? date('Y-m-d');
        $sucFiltro = trim($params['sucursal_entrega'] ?? '');
        $refQ      = trim($params['referencia']       ?? '');
        $export    = ($params['export'] ?? '') === 'csv';

        $like = $this->isPg() ? 'ILIKE' : 'LIKE';

        $query = Capsule::table('picking_faltantes as pf')
            ->join('productos as pr',     'pr.id',  '=', 'pf.producto_id')
            ->join('orden_pickings as op', 'op.id',  '=', 'pf.orden_picking_id')
            ->where('pf.empresa_id', $empresaId)
            ->where(Capsule::raw("DATE(pf.created_at)"), '>=', $fIni)
            ->where(Capsule::raw("DATE(pf.created_at)"), '<=', $fFin)
            ->select(
                'pf.id',
                'pf.created_at             as fecha',
                'op.sucursal_entrega',
                'op.numero_orden',
                'op.planilla_numero',
                'op.planilla_lote',
                'op.cliente',
                'pr.codigo_interno         as producto_codigo',
                'pr.nombre                 as producto_nombre',
                'pr.unidades_caja',
                'pf.cantidad_solicitada',
                'pf.cantidad_faltante',
                'pf.causa'
            )
            ->orderBy('pf.created_at', 'desc');

        if ($sucFiltro !== '') {
            $query->where('op.sucursal_entrega', $sucFiltro);
        }

        if (strlen($refQ) >= 2) {
            $term = '%' . $refQ . '%';
            $query->where(function ($q) use ($like, $term) {
                $q->where('pr.nombre',           $like, $term)
                  ->orWhere('pr.codigo_interno',  $like, $term);
            });
        }

        if ($export) {
            $rows = $query->get();
            $bom  = "\xEF\xBB\xBF";
            $csv  = $bom . "Fecha,Sucursal Entrega,Pedido,Planilla,Cliente,Código,Producto,Solicitado (cj),Faltante (cj),Causa\n";
            $esc  = fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';
            foreach ($rows as $row) {
                $csv .= implode(',', [
                    $esc(substr($row->fecha, 0, 10)),
                    $esc($row->sucursal_entrega),
                    $esc($row->numero_orden),
                    $esc($row->planilla_numero ?? $row->planilla_lote),
                    $esc($row->cliente),
                    $esc($row->producto_codigo),
                    $esc($row->producto_nombre),
                    (float)$row->cantidad_solicitada,
                    (float)$row->cantidad_faltante,
                    $esc($row->causa),
                ]) . "\n";
            }

            $body = $res->getBody();
            $body->write($csv);
            return $res
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', "attachment; filename=\"agotados_{$fIni}_{$fFin}.csv\"")
                ->withBody($body);
        }

        $rows = $query->limit(1000)->get();

        $sucursales = Capsule::table('picking_faltantes as pf')
            ->join('orden_pickings as op', 'op.id', '=', 'pf.orden_picking_id')
            ->where('pf.empresa_id', $empresaId)
            ->where(Capsule::raw("DATE(pf.created_at)"), '>=', $fIni)
            ->where(Capsule::raw("DATE(pf.created_at)"), '<=', $fFin)
            ->whereNotNull('op.sucursal_entrega')
            ->distinct()
            ->orderBy('op.sucursal_entrega')
            ->pluck('op.sucursal_entrega');

        return $this->ok($res, [
            'rows'       => $rows,
            'total'      => $rows->count(),
            'sucursales' => $sucursales,
        ]);
    }

    /**
     * GET /api/picking/stock-alternativo?producto_id=X[&excluir_ubicacion_id=Y]
     * Devuelve ubicaciones con stock disponible ordenadas FEFO estricto.
     * Usado en mobile picking cuando la ubicación asignada no tiene inventario.
     * Incluye inventario_id para reasignación exacta sin ambigüedad de lote.
     */
    public function stockAlternativo(Request $r, Response $res): Response
    {
        $user      = $r->getAttribute('user');
        $params    = $r->getQueryParams();
        $productoId        = (int)($params['producto_id'] ?? 0);
        $excluirUbicacionId = (int)($params['excluir_ubicacion_id'] ?? 0);

        if (!$productoId) return $this->error($res, 'producto_id requerido', 400);

        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        $query = Inventario::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('producto_id', $productoId)
            ->where('estado', 'Disponible')
            ->whereRaw('(cantidad - COALESCE(cantidad_reservada, 0)) > 0')
            ->with('ubicacion:id,codigo,zona,tipo_ubicacion');

        if ($excluirUbicacionId > 0) {
            $query->where('ubicacion_id', '!=', $excluirUbicacionId);
        }

        // FEFO estricto: fechas NULL al final, más antiguas primero
        $rows = $query
            ->orderByRaw('fecha_vencimiento IS NULL ASC')
            ->orderBy('fecha_vencimiento', 'ASC')
            ->orderByRaw('(cantidad - COALESCE(cantidad_reservada, 0)) DESC')
            ->limit(15)
            ->get()
            ->map(fn($inv) => [
                'inventario_id'   => $inv->id,
                'ubicacion_id'    => $inv->ubicacion_id,
                'ubicacion_codigo'=> $inv->ubicacion->codigo ?? null,
                'ubicacion_zona'  => $inv->ubicacion->zona ?? null,
                'disponible'      => max(0, (float)$inv->cantidad - (float)($inv->cantidad_reservada ?? 0)),
                'lote'            => $inv->lote,
                'fecha_vencimiento' => $inv->fecha_vencimiento,
            ]);

        return $this->ok($res, $rows);
    }

    /**
     * POST /api/picking/reasignar-ubicacion
     * Reasigna líneas de picking a una ubicación alternativa seleccionada por el auxiliar.
     * Libera la reserva en la ubicación anterior y reserva en la nueva (FEFO).
     * Body: { linea_ids: int[], inventario_id: int, ubicacion_id: int, lote: ?string, fecha_vencimiento: ?string }
     */
    public function reasignarUbicacion(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = (array)($r->getParsedBody() ?? []);

        $lineaIds    = array_values(array_filter(array_map('intval', $data['linea_ids'] ?? []), fn($v) => $v > 0));
        $inventarioId = (int)($data['inventario_id'] ?? 0);
        $ubicacionId  = (int)($data['ubicacion_id']  ?? 0);
        $lote         = isset($data['lote']) && $data['lote'] !== '' ? $data['lote'] : null;
        $fechaVenc    = isset($data['fecha_vencimiento']) && $data['fecha_vencimiento'] !== '' ? $data['fecha_vencimiento'] : null;

        if (empty($lineaIds) || !$ubicacionId) {
            return $this->error($res, 'linea_ids y ubicacion_id son requeridos', 422);
        }

        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        try {
            $resultado = Capsule::transaction(function () use (
                $lineaIds, $inventarioId, $ubicacionId, $lote, $fechaVenc,
                $empresaId, $sucursalId, $user
            ) {
                // ── 1. Cargar líneas con lock ──────────────────────────────────
                // picking_detalles no tiene empresa_id; la validación de tenant
                // se hace a través de la orden padre.
                $lineas = PickingDetalle::whereIn('id', $lineaIds)
                    ->whereHas('ordenPicking', fn($q) => $q->where('empresa_id', $empresaId))
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->lockForUpdate()
                    ->get();

                if ($lineas->isEmpty()) {
                    throw new \Exception('No se encontraron líneas válidas para reasignar');
                }

                $productoId    = $lineas->first()->producto_id;
                $cantidadTotal = (float)$lineas->sum('cantidad_solicitada');

                // ── 2. Liberar reserva en ubicación(es) anteriores ────────────
                $ubicsAnteriores = $lineas->pluck('ubicacion_id')->filter()->unique();
                foreach ($ubicsAnteriores as $oldUbicId) {
                    if ($oldUbicId == $ubicacionId) continue; // misma ubicación: sin cambio
                    $oldInvs = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $productoId)
                        ->where('ubicacion_id', $oldUbicId)
                        ->lockForUpdate()
                        ->get();

                    $cantLiberar = $cantidadTotal;
                    foreach ($oldInvs as $oldInv) {
                        if ($cantLiberar <= 0) break;
                        $liberar = min((float)$oldInv->cantidad_reservada, $cantLiberar);
                        if ($liberar > 0) {
                            $oldInv->cantidad_reservada = max(0, (float)$oldInv->cantidad_reservada - $liberar);
                            $oldInv->save();
                            $cantLiberar -= $liberar;
                        }
                    }
                }

                // ── 3. Bloquear inventario destino (por inventario_id si viene, sino por ubicacion+lote) ──
                $newInvQuery = Inventario::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('producto_id', $productoId)
                    ->where('ubicacion_id', $ubicacionId)
                    ->where('estado', 'Disponible');

                if ($inventarioId > 0) {
                    $newInvQuery->where('id', $inventarioId);
                } else {
                    // Fallback: buscar por lote exacto o el más antiguo (FEFO)
                    if ($lote !== null) {
                        $newInvQuery->where('lote', $lote);
                    }
                    $newInvQuery->orderByRaw('fecha_vencimiento IS NULL ASC')
                                ->orderBy('fecha_vencimiento', 'ASC');
                }

                $newInv = $newInvQuery->lockForUpdate()->first();

                if (!$newInv) {
                    throw new \Exception('No se encontró inventario disponible en la ubicación seleccionada');
                }

                $disponible = (float)$newInv->cantidad - (float)($newInv->cantidad_reservada ?? 0);
                if ($disponible < $cantidadTotal) {
                    throw new \Exception(
                        "Stock insuficiente en ubicación: disponible {$disponible}, requerido {$cantidadTotal}"
                    );
                }

                // ── 4. Reservar en nuevo inventario ───────────────────────────
                $newInv->cantidad_reservada = (float)($newInv->cantidad_reservada ?? 0) + $cantidadTotal;
                $newInv->save();

                // ── 5. Actualizar todas las líneas ────────────────────────────
                foreach ($lineas as $linea) {
                    $linea->ubicacion_id      = $ubicacionId;
                    $linea->lote              = $newInv->lote;
                    $linea->fecha_vencimiento = $newInv->fecha_vencimiento;
                    $linea->save();
                }

                return [
                    'ubicacion_id'      => $ubicacionId,
                    'lote'              => $newInv->lote,
                    'fecha_vencimiento' => $newInv->fecha_vencimiento,
                    'disponible'        => $disponible - $cantidadTotal,
                ];
            });

            return $this->ok($res, $resultado, 'Ubicación reasignada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── GET /api/picking/consolidado/{id}/remision ───────────────────────────
    // Genera remisión HTML del consolidado completo del cliente.
    // Solo disponible cuando el consolidado está Completada.
    public function consolidadoRemision(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        $consol = Capsule::table('picking_consolidados')
            ->where('id', (int)$a['id'])
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if (!$consol) {
            return $this->notFound($res);
        }

        $consolIds = json_decode($consol->orden_ids ?? '[]', true) ?: [];

        // Cargar todas las órdenes del consolidado con sus líneas
        $ordenes = OrdenPicking::whereIn('id', $consolIds)
            ->where('empresa_id', $empresaId)
            ->with(['detalles.producto'])
            ->orderBy('numero_orden')
            ->get();

        // Cargar estado de ambientes
        $certAmbientes = Capsule::table('picking_cert_ambiente')
            ->whereIn('orden_picking_id', $consolIds)
            ->get()
            ->groupBy('orden_picking_id');

        // Construir HTML imprimible
        $totalLineas = 0;
        $totalUnd    = 0;
        $rowsHtml    = '';
        $lineaNum    = 0;

        foreach ($ordenes as $orden) {
            $ambEstados = $certAmbientes[$orden->id] ?? collect();
            $ambBadges  = $ambEstados->map(function($a) {
                $color = $a->estado === 'Certificada' ? '#10b981' : '#f59e0b';
                return "<span style='background:{$color};color:#fff;padding:1px 6px;border-radius:3px;font-size:10px;'>{$a->ambiente}</span>";
            })->implode(' ');

            foreach ($orden->detalles as $det) {
                $lineaNum++;
                $totalLineas++;
                $totalUnd += $det->cantidad_pickeada;
                $prod = $det->producto;
                $rowsHtml .= "
                <tr style='border-bottom:1px solid #e5e7eb;'>
                    <td style='padding:6px 8px;font-size:11px;color:#374151;'>{$lineaNum}</td>
                    <td style='padding:6px 8px;font-size:10px;color:#6b7280;'>" . htmlspecialchars($prod->codigo_interno ?? '') . "</td>
                    <td style='padding:6px 8px;font-size:11px;'>" . htmlspecialchars($prod->nombre ?? 'N/A') . "</td>
                    <td style='padding:6px 8px;font-size:11px;text-align:right;'>" . number_format($det->cantidad_solicitada, 0) . "</td>
                    <td style='padding:6px 8px;font-size:11px;text-align:right;font-weight:700;color:#059669;'>" . number_format($det->cantidad_pickeada, 0) . "</td>
                    <td style='padding:6px 8px;font-size:10px;'>" . htmlspecialchars($det->ambiente ?? '') . "</td>
                    <td style='padding:6px 8px;font-size:10px;color:#6b7280;'>" . htmlspecialchars(($orden->planilla_numero ? $orden->planilla_numero . ' / ' : '') . $orden->numero_orden) . "</td>
                    <td style='padding:6px 8px;font-size:10px;'>{$ambBadges}</td>
                </tr>";
            }
        }

        $estadoBadge = $consol->estado === 'Completada'
            ? "<span style='background:#10b981;color:#fff;padding:2px 10px;border-radius:4px;font-size:11px;'>CERTIFICADO</span>"
            : "<span style='background:#f59e0b;color:#fff;padding:2px 10px;border-radius:4px;font-size:11px;'>EN PROCESO</span>";

        $html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
        <title>Remisión Consolidada — " . htmlspecialchars($consol->cliente) . "</title>
        <style>
            body { font-family:Arial,sans-serif; margin:20px; color:#111; font-size:12px; }
            h1   { font-size:18px; margin:0 0 4px; }
            table{ width:100%; border-collapse:collapse; }
            thead th { background:#1e3a5f; color:#fff; padding:8px; font-size:11px; text-align:left; }
            @media print { .no-print { display:none; } }
        </style></head><body>
        <div style='display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:2px solid #1e3a5f;padding-bottom:12px;'>
            <div>
                <h1>REMISIÓN CONSOLIDADA</h1>
                <div style='font-size:13px;font-weight:700;margin-top:4px;'>" . htmlspecialchars($consol->cliente) . "</div>
                <div style='font-size:11px;color:#6b7280;margin-top:2px;'>Fecha operación: " . htmlspecialchars($consol->fecha_consolidacion) . " &nbsp;·&nbsp; Generado: " . date('Y-m-d H:i') . "</div>
            </div>
            <div style='text-align:right;'>
                {$estadoBadge}<br>
                <div style='font-size:11px;color:#6b7280;margin-top:6px;'>Planillas: " . count($consolIds) . " &nbsp;·&nbsp; Líneas: {$totalLineas} &nbsp;·&nbsp; Unidades: " . number_format($totalUnd, 0) . "</div>
            </div>
        </div>
        <table>
            <thead><tr>
                <th>#</th><th>Código</th><th>Producto</th>
                <th style='text-align:right;'>Sol.</th><th style='text-align:right;'>Sep.</th>
                <th>Ambiente</th><th>Planilla</th><th>Estado cert.</th>
            </tr></thead>
            <tbody>{$rowsHtml}</tbody>
            <tfoot>
                <tr style='background:#f3f4f6;font-weight:700;'>
                    <td colspan='3' style='padding:8px;'>TOTALES</td>
                    <td style='padding:8px;text-align:right;'></td>
                    <td style='padding:8px;text-align:right;'>" . number_format($totalUnd, 0) . "</td>
                    <td colspan='3'></td>
                </tr>
            </tfoot>
        </table>
        <div class='no-print' style='margin-top:20px;text-align:center;'>
            <button onclick='window.print()' style='background:#1e3a5f;color:#fff;border:none;padding:10px 30px;border-radius:5px;cursor:pointer;font-size:13px;'>Imprimir</button>
        </div>
        <script>setTimeout(()=>window.print(),600);</script>
        </body></html>";

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // =========================================================================
    // MÉTODOS NUEVOS
    // =========================================================================

    // ── GET /api/picking/consulta ─────────────────────────────────────────────
    // Búsqueda dinámica: qué sucursales pidieron qué referencias y en qué cantidad.
    // Params: q, sucursal_id, producto_id, estado, fecha_desde, fecha_hasta, page, per_page
    // Retorna agrupado por producto → sucursales
    public function consultaPicking(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $params     = $r->getQueryParams();

        $q           = trim($params['q'] ?? '');
        $filtroSuc   = isset($params['sucursal_id']) ? (int)$params['sucursal_id'] : null;
        $filtroProd  = isset($params['producto_id']) ? (int)$params['producto_id'] : null;
        $filtroEst   = $params['estado'] ?? null;
        $fechaDesde  = $params['fecha_desde'] ?? null;
        $fechaHasta  = $params['fecha_hasta'] ?? null;
        $page        = max(1, (int)($params['page'] ?? 1));
        $perPage     = min(100, max(10, (int)($params['per_page'] ?? 15)));

        // Auxiliar solo ve su propia sucursal de entrega; Admin/Supervisor ven todo
        $soloMiSucursal = !$this->isSupervisorOrAbove($user);

        $query = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p',       'p.id',  '=', 'pd.producto_id')
            ->where('op.empresa_id',  $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->whereNotIn('op.estado', ['Anulado', 'Cancelada'])
            ->select(
                'p.id as producto_id',
                'p.codigo_interno as codigo',
                'p.nombre as nombre_producto',
                'op.sucursal_entrega',
                'op.numero_orden',
                'op.estado as estado_orden',
                'pd.estado as estado_linea',
                'pd.cantidad_solicitada',
                'pd.cantidad_pickeada',
                'pd.ambiente',
                'op.fecha_movimiento'
            );

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('p.nombre', 'like', "%{$q}%")
                   ->orWhere('p.codigo_interno', 'like', "%{$q}%");
                // Buscar también por EAN si la tabla lo permite
                try {
                    $qb->orWhereExists(function ($sub) use ($q) {
                        $sub->from('producto_eans as pe')
                            ->whereColumn('pe.producto_id', 'p.id')
                            ->where('pe.codigo_ean', 'like', "%{$q}%");
                    });
                } catch (\Throwable $ignored) {}
            });
        }

        if ($filtroProd) {
            $query->where('pd.producto_id', $filtroProd);
        }
        if ($filtroEst) {
            $query->where('pd.estado', $filtroEst);
        }
        if ($fechaDesde) {
            $query->where('op.fecha_movimiento', '>=', $fechaDesde);
        }
        if ($fechaHasta) {
            $query->where('op.fecha_movimiento', '<=', $fechaHasta);
        }
        if ($filtroSuc && $this->isSupervisorOrAbove($user)) {
            $query->where('op.sucursal_entrega_id', $filtroSuc);
        }
        if ($soloMiSucursal) {
            // Auxiliar: solo sus órdenes asignadas
            $query->where('op.auxiliar_id', $user->id);
        }

        $allRows = $query->get();

        // Agrupar por producto
        $porProducto = [];
        foreach ($allRows as $row) {
            $pid = $row->producto_id;
            if (!isset($porProducto[$pid])) {
                $porProducto[$pid] = [
                    'producto_id'    => $pid,
                    'codigo'         => $row->codigo,
                    'nombre'         => $row->nombre_producto,
                    'total_sol'      => 0,
                    'total_sep'      => 0,
                    'sucursales'     => [],
                ];
            }
            $suc = $row->sucursal_entrega ?? '(Sin sucursal)';
            if (!isset($porProducto[$pid]['sucursales'][$suc])) {
                $porProducto[$pid]['sucursales'][$suc] = [
                    'nombre'              => $suc,
                    'cantidad_solicitada' => 0,
                    'cantidad_separada'   => 0,
                    'pendiente'          => 0,
                    'estado'             => $row->estado_linea,
                    'ordenes'            => [],
                ];
            }
            $porProducto[$pid]['sucursales'][$suc]['cantidad_solicitada'] += (float)$row->cantidad_solicitada;
            $porProducto[$pid]['sucursales'][$suc]['cantidad_separada']   += (float)$row->cantidad_pickeada;
            $porProducto[$pid]['sucursales'][$suc]['pendiente']           += max(0, (float)$row->cantidad_solicitada - (float)$row->cantidad_pickeada);
            $porProducto[$pid]['sucursales'][$suc]['ordenes'][]           = $row->numero_orden;
            $porProducto[$pid]['total_sol'] += (float)$row->cantidad_solicitada;
            $porProducto[$pid]['total_sep'] += (float)$row->cantidad_pickeada;
        }

        // Normalizar sucursales a array y deduplicar ordenes
        $lista = [];
        foreach ($porProducto as $item) {
            $sucArr = [];
            foreach ($item['sucursales'] as $s) {
                $s['ordenes'] = array_values(array_unique($s['ordenes']));
                $sucArr[] = $s;
            }
            $item['sucursales'] = $sucArr;
            $lista[] = $item;
        }

        // Ordenar por nombre
        usort($lista, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));

        $total   = count($lista);
        $offset  = ($page - 1) * $perPage;
        $paged   = array_slice($lista, $offset, $perPage);

        return $this->ok($res, [
            'data'       => $paged,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int)ceil($total / $perPage),
            ],
        ]);
    }

    // ── GET /api/picking/novedades ────────────────────────────────────────────
    // Lista novedades de picking registradas en novedades_picking.
    // Params: estado, fecha_desde, fecha_hasta, page, per_page
    // Solo Admin/Supervisor
    public function getNovedades(Request $r, Response $res): Response
    {
        if ($deny = $this->requireSupervisor($r->getAttribute('user'), $res)) return $deny;

        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $params    = $r->getQueryParams();

        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(200, max(10, (int)($params['per_page'] ?? 50)));

        $query = Capsule::table('novedades_picking')
            ->where('empresa_id', $empresaId)
            ->orderBy('created_at', 'desc');

        if (!empty($params['estado'])) {
            $query->where('estado', $params['estado']);
        }
        if (!empty($params['fecha_desde'])) {
            $query->whereDate('created_at', '>=', $params['fecha_desde']);
        }
        if (!empty($params['fecha_hasta'])) {
            $query->whereDate('created_at', '<=', $params['fecha_hasta']);
        }

        $total = $query->count();
        $items = $query->forPage($page, $perPage)->get();

        return $this->ok($res, [
            'data'       => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int)ceil($total / $perPage),
            ],
        ]);
    }

    // ── PUT /api/picking/novedades/{id}/resolver ──────────────────────────────
    // Body: {estado: 'Resuelta'|'Ignorada', nota_resolucion: string}
    // Solo Admin/Supervisor
    public function resolverNovedad(Request $r, Response $res, array $args): Response
    {
        if ($deny = $this->requireSupervisor($r->getAttribute('user'), $res)) return $deny;

        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $id        = (int)($args['id'] ?? 0);
        $body      = (array)($r->getParsedBody() ?? []);

        $estadoNuevo = trim($body['estado'] ?? '');
        if (!in_array($estadoNuevo, ['Resuelta', 'Ignorada'], true)) {
            return $this->error($res, "El campo 'estado' debe ser 'Resuelta' o 'Ignorada'");
        }

        $novedad = Capsule::table('novedades_picking')
            ->where('id', $id)
            ->where('empresa_id', $empresaId)
            ->first();

        if (!$novedad) {
            return $this->notFound($res, 'Novedad no encontrada');
        }

        Capsule::table('novedades_picking')
            ->where('id', $id)
            ->update([
                'estado'           => $estadoNuevo,
                'nota_resolucion'  => trim($body['nota_resolucion'] ?? ''),
                'resuelto_por'     => $user->id,
                'fecha_resolucion' => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

        return $this->ok($res, null, "Novedad marcada como {$estadoNuevo}");
    }

    // ── PUT /api/picking/detalles/{id}/cantidad ───────────────────────────────
    // Body: {cantidad_solicitada: float, motivo: string}
    // Aplica lógica de ajuste de inventario según el estado de la línea.
    // Solo Admin/Supervisor
    public function editarLineaPicking(Request $r, Response $res, array $args): Response
    {
        if ($deny = $this->requireSupervisor($r->getAttribute('user'), $res)) return $deny;

        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $id         = (int)($args['id'] ?? 0);
        $body       = (array)($r->getParsedBody() ?? []);

        $nuevaCant = isset($body['cantidad_solicitada']) ? (float)$body['cantidad_solicitada'] : null;
        $motivo    = trim($body['motivo'] ?? '');

        if ($nuevaCant === null || $nuevaCant < 0) {
            return $this->error($res, 'Se requiere cantidad_solicitada >= 0');
        }

        $detalle = PickingDetalle::where('id', $id)
            ->whereHas('ordenPicking', function ($q) use ($empresaId, $sucursalId) {
                $q->where('empresa_id',  $empresaId)
                  ->where('sucursal_id', $sucursalId);
            })
            ->first();

        if (!$detalle) {
            return $this->notFound($res, 'Línea de picking no encontrada');
        }

        if (in_array($detalle->estado, ['Completado', 'Anulado'], true)) {
            return $this->error($res, "No se puede editar una línea en estado '{$detalle->estado}'");
        }

        if (!in_array($detalle->estado, ['Pendiente', 'EnProceso', 'Faltante'], true)) {
            return $this->error($res, "Edición no soportada para líneas en estado '{$detalle->estado}'. Elimine la línea y agregue una nueva.");
        }

        $ordenDetalle = $detalle->ordenPicking;
        if ($ordenDetalle && !empty($ordenDetalle->estado_despacho)) {
            return $this->error($res, "No se puede editar una línea de una orden ya {$ordenDetalle->estado_despacho}");
        }

        $result = null;

        try {
            Capsule::transaction(function () use ($detalle, $nuevaCant, $motivo, $user, $r, &$result) {
                if ($detalle->estado === 'Pendiente') {
                    // Sin ubicación/reserva asignada todavía — solo actualiza la solicitud.
                    $detalle->cantidad_solicitada = $nuevaCant;
                    $detalle->save();
                } else {
                    // EnProceso (reserva completa) o Faltante (reserva parcial o nula)
                    $result = $this->_ajustarReservaEdicionLinea($detalle, $nuevaCant, $user, $r, $motivo);
                }
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al editar la línea: ' . $e->getMessage());
        }

        // Verificar si se detectó conflicto de stock
        if (!empty($result['conflict'])) {
            return $this->json($res, [
                'error'             => true,
                'message'           => "Stock insuficiente en ubicación {$result['ubicacion']}: disponible {$result['stock_disponible']}, requerido {$result['requerido']}",
                'stock_disponible'  => $result['stock_disponible'],
                'ubicacion_id'      => $result['ubicacion'],
            ], 409);
        }

        $detalle->refresh();
        return $this->ok($res, $detalle, 'Línea actualizada correctamente');
    }

    // ── PUT /api/picking/{id}/certificaciones/{det_id} ────────────────────────
    // Body: {cantidad_certificada: float}
    // Actualiza la cantidad certificada de una línea en una orden en proceso.
    // Solo Admin
    public function editarCertificacion(Request $r, Response $res, array $args): Response
    {
        if ($deny = $this->requireAdmin($r->getAttribute('user'), $res)) return $deny;

        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $ordenId    = (int)($args['id'] ?? 0);
        $detId      = (int)($args['det_id'] ?? 0);
        $body       = (array)($r->getParsedBody() ?? []);

        $cantCert = isset($body['cantidad_certificada']) ? (float)$body['cantidad_certificada'] : null;
        if ($cantCert === null || $cantCert < 0) {
            return $this->error($res, 'Se requiere cantidad_certificada >= 0');
        }

        $orden = OrdenPicking::where('id', $ordenId)
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if (!$orden) {
            return $this->notFound($res, 'Orden de picking no encontrada');
        }
        if (!in_array($orden->estado, ['EnProceso', 'Completada'], true)) {
            return $this->error($res, "No se puede editar certificación en estado '{$orden->estado}'");
        }

        $detalle = PickingDetalle::where('id', $detId)
            ->where('orden_picking_id', $ordenId)
            ->first();

        if (!$detalle) {
            return $this->notFound($res, 'Línea de detalle no encontrada');
        }

        $estadoCert = $cantCert > 0 ? 'Certificada' : 'Pendiente';

        $detalle->cantidad_certificada  = $cantCert;
        $detalle->estado_certificacion  = $estadoCert;
        $detalle->save();

        return $this->ok($res, $detalle, 'Certificación actualizada correctamente');
    }

    // ── POST /api/picking/{id}/recalcular-remision ────────────────────────────
    // Re-evalúa la remisión: limpia agotados de líneas que ya tienen certificación,
    // recalcula totales y retorna la orden actualizada.
    // Solo Admin
    public function recalcularRemision(Request $r, Response $res, array $args): Response
    {
        if ($deny = $this->requireAdmin($r->getAttribute('user'), $res)) return $deny;

        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $ordenId    = (int)($args['id'] ?? 0);

        $orden = OrdenPicking::where('id', $ordenId)
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with('detalles')
            ->first();

        if (!$orden) {
            return $this->notFound($res, 'Orden de picking no encontrada');
        }

        Capsule::transaction(function () use ($orden) {
            // Leer agotados actuales
            $agotadosRaw = $orden->agotados;
            $agotadosIds = [];
            if (!empty($agotadosRaw)) {
                if (is_string($agotadosRaw)) {
                    $decoded = json_decode($agotadosRaw, true);
                    $agotadosIds = is_array($decoded) ? $decoded : [];
                } elseif (is_array($agotadosRaw)) {
                    $agotadosIds = $agotadosRaw;
                }
            }

            $nuevosAgotados = $agotadosIds;
            foreach ($orden->detalles as $det) {
                $cantCert = (float)($det->cantidad_certificada ?? 0);
                if ($cantCert > 0) {
                    // Quitar este producto de la lista de agotados
                    $nuevosAgotados = array_values(array_filter(
                        $nuevosAgotados,
                        fn($pid) => (int)$pid !== (int)$det->producto_id
                    ));
                    // Si estaba marcado como Agotado en certificación, restaurar a Disponible
                    if (($det->estado_certificacion ?? '') === 'Agotado') {
                        $det->estado_certificacion = 'Disponible';
                        $det->save();
                    }
                }
            }

            // Recalcular totales de la orden
            $totalSol = $orden->detalles->sum('cantidad_solicitada');
            $totalPick= $orden->detalles->sum('cantidad_pickeada');
            $totalCert= $orden->detalles->sum('cantidad_certificada');

            $orden->agotados         = json_encode(array_values($nuevosAgotados));
            $orden->total_solicitado = $totalSol;
            $orden->total_pickeado   = $totalPick;
            $orden->total_certificado= $totalCert;
            $orden->save();
        });

        $orden->load('detalles.producto');

        return $this->ok($res, $orden, 'Remisión recalculada correctamente');
    }

    // ── PUT /api/picking/certificacion/admin-lote/{sucursal} ─────────────────
    // Guarda en lote los cambios de certificación, ajusta inventario y recalcula totales.
    // Body: {lineas: [{det_id: int, cantidad_certificada: float}]}
    // Solo Admin
    public function certAdminLote(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $sucursal   = urldecode($a['sucursal']);
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $body       = (array)($r->getParsedBody() ?? []);
        $lineas     = $body['lineas'] ?? [];

        if (empty($lineas)) {
            return $this->error($res, 'No se enviaron líneas para actualizar');
        }

        $actualizadas = 0;
        $invAjustado  = 0;
        $errores      = [];
        $ordenIds     = [];

        try {
            Capsule::transaction(function () use (
                $lineas, $empresaId, $sucursalId, $sucursal, $user,
                &$actualizadas, &$invAjustado, &$errores, &$ordenIds
            ) {
                foreach ($lineas as $item) {
                    $detId     = (int)($item['det_id'] ?? 0);
                    $nuevaCant = isset($item['cantidad_certificada']) ? (float)$item['cantidad_certificada'] : null;
                    if (!$detId || $nuevaCant === null || $nuevaCant < 0) continue;

                    $det = PickingDetalle::where('id', $detId)
                        ->whereHas('orden', fn($q) => $q
                            ->where('empresa_id',       $empresaId)
                            ->where('sucursal_id',      $sucursalId)
                            ->where('sucursal_entrega', $sucursal)
                        )
                        ->first();

                    if (!$det) { $errores[] = "Det #{$detId} no encontrado"; continue; }

                    $viejaCant = (float)($det->cantidad_certificada ?? 0);
                    $diff      = $nuevaCant - $viejaCant;

                    // Ajuste de inventario cuando la cantidad cambia
                    if (abs($diff) > 0.0001 && $det->ubicacion_id) {
                        $inv = Inventario::where('empresa_id',  $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('producto_id', $det->producto_id)
                            ->where('ubicacion_id', $det->ubicacion_id)
                            ->where('estado', 'Disponible')
                            ->first();

                        if ($diff < 0) {
                            // Certificó menos → devolver diferencia al inventario
                            $devolver = abs($diff);
                            if ($inv) {
                                $inv->cantidad += $devolver;
                                $inv->save();
                            } else {
                                Inventario::create([
                                    'empresa_id'   => $empresaId,
                                    'sucursal_id'  => $sucursalId,
                                    'producto_id'  => $det->producto_id,
                                    'ubicacion_id' => $det->ubicacion_id,
                                    'cantidad'     => $devolver,
                                    'estado'       => 'Disponible',
                                ]);
                            }
                            MovimientoInventario::create([
                                'empresa_id'      => $empresaId,
                                'sucursal_id'     => $sucursalId,
                                'producto_id'     => $det->producto_id,
                                'ubicacion_id'    => $det->ubicacion_id,
                                'cantidad'        => $devolver,
                                'tipo_movimiento' => 'DevolucionCertificacion',
                                'referencia_id'   => $det->id,
                                'referencia_tipo' => 'picking_detalle',
                                'usuario_id'      => $user->id,
                                'observaciones'   => "Ajuste certificación: {$viejaCant} → {$nuevaCant}",
                                'fecha_movimiento'=> date('Y-m-d'),
                                'hora_inicio'     => date('H:i:s'),
                            ]);
                            $invAjustado++;
                        } elseif ($diff > 0 && $inv) {
                            // Certificó más → descontar de inventario si hay stock
                            $stockDisp = max(0, (float)$inv->cantidad - (float)($inv->cantidad_reservada ?? 0));
                            if ($stockDisp >= $diff) {
                                $inv->cantidad -= $diff;
                                $inv->save();
                                MovimientoInventario::create([
                                    'empresa_id'      => $empresaId,
                                    'sucursal_id'     => $sucursalId,
                                    'producto_id'     => $det->producto_id,
                                    'ubicacion_id'    => $det->ubicacion_id,
                                    'cantidad'        => -$diff,
                                    'tipo_movimiento' => 'DescuentoCertificacion',
                                    'referencia_id'   => $det->id,
                                    'referencia_tipo' => 'picking_detalle',
                                    'usuario_id'      => $user->id,
                                    'observaciones'   => "Ajuste certificación: {$viejaCant} → {$nuevaCant}",
                                    'fecha_movimiento'=> date('Y-m-d'),
                                    'hora_inicio'     => date('H:i:s'),
                                ]);
                                $invAjustado++;
                            }
                        }
                    }

                    $det->cantidad_certificada = $nuevaCant;
                    $det->estado_certificacion = $nuevaCant > 0 ? 'Certificada' : 'Pendiente';
                    $det->save();

                    $ordenIds[] = $det->orden_picking_id;
                    $actualizadas++;
                }

                // Recalcular totales de todas las órdenes afectadas
                foreach (array_unique($ordenIds) as $oid) {
                    $orden = OrdenPicking::with('detalles')->find($oid);
                    if (!$orden) continue;
                    $orden->total_certificado = $orden->detalles->sum('cantidad_certificada');
                    $orden->total_pickeado    = $orden->detalles->sum('cantidad_pickeada');
                    $orden->save();
                }
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al guardar lote: ' . $e->getMessage());
        }

        return $this->ok($res, [
            'actualizadas'       => $actualizadas,
            'inventario_ajustado'=> $invAjustado,
            'ordenes_afectadas'  => count(array_unique($ordenIds)),
            'errores'            => $errores,
        ], "Se actualizaron {$actualizadas} línea(s) y se ajustaron {$invAjustado} registro(s) de inventario.");
    }

    // ── HELPER PRIVADO ────────────────────────────────────────────────────────
    // Inserta una novedad en novedades_picking.
    // Usar desde importarPedidos() u otros flujos cuando se detecten anomalías.
    private function _registrarNovedad(array $data, int $empresaId, ?int $sucursalId): void
    {
        try {
            Capsule::table('novedades_picking')->insert([
                'empresa_id'     => $empresaId,
                'sucursal_id'    => $sucursalId,
                'tipo'           => $data['tipo']           ?? 'General',
                'descripcion'    => $data['descripcion']    ?? '',
                'referencia'     => $data['referencia']     ?? null,
                'producto_id'    => $data['producto_id']    ?? null,
                'orden_id'       => $data['orden_id']       ?? null,
                'cantidad'       => $data['cantidad']        ?? null,
                'estado'         => 'Pendiente',
                'fecha_novedad'  => date('Y-m-d'),
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $ignored) {
            // No interrumpir el flujo principal si falla el registro de novedad
        }
    }
}
