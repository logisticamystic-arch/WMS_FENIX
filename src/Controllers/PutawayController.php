<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Inventario;
use App\Models\Ubicacion;
use App\Models\MovimientoInventario;
use App\Models\ProductoEan;
use App\Models\Producto;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * PutawayController — Almacenamiento, traslados y resolución de EAN.
 */
class PutawayController extends BaseController
{
    /**
     * GET /api/putaway/patio
     * Lista todo el stock en ubicaciones tipo Patio de la sucursal actual.
     */
    public function listarPatio(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        try {
            $stock = DB::table('inventarios as i')
                ->join('productos as p', 'p.id', '=', 'i.producto_id')
                ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                ->where('i.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('i.sucursal_id', $user->sucursal_id)
                ->where('i.cantidad', '>', 0)
                ->where(function ($q) {
                    $q->where('u.tipo_ubicacion', 'Patio')
                      ->orWhereNull('i.ubicacion_id');
                })
                ->select([
                    'i.id',
                    'i.producto_id',
                    'p.nombre as producto_nombre',
                    'p.codigo_interno',
                    'p.unidad_medida',
                    'p.unidades_caja',
                    'p.factor_udm',
                    'p.unidad_contenido',
                    'i.lote',
                    'i.fecha_vencimiento',
                    'i.cantidad',
                    'i.cantidad_cajas',
                    'i.saldos',
                    'i.cantidad_reservada',
                    'i.ubicacion_id',
                    'i.numero_pallet',
                    'i.estado',
                    'u.codigo as ubicacion_codigo',
                    'u.tipo_ubicacion',
                    'i.created_at',
                ])
                ->orderBy('i.created_at')
                ->get();

            return $this->ok($res, $stock);
        } catch (\Exception $e) {
            error_log('PutawayController::listarPatio error: ' . $e->getMessage());
            return $this->error($res, 'Error al listar patio.', 500);
        }
    }

    /**
     * POST /api/putaway/patio/{id}/eliminar
     * Da de baja una línea de inventario fantasma en Patio (mercancía que ya se
     * ubicó en su destino final pero el registro viejo de Patio quedó vivo, ej.
     * por el bug de traslado sin origen ya corregido). Solo Supervisor/Admin.
     * Nunca es un DELETE silencioso: siempre deja Kardex (AjusteNegativo) +
     * audit_logs con el motivo — mismo criterio que cualquier ajuste manual de
     * inventario en el resto del sistema.
     */
    public function eliminarFantasmaPatio(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data   = (array)($r->getParsedBody() ?? []);
        $motivo = trim($data['motivo'] ?? '');
        if ($motivo === '') {
            return $this->error($res, 'El motivo es obligatorio — explique por qué se está dando de baja este inventario de Patio.', 400);
        }

        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        try {
            DB::beginTransaction();

            $inv = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('id', (int)$a['id'])
                ->lockForUpdate()
                ->first();

            if (!$inv) {
                DB::rollBack();
                return $this->error($res, 'Registro de inventario no encontrado.', 404);
            }

            // Blindaje: solo se puede dar de baja por esta vía si realmente está en
            // Patio (o huérfano sin ubicación) — nunca una ubicación de almacenamiento
            // real, para no convertir esto en un atajo de ajuste de inventario general.
            $ubicacion = $inv->ubicacion_id ? Ubicacion::find($inv->ubicacion_id) : null;
            $esPatioOHuerfano = !$inv->ubicacion_id || ($ubicacion && $ubicacion->tipo_ubicacion === 'Patio');
            if (!$esPatioOHuerfano) {
                DB::rollBack();
                return $this->error($res, 'Este registro no está en Patio — use el ajuste de inventario normal para corregirlo.', 422);
            }

            if ((float)($inv->cantidad_reservada ?? 0) > 0) {
                $hasActivePicking = \App\Models\PickingDetalle::join('orden_pickings', 'orden_pickings.id', '=', 'picking_detalles.orden_picking_id')
                    ->where('picking_detalles.producto_id', $inv->producto_id)
                    ->whereIn('orden_pickings.estado', ['Pendiente', 'Asignada', 'En Proceso', 'Pausada'])
                    ->where(function($q) {
                        $q->whereNull('orden_pickings.estado_certificacion')
                          ->orWhere('orden_pickings.estado_certificacion', '!=', 'Certificado');
                    })
                    ->where('orden_pickings.empresa_id', $empresaId)
                    ->where('orden_pickings.sucursal_id', $user->sucursal_id)
                    ->exists();

                if ($hasActivePicking) {
                    DB::rollBack();
                    return $this->error($res, 'Hay stock reservado y pedidos pendientes de separar para este producto. No se puede dar de baja.', 422);
                }
            }

            $cantidadBaja = (float)$inv->cantidad;
            $productoId   = $inv->producto_id;
            $ubicacionId  = $inv->ubicacion_id;
            $lote         = $inv->lote;
            $fechaVenc    = $inv->fecha_vencimiento;

            $inv->delete();

            MovimientoInventario::create([
                'empresa_id'           => $empresaId,
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $productoId,
                'ubicacion_origen_id'  => $ubicacionId,
                'ubicacion_destino_id' => null,
                'tipo_movimiento'      => MovimientoInventario::TIPO_AJUSTE_NEGATIVO,
                'cantidad'             => $cantidadBaja,
                'lote'                 => $lote,
                'fecha_vencimiento'    => $fechaVenc,
                'referencia_tipo'      => 'baja_fantasma_patio',
                'auxiliar_id'          => $user->id,
                'fecha_movimiento'     => date('Y-m-d'),
                'hora_inicio'          => date('H:i:s'),
                'hora_fin'             => date('H:i:s'),
                'observaciones'        => 'Baja de inventario fantasma en Patio — ' . $motivo,
            ]);

            $this->audit($user, 'almacenamiento', 'baja_fantasma_patio', 'inventarios', (int)$a['id'],
                ['cantidad' => $cantidadBaja, 'producto_id' => $productoId],
                null,
                "Baja de {$cantidadBaja} unidades del producto #{$productoId} en Patio — Motivo: {$motivo}");

            DB::commit();

            return $this->ok($res, ['cantidad_dada_de_baja' => $cantidadBaja], 'Inventario de Patio dado de baja correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('PutawayController::eliminarFantasmaPatio error: ' . $e->getMessage());
            return $this->error($res, 'Error al dar de baja el inventario.', 500);
        }
    }

    /**
     * DELETE /api/putaway/patio/pallet/{pallet}
     * Elimina por completo todas las líneas de un pallet que se encuentra en Patio.
     * Solo Supervisor/Admin. Registra Kardex y Auditoría.
     */
    public function eliminarPalletPatio(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $pallet = trim($a['pallet'] ?? '');
        if ($pallet === '') return $this->error($res, 'Número de pallet inválido', 400);

        $data   = (array)($r->getParsedBody() ?? []);
        $motivo = trim($data['motivo'] ?? '');
        if ($motivo === '') {
            return $this->error($res, 'El motivo es obligatorio para eliminar un pallet.', 400);
        }

        try {
            DB::beginTransaction();

            $empresaId = $this->getEffectiveEmpresaId($user, $r);

            // Buscar todos los inventarios en patio con este pallet
            $items = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('numero_pallet', $pallet)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                DB::rollBack();
                return $this->error($res, 'No se encontró mercancía en este pallet en la sucursal.', 404);
            }

            $cantidadBajaTotal = 0;
            $detalles = [];

            foreach ($items as $inv) {
                if ($inv->estado !== 'En Patio' && $inv->estado !== 'Disponible') {
                    DB::rollBack();
                    return $this->error($res, 'El pallet contiene mercancía en un estado que no puede eliminarse: ' . $inv->estado, 422);
                }
                if ((float)($inv->cantidad_reservada ?? 0) > 0) {
                    $hasActivePicking = \App\Models\PickingDetalle::join('orden_pickings', 'orden_pickings.id', '=', 'picking_detalles.orden_picking_id')
                        ->where('picking_detalles.producto_id', $inv->producto_id)
                        ->whereIn('orden_pickings.estado', ['Pendiente', 'Asignada', 'En Proceso', 'Pausada'])
                        ->where(function($q) {
                            $q->whereNull('orden_pickings.estado_certificacion')
                              ->orWhere('orden_pickings.estado_certificacion', '!=', 'Certificado');
                        })
                        ->where('orden_pickings.empresa_id', $empresaId)
                        ->where('orden_pickings.sucursal_id', $user->sucursal_id)
                        ->exists();
    
                    if ($hasActivePicking) {
                        DB::rollBack();
                        return $this->error($res, 'Hay stock reservado y pedidos pendientes de separar para este producto. No se puede eliminar el pallet.', 422);
                    }
                }

                $cantidadBaja = (float)$inv->cantidad;
                $cantidadBajaTotal += $cantidadBaja;
                $productoId   = $inv->producto_id;
                $ubicacionId  = $inv->ubicacion_id;
                
                MovimientoInventario::create([
                    'empresa_id'           => $empresaId,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $productoId,
                    'ubicacion_origen_id'  => $ubicacionId,
                    'ubicacion_destino_id' => null,
                    'tipo_movimiento'      => MovimientoInventario::TIPO_AJUSTE_NEGATIVO,
                    'cantidad'             => $cantidadBaja,
                    'cantidad_cajas'       => $inv->cantidad_cajas,
                    'saldos'               => $inv->saldos,
                    'lote'                 => $inv->lote,
                    'fecha_vencimiento'    => $inv->fecha_vencimiento,
                    'referencia_tipo'      => 'baja_pallet_patio',
                    'auxiliar_id'          => $user->id,
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                    'hora_fin'             => date('H:i:s'),
                    'observaciones'        => 'Eliminación completa de pallet de patio #' . $pallet . ' — ' . $motivo,
                    'numero_pallet'        => $pallet,
                ]);

                $detalles[] = "Prod #{$productoId}: {$cantidadBaja}";
                $inv->delete();
            }

            $this->audit($user, 'almacenamiento', 'baja_pallet_patio', 'inventarios', null,
                ['pallet' => $pallet, 'total_baja' => $cantidadBajaTotal],
                null,
                "Baja del Pallet #{$pallet} completo en Patio — Motivo: {$motivo}. Detalles: " . implode(', ', $detalles));

            DB::commit();

            return $this->ok($res, ['cantidad_dada_de_baja' => $cantidadBajaTotal], 'Pallet eliminado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('PutawayController::eliminarPalletPatio error: ' . $e->getMessage());
            return $this->error($res, 'Error al eliminar el pallet.', 500);
        }
    }

    /**
     * GET /api/putaway/sugerir/{producto_id}
     * Devuelve las 5 mejores ubicaciones para almacenar el producto,
     * priorizando: 1) ubicaciones donde ya existe el producto, 2) libres con capacidad.
     */
    public function sugerirUbicacion(Request $r, Response $res, array $args): Response
    {
        $user       = $r->getAttribute('user');
        $productoId = (int)($args['producto_id'] ?? 0);

        if (!$productoId) {
            return $this->error($res, 'producto_id requerido.', 400);
        }

        try {
            // Igual que ubicar(): esta sucursal no tiene ubicaciones tipo 'Almacenamiento',
            // solo 'Picking' — con el filtro original esta lista siempre salía vacía.
            $ubicaciones = Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->whereIn('tipo_ubicacion', ['Almacenamiento', 'Picking'])
                ->where('activo', 1)
                ->get();

            $stockPorUbicacion = DB::table('inventarios')
                ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('cantidad', '>', 0)
                ->select('ubicacion_id', DB::raw('SUM(cantidad) as total'))
                ->groupBy('ubicacion_id')
                ->pluck('total', 'ubicacion_id')
                ->toArray();

            // Existing locations for this product (consolidation priority)
            $existentes = DB::table('inventarios as i')
                ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                ->where('i.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('i.sucursal_id', $user->sucursal_id)
                ->where('i.producto_id', $productoId)
                ->where('i.cantidad', '>', 0)
                ->whereIn('u.tipo_ubicacion', ['Almacenamiento', 'Picking'])
                ->select('u.id', 'u.codigo', 'u.capacidad_maxima', DB::raw('SUM(i.cantidad) as stock_actual'))
                ->groupBy('u.id', 'u.codigo', 'u.capacidad_maxima')
                ->orderBy('stock_actual', 'desc')
                ->get()
                ->keyBy('id');

            $sugerencias     = [];
            $existentesIds   = $existentes->keys()->toArray();

            foreach ($existentes as $u) {
                $sugerencias[] = [
                    'ubicacion_id'  => $u->id,
                    'codigo'        => $u->codigo,
                    'razon'         => 'Consolidación — producto ya almacenado aquí',
                    'prioridad'     => 1,
                    'stock_actual'  => $u->stock_actual,
                    'capacidad_max' => $u->capacidad_maxima,
                ];
            }

            foreach ($ubicaciones as $u) {
                if (in_array($u->id, $existentesIds, true)) continue;
                $stockActual = $stockPorUbicacion[$u->id] ?? 0;
                // Sin límite de capacidad: antes se excluía la ubicación de las
                // sugerencias al alcanzar capacidad_maxima (o 999999 si no tenía
                // configurada) — ahora todas las ubicaciones activas se sugieren
                // sin importar el stock que ya tengan.

                $sugerencias[] = [
                    'ubicacion_id'  => $u->id,
                    'codigo'        => $u->codigo,
                    'razon'         => 'Disponible',
                    'prioridad'     => 2,
                    'stock_actual'  => $stockActual,
                    'capacidad_max' => $u->capacidad_maxima,
                ];
            }

            return $this->ok($res, array_slice($sugerencias, 0, 5));
        } catch (\Exception $e) {
            error_log('PutawayController::sugerirUbicacion error: ' . $e->getMessage());
            return $this->error($res, 'Error al sugerir ubicación.', 500);
        }
    }

    /**
     * POST /api/putaway/ubicar
     * Ejecuta el putaway: descuenta del patio y acredita en el rack destino.
     * Body: { producto_id, ubicacion_destino_id, cantidad, lote?, fecha_vencimiento?, ubicacion_origen_id? }
     */
    public function ubicar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = (array)($r->getParsedBody() ?? []);

        $productoId      = (int)($data['producto_id'] ?? 0);
        $ubicacionDestId = (int)($data['ubicacion_destino_id'] ?? 0);
        $cantidad        = (float)($data['cantidad'] ?? 0);
        $lote            = trim($data['lote'] ?? '') ?: null;
        $fechaVenc       = $data['fecha_vencimiento'] ?? null;
        $ubicacionOrigId = isset($data['ubicacion_origen_id']) && $data['ubicacion_origen_id']
            ? (int)$data['ubicacion_origen_id'] : null;
        // Cajas y saldos desde el request (si vienen del móvil)
        $cajasReq  = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : null;
        $saldosReq = isset($data['saldos'])         ? (float)$data['saldos']       : null;

        if (!$productoId || !$ubicacionDestId || $cantidad <= 0) {
            return $this->error($res, 'producto_id, ubicacion_destino_id y cantidad > 0 son requeridos.', 400);
        }

        try {
            DB::beginTransaction();

            // Resolver UPC del producto
            $producto  = \App\Models\Producto::select('id', 'unidades_caja', 'factor_udm')->find($productoId);
            $factor    = (float)($producto->factor_udm ?? 0);
            $upc       = $factor > 0 ? (int)$factor : max(1, (int)(($producto->unidades_caja ?? null) ?: 1));

            // Si el cliente envía cajas y saldos, calculamos cantidad exacta, de lo contrario inferimos (fallback)
            if ($cajasReq !== null && $saldosReq !== null) {
                $cajasMove = $cajasReq;
                $saldosMove = $saldosReq;
                $cantidad = ($cajasMove * $upc) + $saldosMove;
            } else {
                $cajasMove = (int)floor($cantidad / $upc);
                $saldosMove = round(fmod($cantidad, (float)$upc), 4);
            }

            if ($cantidad <= 0) {
                DB::rollBack();
                return $this->error($res, 'La cantidad total a ubicar debe ser mayor a 0.', 400);
            }

            // Verificar ubicación destino
            $destino = Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->whereIn('tipo_ubicacion', ['Almacenamiento', 'Picking', 'Patio'])
                ->find($ubicacionDestId);
            if (!$destino) {
                $destino = Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where('sucursal_id', $user->sucursal_id)
                    ->where('estado', 'Activo')
                    ->where('tipo_ubicacion', '!=', 'Bloqueada')
                    ->find($ubicacionDestId);
            }

            if (!$destino) {
                DB::rollBack();
                return $this->error($res, 'Ubicación de destino no válida para esta sucursal.', 404);
            }

            // Descontar origen
            $origenQuery = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $productoId)
                ->where('lote', $lote)
                ->whereIn('estado', ['Disponible', 'En Patio']);

            $numPallet = trim($data['numero_pallet'] ?? '');
            if ($numPallet !== '') {
                $origenQuery->where('numero_pallet', $numPallet);
            } else {
                $origenQuery->where(function($q) {
                    $q->whereNull('numero_pallet')->orWhere('numero_pallet', '');
                });
            }

            if ($ubicacionOrigId) {
                $origenQuery->where('ubicacion_id', $ubicacionOrigId);
            } else {
                $origenQuery->whereNull('ubicacion_id');
            }

            $invOrigen = $origenQuery->lockForUpdate()->first();

            if (!$invOrigen) {
                DB::rollBack();
                return $this->error($res, "No se encontró inventario de origen (Prod: $productoId, Lote: $lote, UbiOrig: $ubicacionOrigId, PalletReq: ".($data['numero_pallet']??'null').").", 400);
            }

            if ($cajasMove > $invOrigen->cantidad_cajas || $saldosMove > $invOrigen->saldos || $cantidad > $invOrigen->cantidad) {
                DB::rollBack();
                return $this->error($res, "Error de cantidades. Req: (C:$cajasMove, S:$saldosMove, T:$cantidad). Disp: (C:$invOrigen->cantidad_cajas, S:$invOrigen->saldos, T:$invOrigen->cantidad).", 400);
            }

            if ((float)($invOrigen->cantidad_reservada ?? 0) > 0) {
                DB::rollBack();
                return $this->error($res, 'Hay stock reservado en esta ubicación. No se puede mover.', 422);
            }

            if (!$fechaVenc && $invOrigen->fecha_vencimiento) {
                $fechaVenc = $invOrigen->fecha_vencimiento;
            }

            $invOrigen->cantidad_cajas -= $cajasMove;
            $invOrigen->saldos = round($invOrigen->saldos - $saldosMove, 4);
            $invOrigen->cantidad = ($invOrigen->cantidad_cajas * $upc) + $invOrigen->saldos;

            if ($invOrigen->cantidad <= 0) {
                $invOrigen->delete();
            } else {
                $invOrigen->save();
            }

            // Acreditar en destino
            $invDest = Inventario::firstOrNew([
                'empresa_id'   => $this->getEffectiveEmpresaId($user, $r),
                'sucursal_id'  => $user->sucursal_id,
                'producto_id'  => $productoId,
                'ubicacion_id' => $ubicacionDestId,
                'lote'         => $lote,
                'numero_pallet'=> $data['numero_pallet'] ?? null,
            ]);
            
            if (!$invDest->exists) {
                $invDest->cantidad           = 0;
                $invDest->cantidad_cajas     = 0;
                $invDest->saldos             = 0;
                $invDest->cantidad_reservada = 0;
                $invDest->estado             = 'Disponible';
            }
            if ($fechaVenc) $invDest->fecha_vencimiento = $fechaVenc;

            $invDest->cantidad_cajas += $cajasMove;
            $invDest->saldos = round($invDest->saldos + $saldosMove, 4);
            $invDest->cantidad = ($invDest->cantidad_cajas * $upc) + $invDest->saldos;
            $invDest->save();

            // Registro de movimiento
            MovimientoInventario::create([
                'empresa_id'           => $this->getEffectiveEmpresaId($user, $r),
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $productoId,
                'ubicacion_origen_id'  => $ubicacionOrigId,
                'ubicacion_destino_id' => $ubicacionDestId,
                'tipo_movimiento'      => MovimientoInventario::TIPO_TRASLADO,
                'cantidad'             => $cantidad,
                'cantidad_cajas'       => $cajasMove,
                'saldos'               => $saldosMove,
                'lote'                 => $lote,
                'fecha_vencimiento'    => $fechaVenc,
                'referencia_tipo'      => 'putaway',
                'auxiliar_id'          => $user->id,
                'fecha_movimiento'     => date('Y-m-d'),
                'hora_inicio'          => date('H:i:s'),
                'hora_fin'             => date('H:i:s'),
                'observaciones'        => 'Putaway (Ubicar) en ' . $destino->codigo,
                'numero_pallet'        => $data['numero_pallet'] ?? null,
            ]);

            DB::commit();

            return $this->ok($res, [
                'producto_id'    => $productoId,
                'ubicacion_dest' => $destino->codigo,
                'cantidad'       => $cantidad,
            ], 'Putaway ejecutado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('PutawayController::ubicar error: ' . $e->getMessage());
            return $this->error($res, 'Error al ejecutar putaway.', 500);
        }
    }

    /**
     * POST /api/putaway/trasladar
     * Traslado interno entre dos ubicaciones por código.
     * Body: { codigo_origen, codigo_destino, ean, cantidad, lote? }
     */
    public function trasladar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = (array)($r->getParsedBody() ?? []);

        $codOrigen  = strtoupper(trim($data['codigo_origen']  ?? ''));
        $codDestino = strtoupper(trim($data['codigo_destino'] ?? ''));
        $ean        = trim($data['ean'] ?? '');
        $cantidad   = (float)($data['cantidad'] ?? 0);
        $lote       = trim($data['lote'] ?? '') ?: null;

        if (empty($codOrigen) || empty($codDestino) || empty($ean) || $cantidad <= 0) {
            return $this->error($res, 'codigo_origen, codigo_destino, ean y cantidad > 0 son requeridos.', 400);
        }
        if ($codOrigen === $codDestino) {
            return $this->error($res, 'La ubicación de origen y destino no pueden ser la misma.', 400);
        }

        try {
            // Resolver EAN → producto_id
            $eanModel = ProductoEan::where('codigo_ean', $ean)->where('activo', 1)->first();
            if ($eanModel) {
                $productoId = $eanModel->producto_id;
            } else {
                $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where('codigo_interno', $ean)->where('activo', 1)->first();
                if (!$prod) {
                    return $this->error($res, "Producto no encontrado para el código: {$ean}", 404);
                }
                $productoId = $prod->id;
            }

            // Resolver códigos de ubicación → IDs
            $origen = Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('codigo', $codOrigen)->first();
            if (!$origen) {
                return $this->error($res, "Ubicación origen '{$codOrigen}' no encontrada.", 404);
            }

            $destino = Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('codigo', $codDestino)->first();
            if (!$destino) {
                return $this->error($res, "Ubicación destino '{$codDestino}' no encontrada.", 404);
            }

            DB::beginTransaction();

            // Stock en origen
            $invOrigen = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $productoId)
                ->where('ubicacion_id', $origen->id)
                ->when($lote, fn($q) => $q->where('lote', $lote))
                ->first();

            if (!$invOrigen || $invOrigen->cantidad < $cantidad) {
                DB::rollBack();
                return $this->error($res, 'Stock insuficiente en la ubicación de origen.', 400);
            }

            $loteReal  = $invOrigen->lote;
            $fechaVenc = $invOrigen->fecha_vencimiento;

            if ((float)($invOrigen->cantidad_reservada ?? 0) > 0) {
                DB::rollBack();
                return $this->error($res, 'Hay stock reservado en esta ubicación. No se puede mover.', 422);
            }

            // Resolver UPC para cajas/saldos
            $productoT = \App\Models\Producto::select('id','unidades_caja')->find($productoId);
            $upcT      = max(1, (int)(($productoT->unidades_caja ?? null) ?: 1));
            $cajasT    = (int)floor($cantidad / $upcT);
            $saldosT   = round(fmod($cantidad, (float)$upcT), 4);

            $nuevoOrigenT = round((float)$invOrigen->cantidad - $cantidad, 4);
            if ($nuevoOrigenT <= 0) {
                $invOrigen->delete();
            } else {
                $invOrigen->cantidad      = $nuevoOrigenT;
                $invOrigen->cantidad_cajas= (int)floor($nuevoOrigenT / $upcT);
                $invOrigen->saldos        = round(fmod($nuevoOrigenT, (float)$upcT), 4);
                $invOrigen->save();
            }

            // Acreditar en destino
            $invDest = Inventario::firstOrNew([
                'empresa_id'   => $this->getEffectiveEmpresaId($user, $r),
                'sucursal_id'  => $user->sucursal_id,
                'producto_id'  => $productoId,
                'ubicacion_id' => $destino->id,
                'lote'         => $loteReal,
                'fecha_vencimiento' => $fechaVenc,
            ]);

            if (!$invDest->exists) {
                $invDest->cantidad           = 0;
                $invDest->cantidad_cajas     = 0;
                $invDest->saldos             = 0;
                $invDest->cantidad_reservada = 0;
                $invDest->estado             = 'Disponible';
            }

            $nuevoDestT       = round((float)($invDest->cantidad ?? 0) + $cantidad, 4);
            $invDest->cantidad      = $nuevoDestT;
            $invDest->cantidad_cajas= (int)floor($nuevoDestT / $upcT);
            $invDest->saldos        = round(fmod($nuevoDestT, (float)$upcT), 4);
            $invDest->save();

            MovimientoInventario::create([
                'empresa_id'           => $this->getEffectiveEmpresaId($user, $r),
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $productoId,
                'ubicacion_origen_id'  => $origen->id,
                'ubicacion_destino_id' => $destino->id,
                'tipo_movimiento'      => 'Traslado',
                'cantidad'             => $cantidad,
                'cantidad_cajas'       => $cajasT,
                'saldos'               => $saldosT,
                'lote'                 => $loteReal,
                'fecha_vencimiento'    => $fechaVenc,
                'referencia_tipo'      => 'traslado',
                'auxiliar_id'          => $user->id,
                'fecha_movimiento'     => date('Y-m-d'),
                'hora_inicio'          => date('H:i:s'),
                'hora_fin'             => date('H:i:s'),
            ]);

            DB::commit();

            return $this->ok($res, [
                'de'       => $origen->codigo,
                'hacia'    => $destino->codigo,
                'cantidad' => $cantidad,
            ], 'Traslado ejecutado correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('PutawayController::trasladar error: ' . $e->getMessage());
            return $this->error($res, 'Error al ejecutar traslado.', 500);
        }
    }

    /**
     * GET /api/putaway/resolver-ean?ean=xxx
     * Resuelve un EAN o código_interno al producto + su stock en patio.
     */
    public function resolverEan(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $ean    = trim($params['ean'] ?? '');

        if (empty($ean)) {
            return $this->error($res, 'Parámetro ean es requerido.', 400);
        }

        try {
            // Buscar en tabla de EANs primero
            $eanModel = ProductoEan::with('producto')
                ->where('codigo_ean', $ean)->where('activo', 1)->first();
            $producto = $eanModel ? $eanModel->producto : null;

            // Fallback por codigo_interno
            if (!$producto) {
                $producto = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where('codigo_interno', $ean)->where('activo', 1)->first();
            }

            if (!$producto) {
                return $this->error($res, "No se encontró producto para el código: {$ean}", 404);
            }

            // Stock en patio
            $stockPatio = DB::table('inventarios as i')
                ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                ->where('i.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('i.sucursal_id', $user->sucursal_id)
                ->where('i.producto_id', $producto->id)
                ->where('i.cantidad', '>', 0)
                ->where('u.tipo_ubicacion', 'Patio')
                ->select([
                    'i.id as inv_id',
                    'i.lote',
                    'i.fecha_vencimiento',
                    'i.cantidad',
                    'u.id as ubicacion_id',
                    'u.codigo as ubicacion_codigo',
                ])
                ->orderBy('i.fecha_vencimiento')
                ->get();

            return $this->ok($res, [
                'producto'    => $producto,
                'stock_patio' => $stockPatio,
                'total_patio' => $stockPatio->sum('cantidad'),
            ]);
        } catch (\Exception $e) {
            error_log('PutawayController::resolverEan error: ' . $e->getMessage());
            return $this->error($res, 'Error al resolver EAN.', 500);
        }
    }
}
