<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Devolucion;
use App\Models\DevolucionDetalle;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Ubicacion;

class DevolucionController extends BaseController
{
    /**
     * GET /api/devoluciones
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        try {
            $devoluciones = Devolucion::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();
            return $this->json($response, ['error' => false, 'data' => $devoluciones]);
        } catch (\Exception $e) {
            error_log('DevolucionController::index error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al obtener devoluciones.'], 500);
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
            $devolucion = Devolucion::with('detalles')
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
     * POST /api/devoluciones
     * Iniciar proceso de devolución (crear encabezado y líneas)
     */
    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $request);
        
        // Cualquier usuario autenticado puede registrar devoluciones

        $data = $request->getParsedBody();

        $tipo = $data['tipo'] ?? 'ReingresoBuenEstado';
        $motivo_general = $data['motivo_general'] ?? '';
        $detalles = $data['detalles'] ?? [];

        if (empty($detalles) || !is_array($detalles)) {
            return $this->json($response, ['error' => true, 'message' => 'Debe incluir al menos un producto a devolver.'], 400);
        }

        \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

        try {
            $devolucion = new Devolucion();
            $devolucion->empresa_id = $empresaId;
            $devolucion->sucursal_id = $sucursalId;
            $devolucion->recepcion_id = $data['recepcion_id'] ?? null;
            $devolucion->proveedor = $data['proveedor'] ?? null;
            $devolucion->numero_devolucion = 'DEV-' . time() . '-' . rand(10,99);
            $devolucion->tipo = $tipo;
            $devolucion->estado = 'Procesada'; // Directamente procesada por ahora
            $devolucion->motivo_general = $motivo_general;
            $devolucion->auxiliar_id = $user->id;
            $devolucion->fecha_movimiento = date('Y-m-d');
            $devolucion->hora_inicio = date('H:i:s');
            $devolucion->hora_fin = date('H:i:s');
            $devolucion->save();

            // Buscar la ubicación virtual adecuada (OBSOLETO o PATIO)
            $ubicacion_obsoleto = Ubicacion::where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->where('tipo_ubicacion', 'Patio')->where('codigo', 'OBSOLETO')->first();
            $ubicacion_patio = Ubicacion::where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->where('tipo_ubicacion', 'Patio')->where('codigo', 'PATIO')->first();

            foreach ($detalles as $idx => $linea) {
                $lineaNum = $idx + 1;
                if (empty($linea['producto_id']) || !is_numeric($linea['producto_id'])) {
                    \Illuminate\Database\Capsule\Manager::connection()->rollBack();
                    return $this->json($response, ['error' => true, 'message' => "Línea {$lineaNum}: producto_id inválido."], 400);
                }
                $cantidad = (int)($linea['cantidad'] ?? 0);
                if ($cantidad <= 0) {
                    \Illuminate\Database\Capsule\Manager::connection()->rollBack();
                    return $this->json($response, ['error' => true, 'message' => "Línea {$lineaNum}: la cantidad debe ser mayor a cero."], 400);
                }

                $destino = $linea['destino'] ?? 'InventarioObsoleto';
                $ubicacion_destino_id = ($destino === 'InventarioObsoleto') ? ($ubicacion_obsoleto ? $ubicacion_obsoleto->id : null) : ($ubicacion_patio ? $ubicacion_patio->id : null);

                $detalle = new DevolucionDetalle();
                $detalle->devolucion_id = $devolucion->id;
                $detalle->producto_id = (int)$linea['producto_id'];
                $detalle->lote = $linea['lote'] ?? null;
                $detalle->fecha_vencimiento = $linea['fecha_vencimiento'] ?? null;
                $detalle->cantidad = $cantidad;
                $detalle->motivo = $linea['motivo'] ?? 'Otro';
                $detalle->detalle_motivo = $linea['detalle_motivo'] ?? null;
                $detalle->destino = $destino;
                $detalle->ubicacion_destino_id = $ubicacion_destino_id;
                $detalle->save();

                // Registrar en MovimientoInventario
                $movimiento = new MovimientoInventario();
                $movimiento->empresa_id = $empresaId;
                $movimiento->sucursal_id = $sucursalId;
                $movimiento->producto_id = $linea['producto_id'];
                $movimiento->ubicacion_origen_id = null; // Viene del usuario/proveedor o zona perdida
                $movimiento->ubicacion_destino_id = $ubicacion_destino_id;
                $movimiento->tipo_movimiento = 'Devolucion';
                $movimiento->cantidad = $cantidad;
                $movimiento->lote = $linea['lote'] ?? null;
                $movimiento->fecha_vencimiento = $linea['fecha_vencimiento'] ?? null;
                $movimiento->referencia_tipo = 'devolucion';
                $movimiento->referencia_id = $devolucion->id;
                $movimiento->auxiliar_id = $user->id;
                $movimiento->fecha_movimiento = date('Y-m-d');
                $movimiento->hora_inicio = $devolucion->hora_inicio;
                $movimiento->hora_fin = $devolucion->hora_fin;
                $movimiento->save();

                // Actualizar inventario en la ubicación destino virtual (Obsoleto o Patio)
                if ($ubicacion_destino_id) {
                    $inventario = Inventario::firstOrNew([
                        'empresa_id' => $empresaId,
                        'sucursal_id' => $sucursalId,
                        'producto_id' => $linea['producto_id'],
                        'ubicacion_id' => $ubicacion_destino_id,
                        'lote' => $linea['lote'] ?? null
                    ]);
                    if (!$inventario->exists) {
                        $inventario->cantidad = 0;
                        $inventario->cantidad_reservada = 0;
                        $inventario->estado = ($destino === 'InventarioObsoleto') ? 'Obsoleto' : 'Disponible';
                    }
                    if (!empty($linea['fecha_vencimiento'])) {
                        $inventario->fecha_vencimiento = $linea['fecha_vencimiento'];
                    }
                    $inventario->cantidad += $cantidad;
                    $inventario->save();
                }
            }

            \Illuminate\Database\Capsule\Manager::connection()->commit();

            return $this->json($response, ['error' => false, 'message' => 'Devolución procesada. Inventario actualizado.', 'data' => $devolucion], 201);
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => 'Error al procesar devolución: ' . $e->getMessage()], 500);
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

    /**
     * POST /api/devoluciones/desde-recepcion
     * Crear devolución desde líneas de recepción con estado defectuoso
     */
    public function desdeRecepcion(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $request);
        $data = $request->getParsedBody();

        $recepcion_detalle_ids = $data['recepcion_detalle_ids'] ?? [];
        if (empty($recepcion_detalle_ids)) {
            return $this->json($response, ['error' => true, 'message' => 'Selecciona líneas para devolver'], 400);
        }

        \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
        try {
            // empresa_id join ensures cross-tenant isolation
            $detalles_grupo = \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')
                ->join('recepciones', 'recepciones.id', '=', 'recepcion_detalles.recepcion_id')
                ->where('recepciones.empresa_id', $empresaId)
                ->where('recepciones.sucursal_id', $sucursalId)
                ->whereIn('recepcion_detalles.id', $recepcion_detalle_ids)
                ->select('recepcion_detalles.*')
                ->get();

            $recepcion_id = null;
            $proveedor = null;

            foreach ($detalles_grupo as $det) {
                if (!$recepcion_id) $recepcion_id = $det->recepcion_id;
                // Obtener proveedor desde cita si es necesario
            }

            $devolucion = new Devolucion();
            $devolucion->empresa_id = $empresaId;
            $devolucion->sucursal_id = $sucursalId;
            $devolucion->recepcion_id = $recepcion_id;
            $devolucion->numero_devolucion = 'DEV-RCP-' . time();
            $devolucion->tipo = 'DevolucionRecepcion';
            $devolucion->estado = 'PendienteAutorizacion';
            $devolucion->motivo_general = $data['razon'] ?? 'Novelty en recepción';
            $devolucion->auxiliar_id = $user->id;
            $devolucion->fecha_movimiento = date('Y-m-d');
            $devolucion->save();

            foreach ($detalles_grupo as $det) {
                $detalle = new DevolucionDetalle();
                $detalle->devolucion_id = $devolucion->id;
                $detalle->recepcion_detalle_id = $det->id;
                $detalle->producto_id = $det->producto_id;
                $detalle->cantidad = $det->cantidad;
                $detalle->motivo = $data['motivo'] ?? $det->estado;
                $detalle->destino = $data['destino'] ?? 'RetornoProveedor';
                $detalle->save();

                // Marcar línea como en devolución
                \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')
                    ->where('id', $det->id)
                    ->update(['estado' => 'EnDevolucion']);
            }

            \Illuminate\Database\Capsule\Manager::connection()->commit();
            return $this->json($response, ['error' => false, 'message' => 'Devolución creada', 'data' => $devolucion], 201);

        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/devoluciones/{id}/autorizar
     * Autorizar una devolución pendiente
     */
    public function autorizar(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $id = (int)($args['id'] ?? 0);

        // Verificar permisos (solo Jefe o Admin)
        if (!in_array($user->rol, ['Admin', 'Jefe', 'Supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Permiso denegado'], 403);
        }

        try {
            $devolucion = Devolucion::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->find($id);
            if (!$devolucion) {
                return $this->json($response, ['error' => true, 'message' => 'No encontrada'], 404);
            }

            $devolucion->estado = 'Autorizada';
            $devolucion->autorizado_por = $user->id;
            $devolucion->fecha_autorizacion = date('Y-m-d H:i:s');
            $devolucion->save();

            return $this->json($response, ['error' => false, 'message' => 'Devolución autorizada']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/devoluciones/{id}/completar
     * Marcar devolución como completada
     */
    public function completar(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $id = (int)($args['id'] ?? 0);
        $data = $request->getParsedBody();

        try {
            $devolucion = Devolucion::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->with('detalles')
                ->find($id);
            
            if (!$devolucion) {
                return $this->json($response, ['error' => true, 'message' => 'No encontrada'], 404);
            }

            \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

            $devolucion->estado = 'Completada';
            $devolucion->fecha_devolucion = date('Y-m-d');
            $devolucion->observaciones = $data['observaciones'] ?? null;
            $devolucion->save();

            // Actualizar líneas de recepción asociadas
            foreach ($devolucion->detalles as $detalle) {
                if ($detalle->recepcion_detalle_id) {
                    \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')
                        ->where('id', $detalle->recepcion_detalle_id)
                        ->update(['estado' => 'Devuelto']);
                }
            }

            \Illuminate\Database\Capsule\Manager::connection()->commit();
            return $this->json($response, ['error' => false, 'message' => 'Devolución completada']);

        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

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
            $numeroDevolucion = Devolucion::generarNumero($sucursalId);

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

                // Registrar movimiento de salida / devolución (NO suma a inventario)
                $movimiento = new MovimientoInventario();
                $movimiento->empresa_id        = $empresaId;
                $movimiento->sucursal_id       = $sucursalId;
                $movimiento->producto_id        = $productoId;
                $movimiento->ubicacion_origen_id  = null;
                $movimiento->ubicacion_destino_id = null;
                $movimiento->tipo_movimiento    = 'Devolucion';
                $movimiento->cantidad           = $cantidad;
                $movimiento->lote               = $linea['lote'] ?? null;
                $movimiento->referencia_tipo    = 'devolucion';
                $movimiento->referencia_id      = $devolucion->id;
                $movimiento->auxiliar_id        = $user->id;
                $movimiento->fecha_movimiento   = date('Y-m-d');
                $movimiento->hora_inicio        = $devolucion->hora_inicio;
                $movimiento->hora_fin           = $devolucion->hora_fin;
                $movimiento->save();
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

}
