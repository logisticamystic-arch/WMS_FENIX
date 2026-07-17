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

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        $sucursal = $data['sucursal_entrega'];

        // Guard 1: sesión ya en proceso
        $existing = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado', 'EnProceso')
            ->first();
        if ($existing) {
            return $this->error($res, 'Ya existe una sesión en proceso para esta sucursal. ID: ' . $existing->id, 409);
        }

        // NOTA (corrección de auditoría, commit 3e18bb6 lo introdujo el 2026-07-13):
        // Existía aquí un "Guard 2" que, al detectar CUALQUIER PackingSesion histórica
        // en estado 'Completada' para esta $sucursal_entrega (que es solo el nombre del
        // cliente/destino y se repite en cada ciclo de picking, no un id de lote), asumía
        // que las órdenes 'Pendiente' de certificar ACTUALES pertenecían a esa sesión vieja
        // y las certificaba automáticamente sin pasar por empaque real — y de paso
        // bloqueaba con un error 409 el intento de abrir la nueva sesión legítima.
        // En la práctica, cualquier destino certificado alguna vez disparaba esto en TODOS
        // los ciclos futuros. Se elimina: si hay órdenes nuevas pendientes de certificar,
        // el flujo normal de abajo simplemente abre una sesión de packing nueva para ellas.
        // La recuperación de certificaciones realmente huérfanas de una sesión específica
        // sigue disponible como acción manual explícita vía recertificar().

        // Guard 3: todas las órdenes ya certificadas — no hay nada que empacar
        $hayOrdenesCertificadas = \App\Models\OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado', 'Completada')
            ->where('estado_certificacion', 'Certificada')
            ->exists();
        $hayOrdenesPendientes = \App\Models\OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado', 'Completada')
            ->where('estado_certificacion', 'Pendiente')
            ->exists();
        if ($hayOrdenesCertificadas && !$hayOrdenesPendientes) {
            return $this->error($res, "Las órdenes de \"{$sucursal}\" ya fueron certificadas. No se puede iniciar un nuevo packing.", 409);
        }

        return Capsule::transaction(function () use ($user, $data, $res, $empresaId, $sucursalId, $sucursal) {
            $sesion = PackingSesion::create([
                'empresa_id'           => $empresaId,
                'sucursal_id'          => $sucursalId,
                'sucursal_entrega'     => $sucursal,
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
        $sesion = PackingSesion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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

        // Auto-sanar: sesión EnProceso sin unidad abierta (puede ocurrir por bug de transacción)
        $unidadAbierta = $allUnidades->firstWhere('estado', 'Abierta');
        if (!$unidadAbierta && $sesion->estado === 'EnProceso') {
            $lastUnit      = $allUnidades->sortByDesc('consecutivo')->first();
            $nextConsec    = $lastUnit ? $lastUnit->consecutivo + 1 : 1;
            $unidadAbierta = PackingUnidad::create([
                'sesion_id'   => $sesion->id,
                'consecutivo' => $nextConsec,
                'estado'      => 'Abierta',
            ]);
            $allUnidades->push($unidadAbierta);
        }

        $unidadIds  = $allUnidades->pluck('id')->toArray();
        $allItemsRaw = !empty($unidadIds)
            ? Capsule::table('packing_items as pi')
                ->join('productos as p', 'p.id', '=', 'pi.producto_id')
                ->leftJoin('personal as per', 'per.id', '=', 'pi.separador_id')
                ->whereIn('pi.unidad_id', $unidadIds)
                ->select([
                    'pi.id', 'pi.unidad_id', 'pi.producto_id',
                    'p.nombre as producto_nombre', 'p.codigo_interno as codigo',
                    'pi.cantidad', 'pi.cantidad_cajas', 'pi.saldo',
                    'pi.lote', 'pi.fecha_vencimiento', 'pi.separador_id',
                    Capsule::raw("COALESCE(per.nombre,'') as separador_nombre"),
                ])
                ->get()
                ->groupBy(fn($item) => (string)$item->unidad_id)
            : collect();

        $unidadesData = $allUnidades->map(function ($u) use ($allItemsRaw) {
            $arr          = $u->toArray();
            $arr['items'] = $allItemsRaw->get((string)$u->id, collect())->toArray();
            return $arr;
        })->toArray();

        $pickeados = $this->_getProductosPickados(
            $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $sesion->sucursal_entrega,
            date('Y-m-d', strtotime($sesion->created_at))
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
                'unidades_caja'  => (int)($pick->unidades_caja ?? 1),
                'ambiente_id'    => $pick->ambiente_id,
                'ambiente_nombre'=> $pick->ambiente_nombre ?? 'Sin ambiente',
                'ambiente_color' => $pick->ambiente_color  ?? '#64748b',
                'total_solicitado'=> (float)$pick->total_solicitado,
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

        $sesion = PackingSesion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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
            $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id, $sesion->sucursal_entrega,
            date('Y-m-d', strtotime($sesion->created_at))
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
            $productoId, $sesion->sucursal_entrega, $this->getEffectiveEmpresaId($user, $r), $user->sucursal_id
        );

        // R10/R11 — Expiry check before adding item to packing
        // Se pasa $fechaVenc (ya resuelta arriba desde el detalle de picking real) como
        // criterio primario — es la fecha exacta de la partida que se está empacando,
        // más confiable que localizar por lote cuando el mismo lote puede repetirse
        // entre partidas con fecha de vencimiento distinta.
        if ($lote !== null || $fechaVenc !== null) {
            $expiryGuard = new ExpiryGuard($this->getEffectiveEmpresaId($user, $r), $user->sucursal_id);
            $expiryResult = $expiryGuard->check((int)$productoId, $lote, $user->id, $fechaVenc);

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

        $cantCajas = max(0, round((float)($data['cantidad_cajas'] ?? 0), 3));
        $saldo     = max(0, round((float)($data['saldo']          ?? 0), 3));

        $item = PackingItem::create([
            'unidad_id'          => $unidad->id,
            'picking_detalle_id' => $detalleId,
            'producto_id'        => $productoId,
            'lote'               => $lote,
            'fecha_vencimiento'  => $fechaVenc,
            'separador_id'       => $separadorId,
            'cantidad'           => $cantidad,
            'cantidad_cajas'     => $cantCajas,
            'saldo'              => $saldo,
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
        $sesion = PackingSesion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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

        $sesion = PackingSesion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'Completada') return $this->error($res, 'Sesión ya finalizada', 409);

        return Capsule::transaction(function () use ($sesion, $user, $res, $empresaId, $sucursalId) {
            // Step 1: Validar primero — si hay pendientes, no tocar nada (evita borrar unidad abierta vacía en falso)
            $pickeados = $this->_getProductosPickados(
                $empresaId, $sucursalId, $sesion->sucursal_entrega,
                date('Y-m-d', strtotime($sesion->created_at))
            );
            $empacados = $this->_getProductosEmpacados($sesion->id);
            $totalPend = 0.0;
            foreach ($pickeados as $pid => $pick) {
                $totalPend += max(0, round((float)$pick->total_pickeado - ($empacados[$pid] ?? 0), 3));
            }
            if ($totalPend > 0.001) {
                return $this->error($res, "Quedan {$totalPend} unidades sin empacar", 422);
            }

            // Step 2: Cerrar o eliminar la unidad abierta (solo si la validación pasó)
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

            // Step 3: Mark session complete + run certFinalizar logic
            $sesion->estado = 'Completada';
            $sesion->save();

            $ordenes = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('sucursal_entrega', $sesion->sucursal_entrega)
                ->where('estado', 'Completada')
                ->where('estado_certificacion', 'Pendiente')
                ->get();

            // Bulk update — 1 query en lugar de N saves
            $ids = $ordenes->pluck('id');
            $now = date('Y-m-d H:i:s');
            Capsule::table('orden_pickings')
                ->whereIn('id', $ids)
                ->update([
                    'estado_certificacion' => 'Certificada',
                    'fecha_certificacion'  => $now,
                    'certificador_id'      => $user->id,
                ]);

            // ── Certificación granular por ambiente ──────────────────────────────
            // Para cada orden certificada, registrar qué ambientes quedaron cubiertos.
            // Usa UPSERT para ser idempotente: si se re-certifica no genera duplicados.
            if ($ids->isNotEmpty()) {
                $ambientesPorOrden = Capsule::table('picking_detalles')
                    ->whereIn('orden_picking_id', $ids)
                    ->select('orden_picking_id', 'ambiente')
                    ->distinct()
                    ->get()
                    ->groupBy('orden_picking_id');

                foreach ($ambientesPorOrden as $ordenId => $rows) {
                    foreach ($rows as $row) {
                        $amb = $row->ambiente;
                        if (empty($amb)) continue;
                        try {
                            Capsule::table('picking_cert_ambiente')->updateOrInsert(
                                ['orden_picking_id' => (int)$ordenId, 'ambiente' => $amb],
                                [
                                    'estado'              => 'Certificada',
                                    'fecha_certificacion' => $now,
                                    'certificador_id'     => $user->id,
                                    'updated_at'          => $now,
                                ]
                            );
                        } catch (\Throwable $ignored) {}
                    }
                }

                // ── Marcar consolidados como Completada si todas sus órdenes están certificadas ─
                try {
                    $consolidadosActivos = Capsule::table('picking_consolidados')
                        ->where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->whereDate('fecha_consolidacion', date('Y-m-d'))
                        ->where('estado', '!=', 'Completada')
                        ->get();

                    foreach ($consolidadosActivos as $consol) {
                        $consolIds = json_decode($consol->orden_ids ?? '[]', true) ?: [];
                        if (empty($consolIds)) continue;
                        $pendientes = Capsule::table('orden_pickings')
                            ->whereIn('id', $consolIds)
                            ->where('estado_certificacion', '!=', 'Certificada')
                            ->count();
                        if ($pendientes === 0) {
                            Capsule::table('picking_consolidados')
                                ->where('id', $consol->id)
                                ->update([
                                    'estado'              => 'Completada',
                                    'certificador_id'     => $user->id,
                                    'fecha_certificacion' => $now,
                                    'updated_at'          => $now,
                                ]);
                        }
                    }
                } catch (\Throwable $ignored) {}
            }

            // Cargar todos los detalles de golpe — evita N+1 lazy loads
            $detalles = Capsule::table('picking_detalles')->whereIn('orden_picking_id', $ids)->get();
            $ordenMap = $ordenes->keyBy('id');

            foreach ($detalles as $d) {
                $diff = (float)$d->cantidad_pickeada - (float)($d->cantidad_certificada ?? 0);
                if (abs($diff) > 0.001) {
                    $o = $ordenMap[$d->orden_picking_id];
                    $this->audit(
                        $user, 'picking', 'novedad_certificacion',
                        'picking_detalles', $d->id,
                        ['pick' => $d->cantidad_pickeada],
                        ['cert' => $d->cantidad_certificada],
                        "Diferencia en certificación: Pedido {$o->numero_orden}, Producto ID {$d->producto_id}. Faltan " . abs($diff)
                    );
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

    // ── POST /api/packing/sesion/{id}/recertificar ───────────────────────────
    // Recupera una sesión Completada cuya certificación no se ejecutó correctamente.
    public function recertificar(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucId     = $user->sucursal_id;

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucId)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado !== 'Completada') {
            return $this->error($res, 'Solo se pueden recertificar sesiones ya finalizadas (Completada)', 409);
        }

        $ordenes = \App\Models\OrdenPicking::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucId)
            ->where('sucursal_entrega', $sesion->sucursal_entrega)
            ->where('estado', 'Completada')
            ->where('estado_certificacion', 'Pendiente')
            ->get();

        if ($ordenes->isEmpty()) {
            return $this->ok($res, ['ordenes_certificadas' => 0],
                "No hay órdenes pendientes de certificar para \"{$sesion->sucursal_entrega}\"");
        }

        // ── Validar empaque real ANTES de certificar ──────────────────────────
        // recertificar() existe para recuperar órdenes que SÍ se empacaron pero
        // cuya certificación falló por un error técnico — no para certificar
        // órdenes nuevas que llegaron a "Completada" después de que esta sesión
        // cerró y nunca pasaron por empaque. Sin esta validación, cualquier
        // orden pendiente de la misma sucursal_entrega quedaba certificada "en
        // el papel" sin un solo registro en packing_items (causa confirmada de
        // remisiones con pedidos completos faltantes).
        $sinEmpacar = [];
        $ordenesValidas = $ordenes->filter(function ($o) use (&$sinEmpacar) {
            $detalles = Capsule::table('picking_detalles')->where('orden_picking_id', $o->id)->get();
            $detalleIds = $detalles->pluck('id');
            $totalPickeado = (float) $detalles->sum('cantidad_pickeada');
            $totalEmpacado = $detalleIds->isNotEmpty()
                ? (float) Capsule::table('packing_items')->whereIn('picking_detalle_id', $detalleIds)->sum('cantidad')
                : 0.0;
            $completa = round($totalPickeado - $totalEmpacado, 3) <= 0.001;
            if (!$completa) {
                $sinEmpacar[] = [
                    'orden_id' => $o->id, 'numero_orden' => $o->numero_orden,
                    'numero_factura' => $o->numero_factura,
                    'pickeado' => $totalPickeado, 'empacado' => $totalEmpacado,
                ];
            }
            return $completa;
        })->values();

        if ($ordenesValidas->isEmpty()) {
            return $this->error($res, "Ninguna de las órdenes pendientes de \"{$sesion->sucursal_entrega}\" tiene su empaque completo en packing_items — no se certificó nada. Revise: " .
                collect($sinEmpacar)->pluck('numero_factura')->filter()->implode(', '), 422);
        }

        return Capsule::transaction(function() use ($sesion, $ordenesValidas, $sinEmpacar, $user, $res) {
            $now = date('Y-m-d H:i:s');
            foreach ($ordenesValidas as $o) {
                $o->estado_certificacion = 'Certificada';
                $o->fecha_certificacion  = $now;
                $o->certificador_id      = $user->id;
                $o->save();

                // Mantener picking_detalles consistente con la cabecera — mismo criterio
                // que ya usa autoPack() — para que reportes/remisiones que lean
                // cantidad_certificada directamente no queden en 0 tras esta recuperación manual.
                Capsule::table('picking_detalles')
                    ->where('orden_picking_id', $o->id)
                    ->update([
                        'cantidad_certificada' => Capsule::raw('cantidad_pickeada'),
                        'estado_certificacion' => 'Certificada',
                        'updated_at'           => $now,
                    ]);
            }
            $this->audit($user, 'packing', 'recertificar', 'packing_sesiones', $sesion->id,
                null, ['ordenes' => $ordenesValidas->pluck('id'), 'omitidas_sin_empacar' => $sinEmpacar],
                "Recertificación manual para {$sesion->sucursal_entrega}");

            $msg = "{$ordenesValidas->count()} orden(es) de \"{$sesion->sucursal_entrega}\" certificadas correctamente";
            if (!empty($sinEmpacar)) {
                $msg .= ". ATENCIÓN: se omitieron " . count($sinEmpacar) . " orden(es) sin empaque completo — pedido(s) "
                     . collect($sinEmpacar)->pluck('numero_factura')->filter()->implode(', ') . ". Revíselas manualmente.";
            }
            return $this->ok($res, [
                'ordenes_certificadas' => $ordenesValidas->count(),
                'ordenes_omitidas'     => $sinEmpacar,
            ], $msg);
        });
    }

    // ── PUT /api/packing/sesion/{id}/impresoras ───────────────────────────────
    public function actualizarImpresoras(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $data   = $r->getParsedBody() ?? [];
        $sesion = PackingSesion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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

        $desde    = $q['desde']    ?? null;
        $hasta    = $q['hasta']    ?? null;
        $fmDesde  = $q['fm_desde'] ?? null;   // filtro por fecha_movimiento de las órdenes
        $fmHasta  = $q['fm_hasta'] ?? null;
        $pedido   = $q['pedido']   ?? null;
        $sucursal = $q['sucursal'] ?? null;
        $sesionId = $q['sesion_id'] ?? null;

        $strAggPlanillas = $this->isPg()
            ? "STRING_AGG(DISTINCT op_ref.planilla_numero, ', ')"
            : "GROUP_CONCAT(DISTINCT op_ref.planilla_numero)";

        // Subquery: pu→pi (+ orden_pickings solo para listar planillas distintas por sesión,
        // necesario para detectar/reimprimir sesiones con varias planillas mezcladas)
        $counts = Capsule::table('packing_unidades as pu')
            ->leftJoin('packing_items as pi', 'pi.unidad_id', '=', 'pu.id')
            ->leftJoin('picking_detalles as pd_ref', 'pd_ref.id', '=', 'pi.picking_detalle_id')
            ->leftJoin('orden_pickings as op_ref', 'op_ref.id', '=', 'pd_ref.orden_picking_id')
            ->select(
                'pu.sesion_id',
                Capsule::raw('COUNT(DISTINCT pu.id) as num_unidades'),
                Capsule::raw('COALESCE(SUM(pi.cantidad), 0) as total_empacado'),
                Capsule::raw('COUNT(DISTINCT pd_ref.producto_id) as total_refs'),
                Capsule::raw("$strAggPlanillas as planillas")
            )
            ->groupBy('pu.sesion_id');

        $builder = Capsule::table('packing_sesiones as ps')
            ->leftJoinSub($counts, 'cnt', 'cnt.sesion_id', '=', 'ps.id')
            ->where('ps.empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('ps.sucursal_id', $user->sucursal_id)
            ->select(
                'ps.*',
                Capsule::raw('COALESCE(cnt.num_unidades, 0) as num_unidades'),
                Capsule::raw('COALESCE(cnt.total_empacado, 0) as total_empacado'),
                Capsule::raw('COALESCE(cnt.total_refs, 0) as total_refs'),
                Capsule::raw('cnt.planillas as planillas_str'),
                // fecha_movimiento del pedido asociado (para mostrar la fecha del pedido, no de la sesión)
                Capsule::raw("(SELECT MIN(op_m.fecha_movimiento)
                    FROM packing_unidades pu_m
                    JOIN packing_items pi_m ON pi_m.unidad_id = pu_m.id
                    JOIN picking_detalles pd_m ON pd_m.id = pi_m.picking_detalle_id
                    JOIN orden_pickings op_m ON op_m.id = pd_m.orden_picking_id
                    WHERE pu_m.sesion_id = ps.id) as fecha_movimiento_pedido")
            )
            ->orderBy('ps.created_at', 'desc')
            ->limit(100);

        // Filtro de fecha por defecto: últimos 30 días (evita escanear historial completo)
        // Excepción: cuando ctx=cert (llamada desde módulo de certificación) se devuelven todas
        $ctx = $q['ctx'] ?? '';
        if (!$desde && !$sesionId && !$fmDesde && $ctx !== 'cert') {
            $builder->where('ps.created_at', '>=', date('Y-m-d', strtotime('-30 days')));
        }

        if ($sesionId) $builder->where('ps.id', (int)$sesionId);
        if ($sucursal) $builder->where('ps.sucursal_entrega', 'like', "%{$sucursal}%");
        if ($desde) $builder->whereDate('ps.created_at', '>=', $desde);
        if ($hasta) $builder->whereDate('ps.created_at', '<=', $hasta);

        // Filtro por fecha_movimiento de las órdenes asociadas a la sesión
        if ($fmDesde || $fmHasta) {
            $builder->whereExists(function ($sub) use ($fmDesde, $fmHasta) {
                $sub->select(Capsule::raw(1))
                    ->from('packing_unidades as pu_fm')
                    ->join('packing_items as pi_fm', 'pi_fm.unidad_id', '=', 'pu_fm.id')
                    ->join('picking_detalles as pd_fm', 'pd_fm.id', '=', 'pi_fm.picking_detalle_id')
                    ->join('orden_pickings as op_fm', 'op_fm.id', '=', 'pd_fm.orden_picking_id')
                    ->whereColumn('pu_fm.sesion_id', 'ps.id');
                if ($fmDesde) $sub->where('op_fm.fecha_movimiento', '>=', $fmDesde);
                if ($fmHasta) $sub->where('op_fm.fecha_movimiento', '<=', $fmHasta);
            });
        }
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
        $rows = $rows->map(function ($row) {
            $row->planillas = array_values(array_filter(array_map('trim', explode(',', $row->planillas_str ?? ''))));
            unset($row->planillas_str);
            return $row;
        });
        return $this->ok($res, $rows);
    }

    // ── GET /api/packing/sesion/activa/{sucursal} ─────────────────────────────
    public function getSesionActiva(Request $r, Response $res, array $a): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursal   = urldecode($a['sucursal']);

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $sucursal)
            ->where('estado', 'EnProceso')
            ->latest()
            ->first();

        return $this->ok($res, $sesion ? ['sesion_id' => $sesion->id, 'tipo_empaque' => $sesion->tipo_empaque] : null);
    }

    // ── POST /api/packing/sesion/{id}/reset ──────────────────────────────────
    public function resetCertificacion(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucId     = $user->sucursal_id;

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucId)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'EnProceso') return $this->error($res, 'La sesión aún está en proceso', 409);

        return Capsule::transaction(function () use ($sesion, $user, $empresaId, $sucId, $res) {
            $unidadIds = PackingUnidad::where('sesion_id', $sesion->id)->pluck('id')->toArray();
            if (!empty($unidadIds)) {
                PackingItem::whereIn('unidad_id', $unidadIds)->delete();
                PackingUnidad::whereIn('id', $unidadIds)->delete();
            }

            $sesion->estado = 'Cancelada';
            $sesion->save();

            $ordenes = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucId)
                ->where('sucursal_entrega', $sesion->sucursal_entrega)
                ->where('estado_certificacion', 'Certificada')
                ->get();

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

            $this->audit($user, 'packing', 'reset_certificacion', 'packing_sesiones', $sesion->id,
                ['estado' => 'Completada'], ['estado' => 'Cancelada']);

            return $this->ok($res, null, 'Certificación anulada. Puede iniciar una nueva sesión.');
        });
    }

    // ── POST /api/packing/sesion/{id}/cancelar ───────────────────────────────
    // Cancela una sesión EnProceso sin afectar el estado de certificación de las órdenes
    public function cancelarSesion(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if (!in_array($sesion->estado, ['EnProceso', 'Completada'])) {
            return $this->error($res, 'Solo se pueden cancelar sesiones en proceso o completadas', 409);
        }

        return Capsule::transaction(function () use ($sesion, $user, $res) {
            $unidadIds = PackingUnidad::where('sesion_id', $sesion->id)->pluck('id')->toArray();
            if (!empty($unidadIds)) {
                PackingItem::whereIn('unidad_id', $unidadIds)->delete();
                PackingUnidad::whereIn('id', $unidadIds)->delete();
            }
            $sesion->estado = 'Cancelada';
            $sesion->save();
            $this->audit($user, 'packing', 'cancelar_sesion', 'packing_sesiones', $sesion->id,
                ['estado' => 'EnProceso'], ['estado' => 'Cancelada'],
                "Sesión cancelada manualmente para {$sesion->sucursal_entrega}");
            return $this->ok($res, null, 'Sesión de packing cancelada.');
        });
    }

    // ── GET /api/packing/sesion/{id}/remision ─────────────────────────────────
    public function getRemision(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);

        // Filtro opcional ?planilla=... — permite reimprimir la remisión de una sola
        // planilla cuando la sesión quedó con varias mezcladas (p.ej. sesiones que
        // absorbieron pedidos de días distintos antes del fix de _getProductosPickados).
        $planillaFiltro = trim($r->getQueryParams()['planilla'] ?? '');

        $empresa = Capsule::table('empresas')->find($empresaId);
        $cert    = Capsule::table('personal')->find($sesion->certificador_id);

        $unidades = PackingUnidad::where('sesion_id', $sesion->id)
            ->where('estado', 'Cerrada')
            ->orderBy('consecutivo')
            ->get();

        $unidadIds = $unidades->pluck('id')->toArray();

        // Fecha de la sesión (creación) — ancla de fallback
        $sesionFecha = date('Y-m-d', strtotime($sesion->created_at));

        // Obtener orden IDs vinculados a ESTA sesión via items → picking_detalles
        $sesionOrdenIds = empty($unidadIds) ? [] : Capsule::table('packing_items as pi')
            ->leftJoin('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
            ->whereIn('pi.unidad_id', $unidadIds)
            ->whereNotNull('pd.orden_picking_id')
            ->distinct()
            ->pluck('pd.orden_picking_id')
            ->toArray();

        if (!empty($sesionOrdenIds)) {
            // Cargar todas las órdenes vinculadas sin filtrar por fecha para asegurar que se muestren todos los pedidos empacados
            $ordenesObj = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereIn('id', $sesionOrdenIds)
                ->get(['id', 'numero_orden', 'numero_factura', 'planilla_numero', 'fecha_movimiento']);
        } else {
            // Fallback: órdenes certificadas del cliente en la fecha de creación de la sesión
            $ordenesObj = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('sucursal_entrega', $sesion->sucursal_entrega)
                ->where('estado_certificacion', 'Certificada')
                ->whereDate('fecha_movimiento', $sesionFecha)
                ->get(['id', 'numero_orden', 'numero_factura', 'planilla_numero', 'fecha_movimiento']);
        }

        if ($planillaFiltro !== '') {
            $ordenesObj = $ordenesObj->filter(fn($o) => trim($o->planilla_numero ?? '') === $planillaFiltro)->values();
            if ($ordenesObj->isEmpty()) {
                return $this->error($res, "No hay pedidos de \"{$planillaFiltro}\" en esta sesión");
            }
        }

        $ordenIds    = $ordenesObj->pluck('id')->toArray();

        $itemsRaw  = empty($unidadIds) ? collect() : Capsule::table('packing_items as pi')
            ->join('productos as p', 'p.id', '=', 'pi.producto_id')
            ->leftJoin('ambientes as a', 'a.id', '=', 'p.ambiente_id')
            ->leftJoin('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
            ->whereIn('pi.unidad_id', $unidadIds)
            ->when($planillaFiltro !== '', fn($q) => $q->whereIn('pd.orden_picking_id', $ordenIds))
            ->select([
                Capsule::raw("COALESCE(a.descripcion, 'Sin ambiente') as ambiente_nombre"),
                Capsule::raw("COALESCE(a.color, '#1e3a5f') as ambiente_color"),
                'pi.producto_id',
                'p.nombre as nombre',
                'p.codigo_interno as codigo',
                'p.unidades_caja',
                Capsule::raw('SUM(pi.cantidad) as cantidad'),
                Capsule::raw('SUM(COALESCE(pi.cantidad_cajas, 0)) as cantidad_cajas'),
                Capsule::raw('SUM(COALESCE(pi.saldo, 0)) as saldo'),
                Capsule::raw("MAX(COALESCE(pi.fecha_vencimiento, pd.fecha_vencimiento, (SELECT MIN(inv.fecha_vencimiento) FROM inventarios inv WHERE inv.producto_id = pi.producto_id AND inv.fecha_vencimiento IS NOT NULL AND inv.cantidad > 0 LIMIT 1))) as fecha_vencimiento"),
            ])
            ->groupBy('a.descripcion', 'a.color', 'pi.producto_id', 'p.nombre', 'p.codigo_interno', 'p.unidades_caja')
            ->orderByRaw("COALESCE(a.descripcion, 'Sin ambiente'), p.nombre")
            ->get()
            ->groupBy('ambiente_nombre');

        // Obtener planillas únicas reales asociadas
        $planillas   = $ordenesObj->pluck('planilla_numero')->map(fn($v) => trim($v ?? ''))->filter()->unique()->toArray();
        $planillaStr = !empty($planillas) ? implode(', ', $planillas) : 'N/A';

        // Obtener números de pedido del cliente (numero_factura = "Num Pedido" del cliente);
        // si una orden no tiene numero_factura (creada manualmente), se usa numero_orden como respaldo.
        $pedidos     = $ordenesObj->map(fn($o) => trim($o->numero_factura ?: $o->numero_orden ?: ''))->filter()->unique()->toArray();
        $pedidosStr  = !empty($pedidos) ? implode(', ', $pedidos) : 'N/A';

        // Agotados: productos que no pudieron pickearse por falta de inventario
        $agotados = empty($ordenIds) ? collect() : Capsule::table('picking_faltantes as pf')
            ->join('productos as p', 'p.id', '=', 'pf.producto_id')
            ->leftJoin('orden_pickings as op', 'op.id', '=', 'pf.orden_picking_id')
            ->leftJoin('personal as per', 'per.id', '=', 'op.auxiliar_id')
            ->whereIn('pf.orden_picking_id', $ordenIds)
            ->select([
                'p.codigo_interno as codigo',
                'p.nombre',
                'p.unidades_caja',
                Capsule::raw('SUM(pf.cantidad_solicitada) as solicitado'),
                Capsule::raw('SUM(pf.cantidad_faltante) as faltante'),
                Capsule::raw("STRING_AGG(DISTINCT COALESCE(pf.causa,'Sin stock'), ', ') as causa"),
                Capsule::raw("STRING_AGG(DISTINCT COALESCE(per.nombre,'Sin asignar'), ', ') as responsable"),
            ])
            ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'p.unidades_caja')
            ->get();

        $empNombre  = $empresa->nombre ?? 'WMS Fénix';
        $certNombre = $cert ? trim($cert->nombre) : 'N/A';
        $fecha      = date('d/m/Y H:i', strtotime($sesion->created_at));
        $tipoEmp    = strtoupper($sesion->tipo_empaque);
        $clienteNom = $sesion->sucursal_entrega;

        // Logo embebido como base64 para evitar problemas de ruta en ventana de impresión
        $logoFile = dirname(__DIR__, 2) . '/logo.jpg';
        $logoHtml = file_exists($logoFile)
            ? "<img src='data:image/jpeg;base64," . base64_encode(file_get_contents($logoFile)) . "' style='height:52px;object-fit:contain;display:block;margin-bottom:4px;' alt='Logo'>"
            : "<strong style='font-size:16px;color:#1e3a5f;'>{$empNombre}</strong>";

        $totalUnd      = 0;
        $totalCajas    = 0;
        $numCanastas   = count($unidades);
        $ambientesHtml = '';
        foreach ($itemsRaw as $ambNombre => $ambItems) {
            $ambColor    = $ambItems->first()->ambiente_color ?? '#1e3a5f';
            $subtotalUnd = 0;
            $subtotalCj  = 0;
            $rowsHtml    = '';
            foreach ($ambItems as $it) {
                $upc     = max(1, (int)($it->unidades_caja ?? 1));
                $cantRaw = (float)$it->cantidad;
                $cajasDB = (float)($it->cantidad_cajas ?? 0);
                $saldoDB = (float)($it->saldo ?? 0);

                if ($cajasDB > 0 || $saldoDB > 0) {
                    $cajas = $cajasDB;
                    $saldo = $saldoDB;
                    if ($saldo <= 0 && $upc > 1) {
                        $saldo = max(0, round(($cantRaw - $cajasDB) * $upc, 3));
                    }
                    $undTotal = round(($cajas * $upc) + $saldo, 3);
                } elseif ($upc > 1) {
                    $cajas    = (int)floor($cantRaw);
                    $saldo    = round(($cantRaw - floor($cantRaw)) * $upc, 3);
                    $undTotal = round($cajas * $upc + $saldo, 3);
                } else {
                    $cajas    = $cantRaw;
                    $saldo    = 0;
                    $undTotal = $cantRaw;
                }

                $fv      = $it->fecha_vencimiento ? date('d/m/Y', strtotime($it->fecha_vencimiento)) : '—';
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
            $totalUnd    += $subtotalUnd;
            $totalCajas  += $subtotalCj;
            $ambientesHtml .= "
            <div class='ambiente-block'>
              <table style='table-layout:fixed;width:100%;'>
                <colgroup>
                  <col style='width:12%;'>
                  <col style='width:45%;'>
                  <col style='width:9%;'>
                  <col style='width:9%;'>
                  <col style='width:9%;'>
                  <col style='width:16%;'>
                </colgroup>
                <thead>
                  <tr class='ambiente-header-row'><th colspan='6'>{$ambNombre} &mdash; {$subtotalCj} cj / {$subtotalUnd} und</th></tr>
                  <tr>
                    <th>C&oacute;digo</th><th>Producto</th>
                    <th style='text-align:right'>Cajas</th>
                    <th style='text-align:right'>Saldo</th>
                    <th style='text-align:right'>Und.</th>
                    <th style='text-align:center'>F. Venc.</th>
                  </tr>
                </thead>
                <tbody>{$rowsHtml}</tbody>
              </table>
            </div>";
        }

        // Sección HTML de agotados
        $agotadosHtml = '';
        if ($agotados->isNotEmpty()) {
            $rowsAgo = '';
            foreach ($agotados as $ag) {
                $resp = htmlspecialchars($ag->responsable ?? 'Sin asignar', ENT_QUOTES);
                $caus = htmlspecialchars($ag->causa ?? 'Sin stock', ENT_QUOTES);

                // faltante/solicitado vienen en CAJAS (posiblemente fraccionarias si el
                // déficit no completó una caja) — se descomponen en cajas+saldos para no
                // mostrar un número de cajas confuso como "0.25".
                $upcAg = max(1, (int)($ag->unidades_caja ?? 1));
                $faltanteTxt = (float)$ag->faltante;
                if ($upcAg > 1) {
                    $faltCajas = (int) floor($faltanteTxt);
                    $faltSaldo = round(($faltanteTxt - $faltCajas) * $upcAg, 3);
                    $partesFalt = [];
                    if ($faltCajas > 0) $partesFalt[] = "{$faltCajas} cj";
                    if ($faltSaldo > 0) $partesFalt[] = "{$faltSaldo} suelt.";
                    $faltanteTxt = implode(' + ', $partesFalt) ?: '0';
                }

                $rowsAgo .= "<tr>
                  <td>{$ag->codigo}</td>
                  <td>{$ag->nombre}</td>
                  <td style='text-align:right'>{$ag->solicitado}</td>
                  <td style='text-align:right;color:#c00;font-weight:bold'>{$faltanteTxt}</td>
                  <td>{$caus}</td>
                  <td style='color:#b45309;font-weight:700'>{$resp}</td>
                </tr>";
            }
            $agotadosHtml = "
            <div class='agotados-section'>
              <div class='agotados-header'>&#9888; REFERENCIAS AGOTADAS (sin inventario en asignación)</div>
              <table>
                <thead><tr>
                  <th>Código</th><th>Producto</th><th>Solicitado</th><th>Faltante</th><th>Causa</th><th>Responsable</th>
                </tr></thead>
                <tbody>{$rowsAgo}</tbody>
              </table>
            </div>";
        }

        $numAmbientes = $itemsRaw->count();
        $novedadesHtml = "
<div class='novedades-section'>
  <div class='novedades-header'>NOVEDADES DE RECEPCI&Oacute;N</div>
  <table style='table-layout:fixed;width:100%;'>
    <colgroup>
      <col style='width:12%;'>
      <col style='width:38%;'>
      <col style='width:10%;'>
      <col style='width:40%;'>
    </colgroup>
    <thead><tr>
      <th>C&oacute;digo</th>
      <th>Descripci&oacute;n</th>
      <th style='text-align:right;'>Cantidad</th>
      <th>Motivo</th>
    </tr></thead>
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
<title>Remisi&#243;n &mdash; {$clienteNom}</title>
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
  .ambiente-block{border:1px solid #cbd5e1;border-radius:4px;overflow:hidden}
  .ambiente-header-row th{background:#000;color:#fff;padding:5px 10px;font-weight:700;font-size:10.5px;letter-spacing:.2px;text-align:left;border:none}
  table{width:100%;border-collapse:collapse}
  thead{display:table-header-group}
  tr{page-break-inside:avoid}
  th,td{border:1px solid #e2e8f0;padding:4px 6px;font-size:9.5px;text-align:left;vertical-align:middle}
  th{background:#f1f5f9;font-weight:700;color:#334155;white-space:nowrap}
  tr:nth-child(even) td{background:#f8fafc}
  .totales{border-top:3px solid #1e3a5f;padding:8px 0;font-weight:700;font-size:12px;margin-top:10px;color:#1e3a5f}
  .agotados-section{margin-top:10px;border:2px solid #dc2626;border-radius:4px;overflow:hidden;page-break-inside:avoid}
  .agotados-header{background:#dc2626;color:#fff;padding:5px 8px;font-weight:700;font-size:10px}
  .agotados-section td:nth-child(4){color:#dc2626;font-weight:700}
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
    <strong>Sesi&oacute;n # {$sesion->id}</strong><br>
    Fecha: {$fecha}
  </div>
</div>
<div class='info-grid'>
  <span class='campo'><span class='lbl'>Cliente / Sucursal:</span>{$clienteNom}</span>
  <span class='campo'><span class='lbl'>Tipo empaque:</span>{$tipoEmp}</span>
  <span class='campo'><span class='lbl'>Planilla:</span>{$planillaStr}</span>
  <span class='campo'><span class='lbl'>N&ordm; Pedidos:</span>{$pedidosStr}</span>
  <span class='campo'><span class='lbl'>Certificador:</span>{$certNombre}</span>
</div>
<div class='ambientes-grid'>{$ambientesHtml}</div>
{$agotadosHtml}
{$novedadesHtml}
<div class='totales'>TOTAL GENERAL: {$numCanastas} {$tipoEmp}(S) &mdash; {$totalCajas} CAJAS / {$totalUnd} UNIDADES CERTIFICADAS</div>
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

    // ── GET /api/packing/sesion/{id}/etiquetas (todas las canastas, 1 por página) ─
    public function getEtiquetasTodas(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);

        $empresa    = Capsule::table('empresas')->find($empresaId);
        $cert       = Capsule::table('personal')->find($sesion->certificador_id);
        $empNombre  = $empresa->nombre ?? 'WMS Fénix';
        $certNombre = $cert ? trim($cert->nombre) : '—';
        $tipoLabel  = strtoupper($sesion->tipo_empaque);
        $fecha      = date('d/m/Y H:i', strtotime($sesion->created_at));

        $logoFileE = dirname(__DIR__, 2) . '/logo.jpg';
        $logoB64E  = file_exists($logoFileE) ? base64_encode(file_get_contents($logoFileE)) : null;
        $logoTagE  = $logoB64E
            ? "<img src='data:image/jpeg;base64,{$logoB64E}' style='height:38px;object-fit:contain;display:block;margin:0 auto 3px;' alt='Logo'>"
            : "<strong>{$empNombre}</strong>";

        $unidades = PackingUnidad::where('sesion_id', $sesion->id)
            ->where('estado', 'Cerrada')->orderBy('consecutivo')->get();

        $unidadIds = $unidades->pluck('id')->toArray();
        $itemsMap  = empty($unidadIds) ? collect() : Capsule::table('packing_items as pi')
            ->join('productos as p', 'p.id', '=', 'pi.producto_id')
            ->leftJoin('personal as sep', 'sep.id', '=', 'pi.separador_id')
            ->leftJoin('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
            ->whereIn('pi.unidad_id', $unidadIds)
            ->select([
                'pi.unidad_id', 'p.codigo_interno as codigo', 'p.nombre',
                Capsule::raw('COALESCE(SUM(pd.cantidad_solicitada),0) as total_pedido'),
                Capsule::raw('SUM(pi.cantidad) as total_cert'),
                Capsule::raw("COALESCE(MAX(sep.nombre),'—') as separador"),
            ])
            ->groupBy('pi.unidad_id', 'p.codigo_interno', 'p.nombre')
            ->get()->groupBy('unidad_id');

        $bloques = '';
        foreach ($unidades as $idx => $u) {
            $items = $itemsMap->get($u->id, collect());
            $rows  = '';
            foreach ($items as $it) {
                $rows .= "<tr><td>{$it->codigo}</td><td style='text-align:left'>{$it->nombre}</td>
                    <td style='text-align:center'>{$it->total_pedido}</td>
                    <td style='text-align:center'>{$it->total_cert}</td>
                    <td style='text-align:center'>{$it->separador}</td></tr>";
            }
            $pb = $idx < count($unidades) - 1 ? "page-break-after:always;" : "";
            $bloques .= "<div style='{$pb}padding:6mm;'>
              <div style='text-align:center;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:8px;'>
                {$logoTagE}<br>
                <span style='font-size:10px;'>{$sesion->sucursal_entrega} — {$fecha}</span><br>
                <span style='display:inline-block;background:#1e3a5f;color:#fff;font-size:20px;font-weight:900;padding:3px 18px;border-radius:5px;margin:5px 0;'>
                  {$tipoLabel} #{$u->consecutivo}
                </span>
              </div>
              <table style='width:100%;border-collapse:collapse;font-size:9px;'>
                <thead><tr style='background:#1e3a5f;color:#fff;'>
                  <th style='padding:3px;'>Código</th><th style='padding:3px;text-align:left;'>Descripción</th>
                  <th style='padding:3px;'>T.Ped</th><th style='padding:3px;'>T.Cert</th><th style='padding:3px;'>Separó</th>
                </tr></thead>
                <tbody>{$rows}</tbody>
              </table>
              <div style='display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;'>
                <div style='border-top:1px solid #000;padding-top:3px;text-align:center;font-size:9px;'>Certificador<br><strong>{$certNombre}</strong></div>
                <div style='border-top:1px solid #000;padding-top:3px;text-align:center;font-size:9px;'>Transportador</div>
              </div>
            </div>";
        }

        $html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Etiquetas — {$sesion->sucursal_entrega}</title>
<style>
  @page{size:5.5in 8.5in;margin:5mm}
  body{font-family:Arial,sans-serif;font-size:11px;margin:0;}
  .no-print{margin:10px;}
  @media print{.no-print{display:none}}
</style></head><body>
<div class='no-print'>
  <button onclick='window.print()' style='padding:8px 20px;font-size:14px;cursor:pointer;margin-right:10px;'>
    🖨 Imprimir Todas
  </button>
  <span style='font-size:12px;color:#555;'>{$tipoLabel}S: " . count($unidades) . " — {$sesion->sucursal_entrega}</span>
</div>
{$bloques}
</body></html>";

        $body = $res->getBody();
        $body->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    // ── POST /api/packing/unidad/{id}/imprimir — desactivado, usar impresión browser ──
    public function imprimirEtiquetaRed(Request $r, Response $res, array $a): Response
    {
        return $this->ok($res, ['browser' => true],
            'Impresión de red desactivada. Use la opción de impresión desde el navegador (escritorio).');
    }

    public function _imprimirEtiquetaRedLegacy(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $unidad = PackingUnidad::find((int)$a['id']);
        if (!$unidad) return $this->notFound($res);

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($unidad->sesion_id);
        if (!$sesion) return $this->forbidden($res);

        $impresoraId = $sesion->impresora_sticker_id;
        if (!$impresoraId) return $this->error($res, 'No hay impresora configurada en la sesión', 422);

        $impresora = Capsule::table('impresoras')->where('id', $impresoraId)->first();
        if (!$impresora) return $this->error($res, 'Impresora no encontrada', 404);

        $empresa  = Capsule::table('empresas')->find($empresaId);
        $empNombre= $empresa->nombre ?? 'WMS Fénix';
        $cert    = Capsule::table('personal')->find($sesion->certificador_id);
        $certNom = $cert ? trim($cert->nombre) : '—';
        $tipoLbl = strtoupper($sesion->tipo_empaque);
        $fecha   = date('d/m/Y H:i');

        $items = Capsule::table('packing_items as pi')
            ->join('productos as p', 'p.id', '=', 'pi.producto_id')
            ->leftJoin('personal as sep',  'sep.id',  '=', 'pi.separador_id')
            ->leftJoin('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
            ->leftJoin('personal as sep2', 'sep2.id', '=', 'pd.auxiliar_id')
            ->where('pi.unidad_id', $unidad->id)
            ->select([
                'p.codigo_interno as codigo',
                'p.nombre',
                Capsule::raw('COALESCE(p.unidades_caja, 1) as unidades_caja'),
                'pi.cantidad',
                'pi.cantidad_cajas',
                'pi.saldo',
                Capsule::raw("COALESCE(sep.nombre, sep2.nombre, '—') as separador"),
            ])
            ->get();

        // Construir lista de items con desglose cajas/saldo
        $itemsData = [];
        foreach ($items as $it) {
            $upc  = max(1, (int)$it->unidades_caja);
            $cant = (float)$it->cantidad;
            $cj   = (int)$it->cantidad_cajas;
            $sl   = round((float)$it->saldo, 2);
            if ($cj === 0 && $sl == 0.0 && $cant > 0) {
                $cj = (int)floor($cant);
                $sl = round(($cant - $cj) * $upc, 2);
            }
            $certLabel = $upc > 1
                ? "{$cj} cj" . ($sl > 0 ? "+{$sl}" : '')
                : (string)round($cant, 3);
            $itemsData[] = [
                'codigo'    => $it->codigo,
                'nombre'    => $it->nombre,
                'cert'      => $certLabel,
                'separador' => $it->separador,
            ];
        }

        $tipoImp = strtoupper($impresora->lenguaje ?? $impresora->tipo ?? 'ZPL');

        if ($tipoImp === 'TSC') {
            // Impresora térmica TSC — TSPL por socket
            $data = [
                'empresa'      => $empNombre,
                'cliente'      => $sesion->sucursal_entrega,
                'tipo'         => $tipoLbl,
                'consecutivo'  => $unidad->consecutivo,
                'fecha'        => $fecha,
                'certificador' => $certNom,
                'items'        => $itemsData,
            ];
            $payload = \App\Helpers\PrintHelper::generateTSPLPacking($data);
            $result  = \App\Helpers\PrintHelper::sendToPrinter($impresora->ip, $impresora->puerto, $payload);
            if ($result['error']) return $this->error($res, $result['message'], 500);
            return $this->ok($res, null, "{$tipoLbl} #{$unidad->consecutivo} enviada a {$impresora->nombre}");

        } elseif ($tipoImp === 'ZEBRA') {
            // Impresora Zebra — ZPL por socket
            $zplTxt = static function (string $s, int $max = 40): string {
                $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
                return substr(preg_replace('/[^A-Za-z0-9 .,;:\-#+\/\(\)%#@!?]/', ' ', $s), 0, $max);
            };
            $payload  = "~JA\r\n^XA\r\n";
            $payload .= "^FO30,25^A0N,30,30^FD" . $zplTxt($empNombre, 35) . "^FS\r\n";
            $payload .= "^FO30,60^A0N,22,22^FD" . $zplTxt($sesion->sucursal_entrega, 40) . "^FS\r\n";
            $payload .= "^FO30,86^A0N,18,18^FD{$fecha}^FS\r\n";
            $payload .= "^FO30,108^A0N,52,52^FD{$tipoLbl} #{$unidad->consecutivo}^FS\r\n";
            $payload .= "^FO30,170^GB752,3,3^FS\r\n";
            $y = 180;
            foreach ($itemsData as $it) {
                $desc     = $zplTxt($it['nombre'], 36);
                $payload .= "^FO30,{$y}^A0N,17,17^FD{$it['codigo']}  {$desc}^FS\r\n";
                $y += 21;
                $payload .= "^FO50,{$y}^A0N,17,17^FDCert: {$it['cert']}  Sep: " . $zplTxt($it['separador'], 18) . "^FS\r\n";
                $y += 25;
            }
            $payload .= "^FO30,{$y}^GB752,3,3^FS\r\n";
            $y += 12;
            $payload .= "^FO30,{$y}^A0N,19,19^FDCertificador: " . $zplTxt($certNom, 25) . "^FS\r\n";
            $payload .= "^XZ\r\n";
            $result = \App\Helpers\PrintHelper::sendToPrinter($impresora->ip, $impresora->puerto, $payload);
            if ($result['error']) return $this->error($res, $result['message'], 500);
            return $this->ok($res, null, "{$tipoLbl} #{$unidad->consecutivo} enviada a {$impresora->nombre}");

        } elseif ($tipoImp === 'PCL') {
            // Impresora láser PCL (Ricoh, HP, etc.) — PCL por socket puerto 9100
            $data = [
                'empresa'      => $empNombre,
                'cliente'      => $sesion->sucursal_entrega,
                'tipo'         => $tipoLbl,
                'consecutivo'  => $unidad->consecutivo,
                'fecha'        => $fecha,
                'certificador' => $certNom,
                'items'        => $itemsData,
            ];
            $payload = \App\Helpers\PrintHelper::generatePCLPacking($data);
            $result  = \App\Helpers\PrintHelper::sendToPrinter($impresora->ip, $impresora->puerto, $payload);
            if ($result['error']) return $this->error($res, $result['message'], 500);
            return $this->ok($res, null, "{$tipoLbl} #{$unidad->consecutivo} enviada a {$impresora->nombre}");

        } elseif ($tipoImp === 'WINDOWS') {
            // Impresora Windows (laser/inkjet) — imprime desde el servidor via PowerShell + driver
            $html    = $this->_buildPackingHtml($unidad, $empresaId);
            $tmpHtml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_eti_' . $unidad->id . '_' . time() . '.html';
            file_put_contents($tmpHtml, $html);
            $fileUri     = 'file:///' . str_replace('\\', '/', realpath($tmpHtml));
            $printerName = $impresora->nombre; // nombre exacto en Windows (ej: "logistica")
            $tmpHtmlEsc  = str_replace("'", "''", $tmpHtml);
            $ps1File     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_print_' . $unidad->id . '.ps1';

            $ps1 = '$net  = New-Object -ComObject WScript.Network' . "\r\n"
                 . '$prev = (Get-WmiObject Win32_Printer -Filter "Default = TRUE").Name' . "\r\n"
                 . '$net.SetDefaultPrinter(\'' . addslashes($printerName) . '\')' . "\r\n"
                 . 'Start-Sleep -Milliseconds 600' . "\r\n"
                 . 'Start-Process -FilePath \'' . addslashes($fileUri) . '\' -Verb Print -Wait' . "\r\n"
                 . 'Start-Sleep -Seconds 8' . "\r\n"
                 . 'if ($prev) { $net.SetDefaultPrinter($prev) }' . "\r\n"
                 . 'Start-Sleep -Seconds 1' . "\r\n"
                 . 'Remove-Item -Path \'' . addslashes($tmpHtmlEsc) . '\' -Force -ErrorAction SilentlyContinue' . "\r\n"
                 . 'Remove-Item -Path \'' . addslashes(str_replace("'", "''", $ps1File)) . '\' -Force -ErrorAction SilentlyContinue' . "\r\n";

            file_put_contents($ps1File, $ps1);
            $ps1Win = str_replace('/', '\\', $ps1File);
            exec("start /B powershell -WindowStyle Hidden -ExecutionPolicy Bypass -File \"{$ps1Win}\"");

            return $this->ok($res, null, "{$tipoLbl} #{$unidad->consecutivo} enviada a {$impresora->nombre}");

        } else {
            // Tipo desconocido → el cliente abre el HTML en su propio navegador
            return $this->ok($res,
                ['browser' => true, 'url' => "/packing/unidad/{$unidad->id}/etiqueta"],
                "{$tipoLbl} #{$unidad->consecutivo} listo para imprimir"
            );
        }
    }

    // ── GET /api/packing/unidad/{id}/etiqueta ────────────────────────────────
    public function getEtiquetaCanasta(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $unidad = PackingUnidad::find((int)$a['id']);
        if (!$unidad) return $this->notFound($res);

        $sesion = PackingSesion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($unidad->sesion_id);
        if (!$sesion) return $this->forbidden($res);

        $html = $this->_buildPackingHtml($unidad, $empresaId);
        $body = $res->getBody();
        $body->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    // ── GET /api/packing/sesion/{id}/agotados ─────────────────────────────────
    public function agotadosSesion(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $sesionId  = (int)$a['id'];
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $sesion = Capsule::table('packing_sesiones')
            ->where('empresa_id', $empresaId)
            ->where('id', $sesionId)
            ->first();
        if (!$sesion) return $this->notFound($res);

        $ordenIds = Capsule::table('orden_pickings')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $sesion->sucursal_entrega)
            ->where('estado_certificacion', 'Certificada')
            ->pluck('id')
            ->toArray();

        if (empty($ordenIds)) return $this->ok($res, []);

        $agotados = Capsule::table('picking_faltantes as pf')
            ->join('productos as p', 'p.id', '=', 'pf.producto_id')
            ->whereIn('pf.orden_picking_id', $ordenIds)
            ->select([
                'p.codigo_interno as codigo',
                'p.nombre as producto_nombre',
                Capsule::raw('SUM(pf.cantidad_solicitada) as cantidad_solicitada'),
                Capsule::raw('SUM(pf.cantidad_faltante) as cantidad_faltante'),
                Capsule::raw("STRING_AGG(DISTINCT COALESCE(pf.causa,'Sin stock'), ', ') as causa"),
                Capsule::raw('MIN(pf.created_at) as fecha'),
            ])
            ->groupBy('p.id', 'p.codigo_interno', 'p.nombre')
            ->get();

        return $this->ok($res, $agotados);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Genera el HTML de la etiqueta de packing (usado por getEtiquetaCanasta y la impresión WINDOWS).
     */
    private function _buildPackingHtml(PackingUnidad $unidad, int $empresaId): string
    {
        $sesion     = Capsule::table('packing_sesiones')->find($unidad->sesion_id);
        $empresa    = Capsule::table('empresas')->find($empresaId);
        $cert       = Capsule::table('personal')->find($sesion->certificador_id ?? 0);
        $empNombre  = $empresa->nombre ?? 'WMS Fénix';
        $certNombre = $cert ? trim($cert->nombre) : '—';
        $tipoLabel  = strtoupper($sesion->tipo_empaque ?? 'CANASTA');
        $fecha      = date('d/m/Y H:i', strtotime($sesion->created_at));

        $logoFilePh = dirname(__DIR__, 2) . '/logo.jpg';
        $logoHtmlPh = file_exists($logoFilePh)
            ? "<img src='data:image/jpeg;base64," . base64_encode(file_get_contents($logoFilePh)) . "' style='height:42px;object-fit:contain;display:block;margin-bottom:4px;' alt='Logo'>"
            : "<strong style='font-size:15px;color:#1e3a5f;'>{$empNombre}</strong>";

        $items = Capsule::table('packing_items as pi')
            ->join('productos as p', 'p.id', '=', 'pi.producto_id')
            ->leftJoin('personal as sep',  'sep.id',  '=', 'pi.separador_id')
            ->leftJoin('picking_detalles as pd', 'pd.id', '=', 'pi.picking_detalle_id')
            ->leftJoin('personal as sep2', 'sep2.id', '=', 'pd.auxiliar_id')
            ->leftJoin('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->where('pi.unidad_id', $unidad->id)
            ->select([
                'p.codigo_interno as codigo',
                'p.nombre',
                Capsule::raw('COALESCE(p.unidades_caja, 1) as unidades_caja'),
                Capsule::raw('COALESCE(SUM(pd.cantidad_solicitada), 0) as total_pedido'),
                Capsule::raw('SUM(pi.cantidad)         as total_cert_caj'),
                Capsule::raw('SUM(pi.cantidad_cajas)   as total_cajas'),
                Capsule::raw('SUM(pi.saldo)             as total_saldo'),
                Capsule::raw("COALESCE(MAX(sep.nombre), MAX(sep2.nombre), '—') as separador"),
            ])
            ->groupBy('p.codigo_interno', 'p.nombre', 'p.unidades_caja')
            ->get();

        $rowsHtml = '';
        foreach ($items as $it) {
            $upc      = max(1, (int)$it->unidades_caja);
            $totalCaj = (float)$it->total_cert_caj;
            $cj       = (int)$it->total_cajas;
            $sl       = round((float)$it->total_saldo, 2);
            if ($cj === 0 && $sl == 0.0 && $totalCaj > 0) {
                $cj = (int)floor($totalCaj);
                $sl = round(($totalCaj - $cj) * $upc, 2);
            }
            $certLabel = $upc > 1
                ? "{$cj} cj" . ($sl > 0 ? " + {$sl} suelt." : '')
                : round($totalCaj, 3) . ' uds';
            $pedLabel  = $upc > 1
                ? (int)$it->total_pedido . ' cj'
                : round((float)$it->total_pedido, 3) . ' uds';
            $rowsHtml .= "<tr>
                <td>{$it->codigo}</td>
                <td style='text-align:left'>{$it->nombre}</td>
                <td style='text-align:center'>{$pedLabel}</td>
                <td style='text-align:center'><strong>{$certLabel}</strong></td>
                <td style='text-align:center'>{$it->separador}</td>
            </tr>";
        }

        return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>{$tipoLabel} #{$unidad->consecutivo} &mdash; {$sesion->sucursal_entrega}</title>
<style>
  @page{size:5.5in 8.5in;margin:10mm 12mm}
  @media print{.no-print{display:none!important} body{margin:0}}
  body{font-family:Arial,sans-serif;font-size:11px;margin:0;color:#1a1a1a;padding:8px}
  .hdr{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #1e3a5f;padding-bottom:8px;margin-bottom:10px}
  .hdr-left h3{margin:0 0 2px;font-size:13px;color:#1e3a5f} .hdr-left p{margin:1px 0;font-size:9.5px;color:#555}
  .badge{display:inline-block;background:#1e3a5f;color:#fff;font-size:22px;font-weight:900;padding:5px 18px;border-radius:6px;margin-top:4px}
  .info{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;font-size:10px;margin-bottom:10px;background:#f8fafc;padding:8px;border-radius:4px}
  .info b{color:#334155;text-transform:uppercase;font-size:9px}
  table{width:100%;border-collapse:collapse;margin-top:6px}
  th{background:#1e3a5f;color:#fff;padding:5px 4px;font-size:9.5px;text-align:center}
  td{border:1px solid #e2e8f0;padding:3px 4px;font-size:9.5px}
  tr:nth-child(even) td{background:#f8fafc}
  .firma{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:28px}
  .fline{border-top:2px solid #1e3a5f;padding-top:4px;text-align:center;font-size:9.5px;color:#334155}
  .no-print{padding:8px 0;margin-bottom:10px}
  .no-print button{padding:7px 18px;font-size:13px;cursor:pointer;background:#1e3a5f;color:#fff;border:none;border-radius:5px}
</style></head><body>
<div class='no-print'>
  <button onclick='window.print()'>&#128424; Imprimir / Guardar PDF</button>
</div>
</div>
<div class='hdr'>
  <div class='hdr-left'>
    {$logoHtmlPh}
    <p>ETIQUETA DE PACKING &mdash; {$sesion->sucursal_entrega}</p>
    <p>Fecha: {$fecha}</p>
  </div>
  <div class='badge'>{$tipoLabel} #{$unidad->consecutivo}</div>
</div>
<div class='info'>
  <b>Cliente:</b><span>{$sesion->sucursal_entrega}</span>
  <b>Certificador:</b><span>{$certNombre}</span>
  <b>Total uds:</b><span>{$unidad->total_unidades}</span>
</div>
<table>
  <thead><tr>
    <th>C&oacute;digo</th><th>Descripci&oacute;n</th><th>Tot.Ped</th><th>Tot.Cert</th><th>Separ&oacute;</th>
  </tr></thead>
  <tbody>{$rowsHtml}</tbody>
</table>
<div class='firma'>
  <div class='fline'>Certificador<br><strong>{$certNombre}</strong></div>
  <div class='fline'>Transportador</div>
</div>
<script>window.onload = () => { setTimeout(() => window.print(), 500); }<\/script>
</body></html>";
    }

    // ── POST /api/packing/autopack ────────────────────────────────────────────────
    public function autoPack(Request $r, Response $res): Response
    {
        $user      = $r->getAttribute('user');
        $body      = $r->getParsedBody() ?? [];
        $sucursal  = $body['sucursal_entrega'] ?? null;
        $tipoEmp   = $body['tipo_empaque'] ?? 'canasta';
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        if (!$sucursal) return $this->badRequest($res, 'Falta sucursal_entrega');

        return Capsule::transaction(function () use ($user, $empresaId, $sucursal, $tipoEmp, $res) {
            // Obtener o crear sesión
            $sesion = PackingSesion::firstOrCreate(
                [
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $user->sucursal_id,
                    'sucursal_entrega' => $sucursal,
                    'estado' => 'EnProceso'
                ],
                [
                    'tipo_empaque' => $tipoEmp,
                    'certificador_id' => $user->id,
                ]
            );

            // Obtener productos pendientes
            $pickeados = $this->_getProductosPickados($empresaId, $user->sucursal_id, $sucursal);
            $empacados = $this->_getProductosEmpacados($sesion->id);

            $pendientes = [];
            foreach ($pickeados as $pid => $pick) {
                $empQty = $empacados[$pid] ?? 0;
                $pend = round((float)$pick->total_pickeado - $empQty, 3);
                if ($pend > 0.001) {
                    $pendientes[] = [
                        'producto_id' => $pid,
                        'cantidad'    => $pend,
                        'upc'         => $pick->unidades_caja ?? 1
                    ];
                }
            }

            if (!empty($pendientes)) {
                // Si hay unidad abierta, usamos esa o creamos una nueva
                $unidad = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Abierta')->first();
                if (!$unidad) {
                    $consecutivo = PackingUnidad::where('sesion_id', $sesion->id)->max('consecutivo') ?? 0;
                    $unidad = PackingUnidad::create([
                        'sesion_id'   => $sesion->id,
                        'consecutivo' => $consecutivo + 1,
                        'estado'      => 'Abierta',
                    ]);
                }

                $totalAgregado = 0;
                foreach ($pendientes as $p) {
                    $cant  = $p['cantidad'];
                    $upc   = $p['upc'];
                    $cajas = $upc > 1 ? floor($cant / $upc) : 0;
                    $saldo = $upc > 1 ? round($cant - ($cajas * $upc), 3) : 0;
                    if ($upc <= 1) { $cajas = 0; $saldo = 0; }

                    [$lote, $fechaVenc, $separadorId, $detalleId] = $this->_resolveFromPicking(
                        $p['producto_id'], $sucursal, $empresaId, $user->sucursal_id
                    );

                    PackingItem::create([
                        'unidad_id'          => $unidad->id,
                        'picking_detalle_id' => $detalleId,
                        'producto_id'        => $p['producto_id'],
                        'lote'               => $lote,
                        'fecha_vencimiento'  => $fechaVenc,
                        'separador_id'       => $separadorId,
                        'cantidad'           => $cant,
                        'cantidad_cajas'     => $cajas,
                        'saldo'              => $saldo,
                    ]);
                    $totalAgregado += $cant;
                }

                // Cerrar unidad
                $unidad->estado = 'Cerrada';
                $unidad->total_unidades = $totalAgregado;
                $unidad->closed_at = date('Y-m-d H:i:s');
                $unidad->save();
            }

            // Finalizar sesión y certificar
            $openUnidad = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Abierta')->first();
            if ($openUnidad) {
                if (PackingItem::where('unidad_id', $openUnidad->id)->count() === 0) {
                    $openUnidad->delete();
                } else {
                    $openUnidad->estado = 'Cerrada';
                    $openUnidad->total_unidades = (float) PackingItem::where('unidad_id', $openUnidad->id)->sum('cantidad');
                    $openUnidad->closed_at = date('Y-m-d H:i:s');
                    $openUnidad->save();
                }
            }

            $sesion->estado = 'Completada';
            $sesion->save();

            // Actualizar órdenes a Certificada
            $ordenesAll = OrdenPicking::where('empresa_id', $empresaId)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('sucursal_entrega', $sucursal)
                ->where('estado', 'Completada')
                ->whereIn('estado_certificacion', ['Pendiente', 'EnProceso'])
                ->get();

            // Solo auto-certificar órdenes cuyas líneas estén todas en estado terminal
            // (Completada/Faltante/Agotado). Líneas en Pendiente/EnProceso indican
            // ambientes que aún no terminaron el picking → no se deben marcar como Certificadas.
            $ordenesCompletas = Capsule::table('picking_detalles')
                ->whereIn('orden_picking_id', $ordenesAll->pluck('id'))
                ->groupBy('orden_picking_id')
                ->havingRaw("SUM(CASE WHEN estado NOT IN ('Completada','Completado','Faltante','Agotado','Certificada','Certificado') THEN 1 ELSE 0 END) = 0")
                ->pluck('orden_picking_id');

            $ordenes = $ordenesAll->filter(fn($o) => $ordenesCompletas->contains($o->id));

            foreach ($ordenes as $o) {
                $o->estado_certificacion = 'Certificada';
                $o->fecha_certificacion  = date('Y-m-d H:i:s');
                $o->certificador_id      = $user->id;
                $o->save();

                Capsule::table('picking_detalles')
                    ->where('orden_picking_id', $o->id)
                    ->update([
                        'cantidad_certificada' => Capsule::raw('cantidad_pickeada'),
                        'estado_certificacion' => 'Certificada',
                        'updated_at'           => date('Y-m-d H:i:s'),
                    ]);
            }

            $this->audit($user, 'packing', 'autopack', 'packing_sesiones', $sesion->id, null, ['sucursal' => $sucursal]);

            return $this->ok($res, ['sesion_id' => $sesion->id], 'Certificación automática completada exitosamente');
        });
    }

    private function _getProductosPickados(int $empresaId, int $sucursalId, string $sucursalEntrega, ?string $fecha = null): array
    {
        // $fecha ancla los "pendientes por empacar" al día de la sesión que los consulta.
        // Sin este filtro, una sesión de packing que quedó abierta ("EnProceso") varios días
        // absorbía silenciosamente pedidos nuevos del mismo cliente que llegaban después
        // (distinta planilla/fecha), mezclándolos en la misma remisión. Ver getSesion(),
        // agregarItem() y finalizarSesion(), que pasan la fecha de creación de su $sesion;
        // autoPack() pasa la fecha del día en que se ejecuta.
        $hoy = $fecha ?? date('Y-m-d');
        return Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->leftJoin('ambientes as amb', function ($join) use ($empresaId) {
                $join->on('amb.id', '=', 'p.ambiente_id')
                     ->where('amb.empresa_id', $empresaId);
            })
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.sucursal_entrega', $sucursalEntrega)
            ->where('op.estado', 'Completada')
            ->where('op.estado_certificacion', 'Pendiente')
            ->whereDate('op.fecha_movimiento', $hoy)
            ->select([
                'pd.producto_id',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo',
                'p.ambiente_id',
                Capsule::raw("COALESCE(amb.descripcion, 'Sin ambiente') as ambiente_nombre"),
                Capsule::raw("COALESCE(amb.color, '#64748b') as ambiente_color"),
                Capsule::raw('COALESCE(p.unidades_caja, 1) as unidades_caja'),
                Capsule::raw('SUM(pd.cantidad_pickeada) as total_pickeado'),
                Capsule::raw('SUM(pd.cantidad_solicitada) as total_solicitado'),
            ])
            ->groupBy(
                'pd.producto_id', 'p.nombre', 'p.codigo_interno', 'p.unidades_caja',
                'p.ambiente_id', 'amb.descripcion', 'amb.color'
            )
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
            ->orderByRaw('CASE WHEN COALESCE(pd.fecha_vencimiento, i.fecha_vencimiento) IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(pd.fecha_vencimiento, i.fecha_vencimiento) ASC')
            ->select(['pd.id', 'pd.lote', Capsule::raw('COALESCE(pd.fecha_vencimiento, i.fecha_vencimiento) as fecha_vencimiento'), 'pd.auxiliar_id'])
            ->first();

        if (!$detalle) return [null, null, null, null];
        return [$detalle->lote, $detalle->fecha_vencimiento, $detalle->auxiliar_id, $detalle->id];
    }
}
