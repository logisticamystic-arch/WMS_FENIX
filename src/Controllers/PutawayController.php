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
                ->where('i.empresa_id', $user->empresa_id)
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
                    'i.lote',
                    'i.fecha_vencimiento',
                    'i.cantidad',
                    'i.ubicacion_id',
                    'u.codigo as ubicacion_codigo',
                    'u.tipo_ubicacion',
                ])
                ->orderBy('i.fecha_vencimiento')
                ->get();

            return $this->ok($res, $stock);
        } catch (\Exception $e) {
            error_log('PutawayController::listarPatio error: ' . $e->getMessage());
            return $this->error($res, 'Error al listar patio.', 500);
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
            $ubicaciones = Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('tipo_ubicacion', 'Almacenamiento')
                ->where('activo', 1)
                ->get();

            $stockPorUbicacion = DB::table('inventarios')
                ->where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('cantidad', '>', 0)
                ->select('ubicacion_id', DB::raw('SUM(cantidad) as total'))
                ->groupBy('ubicacion_id')
                ->pluck('total', 'ubicacion_id')
                ->toArray();

            // Existing locations for this product (consolidation priority)
            $existentes = DB::table('inventarios as i')
                ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                ->where('i.empresa_id', $user->empresa_id)
                ->where('i.sucursal_id', $user->sucursal_id)
                ->where('i.producto_id', $productoId)
                ->where('i.cantidad', '>', 0)
                ->where('u.tipo_ubicacion', 'Almacenamiento')
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
                $capacidad   = $u->capacidad_maxima ?: 999999;
                if ($stockActual >= $capacidad) continue;

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
        $cantidad        = (int)($data['cantidad'] ?? 0);
        $lote            = trim($data['lote'] ?? '') ?: null;
        $fechaVenc       = $data['fecha_vencimiento'] ?? null;
        $ubicacionOrigId = isset($data['ubicacion_origen_id']) && $data['ubicacion_origen_id']
            ? (int)$data['ubicacion_origen_id'] : null;

        if (!$productoId || !$ubicacionDestId || $cantidad <= 0) {
            return $this->error($res, 'producto_id, ubicacion_destino_id y cantidad > 0 son requeridos.', 400);
        }

        try {
            DB::beginTransaction();

            // Verificar ubicación destino pertenece a empresa/sucursal
            $destino = Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('tipo_ubicacion', 'Almacenamiento')
                ->find($ubicacionDestId);

            if (!$destino) {
                DB::rollBack();
                return $this->error($res, 'Ubicación de destino no válida para esta sucursal.', 404);
            }

            // Descontar del origen (patio) si se especificó
            if ($ubicacionOrigId) {
                $invOrigen = Inventario::where('empresa_id', $user->empresa_id)
                    ->where('sucursal_id', $user->sucursal_id)
                    ->where('producto_id', $productoId)
                    ->where('ubicacion_id', $ubicacionOrigId)
                    ->where('lote', $lote)
                    ->first();

                if (!$invOrigen || $invOrigen->cantidad < $cantidad) {
                    DB::rollBack();
                    return $this->error($res, 'Stock insuficiente en la ubicación de origen.', 400);
                }

                $invOrigen->cantidad -= $cantidad;
                if ($invOrigen->cantidad <= 0) {
                    $invOrigen->delete();
                } else {
                    $invOrigen->save();
                }

                // Heredar fecha de vencimiento del origen si no viene en el request
                if (!$fechaVenc && $invOrigen->fecha_vencimiento) {
                    $fechaVenc = $invOrigen->fecha_vencimiento;
                }
            }

            // Acreditar en destino
            $invDest = Inventario::firstOrNew([
                'empresa_id'   => $user->empresa_id,
                'sucursal_id'  => $user->sucursal_id,
                'producto_id'  => $productoId,
                'ubicacion_id' => $ubicacionDestId,
                'lote'         => $lote,
            ]);
            if (!$invDest->exists) {
                $invDest->cantidad           = 0;
                $invDest->cantidad_reservada = 0;
                $invDest->estado             = 'Disponible';
            }
            if ($fechaVenc) $invDest->fecha_vencimiento = $fechaVenc;
            $invDest->cantidad += $cantidad;
            $invDest->save();

            // Registro de movimiento
            MovimientoInventario::create([
                'empresa_id'           => $user->empresa_id,
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $productoId,
                'ubicacion_origen_id'  => $ubicacionOrigId,
                'ubicacion_destino_id' => $ubicacionDestId,
                'tipo_movimiento'      => 'Putaway',
                'cantidad'             => $cantidad,
                'lote'                 => $lote,
                'fecha_vencimiento'    => $fechaVenc,
                'referencia_tipo'      => 'putaway',
                'auxiliar_id'          => $user->id,
                'fecha_movimiento'     => date('Y-m-d'),
                'hora_inicio'          => date('H:i:s'),
                'hora_fin'             => date('H:i:s'),
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
        $cantidad   = (int)($data['cantidad'] ?? 0);
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
                $prod = Producto::where('empresa_id', $user->empresa_id)
                    ->where('codigo_interno', $ean)->where('activo', 1)->first();
                if (!$prod) {
                    return $this->error($res, "Producto no encontrado para el código: {$ean}", 404);
                }
                $productoId = $prod->id;
            }

            // Resolver códigos de ubicación → IDs
            $origen = Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('codigo', $codOrigen)->first();
            if (!$origen) {
                return $this->error($res, "Ubicación origen '{$codOrigen}' no encontrada.", 404);
            }

            $destino = Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('codigo', $codDestino)->first();
            if (!$destino) {
                return $this->error($res, "Ubicación destino '{$codDestino}' no encontrada.", 404);
            }

            DB::beginTransaction();

            // Stock en origen
            $invOrigen = Inventario::where('empresa_id', $user->empresa_id)
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

            $invOrigen->cantidad -= $cantidad;
            if ($invOrigen->cantidad <= 0) {
                $invOrigen->delete();
            } else {
                $invOrigen->save();
            }

            // Acreditar en destino
            $invDest = Inventario::firstOrNew([
                'empresa_id'   => $user->empresa_id,
                'sucursal_id'  => $user->sucursal_id,
                'producto_id'  => $productoId,
                'ubicacion_id' => $destino->id,
                'lote'         => $loteReal,
            ]);
            if (!$invDest->exists) {
                $invDest->cantidad           = 0;
                $invDest->cantidad_reservada = 0;
                $invDest->estado             = 'Disponible';
                $invDest->fecha_vencimiento  = $fechaVenc;
            }
            $invDest->cantidad += $cantidad;
            $invDest->save();

            MovimientoInventario::create([
                'empresa_id'           => $user->empresa_id,
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $productoId,
                'ubicacion_origen_id'  => $origen->id,
                'ubicacion_destino_id' => $destino->id,
                'tipo_movimiento'      => 'Traslado',
                'cantidad'             => $cantidad,
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
                $producto = Producto::where('empresa_id', $user->empresa_id)
                    ->where('codigo_interno', $ean)->where('activo', 1)->first();
            }

            if (!$producto) {
                return $this->error($res, "No se encontró producto para el código: {$ean}", 404);
            }

            // Stock en patio
            $stockPatio = DB::table('inventarios as i')
                ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                ->where('i.empresa_id', $user->empresa_id)
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
