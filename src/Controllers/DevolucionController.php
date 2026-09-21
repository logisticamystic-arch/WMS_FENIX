<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Devolucion;
use App\Models\DevolucionDetalle;
use App\Models\CausalDevolucion;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Ubicacion;
use Illuminate\Database\Capsule\Manager as DB;

class DevolucionController extends BaseController
{
    /**
     * GET /api/devoluciones
     */
    public function index(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $params     = $request->getQueryParams();

        try {
            $q = Devolucion::with(['detalles.producto', 'causal', 'ubicacionPatio', 'sucursalOrigen', 'clienteOrigen'])
                ->where('empresa_id',  $empresaId)
                ->where('sucursal_id', $sucursalId);

            if (!empty($params['tipo']))              $q->where('tipo',   $params['tipo']);
            if (!empty($params['estado']))             $q->where('estado', $params['estado']);
            if (!empty($params['desde']))              $q->where('created_at', '>=', $params['desde'] . ' 00:00:00');
            if (!empty($params['hasta']))              $q->where('created_at', '<=', $params['hasta'] . ' 23:59:59');
            if (!empty($params['fecha_desde']))        $q->where('fecha_movimiento', '>=', $params['fecha_desde']);
            if (!empty($params['fecha_hasta']))        $q->where('fecha_movimiento', '<=', $params['fecha_hasta']);
            if (!empty($params['causal_id']))          $q->where('causal_devolucion_id', (int)$params['causal_id']);
            if (!empty($params['responsable']))        $q->where('responsable_devolucion', 'like', '%' . $params['responsable'] . '%');
            if (!empty($params['cliente_id']))         $q->where('cliente_origen_id', (int)$params['cliente_id']);
            if (!empty($params['sucursal_origen_id'])) $q->where('sucursal_origen_id', (int)$params['sucursal_origen_id']);
            if (!empty($params['consecutivo']))        $q->where('consecutivo_devolucion', (int)$params['consecutivo']);

            if (!empty($params['referencia'])) {
                $ref = $params['referencia'];
                $q->where(function($qb) use ($ref) {
                    $qb->where('numero_devolucion', 'like', "%{$ref}%")
                       ->orWhere('consecutivo_devolucion', 'like', "%{$ref}%")
                       ->orWhere('referencia_externa', 'like', "%{$ref}%")
                       ->orWhereHas('detalles', function($dq) use ($ref) {
                           $dq->whereHas('producto', function($pq) use ($ref) {
                               $pq->where('codigo_interno', 'like', "%{$ref}%")
                                  ->orWhere('nombre', 'like', "%{$ref}%");
                           });
                       });
                });
            }

            if (!empty($params['q'])) {
                $sq = $params['q'];
                $q->where(fn($qb) => $qb->where('numero_devolucion', 'like', "%{$sq}%")
                                        ->orWhere('consecutivo_devolucion', 'like', "%{$sq}%")
                                        ->orWhere('referencia_externa', 'like', "%{$sq}%")
                                        ->orWhere('motivo_general', 'like', "%{$sq}%"));
            }

            $devoluciones = $q->orderBy('created_at', 'desc')->limit(200)->get();
            return $this->ok($response, $devoluciones);
        } catch (\Exception $e) {
            error_log('DevolucionController::index error: ' . $e->getMessage());
            return $this->error($response, 'Error al listar devoluciones.', 500);
        }
    }

