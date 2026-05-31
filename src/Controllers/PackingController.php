<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\PackingSesion;
use App\Models\PackingUnidad;
use App\Models\PackingItem;
use App\Models\OrdenPicking;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\ExpiryGuard;
use App\Helpers\ExpiryResult;

class PackingController extends BaseController
{
    // ── POST /api/packing/sesion ───────────────────────────────────────────────
    public function iniciarSesion(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if ($deny = $this->requireFields($data, ['sucursal_entrega', 'tipo_empaque'], $res)) {
            return $deny;
        }

        if (!in_array($data['tipo_empaque'], ['canasta', 'caja', 'paquete'], true)) {
            return $this->error($res, 'tipo_empaque inválido. Valores: canasta, caja, paquete');
        }

        $existing = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $data['sucursal_entrega'])
            ->where('estado', 'EnProceso')
            ->first();
        if ($existing) {
            return $this->error($res, 'Ya existe una sesión en proceso para esta sucursal. ID: ' . $existing->id, 409);
        }

        return Capsule::transaction(function () use ($user, $data, $res) {
            $sesion = PackingSesion::create([
                'empresa_id'           => $user->empresa_id,
                'sucursal_id'          => $user->sucursal_id,
                'sucursal_entrega'     => $data['sucursal_entrega'],
                'tipo_empaque'         => $data['tipo_empaque'],
                'certificador_id'      => $user->id,
                'impresora_sticker_id' => $data['impresora_sticker_id'] ?? null,
                'impresora_doc_id'     => $data['impresora_doc_id'] ?? null,
                'estado'               => 'EnProceso',
            ]);

            PackingUnidad::create([
                'sesion_id'   => $sesion->id,
                'consecutivo' => 1,
                'estado'      => 'Abierta',
            ]);

            $this->audit($user, 'packing', 'iniciar_sesion', 'packing_sesiones', $sesion->id,
                null, ['sucursal_entrega' => $sesion->sucursal_entrega, 'tipo_empaque' => $sesion->tipo_empaque]);

            return $this->created($res, ['sesion_id' => $sesion->id], 'Sesión de packing iniciada');
        });
    }

    // ── GET /api/packing/sesion/{id} ──────────────────────────────────────────
    public function getSesion(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);

        $certificador = Capsule::table('personal')->find($sesion->certificador_id);
        $certNombre   = $certificador
            ? trim($certificador->nombre)
            : 'N/A';

        $empresa   = Capsule::table('empresas')->find($sesion->empresa_id);
        $empNombre = $empresa ? $empresa->nombre : 'WMS Fénix';

        $allUnidades = PackingUnidad::where('sesion_id', $sesion->id)
            ->orderBy('consecutivo')
            ->get();

        $unidadIds  = $allUnidades->pluck('id')->toArray();
        $allItemsRaw = !empty($unidadIds)
            ? Capsule::table('packing_items as pi')
                ->join('productos as p', 'p.id', '=', 'pi.producto_id')
                ->leftJoin('personal as per', 'per.id', '=', 'pi.separador_id')
                ->whereIn('pi.unidad_id', $unidadIds)
                ->select([
                    'pi.id', 'pi.unidad_id', 'pi.producto_id',
                    'p.nombre as producto_nombre', 'p.codigo_interno as codigo',
                    'pi.cantidad', 'pi.lote', 'pi.fecha_vencimiento', 'pi.separador_id',
                    Capsule::raw("COALESCE(per.nombre,'') as separador_nombre"),
                ])
                ->get()
                ->groupBy('unidad_id')
            : collect();

        $unidadesData = $allUnidades->map(function ($u) use ($allItemsRaw) {
            $arr          = $u->toArray();
            $arr['items'] = $allItemsRaw->get($u->id, collect())->toArray();
            return $arr;
        })->toArray();

        $unidadAbierta = $allUnidades->firstWhere('estado', 'Abierta');

        $pickeados = $this->_getProductosPickados(
            $user->empresa_id, $user->sucursal_id, $sesion->sucursal_entrega
        );
        $empacados = $this->_getProductosEmpacados($sesion->id);

        $productos = [];
        foreach ($pickeados as $pid => $pick) {
            $empQty    = $empacados[$pid] ?? 0;
            $pendiente = max(0, round((float)$pick->total_pickeado - $empQty, 3));
            $productos[] = [
                'producto_id'    => (int)$pid,
                'nombre'         => $pick->producto_nombre,
                'codigo'         => $pick->codigo,
                'ean'            => $pick->ean,
                'total_pickeado' => (float)$pick->total_pickeado,
                'total_empacado' => (float)$empQty,
                'pendiente'      => $pendiente,
            ];
        }

        $totalPickeado = array_sum(array_column($productos, 'total_pickeado'));
        $totalEmpacado = array_sum(array_column($productos, 'total_empacado'));

        $sesionArr                        = $sesion->toArray();
        $sesionArr['certificador_nombre'] = $certNombre;
        $sesionArr['empresa_nombre']      = $empNombre;

        return $this->ok($res, [
            'sesion'         => $sesionArr,
            'unidad_abierta' => $unidadAbierta ? $unidadAbierta->id : null,
            'unidades'       => $unidadesData,
            'productos'      => $productos,
            'totales'        => [
                'total_pickeado' => $totalPickeado,
                'total_empacado' => $totalEmpacado,
                'pendiente'      => max(0, round($totalPickeado - $totalEmpacado, 3)),
                'num_unidades'   => count(array_filter($unidadesData, fn($u) => $u['estado'] === 'Cerrada')),
            ],
        ]);
    }

    // ── POST /api/packing/sesion/{id}/item ────────────────────────────────────
    public function agregarItem(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if ($deny = $this->requireFields($data, ['producto_id', 'cantidad'], $res)) return $deny;

        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'Completada') return $this->error($res, 'Sesión ya finalizada', 409);

        $unidad = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Abierta')->first();
        if (!$unidad) return $this->error($res, 'No hay unidad abierta en esta sesión');

        $productoId = (int)$data['producto_id'];
        $cantidad   = round((float)$data['cantidad'], 3);
        if ($cantidad <= 0) return $this->error($res, 'La cantidad debe ser mayor a 0');

        $pickeados = $this->_getProductosPickados(
            $user->empresa_id, $user->sucursal_id, $sesion->sucursal_entrega
        );
        if (!isset($pickeados[$productoId])) {
            return $this->error($res, 'El producto no pertenece a los pedidos de esta sucursal', 422);
        }

        $empacados = $this->_getProductosEmpacados($sesion->id);
        $pendiente = round((float)$pickeados[$productoId]->total_pickeado - ($empacados[$productoId] ?? 0), 3);

        if ($cantidad > $pendiente + 0.001) {
            return $this->error($res, "Cantidad supera el pendiente: {$pendiente} uds disponibles", 422);
        }

        [$lote, $fechaVenc, $separadorId, $detalleId] = $this->_resolveFromPicking(
            $productoId, $sesion->sucursal_entrega, $user->empresa_id, $user->sucursal_id
        );

        // R10/R11 — Expiry check before adding item to packing
        if ($lote !== null) {
            $expiryGuard = new ExpiryGuard($user->empresa_id, $user->sucursal_id);
            $expiryResult = $expiryGuard->check((int)$productoId, $lote, $user->id);

            if ($expiryResult->status === ExpiryResult::BLOCKED) {
                return $this->error($res, $expiryResult->message, 422);
            }

            if ($expiryResult->status === ExpiryResult::PENDING) {
                $body = $res->getBody();
                $body->write(json_encode([
                    'error'         => false,
                    'status'        => 'pending_approval',
                    'aprobacion_id' => $expiryResult->aprobacionId,
                    'message'       => $expiryResult->message,
                    'dias_restantes'=> $expiryResult->diasRestantes,
                ], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(202);
            }
        }

        $item = PackingItem::create([
            'unidad_id'          => $unidad->id,
            'picking_detalle_id' => $detalleId,
            'producto_id'        => $productoId,
            'lote'               => $lote,
            'fecha_vencimiento'  => $fechaVenc,
            'separador_id'       => $separadorId,
            'cantidad'           => $cantidad,
        ]);

        return $this->created($res, $item, 'Producto agregado');
    }

    // ── DELETE /api/packing/item/{id} ─────────────────────────────────────────
    public function eliminarItem(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $item   = PackingItem::find((int)$a['id']);
        if (!$item) return $this->notFound($res);

        $unidad = PackingUnidad::find($item->unidad_id);
        if (!$unidad) return $this->notFound($res);
        if ($unidad->estado !== 'Abierta') {
            return $this->error($res, 'Solo se pueden eliminar ítems de una unidad abierta', 422);
        }
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($unidad->sesion_id);
        if (!$sesion) return $this->forbidden($res);

        $item->delete();
        return $this->ok($res, null, 'Ítem eliminado');
    }

    // ── POST /api/packing/unidad/{id}/cerrar ──────────────────────────────────
    public function cerrarUnidad(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $unidad = PackingUnidad::find((int)$a['id']);
        if (!$unidad) return $this->notFound($res);
        if ($unidad->estado === 'Cerrada') return $this->error($res, 'La unidad ya está cerrada', 409);

        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($unidad->sesion_id);
        if (!$sesion) return $this->forbidden($res);

        $totalItems = PackingItem::where('unidad_id', $unidad->id)->count();
        if ($totalItems === 0) return $this->error($res, 'La unidad está vacía', 422);

        return Capsule::transaction(function () use ($unidad, $sesion, $res) {
            $total               = (float) PackingItem::where('unidad_id', $unidad->id)->sum('cantidad');
            $unidad->estado         = 'Cerrada';
            $unidad->total_unidades = $total;
            $unidad->closed_at      = date('Y-m-d H:i:s');
            $unidad->save();

            $nuevaUnidad = PackingUnidad::create([
                'sesion_id'   => $sesion->id,
                'consecutivo' => $unidad->consecutivo + 1,
                'estado'      => 'Abierta',
            ]);

            return $this->ok($res, [
                'unidad_cerrada' => $unidad->toArray(),
                'nueva_unidad'   => $nuevaUnidad->toArray(),
            ], "Unidad #{$unidad->consecutivo} cerrada");
        });
    }

    // ── POST /api/packing/sesion/{id}/finalizar ───────────────────────────────
    public function finalizarSesion(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'Completada') return $this->error($res, 'Sesión ya finalizada', 409);

        return Capsule::transaction(function () use ($sesion, $user, $res) {
            // Step 1: Auto-close or delete the open unit
            $openUnidad = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Abierta')->first();
            if ($openUnidad) {
                $itemCount = PackingItem::where('unidad_id', $openUnidad->id)->count();
                if ($itemCount > 0) {
                    $total                      = (float)PackingItem::where('unidad_id', $openUnidad->id)->sum('cantidad');
                    $openUnidad->estado         = 'Cerrada';
                    $openUnidad->total_unidades = $total;
                    $openUnidad->closed_at      = date('Y-m-d H:i:s');
                    $openUnidad->save();
                } else {
                    $openUnidad->delete();
                }
            }

            // Step 2: Re-validate pending items inside transaction (TOCTOU fix)
            $pickeados = $this->_getProductosPickados($user->empresa_id, $user->sucursal_id, $sesion->sucursal_entrega);
            $empacados = $this->_getProductosEmpacados($sesion->id);
            $totalPend = 0.0;
            foreach ($pickeados as $pid => $pick) {
                $totalPend += max(0, round((float)$pick->total_pickeado - ($empacados[$pid] ?? 0), 3));
            }
            if ($totalPend > 0.001) {
                return $this->error($res, "Quedan {$totalPend} unidades sin empacar", 422);
            }

            // Step 3: Mark session complete + run certFinalizar logic
            $sesion->estado = 'Completada';
            $sesion->save();

            $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('sucursal_entrega', $sesion->sucursal_entrega)
                ->where('estado', 'Completada')
                ->where('estado_certificacion', 'Pendiente')
                ->get();

            foreach ($ordenes as $o) {
                $o->estado_certificacion = 'Certificada';
                $o->fecha_certificacion  = date('Y-m-d H:i:s');
                $o->certificador_id      = $user->id;
                $o->save();

                foreach ($o->detalles as $d) {
                    $diff = (float)$d->cantidad_pickeada - (float)$d->cantidad_certificada;
                    if ($diff != 0) {
                        $this->audit(
                            $user, 'picking', 'novedad_certificacion',
                            'picking_detalles', $d->id,
                            ['pick' => $d->cantidad_pickeada],
                            ['cert' => $d->cantidad_certificada],
                            "Diferencia en certificación: Pedido {$o->numero_orden}, Producto ID {$d->producto_id}. Faltan " . abs($diff)
                        );
                    }
                }
            }

            $this->audit($user, 'packing', 'finalizar_sesion', 'packing_sesiones', $sesion->id,
                ['estado' => 'EnProceso'], ['estado' => 'Completada']);

            $numUnidades = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Cerrada')->count();

            return $this->ok($res, [
                'sesion_id'        => $sesion->id,
                'tipo_empaque'     => $sesion->tipo_empaque,
                'total_unidades'   => $numUnidades,
                'sucursal_entrega' => $sesion->sucursal_entrega,
            ], 'Certificación finalizada correctamente');
        });
    }

    // ── PUT /api/packing/sesion/{id}/impresoras ───────────────────────────────
    public function actualizarImpresoras(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $data   = $r->getParsedBody() ?? [];
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'Completada') return $this->error($res, 'No se puede modificar una sesión finalizada', 409);

        $sesion->impresora_sticker_id = $data['impresora_sticker_id'] ?? null;
        $sesion->impresora_doc_id     = $data['impresora_doc_id'] ?? null;
        $sesion->save();

        return $this->ok($res, $sesion, 'Impresoras actualizadas');
    }

    // ── GET /api/packing/sesiones ───────────────────────────────────────────
    public function listarSesiones(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $q = $r->getQueryParams();

        $desde = $q['desde'] ?? null;
        $hasta = $q['hasta'] ?? null;
        $pedido = $q['pedido'] ?? null;
        $sucursal = $q['sucursal'] ?? null;
        $sesionId = $q['sesion_id'] ?? null;

        $counts = Capsule::table('packing_unidades as pu')
            ->leftJoin('packing_items as pi', 'pi.unidad_id', '=', 'pu.id')
            ->leftJoin('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
            ->leftJoin('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->select(
                'pu.sesion_id',
                Capsule::raw('COUNT(DISTINCT pu.id) as num_unidades'),
                Capsule::raw('COUNT(DISTINCT op.id) as num_pedidos'),
                Capsule::raw('COALESCE(SUM(pi.cantidad), 0) as total_empacado')
            )
            ->groupBy('pu.sesion_id');

        $builder = Capsule::table('packing_sesiones as ps')
            ->leftJoinSub($counts, 'cnt', 'cnt.sesion_id', '=', 'ps.id')
            ->where('ps.empresa_id', $user->empresa_id)
            ->where('ps.sucursal_id', $user->sucursal_id)
            ->select(
                'ps.*',
                Capsule::raw('COALESCE(cnt.num_unidades, 0) as num_unidades'),
                Capsule::raw('COALESCE(cnt.num_pedidos, 0) as num_pedidos'),
                Capsule::raw('COALESCE(cnt.total_empacado, 0) as total_empacado')
            )
            ->orderBy('ps.created_at', 'desc')
            ->limit(200);

        if ($sesionId) $builder->where('ps.id', (int)$sesionId);
        if ($sucursal) $builder->where('ps.sucursal_entrega', 'like', "%{$sucursal}%");
        if ($desde) $builder->whereDate('ps.created_at', '>=', $desde);
        if ($hasta) $builder->whereDate('ps.created_at', '<=', $hasta);
        if ($pedido) $builder->whereExists(function ($sub) use ($pedido) {
            $sub->select(Capsule::raw(1))
                ->from('packing_unidades as pu2')
                ->join('packing_items as pi2', 'pi2.unidad_id', '=', 'pu2.id')
                ->join('picking_detalles as pd2', 'pd2.id', '=', 'pi2.picking_detalle_id')
                ->join('orden_pickings as op2', 'op2.id', '=', 'pd2.orden_picking_id')
                ->whereColumn('pu2.sesion_id', 'ps.id')
                ->where('op2.numero_orden', 'like', "%{$pedido}%");
        });

        $rows = $builder->get();
        return $this->ok($res, $rows);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    private function _getProductosPickados(int $empresaId, int $sucursalId, string $sucursalEntrega): array
    {
        return Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.sucursal_entrega', $sucursalEntrega)
            ->where('op.estado', 'Completada')
            ->where('op.estado_certificacion', 'Pendiente')
            ->select([
                'pd.producto_id',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo',
                Capsule::raw("NULL as ean"),
                Capsule::raw('SUM(pd.cantidad_pickeada) as total_pickeado'),
            ])
            ->groupBy('pd.producto_id', 'p.nombre', 'p.codigo_interno')
            ->get()
            ->keyBy('producto_id')
            ->toArray();
    }

    private function _getProductosEmpacados(int $sesionId): array
    {
        $rows = Capsule::table('packing_items as pi')
            ->join('packing_unidades as pu', 'pu.id', '=', 'pi.unidad_id')
            ->where('pu.sesion_id', $sesionId)
            ->select(['pi.producto_id', Capsule::raw('SUM(pi.cantidad) as total_empacado')])
            ->groupBy('pi.producto_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->producto_id] = (float)$row->total_empacado;
        }
        return $result;
    }

    private function _resolveFromPicking(int $productoId, string $sucursalEntrega, int $empresaId, int $sucursalId): array
    {
        $detalle = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->leftJoin('inventarios as i', function ($join) use ($empresaId, $sucursalId) {
                $join->on('i.producto_id', '=', 'pd.producto_id')
                     ->on('i.lote', '=', 'pd.lote')
                     ->where('i.empresa_id', $empresaId)
                     ->where('i.sucursal_id', $sucursalId)
                     ->where('i.estado', 'Disponible');
            })
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.sucursal_entrega', $sucursalEntrega)
            ->where('op.estado', 'Completada')
            ->where('op.estado_certificacion', 'Pendiente')
            ->where('pd.producto_id', $productoId)
            ->orderByRaw('CASE WHEN i.fecha_vencimiento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('i.fecha_vencimiento', 'asc')
            ->select(['pd.id', 'pd.lote', 'i.fecha_vencimiento', 'op.auxiliar_id'])
            ->first();

        if (!$detalle) return [null, null, null, null];
        return [$detalle->lote, $detalle->fecha_vencimiento, $detalle->auxiliar_id, $detalle->id];
    }
}
