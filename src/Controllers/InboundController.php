<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Personal;
use App\Models\Notificacion;
use App\Models\Inventario;
use App\Models\Recepcion;
use App\Models\RecepcionDetalle;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * InboundController — Órdenes de Compra (ODC)
 * Gestión completa del ciclo: Borrador → Confirmada → En Proceso → Cerrada
 */
class InboundController extends BaseController
{
    // ── GET /api/odc ─────────────────────────────────────────────────────────
    public function getOrdenesCompra(Request $req, Response $res): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();

            if (!$user) {
                return $this->error($res, 'Usuario no autenticado', 401);
            }

            [$ini, $fin] = $this->getDateRange($params);
            [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);

            $q = OrdenCompra::where('ordenes_compra.empresa_id', $empresaId)
                ->where(function ($q2) use ($ini, $fin) {
                    // Siempre incluir ODCs activas (Confirmada / En Proceso) sin importar la fecha
                    $q2->whereBetween('ordenes_compra.created_at', [$ini, $fin])
                       ->orWhereIn('ordenes_compra.estado', ['Confirmada', 'En Proceso']);
                })
                ->with(['proveedor'])
                ->withCount('detalles');

            if (!empty($params['estado'])) {
                // Si se filtra por un estado específico, aplicar exacto
                $estados = array_filter(array_map('trim', explode(',', $params['estado'])));
                if (count($estados) === 1) {
                    $q->where('ordenes_compra.estado', $estados[0]);
                } elseif (count($estados) > 1) {
                    $q->whereIn('ordenes_compra.estado', $estados);
                }
            }

            // Filtro por Auxiliar vía tabla pivote o auxiliar_id directo
            if ($user->rol === 'Auxiliar') {
                $q->whereIn('estado', ['Confirmada', 'En Proceso']);
                $q->whereExists(function ($query) use ($user) {
                    $query->select(Capsule::raw(1))
                        ->from('odc_auxiliares')
                        ->whereColumn('odc_auxiliares.orden_compra_id', 'ordenes_compra.id')
                        ->where('odc_auxiliares.auxiliar_id', $user->id);
                });
            } elseif (!empty($params['auxiliar_id'])) {
                $q->whereExists(function ($query) use ($params) {
                    $query->select(Capsule::raw(1))
                        ->from('odc_auxiliares')
                        ->whereColumn('odc_auxiliares.orden_compra_id', 'ordenes_compra.id')
                        ->where('odc_auxiliares.auxiliar_id', (int)$params['auxiliar_id']);
                });
            }

            $limit = isset($params['limit']) ? (int)$params['limit'] : 100;
            $ordenes = $q->orderBy('ordenes_compra.created_at', 'desc')
                ->limit($limit)
                ->get();