    /**
     * GET /api/devoluciones/{id}
     */
    public function ver(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $id = (int)($args['id'] ?? 0);
        try {
            $devolucion = Devolucion::with(['detalles.producto', 'causal', 'ubicacionPatio', 'sucursalOrigen', 'clienteOrigen', 'auxiliar', 'solicitante', 'aprobador', 'procesador'])
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->find($id);
            if (!$devolucion) {
                return $this->json($response, ['error' => true, 'message' => 'Devolución no encontrada.'], 404);
            }
            return $this->json($response, ['error' => false, 'data' => $devolucion]);
        } catch (\Exception $e) {
            error_log('DevolucionController::ver error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al obtener devolución.'], 500);
        }
    }

    /**
     * DELETE /api/devoluciones/{id}
     */
    public function eliminar(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!($user->rol === 'Admin' || $user->rol === 'Supervisor')) {
            return $this->json($response, ['error' => true, 'message' => 'No tienes permiso para eliminar devoluciones.'], 403);
        }
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $id = (int)($args['id'] ?? 0);
        try {
            $devolucion = Devolucion::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->find($id);
            if (!$devolucion) {
                return $this->json($response, ['error' => true, 'message' => 'Devolución no encontrada.'], 404);
            }
            DevolucionDetalle::where('devolucion_id', $devolucion->id)->delete();
            $devolucion->delete();
            return $this->json($response, ['error' => false, 'message' => 'Devolución eliminada.']);
        } catch (\Exception $e) {
            error_log('DevolucionController::eliminar error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al eliminar devolución.'], 500);
        }
    }

    /**
     * Crea una devolución con la MISMA numeración real que store() —
     * Devolucion::generarNumero()/generarConsecutivo(), nunca inventada por el
     * llamador — para integraciones que no llegan por HTTP multipart (p.ej. el
     * webhook TMS/YMS de devoluciones registradas en el punto de venta). Sin
     * manejo de fotos ni $request: el ajuste de inventario para tipo='cliente'
     * no ocurre aquí (igual que en store()) — queda 'Pendiente' para el mismo
     * triage de calidad (procesar()) que ya usan las devoluciones manuales.
     *
     * @param array $detalles [{producto_id, cantidad, lote?, fecha_vencimiento?, motivo?, condicion?}, ...]
     * @return array{0:int,1:string,2:int} [devolucion_id, numero_devolucion, consecutivo]
     * @throws \InvalidArgumentException si algún dato requerido es inválido
     */
    public function crearDesdeIntegracion(
        int $empresaId,
        int $sucursalId,
        int $auxiliarId,
        string $tipo,
        string $motivoGeneral,
        array $detalles,
        ?int $causalId = null,
        ?string $referenciaExterna = null
    ): array {
        $tiposValidos = ['AProveedorAveria','AProveedorVencido','ReingresoBuenEstado','cliente','proveedor','interna','BuenEstado','MalEstado'];
        if (!in_array($tipo, $tiposValidos, true)) {
            throw new \InvalidArgumentException("tipo inválido: {$tipo}");
        }
        if (empty($detalles)) {
            throw new \InvalidArgumentException('Debe incluir al menos un producto a devolver');
        }
        foreach ($detalles as $i => $d) {
            if (empty($d['producto_id']) || !is_numeric($d['producto_id'])) {
                throw new \InvalidArgumentException('Línea ' . ($i + 1) . ': producto_id inválido');
            }
            if (empty($d['cantidad']) || (float)$d['cantidad'] <= 0) {
                throw new \InvalidArgumentException('Línea ' . ($i + 1) . ': cantidad debe ser mayor a cero');
            }
        }
        if ($causalId !== null) {
            $causalExiste = CausalDevolucion::where('empresa_id', $empresaId)
                ->where('id', $causalId)->where('activo', true)->exists();
            if (!$causalExiste) {
                throw new \InvalidArgumentException('causal_devolucion_id no existe o no está activa');
            }
        }

        return DB::transaction(function () use ($empresaId, $sucursalId, $auxiliarId, $tipo, $motivoGeneral, $detalles, $causalId, $referenciaExterna) {
            $numero      = Devolucion::generarNumero($empresaId);
            $consecutivo = Devolucion::generarConsecutivo($empresaId);

            $dev = Devolucion::create([
                'empresa_id'             => $empresaId,
                'sucursal_id'            => $sucursalId,
                'numero_devolucion'      => $numero,
                'consecutivo_devolucion' => $consecutivo,
                'tipo'                   => $tipo,
                'estado'                 => Devolucion::ESTADO_PENDIENTE,
                'motivo_general'         => $motivoGeneral,
                'referencia_externa'     => $referenciaExterna,
                'auxiliar_id'            => $auxiliarId,
                'solicitado_por'         => $auxiliarId,
                'fecha_movimiento'       => date('Y-m-d'),
                'hora_inicio'            => date('H:i:s'),
                'causal_devolucion_id'   => $causalId,
            ]);

            foreach ($detalles as $d) {
                $condicion = $d['condicion'] ?? ($tipo === 'MalEstado' ? 'dañado' : 'bueno');
                $destino   = ($condicion === 'dañado' || $condicion === 'vencido') ? 'InventarioObsoleto' : 'Reingreso';
                DevolucionDetalle::create([
                    'devolucion_id'     => $dev->id,
                    'producto_id'       => (int)$d['producto_id'],
                    'lote'              => $d['lote'] ?? null,
                    'fecha_vencimiento' => $d['fecha_vencimiento'] ?? null,
                    'cantidad'          => (float)$d['cantidad'],
                    'condicion'         => $condicion,
                    // devolucion_detalles.motivo tiene un CHECK a solo 5 valores
                    // fijos (Averia/Vencido/ErrorProveedor/CalidadDeficiente/Otro)
                    // — no es el mismo campo libre que causal_devolucion_id. El
                    // llamador de integración (YMS) manda el nombre real de la
                    // causal en $d['motivo'] para trazabilidad, pero eso violaría
                    // el CHECK si no coincide exacto; la causal real ya queda
                    // registrada en el encabezado vía causal_devolucion_id, así
                    // que aquí siempre se usa 'Otro'.
                    'motivo'            => 'Otro',
                    'destino'           => $destino,
                ]);
            }

            return [$dev->id, $numero, $consecutivo];
        });
    }

    /**
     * POST /api/devoluciones
     * Iniciar proceso de devolución (crear encabezado y líneas)
     */
    public function store(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $data       = $request->getParsedBody() ?? [];

        // Soporte FormData (multipart/form-data) o JSON
        if (isset($data['detalles']) && is_string($data['detalles'])) {
            $data['detalles'] = json_decode($data['detalles'], true) ?? [];
        }

        if ($deny = $this->requireFields($data, ['tipo', 'motivo_general', 'detalles'], $response)) {
            return $deny;
        }

        $tiposValidos = ['AProveedorAveria','AProveedorVencido','ReingresoBuenEstado','cliente','proveedor','interna','BuenEstado','MalEstado'];
        if (!in_array($data['tipo'], $tiposValidos, true)) {
            return $this->error($response, 'tipo inválido');
        }

        $detalles = $data['detalles'] ?? [];
        if (empty($detalles) || !is_array($detalles)) {
            return $this->error($response, 'Debe incluir al menos un producto a devolver');
        }

        // Blindaje 2026-08-06: si la línea trae cantidad_cajas/cantidad_saldo, el
        // servidor recalcula la cantidad real de unidades a partir de unidades_caja
        // del producto — no confía en un total ya sumado por el cliente.
        $prodIdsUpc = array_values(array_unique(array_filter(array_map(
            fn($d) => (int)($d['producto_id'] ?? 0), $detalles
        ))));
        $upcMap = $prodIdsUpc ? \App\Models\Producto::whereIn('id', $prodIdsUpc)->pluck('unidades_caja', 'id') : collect();
        foreach ($detalles as &$d) {
            if (isset($d['cantidad_cajas']) || isset($d['cantidad_saldo'])) {
                $upc = max(1, (int)($upcMap[(int)($d['producto_id'] ?? 0)] ?? 1));
                $d['cantidad'] = ((float)($d['cantidad_cajas'] ?? 0) * $upc) + (float)($d['cantidad_saldo'] ?? 0);
            }
        }
        unset($d);

        // Validate causal_devolucion_id if provided
        $causalId = !empty($data['causal_devolucion_id']) ? (int)$data['causal_devolucion_id'] : null;
        if ($causalId !== null) {
            $causalExiste = CausalDevolucion::where('empresa_id', $empresaId)
                ->where('id', $causalId)
                ->where('activo', true)
                ->exists();
            if (!$causalExiste) {
                return $this->error($response, 'causal_devolucion_id no existe o no está activa', 422);
            }
        }

        // Validate ubicacion_patio_id if provided
        $ubicacionPatioId = !empty($data['ubicacion_patio_id']) ? (int)$data['ubicacion_patio_id'] : null;
        if ($ubicacionPatioId !== null) {
            $ubicExiste = DB::table('ubicaciones')
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('id', $ubicacionPatioId)
                ->exists();
            if (!$ubicExiste) {
                return $this->error($response, 'ubicacion_patio_id no existe en esta sucursal', 422);
            }
        }

        // Validate items before entering the transaction
        foreach ($detalles as $i => $d) {
            if (empty($d['producto_id']) || !is_numeric($d['producto_id'])) {
                return $this->error($response, 'Línea ' . ($i + 1) . ': producto_id inválido', 422);
            }
            if (empty($d['cantidad']) || (float)$d['cantidad'] <= 0) {
                return $this->error($response, 'Línea ' . ($i + 1) . ': cantidad debe ser mayor a cero', 422);
            }
        }

        // Procesar fotos si fueron adjuntadas en el request multipart
        $files     = $request->getUploadedFiles();
        $fotoPaths = [];

        [$devId, $numero, $consecutivo, $productosMovidos] = DB::transaction(function () use (
            $user, $empresaId, $sucursalId, $data, $detalles, $causalId, $ubicacionPatioId, $files, &$fotoPaths
        ) {
            $numero      = Devolucion::generarNumero($empresaId);
            $consecutivo = Devolucion::generarConsecutivo($empresaId);

            if (!empty($files)) {
                $uploadDir = dirname(__DIR__, 2) . '/public/uploads/devoluciones/' . $numero . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fotoKeys = ['fotos', 'foto_0', 'foto_1', 'foto_2', 'foto_3', 'foto_4', 'foto_5', 'foto_6', 'foto_7', 'foto_8', 'foto_9'];
                foreach ($fotoKeys as $key) {
                    if (!isset($files[$key])) continue;
                    $fileItems = is_array($files[$key]) ? $files[$key] : [$files[$key]];
                    foreach ($fileItems as $uploadedFile) {
                        if (!$uploadedFile instanceof \Psr\Http\Message\UploadedFileInterface) continue;
                        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) continue;
                        $ext     = strtolower(pathinfo($uploadedFile->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION));
                        $allowed = ['jpg','jpeg','png','webp','heic'];
                        if (!in_array($ext, $allowed)) continue;
                        $fileName = uniqid('ev_', true) . '.' . $ext;
                        $uploadedFile->moveTo($uploadDir . $fileName);
                        $fotoPaths[] = '/uploads/devoluciones/' . $numero . '/' . $fileName;
                        if (count($fotoPaths) >= 10) break 2;
                    }
                }
            }

            $dev = Devolucion::create([
                'empresa_id'              => $empresaId,
                'sucursal_id'             => $sucursalId,
                'numero_devolucion'       => $numero,
                'consecutivo_devolucion'  => $consecutivo,
                'tipo'                    => $data['tipo'],
                'estado'                  => Devolucion::ESTADO_PENDIENTE,
                'motivo_general'          => $data['motivo_general'],
                'referencia_externa'      => $data['referencia_externa'] ?? null,
                'auxiliar_id'             => $user->id,
                'solicitado_por'          => $user->id,
                'fecha_movimiento'        => date('Y-m-d'),
                'hora_inicio'             => date('H:i:s'),
                'recepcion_id'            => $data['recepcion_id'] ?? null,
                'proveedor'               => $data['proveedor'] ?? null,
                'causal_devolucion_id'    => $causalId,
                'responsable_devolucion'  => $data['responsable_devolucion'] ?? null,
                'ubicacion_patio_id'      => $ubicacionPatioId,
                'cliente_origen_id'       => !empty($data['cliente_origen_id']) ? (int)$data['cliente_origen_id'] : (!empty($data['tercero_id']) ? (int)$data['tercero_id'] : (!empty($data['cliente_id']) ? (int)$data['cliente_id'] : null)),
                'sucursal_origen_id'      => !empty($data['sucursal_origen_id']) ? (int)$data['sucursal_origen_id'] : null,
                'fotos_json'              => count($fotoPaths) > 0 ? $fotoPaths : null,
            ]);

            foreach ($detalles as $d) {
                $tipoDev   = $data['tipo'] ?? 'cliente';
                // El nuevo formulario ya no pide "condición" por ítem — el tipo de
                // devolución (Buen Estado / Mal Estado), elegido una sola vez para
                // todo el formulario, decide la condición por defecto de cada línea.
                $condicion = $d['condicion'] ?? ($tipoDev === 'MalEstado' ? 'dañado' : 'bueno');
                $destino   = !empty($d['destino']) ? $d['destino'] : (
                    ($tipoDev === 'proveedor' || $tipoDev === 'AProveedorAveria' || $tipoDev === 'AProveedorVencido')
                        ? 'DevolucionProveedor'
                        : (($condicion === 'dañado' || $condicion === 'vencido') ? 'InventarioObsoleto' : 'Reingreso')
                );

                DevolucionDetalle::create([
                    'devolucion_id'     => $dev->id,
                    'producto_id'       => (int)$d['producto_id'],
                    'lote'              => $d['lote'] ?? null,
                    'fecha_vencimiento' => $d['fecha_vencimiento'] ?? null,
                    'cantidad'          => (float)($d['cantidad'] ?? 0),
                    'condicion'         => $condicion,
                    'motivo'            => $d['motivo'] ?? 'Otro',
                    'detalle_motivo'    => $d['motivo_item'] ?? null,
                    'destino'           => $destino,
                ]);
            }

            $productosMovidos = [];

            // BuenEstado/MalEstado son devoluciones DESDE el cliente (igual que 'cliente'
            // legacy) — el producto entra físicamente a la bodega y su inventario se suma
            // recién en procesar() (triage restock/descarte/proveedor), no aquí al crear.
            if (!in_array($data['tipo'], ['cliente', 'BuenEstado', 'MalEstado'], true)) {
                foreach ($detalles as $d) {
                    $productoId   = (int)$d['producto_id'];
                    $cantidad     = (float)($d['cantidad'] ?? 0);
                    if ($cantidad <= 0) continue;

                    $lote         = $d['lote'] ?? null;
                    $ubicOrigenId = !empty($d['ubicacion_origen_id']) ? (int)$d['ubicacion_origen_id'] : null;

                    if ($ubicOrigenId) {
                        $invRow = DB::table('inventarios')
                            ->where('empresa_id',  $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('producto_id', $productoId)
                            ->where('ubicacion_id', $ubicOrigenId)
                            ->where('estado', 'Disponible')
                            ->where('cantidad', '>', 0)
                            ->lockForUpdate()->first();
                    } else {
                        $invRow = DB::table('inventarios as i')
                            ->join('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                            ->where('i.empresa_id',  $empresaId)
                            ->where('i.sucursal_id', $sucursalId)
                            ->where('i.producto_id', $productoId)
                            ->where('i.estado', 'Disponible')
                            ->where('i.cantidad', '>', 0)
                            ->where('u.tipo_ubicacion', 'Patio')
                            ->select('i.*')
                            ->lockForUpdate()->first();

                        if (!$invRow) {
                            $invRow = DB::table('inventarios')
                                ->where('empresa_id',  $empresaId)
                                ->where('sucursal_id', $sucursalId)
                                ->where('producto_id', $productoId)
                                ->where('estado', 'Disponible')
                                ->where('cantidad', '>', 0)
                                ->lockForUpdate()->first();
                        }
                    }

                    if (!$invRow) continue;

                    $prod     = DB::table('productos')->where('id', $productoId)->select('unidades_caja')->first();
                    $cajasUnd = max(1, (int)(($prod->unidades_caja ?? 1) ?: 1));
                    $nuevaCant = max(0, $invRow->cantidad - $cantidad);
                    $cantCajas = (int)floor($nuevaCant / $cajasUnd);
                    $saldos    = fmod($nuevaCant, $cajasUnd);

                    DB::table('inventarios')->where('id', $invRow->id)->update([
                        'cantidad'      => $nuevaCant,
                        'cantidad_cajas'=> $cantCajas,
                        'saldos'        => $saldos,
                        'updated_at'    => date('Y-m-d H:i:s'),
                    ]);

                    DB::table('movimiento_inventarios')->insert([
                        'empresa_id'         => $empresaId,
                        'sucursal_id'        => $sucursalId,
                        'producto_id'        => $productoId,
                        'tipo_movimiento'    => 'Salida',
                        'cantidad'           => $cantidad,
                        'lote'               => $lote,
                        'fecha_vencimiento'  => $d['fecha_vencimiento'] ?? null,
                        'referencia_tipo'    => 'devolucion',
                        'referencia_id'      => $dev->id,
                        'auxiliar_id'        => $user->id,
                        'fecha_movimiento'   => date('Y-m-d'),
                        'hora_inicio'        => date('H:i:s'),
                        'observaciones'      => "Salida devolución {$numero}",
                        'ubicacion_origen_id'=> $invRow->ubicacion_id,
                        'created_at'         => date('Y-m-d H:i:s'),
                    ]);

                    $productosMovidos[$productoId] = true;
                }
            }

            return [$dev->id, $numero, $consecutivo, $productosMovidos];
        });

        // Audit
        $this->audit($user, 'devoluciones', 'crear', 'devoluciones', $devId,
            null, ['numero' => $numero, 'consecutivo' => $consecutivo, 'tipo' => $data['tipo']]);

        // Regla de Oro #3 (modo alerta, no bloquea) — mismo patrón que en PickingController.
        $guardInv = new \App\Helpers\InventoryGuard($empresaId, $sucursalId, $user->id);
        foreach (array_keys($productosMovidos) as $productoId) {
            $guardInv->assertLedgerMatchesStock((int)$productoId);
        }

        // Notify supervisors
        try {
            if (\Illuminate\Database\Capsule\Manager::schema()->hasTable('anomaly_flags')) {
                \Illuminate\Database\Capsule\Manager::table('anomaly_flags')->insert([
                    'empresa_id'     => $empresaId,
                    'sucursal_id'    => $sucursalId,
                    'tipo'           => 'devolucion',
                    'severidad'      => 'media',
                    'titulo'         => "Devolución #{$consecutivo} ({$numero}) — aprobación requerida",
                    'descripcion'    => count($detalles) . ' ítem(s). Motivo: ' . mb_substr($data['motivo_general'], 0, 100),
                    'datos_anomalia' => json_encode(['devolucion_id' => $devId, 'consecutivo' => $consecutivo, 'tipo' => $data['tipo']], JSON_UNESCAPED_UNICODE),
                    'estado'         => 'pendiente',
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            error_log('store devolucion: anomaly_flag insert failed: ' . $e->getMessage());
        }

        return $this->created($response, [
            'devolucion_id'          => $devId,
            'numero'                 => $numero,
            'consecutivo_devolucion' => $consecutivo,
            'fotos'                  => $fotoPaths
        ], 'Devolución registrada');
    }

    /**
     * POST /api/devoluciones/{id}/fotos
     * Subir fotos adicionales a una devolución existente.
     */
    public function uploadFotos(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $id        = (int)($args['id'] ?? 0);

        $dev = Devolucion::where('empresa_id', $empresaId)->find($id);
        if (!$dev) return $this->notFound($response);

        $files = $request->getUploadedFiles();
        if (empty($files)) {
            return $this->error($response, 'No se enviaron archivos', 400);
        }

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/devoluciones/' . $dev->numero_devolucion . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $currentFotos = is_array($dev->fotos_json) ? $dev->fotos_json : (json_decode($dev->fotos_json ?? '[]', true) ?: []);
        $newFotos     = [];

        $fotoKeys = ['fotos', 'foto', 'foto_0', 'foto_1', 'foto_2', 'foto_3', 'foto_4'];
        foreach ($fotoKeys as $key) {
            if (!isset($files[$key])) continue;
            $fileItems = is_array($files[$key]) ? $files[$key] : [$files[$key]];
            foreach ($fileItems as $uploadedFile) {
                if (!$uploadedFile instanceof \Psr\Http\Message\UploadedFileInterface) continue;
                if ($uploadedFile->getError() !== UPLOAD_ERR_OK) continue;
                $ext     = strtolower(pathinfo($uploadedFile->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','webp','heic'];
                if (!in_array($ext, $allowed)) continue;
                $fileName = uniqid('ev_', true) . '.' . $ext;
                $uploadedFile->moveTo($uploadDir . $fileName);
                $relPath = '/uploads/devoluciones/' . $dev->numero_devolucion . '/' . $fileName;
                $newFotos[] = $relPath;
            }
        }

        $allFotos = array_merge($currentFotos, $newFotos);
        $dev->fotos_json = $allFotos;
        $dev->save();

        return $this->ok($response, ['fotos' => $allFotos], 'Fotos subidas con éxito');
    }

    /**
     * GET /api/devoluciones/buscar-consecutivo
     * Consulta por consecutivo numérico simple (consecutivo=47 o q=47)
     */
    public function buscarConsecutivo(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $params     = $request->getQueryParams();
        $num        = (int)($params['consecutivo'] ?? $params['q'] ?? 0);

        if ($num <= 0) {
            return $this->error($response, 'Consecutivo no válido', 400);
        }

        try {
            $dev = Devolucion::with(['detalles.producto', 'causal', 'ubicacionPatio', 'sucursalOrigen', 'clienteOrigen', 'auxiliar', 'solicitante', 'aprobador', 'procesador'])
                ->where('empresa_id', $empresaId)
                ->where(function($q) use ($num) {
                    $q->where('consecutivo_devolucion', $num)
                      ->orWhere('numero_devolucion', 'like', "%{$num}");
                })
                ->first();

            if (!$dev) {
                return $this->json($response, ['error' => true, 'message' => "No se encontró devolución con el consecutivo #{$num}"], 404);
            }

            return $this->ok($response, $dev);
        } catch (\Exception $e) {
            error_log('DevolucionController::buscarConsecutivo error: ' . $e->getMessage());
            return $this->error($response, 'Error al buscar devolución.', 500);
        }
    }

    // ── GET /api/devoluciones/odc/{odcId} ────────────────────────────────────
    public function getByOdc(Request $request, Response $response, array $args): Response
    {
        $user  = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $odcId = (int)($args['odcId'] ?? 0);
        try {
            $devs = Devolucion::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('odc_id', $odcId)
                ->with('detalles.producto')
                ->orderBy('created_at', 'desc')
                ->get();
            return $this->ok($response, $devs);
        } catch (\Exception $e) {
            return $this->error($response, $e->getMessage());
        }
    }

    // NOTA: desdeRecepcion() y autorizar() fueron eliminados — auditoría confirmó que
    // implementaban una máquina de estados paralela ('PendienteAutorizacion'/'Autorizada')
    // incompatible con la real (ESTADO_PENDIENTE/APROBADA/PROCESADA/RECHAZADA/ANULADA) y que
    // ningún frontend los invocaba. Riesgo: dejar un registro en un estado que el resto del
    // controlador (aprobar/rechazar/procesar/anular) no reconoce.

    // ── POST /api/devoluciones/{id}/aprobar ───────────────────────────────────
    public function aprobar(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $dev = Devolucion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if ($dev->estado !== Devolucion::ESTADO_PENDIENTE) {
            return $this->error($response, 'La devolución no está en estado PendienteAprobacion', 409);
        }

        $dev->estado      = Devolucion::ESTADO_APROBADA;
        $dev->aprobado_por = $user->id;
        $dev->aprobado_at  = date('Y-m-d H:i:s');
        $dev->save();

        $this->audit($user, 'devoluciones', 'aprobar', 'devoluciones', $dev->id,
            ['estado' => Devolucion::ESTADO_PENDIENTE], ['estado' => Devolucion::ESTADO_APROBADA]);

        return $this->ok($response, null, 'Devolución aprobada');
    }

    // ── POST /api/devoluciones/{id}/rechazar ──────────────────────────────────
    public function rechazar(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $data       = $request->getParsedBody() ?? [];

        $dev = Devolucion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if ($dev->estado !== Devolucion::ESTADO_PENDIENTE) {
            return $this->error($response, 'La devolución no está en estado PendienteAprobacion', 409);
        }

        $productosMovidos = DB::transaction(function () use ($dev, $user, $data) {
            $productos = $this->reponerStockDevolucion($dev, $user, 'Rechazo de devolución');

            $dev->estado        = Devolucion::ESTADO_RECHAZADA;
            // Reuse aprobado_por/aprobado_at to record who acted — estado=Rechazada disambiguates
            $dev->aprobado_por  = $user->id;
            $dev->aprobado_at   = date('Y-m-d H:i:s');
            $dev->observaciones = $data['motivo_rechazo'] ?? null;
            $dev->save();

            return $productos;
        });

        $this->audit($user, 'devoluciones', 'rechazar', 'devoluciones', $dev->id,
            ['estado' => Devolucion::ESTADO_PENDIENTE], ['estado' => Devolucion::ESTADO_RECHAZADA]);

        // Regla de Oro #3 (modo alerta, no bloquea) — mismo patrón que en PickingController.
        $guardInv = new \App\Helpers\InventoryGuard($empresaId, $sucursalId, $user->id);
        foreach ($productosMovidos as $productoId) {
            $guardInv->assertLedgerMatchesStock($productoId);
        }

        return $this->ok($response, null, 'Devolución rechazada');
    }

    /**
     * Revierte el descuento de inventario que store() aplicó al crear la devolución
     * (solo aplica a tipos distintos de 'cliente', que desde la corrección de auditoría
     * ya no descuentan nada al crear). Se apoya en los MovimientoInventario tipo 'Salida'
     * ya registrados para esta devolución, para reponer exactamente lo que se descontó
     * — misma ubicación, mismo lote — con su propio registro de auditoría inverso.
     *
     * @return int[] producto_id únicos tocados (para validar la invariante Regla de Oro #3 tras el commit).
     */
    private function reponerStockDevolucion(Devolucion $dev, $user, string $motivo): array
    {
        $salidas = DB::table('movimiento_inventarios')
            ->where('referencia_tipo', 'devolucion')
            ->where('referencia_id', $dev->id)
            ->where('tipo_movimiento', 'Salida')
            ->get();

        $productosMovidos = [];

        foreach ($salidas as $mov) {
            if (!$mov->ubicacion_origen_id) continue;

            $inv = DB::table('inventarios')
                ->where('empresa_id', $dev->empresa_id)
                ->where('sucursal_id', $dev->sucursal_id)
                ->where('producto_id', $mov->producto_id)
                ->where('ubicacion_id', $mov->ubicacion_origen_id)
                ->where('estado', 'Disponible')
                ->when($mov->lote, fn($q) => $q->where('lote', $mov->lote))
                ->when(!$mov->lote, fn($q) => $q->whereNull('lote'))
                ->lockForUpdate()->first();

            $prod     = DB::table('productos')->where('id', $mov->producto_id)->select('unidades_caja')->first();
            $cajasUnd = max(1, (int)(($prod->unidades_caja ?? 1) ?: 1));

            if ($inv) {
                $nuevaCant = (float)$inv->cantidad + (float)$mov->cantidad;
                DB::table('inventarios')->where('id', $inv->id)->update([
                    'cantidad'       => $nuevaCant,
                    'cantidad_cajas' => (int)floor($nuevaCant / $cajasUnd),
                    'saldos'         => fmod($nuevaCant, $cajasUnd),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            } else {
                DB::table('inventarios')->insert([
                    'empresa_id'      => $dev->empresa_id,
                    'sucursal_id'     => $dev->sucursal_id,
                    'producto_id'     => $mov->producto_id,
                    'ubicacion_id'    => $mov->ubicacion_origen_id,
                    'lote'            => $mov->lote,
                    'fecha_vencimiento' => $mov->fecha_vencimiento,
                    'estado'          => 'Disponible',
                    'cantidad'        => $mov->cantidad,
                    'cantidad_cajas'  => (int)floor((float)$mov->cantidad / $cajasUnd),
                    'saldos'          => fmod((float)$mov->cantidad, $cajasUnd),
                    'cantidad_reservada' => 0,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }

            DB::table('movimiento_inventarios')->insert([
                'empresa_id'          => $dev->empresa_id,
                'sucursal_id'         => $dev->sucursal_id,
                'producto_id'         => $mov->producto_id,
                'tipo_movimiento'     => 'AjustePositivo',
                'cantidad'            => $mov->cantidad,
                'lote'                => $mov->lote,
                'fecha_vencimiento'   => $mov->fecha_vencimiento,
                'referencia_tipo'     => 'devolucion',
                'referencia_id'       => $dev->id,
                'auxiliar_id'         => $user->id,
                'fecha_movimiento'    => date('Y-m-d'),
                'hora_inicio'         => date('H:i:s'),
                'observaciones'       => "{$motivo} {$dev->numero_devolucion} — reversión de stock descontado",
                'ubicacion_destino_id'=> $mov->ubicacion_origen_id,
                'created_at'          => date('Y-m-d H:i:s'),
            ]);

            $productosMovidos[$mov->producto_id] = true;
        }

        return array_map('intval', array_keys($productosMovidos));
    }

    // ── POST /api/devoluciones/{id}/anular ────────────────────────────────────
    public function anular(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $dev = Devolucion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if (in_array($dev->estado, [
            Devolucion::ESTADO_PROCESADA,
            Devolucion::ESTADO_ANULADA,
            Devolucion::ESTADO_RECHAZADA,
        ], true)) {
            return $this->error($response, 'No se puede anular una devolución ya procesada, rechazada o anulada', 409);
        }

        $estadoAnterior = $dev->estado;
        $productosMovidos = DB::transaction(function () use ($dev, $user) {
            $productos = $this->reponerStockDevolucion($dev, $user, 'Anulación de devolución');
            $dev->estado = Devolucion::ESTADO_ANULADA;
            $dev->save();
            return $productos;
        });

        $this->audit($user, 'devoluciones', 'anular', 'devoluciones', $dev->id,
            ['estado' => $estadoAnterior], ['estado' => Devolucion::ESTADO_ANULADA]);

        // Regla de Oro #3 (modo alerta, no bloquea) — mismo patrón que en PickingController.
        $guardInv = new \App\Helpers\InventoryGuard($empresaId, $sucursalId, $user->id);
        foreach ($productosMovidos as $productoId) {
            $guardInv->assertLedgerMatchesStock($productoId);
        }

        return $this->ok($response, null, 'Devolución anulada');
    }

    // ── POST /api/devoluciones/{id}/procesar ──────────────────────────────────
    public function procesar(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        // BUG CORREGIDO 2026-08-20: a diferencia de sus 3 hermanas (aprobar/rechazar/
        // anular, todas con requireSupervisor), procesar() —la que efectivamente
        // mueve el inventario real (restock/descarte/proveedor)— no validaba ningún
        // rol. Inconsistencia, no diseño intencional.
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $data       = $request->getParsedBody() ?? [];

        $dev = Devolucion::with('detalles')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if ($dev->estado !== Devolucion::ESTADO_APROBADA) {
            return $this->error($response, 'La devolución debe estar en estado Aprobada', 409);
        }

        $itemDecisiones = [];
        foreach ($data['items'] ?? [] as $it) {
            $itemDecisiones[(int)$it['id']] = $it['destino'] ?? null;
        }

        $destinosValidos = [DevolucionDetalle::DESTINO_RESTOCK, DevolucionDetalle::DESTINO_DESCARTE, DevolucionDetalle::DESTINO_PROVEEDOR];

        foreach ($dev->detalles as $det) {
            $destino = $itemDecisiones[$det->id] ?? null;
            if (!in_array($destino, $destinosValidos, true)) {
                return $this->error($response, 'Todos los ítems deben tener destino asignado (restock, descarte o proveedor)', 422);
            }
            // DevolucionController no tenía ninguna validación de vencimiento — un producto
            // ya vencido podía reingresar a stock "Disponible" (aunque fuera a PATIO-DEV)
            // sin ningún control, a diferencia de picking/packing/recepción que sí bloquean
            // vencido. Restock de un ítem ya vencido se rechaza; debe ir a descarte o proveedor.
            if ($destino === DevolucionDetalle::DESTINO_RESTOCK
                && $det->fecha_vencimiento
                && strtotime($det->fecha_vencimiento) < strtotime(date('Y-m-d'))
            ) {
                return $this->error($response,
                    "El ítem \"{$det->producto_id}\" (lote {$det->lote}, vence {$det->fecha_vencimiento}) ya está vencido. No se puede reingresar a stock — seleccione descarte o devolución a proveedor.",
                    422);
            }
        }

        $resultado = \Illuminate\Database\Capsule\Manager::transaction(function () use (
            $dev, $user, $empresaId, $sucursalId, $itemDecisiones, $response
        ) {
            $devProveedorId    = null;
            $devProveedorItems = [];
            $productosMovidos  = [];

            // Ensure PATIO-DEV location exists
            $patioDev = \Illuminate\Database\Capsule\Manager::table('ubicaciones')
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('codigo', 'PATIO-DEV')
                ->first();
            if (!$patioDev) {
                // Ensure ZONA DEVOLUCIONES exists
                $zonaDev = \Illuminate\Database\Capsule\Manager::table('zonas')
                    ->where('empresa_id', $empresaId)
                    ->where('codigo', 'DEVOLUCIONES')
                    ->first();
                if (!$zonaDev) {
                    \Illuminate\Database\Capsule\Manager::table('zonas')->insert([
                        'empresa_id' => $empresaId,
                        'codigo' => 'DEVOLUCIONES',
                        'descripcion' => 'Zona de devoluciones',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $patioDevId = \Illuminate\Database\Capsule\Manager::table('ubicaciones')->insertGetId([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'codigo' => 'PATIO-DEV',
                    'zona' => 'DEVOLUCIONES',
                    'tipo_ubicacion' => 'Patio',
                    'activo' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $patioDevId = $patioDev->id;
            }

            foreach ($dev->detalles as $det) {
                $destino      = $itemDecisiones[$det->id];
                $det->destino = $destino;
                $det->save();

                if ($destino === DevolucionDetalle::DESTINO_RESTOCK) {
                    $inv = \Illuminate\Database\Capsule\Manager::table('inventarios')
                        ->where('empresa_id',  $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $det->producto_id)
                        ->where('lote',        $det->lote)
                        ->where('estado',      'Disponible')
                        ->where('ubicacion_id',$patioDevId)
                        ->lockForUpdate()
                        ->first();

                    // Pallet genérico de devoluciones: así el ítem cae agrupado y visible
                    // en el módulo Ubicar (PutawayController::listarPatio agrupa por
                    // numero_pallet) en vez de caer en "Artículos sin pallet". numero_pallet
                    // es INTEGER — se usa un número reservado (fuera del rango real 1-469
                    // usado hoy por los pallets físicos) en vez de un código de texto.
                    $palletGenericoDevoluciones = 999999;
                    if ($inv) {
                        \Illuminate\Database\Capsule\Manager::table('inventarios')
                            ->where('id', $inv->id)
                            ->update([
                                'cantidad'      => $inv->cantidad + $det->cantidad,
                                'numero_pallet' => $inv->numero_pallet ?: $palletGenericoDevoluciones,
                                'updated_at'    => date('Y-m-d H:i:s'),
                            ]);
                    } else {
                        \Illuminate\Database\Capsule\Manager::table('inventarios')->insert([
                            'empresa_id'         => $empresaId,
                            'sucursal_id'        => $sucursalId,
                            'producto_id'        => $det->producto_id,
                            'ubicacion_id'       => $patioDevId,
                            'lote'               => $det->lote,
                            'fecha_vencimiento'  => $det->fecha_vencimiento,
                            'cantidad'           => $det->cantidad,
                            'cantidad_reservada' => 0,
                            'numero_pallet'      => $palletGenericoDevoluciones,
                            'estado'             => 'Disponible',
                            'created_at'         => date('Y-m-d H:i:s'),
                            'updated_at'         => date('Y-m-d H:i:s'),
                        ]);
                    }

                    \Illuminate\Database\Capsule\Manager::table('movimiento_inventarios')->insert([
                        'empresa_id'        => $empresaId,
                        'sucursal_id'       => $sucursalId,
                        'producto_id'       => $det->producto_id,
                        'tipo_movimiento'   => 'Devolucion',
                        'cantidad'          => $det->cantidad,
                        'lote'              => $det->lote,
                        'fecha_vencimiento' => $det->fecha_vencimiento,
                        'referencia_tipo'   => 'devolucion',
                        'referencia_id'     => $dev->id,
                        'auxiliar_id'       => $user->id,
                        'fecha_movimiento'  => date('Y-m-d'),
                        'hora_inicio'       => date('H:i:s'),
                        'observaciones'     => "Restock devolución {$dev->numero_devolucion}",
                        'created_at'        => date('Y-m-d H:i:s'),
                    ]);

                    $productosMovidos[$det->producto_id] = true;

                } elseif ($destino === DevolucionDetalle::DESTINO_DESCARTE) {
                    \Illuminate\Database\Capsule\Manager::table('movimiento_inventarios')->insert([
                        'empresa_id'        => $empresaId,
                        'sucursal_id'       => $sucursalId,
                        'producto_id'       => $det->producto_id,
                        'tipo_movimiento'   => 'AjusteNegativo',
                        'cantidad'          => -abs($det->cantidad),
                        'lote'              => $det->lote,
                        'fecha_vencimiento' => $det->fecha_vencimiento,
                        'referencia_tipo'   => 'devolucion',
                        'referencia_id'     => $dev->id,
                        'auxiliar_id'       => $user->id,
                        'fecha_movimiento'  => date('Y-m-d'),
                        'hora_inicio'       => date('H:i:s'),
                        'observaciones'     => "Descarte devolución {$dev->numero_devolucion}",
                        'created_at'        => date('Y-m-d H:i:s'),
                    ]);

                    // Sin esto trazado aparte: este tipo NO actualiza `inventarios` en
                    // ninguna rama (el ítem descartado nunca entró a `inventarios` si
                    // vino de una devolución tipo 'cliente') — se marca igual para que
                    // la Regla de Oro #3 (abajo) pueda detectar y loguear el posible
                    // descuadre en vez de dejarlo pasar en silencio.
                    $productosMovidos[$det->producto_id] = true;

                } elseif ($destino === DevolucionDetalle::DESTINO_PROVEEDOR) {
                    $devProveedorItems[] = $det;
                }
            }

            if (!empty($devProveedorItems)) {
                $numProv = Devolucion::generarNumero($empresaId);
                $devProv = Devolucion::create([
                    'empresa_id'        => $empresaId,
                    'sucursal_id'       => $sucursalId,
                    'numero_devolucion' => $numProv,
                    'tipo'              => Devolucion::TIPO_PROVEEDOR,
                    'estado'            => Devolucion::ESTADO_PENDIENTE,
                    'motivo_general'    => "Generada automáticamente desde {$dev->numero_devolucion}",
                    'auxiliar_id'       => $user->id,
                    'solicitado_por'    => $user->id,
                    'fecha_movimiento'  => date('Y-m-d'),
                    'hora_inicio'       => date('H:i:s'),
                ]);
                foreach ($devProveedorItems as $det) {
                    DevolucionDetalle::create([
                        'devolucion_id'     => $devProv->id,
                        'producto_id'       => $det->producto_id,
                        'lote'              => $det->lote,
                        'fecha_vencimiento' => $det->fecha_vencimiento,
                        'cantidad'          => $det->cantidad,
                        'condicion'         => $det->condicion,
                        'motivo'            => $det->motivo ?? 'Otro',
                        'destino'           => 'DevolucionProveedor',
                    ]);
                }
                $devProveedorId = $devProv->id;
            }

            $dev->estado       = Devolucion::ESTADO_PROCESADA;
            $dev->procesado_por = $user->id;
            $dev->procesado_at  = date('Y-m-d H:i:s');
            $dev->save();

            $this->audit($user, 'devoluciones', 'procesar', 'devoluciones', $dev->id,
                ['estado' => Devolucion::ESTADO_APROBADA], ['estado' => Devolucion::ESTADO_PROCESADA]);

            return ['devolucion_proveedor_id' => $devProveedorId, 'productos_movidos' => $productosMovidos];
        });

        // Regla de Oro #3 (modo alerta, no bloquea) — mismo patrón que en PickingController.
        $guardInv = new \App\Helpers\InventoryGuard($empresaId, $sucursalId, $user->id);
        foreach (array_keys($resultado['productos_movidos']) as $productoId) {
            $guardInv->assertLedgerMatchesStock((int)$productoId);
        }

        return $this->ok($response, ['devolucion_proveedor_id' => $resultado['devolucion_proveedor_id']], 'Devolución procesada correctamente');
    }

    // NOTA: completar() fue eliminado por la misma razón — usaba estado='Completada' (ajeno
    // al enum real de Devolucion) sin validar el estado previo ni ejecutar la lógica de
    // destinos/inventario de procesar(). Ningún frontend lo invocaba.

    /**
     * GET /api/devoluciones/resumen/proveedor/{proveedor_id}
     * Resumen de devoluciones por proveedor - últimos 30 días
     */
    public function resumenProveedor(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $proveedor_id = (int)($args['proveedor_id'] ?? 0);

        try {
            $hace30 = date('Y-m-d', strtotime('-30 days'));

            $stats = \Illuminate\Database\Capsule\Manager::table('devoluciones')
                ->where('empresa_id', $empresaId)
                ->where('proveedor', 'LIKE', "%{$proveedor_id}%")
                ->where('created_at', '>=', $hace30)
                ->select(
                    \Illuminate\Database\Capsule\Manager::raw('COUNT(*) as total'),
                    \Illuminate\Database\Capsule\Manager::raw('SUM(CASE WHEN estado = "Completada" THEN 1 ELSE 0 END) as completadas'),
                    \Illuminate\Database\Capsule\Manager::raw('SUM(CASE WHEN estado = "PendienteAutorizacion" THEN 1 ELSE 0 END) as pendientes'),
                    \Illuminate\Database\Capsule\Manager::raw('COUNT(DISTINCT DATE(created_at)) as dias_con_devoluciones')
                )
                ->first();

            return $this->json($response, [
                'error' => false,
                'data' => [
                    'total_devoluciones' => $stats->total ?? 0,
                    'completadas' => $stats->completadas ?? 0,
                    'pendientes_autorizacion' => $stats->pendientes ?? 0,
                    'dias_con_devoluciones' => $stats->dias_con_devoluciones ?? 0,
                    'periodo' => "Últimos 30 días"
                ]
            ]);

        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    // ── GET /api/devoluciones/causales ───────────────────────────────────────────
    /**
     * Devuelve las causales activas de la empresa.
     */
    public function getCausales(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        try {
            $causales = CausalDevolucion::where('empresa_id', $empresaId)
                ->where('activo', true)
                ->orderBy('causal')
                ->get();
            return $this->ok($response, $causales);
        } catch (\Exception $e) {
            error_log('DevolucionController::getCausales error: ' . $e->getMessage());
            return $this->error($response, 'Error al obtener causales.', 500);
        }
    }

    // ── POST /api/devoluciones/causales ──────────────────────────────────────────
    /**
     * Crea una nueva causal. Solo Admin/Supervisor.
     */
    public function createCausal(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Permiso denegado'], 403);
        }
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $data      = $request->getParsedBody() ?? [];

        if ($deny = $this->requireFields($data, ['causal', 'responsable'], $response)) {
            return $deny;
        }
        try {
            $causal = CausalDevolucion::create([
                'empresa_id'  => $empresaId,
                'causal'      => trim($data['causal']),
                'responsable' => trim($data['responsable']),
                'descripcion' => $data['descripcion'] ?? null,
                'activo'      => true,
            ]);
            return $this->created($response, $causal, 'Causal creada');
        } catch (\Exception $e) {
            error_log('DevolucionController::createCausal error: ' . $e->getMessage());
            return $this->error($response, 'Error al crear causal.', 500);
        }
    }

    // ── DELETE /api/devoluciones/causales/{id} ───────────────────────────────────
    /**
     * Elimina una causal. Solo Admin/Supervisor. Bloqueada si ya está en uso en
     * alguna devolución — mismo criterio que deleteEstado() para estados CRM.
     */
    public function deleteCausal(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Permiso denegado'], 403);
        }
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $id        = (int)($args['id'] ?? 0);

        try {
            $causal = CausalDevolucion::where('empresa_id', $empresaId)->find($id);
            if (!$causal) {
                return $this->json($response, ['error' => true, 'message' => 'Causal no encontrada.'], 404);
            }

            $enUso = Devolucion::where('empresa_id', $empresaId)->where('causal_devolucion_id', $id)->exists();
            if ($enUso) {
                return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar: esta causal ya está en uso en una o más devoluciones. Desactívela en su lugar.'], 409);
            }

            $causal->delete();
            return $this->ok($response, null, 'Causal eliminada');
        } catch (\Exception $e) {
            error_log('DevolucionController::deleteCausal error: ' . $e->getMessage());
            return $this->error($response, 'Error al eliminar causal.', 500);
        }
    }

    // ── PUT /api/devoluciones/causales/{id} ──────────────────────────────────────
    /**
     * Edita una causal existente. Solo Admin/Supervisor.
     */
    public function updateCausal(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!in_array($user->rol ?? '', ['Admin', 'Supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Permiso denegado'], 403);
        }
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $id        = (int)($args['id'] ?? 0);
        $data      = $request->getParsedBody() ?? [];

        try {
            $causal = CausalDevolucion::where('empresa_id', $empresaId)->find($id);
            if (!$causal) {
                return $this->json($response, ['error' => true, 'message' => 'Causal no encontrada.'], 404);
            }

            if (!empty($data['causal']))      $causal->causal      = trim($data['causal']);
            if (!empty($data['responsable']))  $causal->responsable = trim($data['responsable']);
            if (array_key_exists('descripcion', $data)) $causal->descripcion = $data['descripcion'];
            if (array_key_exists('activo', $data))      $causal->activo      = (bool)$data['activo'];

            $causal->save();
            return $this->ok($response, $causal, 'Causal actualizada');
        } catch (\Exception $e) {
            error_log('DevolucionController::updateCausal error: ' . $e->getMessage());
            return $this->error($response, 'Error al actualizar causal.', 500);
        }
    }

    // ── GET /api/devoluciones/dashboard ──────────────────────────────────────────
    /**
     * Dashboard KPI de devoluciones.
     * ?fecha_desde= &fecha_hasta=
     */
    public function getDashboard(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $params     = $request->getQueryParams();

        $fechaDesde = $params['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
        $fechaHasta = $params['fecha_hasta'] ?? date('Y-m-d');

        try {
            // ── Total devoluciones en período ────────────────────────────────
            $total = DB::table('devoluciones')
                ->where('empresa_id',  $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->whereBetween('fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->count();

            // ── Por causal ───────────────────────────────────────────────────
            $porCausalRaw = DB::table('devoluciones as d')
                ->leftJoin('causales_devolucion as c', 'c.id', '=', 'd.causal_devolucion_id')
                ->where('d.empresa_id',  $empresaId)
                ->where('d.sucursal_id', $sucursalId)
                ->whereBetween('d.fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->selectRaw('COALESCE(c.causal, \'Sin causal\') as causal, COALESCE(c.responsable, \'\') as responsable, COUNT(*) as cantidad')
                ->groupBy('d.causal_devolucion_id', 'c.causal', 'c.responsable')
                ->orderByDesc('cantidad')
                ->get();

            $porCausal = $porCausalRaw->map(function($row) use ($total) {
                return [
                    'causal'      => $row->causal,
                    'responsable' => $row->responsable,
                    'cantidad'    => (int)$row->cantidad,
                    'porcentaje'  => $total > 0 ? round((int)$row->cantidad / $total * 100, 1) : 0,
                ];
            })->values()->toArray();

            // ── Por mes (últimos 6 meses) ────────────────────────────────────
            $porMesRaw = DB::table('devoluciones')
                ->where('empresa_id',  $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('fecha_movimiento', '>=', date('Y-m-d', strtotime('-6 months')))
                ->selectRaw("DATE_FORMAT(fecha_movimiento, '%Y') as anio, DATE_FORMAT(fecha_movimiento, '%m') as mes, COUNT(*) as cantidad")
                ->groupByRaw("DATE_FORMAT(fecha_movimiento, '%Y'), DATE_FORMAT(fecha_movimiento, '%m')")
                ->orderByRaw("anio ASC, mes ASC")
                ->get();

            $porMes = $porMesRaw->map(fn($r) => [
                'anio'     => (int)$r->anio,
                'mes'      => (int)$r->mes,
                'cantidad' => (int)$r->cantidad,
            ])->values()->toArray();

            // ── Por responsable ──────────────────────────────────────────────
            $porResponsable = DB::table('devoluciones')
                ->where('empresa_id',  $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->whereBetween('fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->whereNotNull('responsable_devolucion')
                ->selectRaw('responsable_devolucion as responsable, COUNT(*) as cantidad')
                ->groupBy('responsable_devolucion')
                ->orderByDesc('cantidad')
                ->get()
                ->map(fn($r) => ['responsable' => $r->responsable, 'cantidad' => (int)$r->cantidad])
                ->values()
                ->toArray();

            // ── Top productos ────────────────────────────────────────────────
            $topProductos = DB::table('devolucion_detalles as dd')
                ->join('devoluciones as d', 'd.id', '=', 'dd.devolucion_id')
                ->join('productos as p', 'p.id', '=', 'dd.producto_id')
                ->where('d.empresa_id',  $empresaId)
                ->where('d.sucursal_id', $sucursalId)
                ->whereBetween('d.fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->selectRaw('p.nombre, p.codigo_interno, COUNT(DISTINCT d.id) as cantidad_veces, SUM(dd.cantidad) as total_unidades')
                ->groupBy('dd.producto_id', 'p.nombre', 'p.codigo_interno')
                ->orderByDesc('cantidad_veces')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'nombre'          => $r->nombre,
                    'codigo_interno'  => $r->codigo_interno,
                    'cantidad_veces'  => (int)$r->cantidad_veces,
                    'total_unidades'  => (float)$r->total_unidades,
                ])
                ->values()
                ->toArray();

            // ── Total referencias (productos distintos) ──────────────────────
            $totalReferencias = DB::table('devolucion_detalles as dd')
                ->join('devoluciones as d', 'd.id', '=', 'dd.devolucion_id')
                ->where('d.empresa_id',  $empresaId)
                ->where('d.sucursal_id', $sucursalId)
                ->whereBetween('d.fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->distinct('dd.producto_id')
                ->count('dd.producto_id');

            return $this->ok($response, [
                'total'             => $total,
                'por_causal'        => $porCausal,
                'por_mes'           => $porMes,
                'por_responsable'   => $porResponsable,
                'top_productos'     => $topProductos,
                'total_referencias' => $totalReferencias,
                'periodo'           => ['desde' => $fechaDesde, 'hasta' => $fechaHasta],
            ]);
        } catch (\Exception $e) {
            error_log('DevolucionController::getDashboard error: ' . $e->getMessage());
            return $this->error($response, 'Error al generar dashboard de devoluciones.', 500);
        }
    }

    /**
     * POST /api/devoluciones/desde-odc  (multipart/form-data desde móvil)
     * Registra devolución amarrada a una ODC. Las unidades devueltas NO se cargan al inventario.
     * El auxiliar puede subir hasta 5 fotos como evidencia.
     */
    public function desdeOdcMovil(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        // Soporte multipart/form-data (con fotos) o JSON (sin fotos)
        $rawBody   = $request->getParsedBody() ?? [];
        $files     = $request->getUploadedFiles();

        // Parseo flexible: puede venir como JSON string dentro de multipart
        $odc_id          = $rawBody['odc_id']          ?? null;
        $motivo_general  = $rawBody['motivo_general']  ?? 'Novedad en recepción';
        $detallesRaw     = $rawBody['detalles']        ?? '[]';
        $causalId        = !empty($rawBody['causal_devolucion_id']) ? (int)$rawBody['causal_devolucion_id'] : null;
        $responsable     = $rawBody['responsable_devolucion'] ?? null;

        // detalles puede venir como JSON string
        if (is_string($detallesRaw)) {
            $detalles = json_decode($detallesRaw, true) ?? [];
        } else {
            $detalles = (array)$detallesRaw;
        }

        if (empty($detalles)) {
            return $this->json($response, ['error' => true, 'message' => 'Debe incluir al menos un producto a devolver.'], 400);
        }

        // Obtener ODC para tomar proveedor y estado
        $odc = null;
        $proveedorNombre = $rawBody['proveedor'] ?? null;
        if ($odc_id) {
            try {
                $odc = \App\Models\OrdenCompra::with('proveedor')->find($odc_id);
                if ($odc && $odc->proveedor) {
                    $proveedorNombre = $odc->proveedor->razon_social ?? $proveedorNombre;
                }
            } catch (\Exception $e) {
                error_log('desdeOdcMovil: No se pudo cargar ODC: ' . $e->getMessage());
            }
        }

        \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
        try {
            // Generar número de devolución único
            $numeroDevolucion = Devolucion::generarNumero($empresaId);

            $devolucion = new Devolucion();
            $devolucion->empresa_id      = $empresaId;
            $devolucion->sucursal_id     = $sucursalId;
            $devolucion->odc_id          = $odc_id ?: null;
            $devolucion->recepcion_id    = null;
            $devolucion->proveedor       = $proveedorNombre;
            $devolucion->numero_devolucion = $numeroDevolucion;
            $devolucion->tipo            = 'AProveedorAveria';
            $devolucion->estado          = 'Procesada';
            $devolucion->motivo_general  = $motivo_general;
            $devolucion->causal_devolucion_id = $causalId;
            $devolucion->responsable_devolucion = $responsable;
            $devolucion->auxiliar_id     = $user->id;
            $devolucion->fecha_movimiento= date('Y-m-d');
            $devolucion->hora_inicio     = date('H:i:s');
            $devolucion->hora_fin        = date('H:i:s');

            // Procesar fotos si se subieron
            $fotoPaths = [];
            if (!empty($files)) {
                $uploadDir = __DIR__ . '/../../public/uploads/devoluciones/' . $numeroDevolucion . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fotoKeys = ['foto_0','foto_1','foto_2','foto_3','foto_4','fotos'];
                foreach ($fotoKeys as $key) {
                    if (!isset($files[$key])) continue;
                    $fileItems = is_array($files[$key]) ? $files[$key] : [$files[$key]];
                    foreach ($fileItems as $uploadedFile) {
                        if (!$uploadedFile instanceof \Psr\Http\Message\UploadedFileInterface) continue;
                        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) continue;
                        $ext      = strtolower(pathinfo($uploadedFile->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION));
                        $allowed  = ['jpg','jpeg','png','webp','heic'];
                        if (!in_array($ext, $allowed)) continue;
                        $fileName = uniqid('ev_', true) . '.' . $ext;
                        $uploadedFile->moveTo($uploadDir . $fileName);
                        $fotoPaths[] = 'uploads/devoluciones/' . $numeroDevolucion . '/' . $fileName;
                        if (count($fotoPaths) >= 5) break 2;
                    }
                }
            }

            $devolucion->fotos_json = count($fotoPaths) > 0 ? json_encode($fotoPaths) : null;
            $devolucion->save();

            // Procesar cada línea de devolución
            foreach ($detalles as $idx => $linea) {
                $cantidad = (float)($linea['cantidad'] ?? 0);
                if ($cantidad <= 0) continue;

                $productoId = (int)($linea['producto_id'] ?? 0);
                if (!$productoId) continue;

                $detalle = new DevolucionDetalle();
                $detalle->devolucion_id = $devolucion->id;
                $detalle->producto_id   = $productoId;
                $detalle->lote          = $linea['lote'] ?? null;
                $detalle->cantidad      = $cantidad;
                $detalle->motivo        = $linea['motivo'] ?? 'Averia';
                $detalle->detalle_motivo= $linea['observacion'] ?? null;
                $detalle->destino       = 'DevolucionProveedor';
                $detalle->save();

                // NO se crea MovimientoInventario aquí: esta devolución nunca toca
                // `inventarios` (las unidades no entran a bodega, van directo de
                // vuelta al proveedor) — un asiento de kardex sin contraparte física
                // descuadraría la invariante SUM(kardex)==existencias. La trazabilidad
                // completa (producto, cantidad, lote, motivo) ya queda en
                // DevolucionDetalle, que es la fuente correcta para este caso.
            }

            // Si viene con ODC, marcar la ODC con estado especial
            if ($odc_id && $odc) {
                try {
                    \Illuminate\Database\Capsule\Manager::table('ordenes_compra')
                        ->where('id', $odc_id)
                        ->update(['tiene_devolucion' => 1]);
                } catch (\Exception $e) {
                    // Columna podría no existir aún — no es fatal
                    error_log('desdeOdcMovil: no se pudo actualizar tiene_devolucion: ' . $e->getMessage());
                }
            }

            \Illuminate\Database\Capsule\Manager::connection()->commit();

            return $this->json($response, [
                'error'   => false,
                'message' => 'Devolución registrada. Las unidades devueltas NO fueron cargadas al inventario.',
                'data'    => [
                    'id'               => $devolucion->id,
                    'numero_devolucion'=> $devolucion->numero_devolucion,
                    'fotos'            => $fotoPaths,
                ]
            ], 201);

        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            error_log('DevolucionController::desdeOdcMovil error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al registrar devolución: ' . $e->getMessage()], 500);
        }
    }
    // ── GET /api/devoluciones/dashboard-stats ──────────────────────────────
    // ?fecha_desde=&fecha_hasta=&causal_id=&responsable=&consecutivo=&referencia=
    public function dashboardStats(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $params = $r->getQueryParams();

        try {
            // BUG CORREGIDO 2026-09-17 (a pedido explícito): esta ruta ignoraba
            // POR COMPLETO los filtros del dashboard (fecha, causal, responsable,
            // consecutivo, referencia) — siempre calculaba sobre los últimos 30
            // días fijos, sin importar lo que el usuario seleccionara. Ahora se
            // arma UNA sola lista de devolucion_id que cumple todos los filtros
            // (fuente de verdad única) y cada métrica se restringe a esa lista,
            // en vez de repetir/ignorar el criterio en cada query por separado.
            $fechaDesde = $params['fecha_desde'] ?? $params['desde'] ?? date('Y-m-d', strtotime('-30 days'));
            $fechaHasta = $params['fecha_hasta'] ?? $params['hasta'] ?? date('Y-m-d');

            $devQuery = \Illuminate\Database\Capsule\Manager::table('devoluciones as d')
                ->where('d.empresa_id', $empresaId)
                ->where('d.sucursal_id', $sucursalId)
                ->whereDate('d.created_at', '>=', $fechaDesde)
                ->whereDate('d.created_at', '<=', $fechaHasta);
            if (!empty($params['causal_id']))    $devQuery->where('d.causal_devolucion_id', (int)$params['causal_id']);
            if (!empty($params['responsable']))  $devQuery->where('d.responsable_devolucion', 'like', '%' . $params['responsable'] . '%');
            if (!empty($params['consecutivo']))  $devQuery->where('d.consecutivo_devolucion', (int)$params['consecutivo']);
            if (!empty($params['referencia']))   $devQuery->where('d.referencia_externa', 'like', '%' . $params['referencia'] . '%');
            $devIds = $devQuery->pluck('d.id');

            if ($devIds->isEmpty()) {
                return $this->ok($res, [
                    'estados' => [], 'causales' => [], 'por_sucursal' => [],
                    'top_productos' => [], 'matriz_productos' => [], 'historico' => [],
                ]);
            }

            // 1. Total por estado
            $estados = \Illuminate\Database\Capsule\Manager::table('devoluciones')
                ->select('estado', \Illuminate\Database\Capsule\Manager::raw('count(*) as total'))
                ->whereIn('id', $devIds)
                ->groupBy('estado')
                ->get();

            // 2. Top Causales — agrupado por la causal real del encabezado
            // (devoluciones.causal_devolucion_id), no por el motivo fijo de la línea.
            $causales = \Illuminate\Database\Capsule\Manager::table('devoluciones as d')
                ->leftJoin('causales_devolucion as c', 'c.id', '=', 'd.causal_devolucion_id')
                ->select(
                    \Illuminate\Database\Capsule\Manager::raw("COALESCE(c.causal, 'Sin causal') as causal"),
                    \Illuminate\Database\Capsule\Manager::raw('count(*) as total')
                )
                ->whereIn('d.id', $devIds)
                ->groupBy('d.causal_devolucion_id', 'c.causal')
                ->orderBy('total', 'desc')
                ->limit(10)
                ->get();

            // 3. Devoluciones por Cliente/Sucursal que devuelve.
            // BUG CORREGIDO 2026-09-17: agrupaba por devoluciones.sucursal_id, que
            // es SIEMPRE la sucursal propia del CEDI que opera el WMS (una sola,
            // constante) — nunca iba a mostrar más de una barra. La sucursal que
            // realmente varía es la del CLIENTE/punto de venta que devuelve
            // (cliente_origen_id → clientes.razon_social; así se le llama también
            // en el formulario "Nueva Devolución": "Cliente / Sucursal que Devuelve").
            $porSucursal = \Illuminate\Database\Capsule\Manager::table('devoluciones as d')
                ->join('clientes as cl', 'cl.id', '=', 'd.cliente_origen_id')
                ->select('cl.razon_social as nombre', \Illuminate\Database\Capsule\Manager::raw('count(*) as total'))
                ->whereIn('d.id', $devIds)
                ->groupBy('cl.razon_social')
                ->orderByDesc('total')
                ->get();

            // 4. Top 10 Productos (mantenido por si acaso)
            $topProductos = \Illuminate\Database\Capsule\Manager::table('devolucion_detalles')
                ->join('productos', 'productos.id', '=', 'devolucion_detalles.producto_id')
                ->select('productos.nombre', 'productos.codigo_interno', \Illuminate\Database\Capsule\Manager::raw('sum(devolucion_detalles.cantidad) as cantidad_total'))
                ->whereIn('devolucion_detalles.devolucion_id', $devIds)
                ->groupBy('productos.nombre', 'productos.codigo_interno')
                ->orderBy('cantidad_total', 'desc')
                ->limit(10)
                ->get();

            // Matriz Completa de Productos (Veces devueltas, Unidades, %)
            $totalVecesGeneral = \Illuminate\Database\Capsule\Manager::table('devolucion_detalles')
                ->whereIn('devolucion_id', $devIds)
                ->count();

            $matrizProductos = \Illuminate\Database\Capsule\Manager::table('devolucion_detalles')
                ->join('productos', 'productos.id', '=', 'devolucion_detalles.producto_id')
                ->select(
                    'productos.nombre',
                    'productos.codigo_interno',
                    \Illuminate\Database\Capsule\Manager::raw('COUNT(devolucion_detalles.id) as veces_devuelto'),
                    \Illuminate\Database\Capsule\Manager::raw('SUM(devolucion_detalles.cantidad) as total_unidades')
                )
                ->whereIn('devolucion_detalles.devolucion_id', $devIds)
                ->groupBy('productos.nombre', 'productos.codigo_interno')
                ->orderBy('veces_devuelto', 'desc')
                ->get();

            $matrizProductos = $matrizProductos->map(function($item) use ($totalVecesGeneral) {
                $item->porcentaje_participacion = $totalVecesGeneral > 0
                    ? round(($item->veces_devuelto / $totalVecesGeneral) * 100, 2)
                    : 0;
                return $item;
            });

            // 5. Histórico por fecha — por devolución (total), por referencias
            // distintas devueltas y por unidades, para que el gráfico pueda
            // alternar dinámicamente entre "referencias" y "unidades".
            $historico = \Illuminate\Database\Capsule\Manager::table('devolucion_detalles as dd')
                ->join('devoluciones as d', 'd.id', '=', 'dd.devolucion_id')
                ->select(
                    \Illuminate\Database\Capsule\Manager::raw('DATE(d.created_at) as fecha'),
                    \Illuminate\Database\Capsule\Manager::raw('COUNT(DISTINCT d.id) as total'),
                    \Illuminate\Database\Capsule\Manager::raw('COUNT(DISTINCT dd.producto_id) as referencias'),
                    \Illuminate\Database\Capsule\Manager::raw('SUM(dd.cantidad) as unidades')
                )
                ->whereIn('d.id', $devIds)
                ->groupBy('fecha')
                ->orderBy('fecha', 'asc')
                ->get()
                ->map(fn($r) => [
                    'fecha'      => $r->fecha,
                    'total'      => (int)$r->total,
                    'referencias'=> (int)$r->referencias,
                    'unidades'   => (float)$r->unidades,
                ]);

            return $this->ok($res, [
                'estados' => $estados,
                'causales' => $causales,
                'por_sucursal' => $porSucursal,
                'top_productos' => $topProductos,
                'matriz_productos' => $matrizProductos,
                'historico' => $historico
            ]);
        } catch (\Exception $e) {
            error_log('DevolucionController::dashboardStats error: ' . $e->getMessage());
            return $this->error($res, 'Error al obtener estadísticas del dashboard');
        }
    }

}