            return $this->ok($res, $ordenes);
        } catch (\Exception $e) {
            wmsLog('ERROR', "InboundController::getOrdenesCompra — " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error($res, 'Error interno al cargar ODCs: ' . $e->getMessage(), 500);
        }
    }

    // ── GET /api/odc/{id} ────────────────────────────────────────────────────
    public function getODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        $odc  = OrdenCompra::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with(['proveedor', 'detalles.producto', 'recepciones.auxiliar', 'recepciones.detalles.producto', 'recepciones.detalles.ubicacionDestino'])
            ->find($a['id']);

        if (!$odc) return $this->notFound($res);
        return $this->ok($res, $odc);
    }

    // ── POST /api/odc ────────────────────────────────────────────────────────
    public function createOrdenCompra(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        $data = $req->getParsedBody() ?? [];

        if (empty($data['proveedor_id'])) return $this->error($res, 'El proveedor es requerido');

        try {
            $odc = Capsule::transaction(function () use ($data, $user, $empresaId, $sucursalId) {
                $odc = OrdenCompra::create([
                    'empresa_id'    => $empresaId,
                    'sucursal_id'   => $sucursalId,
                    'proveedor_id'  => $data['proveedor_id'],
                    'numero_odc'    => 'ODC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'fecha'         => $data['fecha'] ?? date('Y-m-d'),
                    'estado'        => 'Confirmada',
                    'observaciones' => $data['observaciones'] ?? null,
                ]);

                if (!empty($data['detalles']) && is_array($data['detalles'])) {
                    foreach ($data['detalles'] as $det) {
                        OrdenCompraDetalle::create([
                            'orden_compra_id'    => $odc->id,
                            'producto_id'        => $det['producto_id'],
                            'cantidad_solicitada'=> $det['cantidad'] ?? 0,
                            'cantidad_recibida'  => 0,
                        ]);
                    }
                }
                return $odc;
            });

            return $this->created($res, $odc->load('detalles.producto'));
        } catch (\Exception $e) {
            return $this->error($res, 'Error: ' . $e->getMessage());
        }
    }

    // ── PUT /api/odc/{id} ────────────────────────────────────────────────────
    public function updateOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        $odc  = OrdenCompra::where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->find($a['id']);
        if (!$odc) return $this->notFound($res);

        $data    = $req->getParsedBody() ?? [];
        $isSupervisor = $this->isSupervisorOrAbove($user);

        // Operaciones estructurales (cambiar proveedor, fecha, agregar/quitar líneas)
        // requieren Supervisor. Actualizaciones de cantidades recibidas y novedades
        // las puede hacer cualquier usuario autenticado de la empresa.
        $isStructuralChange = isset($data['proveedor_id']) || isset($data['fecha'])
            || isset($data['observaciones']);

        if ($isStructuralChange && !$isSupervisor) {
            return $this->forbidden($res, 'Se requiere rol Supervisor o Administrador para modificar la estructura de la ODC');
        }

        try {
            Capsule::transaction(function () use ($odc, $data, $isSupervisor) {

                // Cabecera (solo supervisor)
                if ($isSupervisor) {
                    $headerUpdate = [];
                    if (isset($data['proveedor_id']))  $headerUpdate['proveedor_id']  = $data['proveedor_id'];
                    if (isset($data['fecha']))          $headerUpdate['fecha']          = $data['fecha'];
                    if (isset($data['observaciones']))  $headerUpdate['observaciones']  = $data['observaciones'];
                    if (!empty($headerUpdate)) $odc->update($headerUpdate);
                }

                if (isset($data['detalles']) && is_array($data['detalles'])) {
                    $requestIds = array_filter(array_column($data['detalles'], 'id'));

                    // Eliminar líneas ausentes (solo supervisor)
                    if ($isSupervisor && !empty($requestIds)) {
                        OrdenCompraDetalle::where('orden_compra_id', $odc->id)
                            ->whereNotIn('id', $requestIds)
                            ->delete();
                    }

                    foreach ($data['detalles'] as $det) {
                        if (!empty($det['id'])) {
                            $updateData = [];

                            // Cantidades — cualquier usuario autenticado puede actualizar
                            if (array_key_exists('cantidad', $det)) {
                                $updateData['cantidad_solicitada'] = (float)$det['cantidad'];
                            } elseif (array_key_exists('cantidad_solicitada', $det)) {
                                $updateData['cantidad_solicitada'] = (float)$det['cantidad_solicitada'];
                            }
                            if (array_key_exists('cantidad_recibida', $det)) {
                                $updateData['cantidad_recibida'] = (float)$det['cantidad_recibida'];
                            }

                            // Novedad — cualquier usuario puede registrar novedades
                            if (array_key_exists('novedad_motivo', $det)) {
                                $updateData['novedad_motivo'] = $det['novedad_motivo'];
                            }
                            if (array_key_exists('novedad_observacion', $det)) {
                                $updateData['novedad_observacion'] = $det['novedad_observacion'];
                            }
                            if (array_key_exists('cantidad_novedad', $det)) {
                                $updateData['cantidad_novedad'] = (float)$det['cantidad_novedad'];
                            }

                            // Producto — solo supervisor
                            if ($isSupervisor && !empty($det['producto_id'])) {
                                $updateData['producto_id'] = $det['producto_id'];
                            }

                            if (!empty($updateData)) {
                                OrdenCompraDetalle::where('id', $det['id'])->update($updateData);
                            }
                        } elseif ($isSupervisor && !empty($det['producto_id'])) {
                            // Crear nueva línea — solo supervisor
                            OrdenCompraDetalle::create([
                                'orden_compra_id'     => $odc->id,
                                'producto_id'         => $det['producto_id'],
                                'cantidad_solicitada' => (float)($det['cantidad'] ?? 0),
                                'cantidad_recibida'   => 0,
                            ]);
                        }
                    }
                }
            });

            return $this->ok($res, $odc->fresh()->load('detalles.producto'), 'ODC actualizada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al actualizar: ' . $e->getMessage());
        }
    }

    // ── DELETE /api/odc/{id} ─────────────────────────────────────────────────
    public function deleteOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->find($a['id']);
        if (!$odc) return $this->notFound($res);

        if ($odc->estado === 'Cerrada') {
            return $this->error($res, 'No se puede eliminar una ODC ya cerrada');
        }

        try {
            Capsule::transaction(function () use ($odc) {
                OrdenCompraDetalle::where('orden_compra_id', $odc->id)->delete();
                Capsule::table('odc_auxiliares')->where('orden_compra_id', $odc->id)->delete();
                $odc->delete();
            });
            return $this->ok($res, null, 'ODC eliminada correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error: ' . $e->getMessage());
        }
    }

    // ── POST /api/odc/{id}/confirmar ─────────────────────────────────────────
    public function confirmarOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $req);
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->find($a['id']);
        if (!$odc || $odc->estado !== 'Borrador') return $this->error($res, 'ODC no válida para confirmar');

        $odc->estado = 'Confirmada';
        $odc->save();

        return $this->ok($res, $odc, 'ODC confirmada');
    }

    // ── POST /api/odc/{id}/asignar ───────────────────────────────────────────
    public function asignarAuxiliar(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
        if (!$odc) return $this->notFound($res);

        $data = $req->getParsedBody() ?? [];
        $auxIds = $data['auxiliar_ids'] ?? [];

        if (empty($auxIds)) return $this->error($res, 'Debe seleccionar al menos un auxiliar');

        try {
            Capsule::transaction(function () use ($odc, $auxIds, $user, $empresaId, $sucursalId) {
                Capsule::table('odc_auxiliares')->where('orden_compra_id', $odc->id)->delete();
                foreach ($auxIds as $auxId) {
                    Capsule::table('odc_auxiliares')->insert([
                        'empresa_id'      => $empresaId,
                        'sucursal_id'     => $sucursalId,
                        'orden_compra_id' => $odc->id,
                        'auxiliar_id'     => $auxId,
                        'assigned_at'     => date('Y-m-d H:i:s')
                    ]);

                    Capsule::table('notificaciones')->insert([
                        'empresa_id'      => $empresaId,
                        'sucursal_id'     => $sucursalId,
                        'personal_id'     => $auxId,
                        'emisor_id'       => $user->id,
                        'titulo'          => 'Nueva ODC Asignada',
                        'mensaje'         => "Se le ha asignado la ODC {$odc->numero_odc} para recepción.",
                        'modulo'          => 'Recepcion',
                        'tipo'            => 'tarea',
                        'link_accion'     => '/recepcion/operativa?id=' . $odc->id,
                        'created_at'      => date('Y-m-d H:i:s'),
                        'updated_at'      => date('Y-m-d H:i:s')
                    ]);
                }
            });
            return $this->ok($res, null, 'Auxiliares asignados correctamente');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al asignar: ' . $e->getMessage());
        }
    }

    // ── NUEVOS ENDPOINTS MÓVIL/ESCRITORIO ─────────────────────────────────────

    public function iniciarReciboODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc  = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
        if (!$odc) return $this->notFound($res);
        if ($odc->estado === 'Confirmada') {
            $odc->update(['estado' => 'En Proceso']);
        }
        return $this->ok($res, $odc, 'Recibo iniciado');
    }

    public function verificarEanODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odcId = $a['id'];
        $ean   = $req->getQueryParams()['q'] ?? '';
        $producto = Producto::findByEan($ean);
        if (!$producto) return $this->error($res, 'Producto no reconocido');
        $detalle = OrdenCompraDetalle::where('orden_compra_id', $odcId)->where('producto_id', $producto->id)->first();
        if (!$detalle) return $this->error($res, 'Producto no pertenece a esta ODC');
        return $this->ok($res, ['producto' => $producto, 'detalle' => $detalle]);
    }
    // ── APROBACIONES (REQUERIMIENTO USUARIO) ──────────────────────────────────

    public function aprobarLineaODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $detId = $a['id'];
        $detalle = OrdenCompraDetalle::with('ordenCompra')->find($detId);
        if (!$detalle || $detalle->ordenCompra->empresa_id !== $this->getEffectiveEmpresaId($user, $req)) return $this->notFound($res);

        wmsLog('INFO', "Iniciando aprobacion de linea ODC", [
            'odc_id'      => $detalle->orden_compra_id,
            'detalle_id'  => $detalle->id,
            'producto_id' => $detalle->producto_id,
            'user_id'     => $user->id
        ]);

        try {
            Capsule::transaction(function () use ($detalle, $user) {
                // 1. Marcar el detalle de la ODC como aprobado
                $detalle->update([
                    'aprobado_admin' => 1,
                    'estado_aprobacion' => 'Aprobado'
                ]);
                
                // 2. Buscar las recepciones asociadas a esta ODC y producto específico
                $recepcionIds = Recepcion::where('odc_id', $detalle->orden_compra_id)->pluck('id');
                $detallesRec = RecepcionDetalle::whereIn('recepcion_id', $recepcionIds)
                    ->where('producto_id', $detalle->producto_id)
                    ->get();

                wmsLog('DEBUG', "Pallets encontrados para aprobar", [
                    'count' => $detallesRec->count(),
                    'recepcion_ids' => $recepcionIds->toArray()
                ]);

                foreach ($detallesRec as $dr) {
                    // Mover stock de "En Patio" a "Disponible"
                    // Usamos Query Builder para ser explicitos con los estados
                    $affected = Capsule::table('inventarios')
                        ->where('empresa_id',  $this->getEffectiveEmpresaId($user, $req))
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $dr->producto_id)
                        ->where('ubicacion_id', $dr->ubicacion_destino_id)
                        ->when($dr->lote, 
                            fn($q) => $q->where('lote', $dr->lote), 
                            fn($q) => $q->whereNull('lote')
                        )
                        ->where('estado',      'En Patio')
                        ->update(['estado' => 'Disponible']);

                    wmsLog('DEBUG', "Stock actualizado para pallet {$dr->id}", [
                        'lote' => $dr->lote,
                        'affected_rows' => $affected
                    ]);
                    
                    // IMPORTANTE: Marcar la línea de recepción como aprobada para la UI (Matrix)
                    RecepcionDetalle::where('id', $dr->id)->update(['aprobado_admin' => 1]);
                }
            });
            return $this->ok($res, null, 'Línea aprobada y disponible para ubicar');
        } catch (\Exception $e) {
            wmsLog('ERROR', "Error en aprobarLineaODC: " . $e->getMessage());
            return $this->error($res, 'Error: ' . $e->getMessage());
        }
    }

    public function aprobarODCTodo(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
        if (!$odc) return $this->notFound($res);

        wmsLog('INFO', "Iniciando aprobacion TOTAL de ODC", [
            'odc_id' => $odc->id,
            'numero' => $odc->numero_odc,
            'user_id' => $user->id
        ]);

        try {
            Capsule::transaction(function () use ($odc, $user) {
                OrdenCompraDetalle::where('orden_compra_id', $odc->id)->update([
                    'aprobado_admin' => 1,
                    'estado_aprobacion' => 'Aprobado'
                ]);
                $recepcionIds = Recepcion::where('odc_id', $odc->id)->pluck('id');
                RecepcionDetalle::whereIn('recepcion_id', $recepcionIds)->update(['aprobado_admin' => 1]);

                $prodIds = OrdenCompraDetalle::where('orden_compra_id', $odc->id)->pluck('producto_id');
                $affected = Capsule::table('inventarios')
                    ->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                    ->where('sucursal_id', $user->sucursal_id)
                    ->whereIn('producto_id', $prodIds)
                    ->where('estado', 'En Patio')
                    ->update(['estado' => 'Disponible']);

                wmsLog('DEBUG', "Stock total actualizado para ODC {$odc->id}", [
                    'affected_rows' => $affected
                ]);

                $odc->update(['estado' => 'Cerrada']);
            });
            return $this->ok($res, null, 'Toda la ODC ha sido aprobada');
        } catch (\Exception $e) {
            wmsLog('ERROR', "Error en aprobarODCTodo: " . $e->getMessage());
            return $this->error($res, 'Error: ' . $e->getMessage());
        }
    }

    public function cerrarOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
        if (!$odc) {
            return $this->notFound($res);
        }
        if ($odc->estado === 'Cerrada') {
            return $this->ok($res, null, 'ODC ya se encuentra cerrada');
        }

        if ($user->rol === 'Auxiliar') {
            $asignada = Capsule::table('odc_auxiliares')
                ->where('orden_compra_id', $odc->id)
                ->where('auxiliar_id', $user->id)
                ->exists();
            if (!$asignada) {
                return $this->forbidden($res, 'No estás asignado a esta ODC');
            }
        }

        try {
            Capsule::transaction(function () use ($odc) {
                $odc->estado = 'Cerrada';
                $odc->save();
            });
            return $this->ok($res, $odc, 'ODC cerrada correctamente');
        } catch (\Exception $e) {
            wmsLog('ERROR', "Error en cerrarOrdenCompra: " . $e->getMessage());
            return $this->error($res, 'Error al cerrar ODC: ' . $e->getMessage(), 500);
        }
    }

    // ── GET /api/odc/{id}/imprimir ───────────────────────────────────────────
    public function imprimirRecibo(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc  = OrdenCompra::with([
            'proveedor',
            'detalles.producto',
            'recepciones.detalles.producto',
            'recepciones.auxiliar',
        ])->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);

        if (!$odc) return $this->notFound($res);

        $now    = date('d/m/Y H:i:s');
        $logo   = htmlspecialchars($user->empresa->nombre ?? 'WMS');
        $estado = $odc->estado;

        // Filas de líneas ODC
        $filasLineas = '';
        foreach ($odc->detalles as $d) {
            $pct = $d->cantidad_solicitada > 0
                ? round(($d->cantidad_recibida / $d->cantidad_solicitada) * 100) : 0;
            $aprobado  = $d->aprobado_admin ? 'APROBADO' : 'En Patio';
            $filasLineas .= '<tr>'
                . '<td>' . htmlspecialchars($d->producto->codigo_interno ?? '-') . '</td>'
                . '<td>' . htmlspecialchars($d->producto->nombre ?? '-') . '</td>'
                . '<td style="text-align:center">' . $d->cantidad_solicitada . '</td>'
                . '<td style="text-align:center;font-weight:bold;color:' . ($pct >= 100 ? '#059669' : '#d97706') . '">' . $d->cantidad_recibida . '</td>'
                . '<td style="text-align:center">' . ($d->cantidad_solicitada - $d->cantidad_recibida) . '</td>'
                . '<td style="text-align:center">' . $pct . '%</td>'
                . '<td style="text-align:center">' . htmlspecialchars($aprobado) . '</td>'
                . '</tr>';
        }

        // Filas de pallets
        $filasPallets = '';
        foreach ($odc->recepciones as $rec) {
            foreach ($rec->detalles as $det) {
                $ap = $det->aprobado_admin ? 'Si' : 'No';
                $filasPallets .= '<tr>'
                    . '<td>' . htmlspecialchars($rec->numero_recepcion) . '</td>'
                    . '<td style="text-align:center"><b>' . ($det->numero_pallet ?? '-') . '</b></td>'
                    . '<td>' . htmlspecialchars($det->producto->nombre ?? '-') . '</td>'
                    . '<td style="text-align:center">' . $det->cantidad_recibida . '</td>'
                    . '<td>' . htmlspecialchars($det->lote ?? 'N/A') . '</td>'
                    . '<td>' . ($det->fecha_vencimiento ? date('d/m/Y', strtotime($det->fecha_vencimiento)) : '-') . '</td>'
                    . '<td>' . htmlspecialchars($rec->auxiliar->nombre ?? '-') . '</td>'
                    . '<td style="text-align:center">' . $ap . '</td>'
                    . '</tr>';
            }
        }

        $nit = htmlspecialchars($odc->proveedor->nit ?? $odc->proveedor->identificacion ?? '-');
        $prov = htmlspecialchars($odc->proveedor->razon_social ?? $odc->proveedor->nombre ?? '-');
        $fecha = $odc->fecha_esperada ? date('d/m/Y', strtotime($odc->fecha_esperada)) : '-';

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<title>Recibo ' . htmlspecialchars($odc->numero_odc) . '</title>'
            . '<style>'
            . '*{margin:0;padding:0;box-sizing:border-box}'
            . 'body{font-family:Arial,sans-serif;font-size:11px;color:#1a202c;padding:20px}'
            . 'h2{font-size:13px;font-weight:800;color:#1e3a5f;background:#e8f0fe;padding:8px 12px;border-left:4px solid #2e75b6;margin:16px 0 8px}'
            . 'table{width:100%;border-collapse:collapse;margin-bottom:16px}'
            . 'th{background:#1e3a5f;color:#fff;padding:6px 8px;text-align:left;font-size:10px;font-weight:700}'
            . 'td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}'
            . 'tr:nth-child(even) td{background:#f8fafc}'
            . '.hdr{display:flex;justify-content:space-between;border-bottom:3px solid #1e3a5f;padding-bottom:16px;margin-bottom:20px}'
            . '.meta{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;background:#f8fafc;padding:14px;border-radius:6px;margin-bottom:18px;border:1px solid #e2e8f0}'
            . '.meta label{font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block}'
            . '.meta span{font-size:13px;font-weight:700;color:#1e3a5f}'
            . '.footer{margin-top:30px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:30px;border-top:2px solid #e2e8f0;padding-top:20px}'
            . '.sign-line{border-top:1px solid #1a202c;margin:50px 0 6px}'
            . '.sign-label{font-size:10px;color:#64748b;text-align:center}'
            . '@media print{button{display:none!important}}'
            . '</style></head><body>'
            . '<button onclick="window.print()" style="position:fixed;top:10px;right:10px;background:#1e3a5f;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-weight:700;cursor:pointer">Imprimir / PDF</button>'
            . '<div class="hdr">'
            . '<div><div style="font-size:22px;font-weight:900;color:#1e3a5f">' . $logo . '</div>'
            . '<div style="font-size:10px;color:#64748b;text-transform:uppercase">WMS Fénix</div></div>'
            . '<div style="text-align:right">'
            . '<div style="font-size:18px;font-weight:800;color:#1e3a5f">ACTA DE RECIBO DE MERCANC&Iacute;A</div>'
            . '<div style="font-size:22px;font-weight:900;color:#2e75b6;margin:4px 0">' . htmlspecialchars($odc->numero_odc) . '</div>'
            . '<div style="color:#64748b;font-size:10px;">Generado el ' . $now . '</div>'
            . '<div style="margin-top:4px;">Estado: <strong style="background:' . ($estado === 'Cerrada' ? '#f0fdf4' : '#fff7ed') . ';color:' . ($estado === 'Cerrada' ? '#059669' : '#d97706') . ';padding:2px 8px;border-radius:4px;border:1px solid">' . htmlspecialchars($estado) . '</strong></div>'
            . '</div></div>'
            . '<div class="meta">'
            . '<div><label>Proveedor</label><span>' . $prov . '</span></div>'
            . '<div><label>NIT / Identificaci&oacute;n</label><span>' . $nit . '</span></div>'
            . '<div><label>Fecha Esperada</label><span>' . $fecha . '</span></div>'
            . '</div>'
            . '<h2>L&iacute;neas de la Orden de Compra</h2>'
            . '<table><thead><tr>'
            . '<th>C&oacute;digo</th><th>Producto</th><th>Solicitado</th><th>Recibido</th><th>Pendiente</th><th>%</th><th>Estado</th>'
            . '</tr></thead><tbody>' . $filasLineas . '</tbody></table>'
            . '<h2>Detalle de Pallets / Capturas</h2>'
            . '<table><thead><tr>'
            . '<th>Recepci&oacute;n</th><th style="text-align:center">Pallet</th><th>Producto</th><th style="text-align:center">Cantidad</th><th>Lote</th><th>Vencimiento</th><th>Auxiliar</th><th style="text-align:center">Aprobado</th>'
            . '</tr></thead><tbody>' . $filasPallets . '</tbody></table>'
            . '<div class="footer">'
            . '<div class="sign-line"></div><div class="sign-label">Firma Auxiliar</div>'
            . '<div class="sign-line"></div><div class="sign-label">Firma Supervisor</div>'
            . '<div class="sign-line"></div><div class="sign-label">Firma Proveedor</div>'
            . '</div>'
            . '</body></html>';

        $response = $res->withHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->getBody()->write($html);
        return $response;
    }

    // ── GET /api/odc/{id}/exportar ───────────────────────────────────────────
    public function exportarODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc  = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
            ->with(['proveedor', 'detalles.producto'])
            ->find($a['id']);

        if (!$odc) return $this->notFound($res);

        $headers = ['Producto', 'Código', 'Cant. Solicitada', 'Cant. Recibida', 'Pendiente'];
        $rows = $odc->detalles->map(fn($d) => [
            $d->producto->nombre         ?? '—',
            $d->producto->codigo_interno ?? '—',
            $d->cantidad_solicitada,
            $d->cantidad_recibida,
            $d->cantidad_solicitada - $d->cantidad_recibida,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows,
            'odc_' . $odc->numero_odc);
    }

    // ── POST /api/odc/importar ────────────────────────────────────────────────
    public function importarODC(Request $req, Response $res): Response
    {
        $user  = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $files = $req->getUploadedFiles();
        $file  = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'Archivo no válido');
        }

        $contents = $file->getStream()->getContents();
        if (!mb_detect_encoding($contents, 'UTF-8', true)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        $lines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $contents)), fn($l) => trim($l) !== '');
        if (count($lines) < 2) return $this->error($res, 'El archivo no contiene datos');

        $sep    = str_contains($lines[array_key_first($lines)], ';') ? ';' : ',';
        $rawHdr = str_getcsv(array_shift($lines), $sep);
        $hdrs   = array_map(fn($h) => strtolower(trim($h, " \t\r\n\xEF\xBB\xBF")), $rawHdr);

        // Group rows by numero_odc or proveedor_nit
        $grupos = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line, $sep);
            $row  = [];
            foreach ($hdrs as $i => $h) { $row[$h] = isset($cols[$i]) ? trim($cols[$i]) : ''; }
            $key = $row['numero_odc'] ?? $row['proveedor_nit'] ?? 'ODC-' . date('YmdHis');
            $grupos[$key][] = $row;
        }

        $summary = ['total' => 0, 'success' => 0, 'errors' => []];

        foreach ($grupos as $numOdc => $filas) {
            $summary['total']++;
            try {
                Capsule::transaction(function () use ($numOdc, $filas, $user) {
                    $fila0 = $filas[0];
                    $proveedor = null;
                    if (!empty($fila0['proveedor_nit'])) {
                        $proveedor = Proveedor::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                            ->where('nit', $fila0['proveedor_nit'])->first();
                    }

                    $odc = OrdenCompra::create([
                        'empresa_id'     => $this->getEffectiveEmpresaId($user, $req),
                        'sucursal_id'    => $user->sucursal_id,
                        'numero_odc'     => $numOdc,
                        'proveedor_id'   => $proveedor?->id,
                        'estado'         => 'Borrador',
                        'fecha_esperada' => $fila0['fecha_esperada'] ?? date('Y-m-d'),
                        'observaciones'  => $fila0['observaciones'] ?? null,
                        'analista_id'    => $user->id,
                    ]);

                    foreach ($filas as $fila) {
                        $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                            ->where('codigo_interno', $fila['producto_codigo'] ?? '')->first();
                        if (!$prod) continue;

                        OrdenCompraDetalle::create([
                            'orden_compra_id'    => $odc->id,
                            'producto_id'        => $prod->id,
                            'cantidad_solicitada'=> (float)($fila['cantidad'] ?? 0),
                            'cantidad_recibida'  => 0,
                            'precio_unitario'    => (float)($fila['precio_unitario'] ?? 0),
                        ]);
                    }
                });
                $summary['success']++;
            } catch (\Exception $e) {
                $summary['errors'][] = "ODC {$numOdc}: " . $e->getMessage();
            }
        }

        return $this->ok($res, $summary, 'Importación completada');
    }

    // ── GET /api/odc/buscar-producto ──────────────────────────────────────────
    public function buscarProducto(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        $q      = trim($params['q'] ?? '');

        if (strlen($q) < 2) {
            return $this->ok($res, []);
        }

        $productos = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
            ->where('activo', 1)
            ->where(function ($query) use ($q) {
                $query->where('codigo_interno', 'LIKE', "%{$q}%")
                      ->orWhere('nombre', 'LIKE', "%{$q}%")
                      ->orWhereHas('eans', fn($eq) => $eq->where('codigo_ean', 'LIKE', "%{$q}%"));
            })
            ->select(['id', 'codigo_interno', 'nombre', 'unidad_medida', 'unidades_caja', 'controla_vencimiento'])
            ->limit(20)
            ->get();

        return $this->ok($res, $productos);
    }
    
    public function reabrirOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if (!$this->isSupervisorOrAbove($user)) {
            return $this->forbidden($res, 'Se requiere rol Supervisor o Administrador para reabrir una ODC');
        }

        $odc = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($a['id']);
        if (!$odc) return $this->notFound($res);

        if ($odc->estado !== 'Cerrada') {
            return $this->error($res, 'Solo se pueden reabrir órdenes que se encuentren en estado Cerrada');
        }

        try {
            $odc->estado = 'En Proceso';
            $odc->save();

            wmsLog('INFO', "ODC #{$odc->numero_odc} REABIERTA por {$user->nombre}");
            return $this->ok($res, $odc, 'La Orden de Compra ha sido reabierta exitosamente.');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al reabrir ODC: ' . $e->getMessage());
        }
    }
}
