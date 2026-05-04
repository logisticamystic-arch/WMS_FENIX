<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Recepcion;
use App\Models\RecepcionDetalle;
use App\Models\Cita;
use App\Models\Producto;
use App\Models\Inventario;
use App\Models\Ubicacion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Personal;
use App\Helpers\InventoryGuard;
use Carbon\Carbon;

class RecepcionController extends BaseController
{
    /**
     * Helper para estandarizar fechas de DD/MM/YYYY a YYYY-MM-DD
     */
    private function estandarizarFecha(?string $fecha): ?string
    {
        if (empty($fecha) || $fecha === 'N/A' || $fecha === '-') return null;
        
        try {
            // Intentar d/m/Y (que es lo que el usuario reporta)
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
                return Carbon::createFromFormat('d/m/Y', $fecha)->format('Y-m-d');
            }
            // Si ya viene Y-m-d
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                return $fecha;
            }
            // Intento genérico
            return Carbon::parse($fecha)->format('Y-m-d');
        } catch (\Exception $e) {
            error_log("Error parseando fecha: " . $fecha . " -> " . $e->getMessage());
            return null;
        }
    }

    /**
     * GET /api/recepciones
     * Listar recepciones
     */
    public function index(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $limit  = min((int)($params['limit'] ?? 50), 200);

        $query = Recepcion::where('empresa_id', $user->empresa_id)
            ->withCount('detalles')
            ->orderBy('id', 'desc')
            ->limit($limit);

        if (!empty($params['estado'])) {
            $query->where('estado', $params['estado']);
        }
        if (!empty($params['odc_id'])) {
            $query->where('odc_id', $params['odc_id'])
                  ->with('detalles.producto');
        }

        $items = $query->get()->map(function($r) {
            // Extraer proveedor de cita o de observaciones (para manual)
            $r->proveedor_nombre = $r->cita?->proveedor;
            if (!$r->proveedor_nombre && preg_match('/Prov: (.*)/', $r->observaciones, $m)) {
                $r->proveedor_nombre = $m[1];
            }
            $r->total_lineas = $r->detalles_count;
            return $r;
        });

        return $this->json($response, [
            'error' => false,
            'data'  => $items,
        ]);
    }

    /**
     * GET /api/recepciones/{id}
     * Ver detalle de una recepción
     */
    public function ver(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = (int)$args['id'];

        $recepcion = Recepcion::with(['detalles.producto', 'detalles.ubicacionDestino'])->where('empresa_id', $user->empresa_id)->find($id);
        if (!$recepcion) {
            return $this->json($response, ['error' => true, 'message' => 'Recepción no encontrada'], 404);
        }

        return $this->json($response, ['error' => false, 'data' => $recepcion]);
    }

    /**
     * GET /api/recepciones/proximo-pallet
     * Obtener el próximo número de pallet secuencial
     */
    public function getProximoPallet(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $max  = (int)Capsule::table('recepcion_detalles')
            ->join('recepciones', 'recepcion_detalles.recepcion_id', '=', 'recepciones.id')
            ->where('recepciones.empresa_id', $user->empresa_id)
            ->where('recepciones.sucursal_id', $user->sucursal_id)
            ->max('recepcion_detalles.numero_pallet');
            
        return $this->json($response, [
            'error' => false,
            'proximo' => $max + 1,
        ]);
    }

    /**
     * DELETE /api/recepciones/{id}
     */
    public function eliminar(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = (int)$args['id'];

        $recepcion = Recepcion::where('empresa_id', $user->empresa_id)->find($id);
        if (!$recepcion) {
            return $this->json($response, ['error' => true, 'message' => 'Recepción no encontrada'], 404);
        }
        if ($recepcion->estado === 'Cerrada') {
            return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar una recepción cerrada'], 400);
        }

        // Delete details first
        \Illuminate\Database\Capsule\Manager::table('recepcion_detalles')
            ->where('recepcion_id', $recepcion->id)->delete();
        $recepcion->delete();

        return $this->json($response, ['error' => false, 'message' => 'Recepción eliminada']);
    }

    /**
     * POST /api/recepciones
     * Iniciar una recepción (Cabecera)
     */
    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        
        if (!$user->hasPermission('recepcion', 'crear')) {
            return $this->json($response, ['error' => true, 'message' => 'No tienes permiso.'], 403);
        }

        $data = $request->getParsedBody();

        $cita_id = $data['cita_id'] ?? null;
        $modo_ciego = $data['modo_ciego'] ?? false;
        $observaciones = $data['observaciones'] ?? '';

        if ($cita_id) {
            $cita = Cita::find($cita_id);
            if (!$cita || $cita->sucursal_id !== $user->sucursal_id) {
                return $this->json($response, ['error' => true, 'message' => 'Cita inválida.'], 400);
            }
            $cita->estado = 'EnCurso';
            $cita->save();
        }

        $recepcion = new Recepcion();
        $recepcion->empresa_id = $user->empresa_id;
        $recepcion->sucursal_id = $user->sucursal_id;
        $recepcion->cita_id = $cita_id;
        // Generate unique recepcion number
        $recepcion->numero_recepcion = 'RC-' . time() . '-' . rand(10,99);
        $recepcion->auxiliar_id = $user->id;
        $recepcion->modo_ciego = $modo_ciego;
        $recepcion->estado = 'Borrador';
        $recepcion->fecha_movimiento = date('Y-m-d');
        $recepcion->hora_inicio = date('H:i:s');
        $recepcion->observaciones = $observaciones;
        
        $recepcion->save();

        return $this->json($response, [
            'error' => false,
            'message' => 'Recepción iniciada.',
            'data' => $recepcion
        ], 201);
    }

    /**
     * POST /api/recepciones/{id}/detalle
     * Agregar una línea a la recepción
     */
    public function addDetail(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;

        $recepcion = Recepcion::find($id);
        if (!$recepcion || $recepcion->sucursal_id !== $user->sucursal_id || $recepcion->estado !== 'Borrador') {
            return $this->json($response, ['error' => true, 'message' => 'Recepción inválida o ya cerrada.'], 400);
        }

        $data = $request->getParsedBody();

        $producto_id = $data['producto_id'] ?? null;

        // ── Conversión cajas → unidades ──────────────────────────────────────
        // El auxiliar puede enviar cantidad_cajas (número de cajas físicas).
        // Si lo envía, multiplicamos por unidades_caja del producto para obtener
        // la cantidad real en unidades que ingresa al inventario.
        // Si envía cantidad_recibida directamente (unidades), se usa tal cual.
        $cantidad_cajas   = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : null;
        $cantidad_recibida = isset($data['cantidad_recibida']) ? (float)$data['cantidad_recibida'] : 0;

        if (!$producto_id) {
            return $this->json($response, ['error' => true, 'message' => 'Producto y cantidad validos son requeridos.'], 400);
        }

        $producto = Producto::where('empresa_id', $recepcion->empresa_id)->find($producto_id);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto inexistente.'], 404);
        }

        // Resolver conversión
        $cajasUnd = max(1, (int)($producto->unidades_caja ?? 1));
        if ($cantidad_cajas !== null && $cantidad_cajas > 0) {
            // Modo cajas: el auxiliar informó cuántas cajas recibió
            $cantidad_recibida = $cantidad_cajas * $cajasUnd;
        } elseif ($cantidad_recibida <= 0 && ($cantidad_cajas === null || $cantidad_cajas <= 0)) {
            return $this->json($response, ['error' => true, 'message' => 'Ingrese la cantidad de cajas o unidades recibidas.'], 400);
        }

        // FEFO Validation for Inbound
        $fecha_vencimiento = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);
        
        $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
        $checkDate = $guard->checkExpirationMandatory($producto_id, $fecha_vencimiento);
        if (!$checkDate['ok']) {
            return $this->json($response, ['error' => true, 'message' => $checkDate['message']], 422);
        }

        $detalle = new RecepcionDetalle();
        $detalle->recepcion_id = $recepcion->id;
        $detalle->producto_id = $producto_id;

        // Blind mode logic: Only store expected if NOT in blind mode (handled by client, but we enforce)
        $detalle->cantidad_esperada  = $recepcion->modo_ciego ? 0 : ($data['cantidad_esperada'] ?? 0);
        $detalle->cantidad_recibida  = $cantidad_recibida;
        $detalle->cantidad_cajas     = $cantidad_cajas ?? (int)ceil($cantidad_recibida / $cajasUnd);
        $detalle->cajas_por_unidad   = $cajasUnd;
        $detalle->lote = $data['lote'] ?? null;
        $detalle->fecha_vencimiento = $fecha_vencimiento;
        $detalle->estado_mercancia = $data['estado_mercancia'] ?? 'BuenEstado';
        $detalle->novedad_motivo = $data['novedad_motivo'] ?? null;

        // Por defecto va a zona PATIO (Recepcion) o ubicacion default virtual
        $patio_id = \App\Models\Ubicacion::where('sucursal_id', $user->sucursal_id)
                                ->where('tipo_ubicacion', 'Patio')
                                ->value('id');
        $detalle->ubicacion_destino_id = $patio_id;

        $detalle->save();

        return $this->json($response, [
            'error' => false,
            'message' => 'Línea de recepción agregada.',
            'data' => $detalle
        ]);
    }

    /**
     * POST /api/recepciones/detalles-operativa
     * Captura operativa de ODC móvil/Desktop y crea o reutiliza la recepción Borrador
     */
    public function detallesOperativa(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];
        $required = ['odc_id', 'producto_id', 'cantidad'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->json($response, ['error' => true, 'message' => "Campo requerido: {$field}"], 400);
            }
        }

        $odc = OrdenCompra::where('empresa_id', $user->empresa_id)->find((int)$data['odc_id']);
        if (!$odc) {
            return $this->json($response, ['error' => true, 'message' => 'ODC no encontrada'], 404);
        }

        if (!in_array($odc->estado, ['Confirmada', 'En Proceso'])) {
            return $this->json($response, ['error' => true, 'message' => 'La ODC no se encuentra en estado válido para recepción'], 400);
        }

        if ($user->rol === 'Auxiliar') {
            $asignada = Capsule::table('odc_auxiliares')
                ->where('orden_compra_id', $odc->id)
                ->where('auxiliar_id', $user->id)
                ->exists();
            if (!$asignada) {
                return $this->json($response, ['error' => true, 'message' => 'No estás asignado a esta ODC'], 403);
            }
        }

        $producto = Producto::where('empresa_id', $user->empresa_id)->find((int)$data['producto_id']);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto inválido'], 404);
        }

        // ── Conversión cajas → unidades ──────────────────────────────────────
        // Priorizamos la 'cantidad' enviada como unidades totales.
        // 'cantidad_cajas' se guarda como referencia del empaque original.
        $cajasUnd      = max(1, (int)($producto->unidades_caja ?? 1));
        $cantidad      = (float)($data['cantidad'] ?? 0);
        $cantidadCajas = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : (int)ceil($cantidad / $cajasUnd);

        // Si el cliente explícitamente pide modo cajas (legacy), recalculamos
        if ($cantidad <= 0) {
            return $this->json($response, ['error' => true, 'message' => 'La cantidad debe ser mayor a cero'], 400);
        }

        // ── Validación estricta vs ODC (InventoryGuard) ─────────────────────
        $detalleOdc = OrdenCompraDetalle::where('orden_compra_id', $odc->id)
            ->where('producto_id', $producto->id)
            ->first();

        if ($detalleOdc) {
            $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
            $check = $guard->canReceive($detalleOdc->id, $cantidad);
            if (!$check['ok']) {
                return $this->json($response, [
                    'error'   => true,
                    'message' => 'EXCEDENTE BLOQUEADO: ' . $check['message']
                ], 422);
            }
        }

        $recepcion = Recepcion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('odc_id', $odc->id)
            ->where('auxiliar_id', $user->id)
            ->where('estado', 'Borrador')
            ->first();

        if (!$recepcion) {
            $recepcion = new Recepcion();
            $recepcion->empresa_id = $user->empresa_id;
            $recepcion->sucursal_id = $user->sucursal_id;
            $recepcion->odc_id = $odc->id;
            $recepcion->numero_recepcion = Recepcion::generarNumero($user->sucursal_id);
            $recepcion->auxiliar_id = $user->id;
            $recepcion->modo_ciego = false;
            $recepcion->estado = 'Borrador';
            $recepcion->fecha_movimiento = date('Y-m-d');
            $recepcion->hora_inicio = date('H:i:s');
            $recepcion->observaciones = 'Recepción operativa para ODC ' . $odc->numero_odc;
            $recepcion->save();
        }

        if ($odc->estado === 'Confirmada') {
            $odc->estado = 'En Proceso';
            $odc->save();
        }

        $detalleOdc = OrdenCompraDetalle::where('orden_compra_id', $odc->id)
            ->where('producto_id', $producto->id)
            ->first();

        $detalle = new RecepcionDetalle();
        $detalle->recepcion_id    = $recepcion->id;
        $detalle->producto_id     = $producto->id;
        $detalle->cantidad_esperada  = $detalleOdc ? $detalleOdc->cantidad_solicitada : 0;
        $detalle->cantidad_recibida  = $cantidad;           // siempre en UNIDADES
        $detalle->cantidad_cajas     = $cantidadCajas;      // snapshot de cajas físicas
        $detalle->cajas_por_unidad   = $cajasUnd;           // snapshot del factor de conversión
        $detalle->lote = $data['lote'] ?? null;
        $detalle->fecha_vencimiento = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);
        $detalle->estado_mercancia = $data['estado_mercancia'] ?? 'BuenEstado';
        $detalle->novedad_motivo = $data['novedad_motivo'] ?? null;

        // Validar mandatorio R09
        $guard = new InventoryGuard($user->empresa_id, $user->sucursal_id, $user->id);
        $checkDate = $guard->checkExpirationMandatory($producto->id, $detalle->fecha_vencimiento);
        if (!$checkDate['ok']) {
            return $this->json($response, ['error' => true, 'message' => $checkDate['message']], 422);
        }

        $ubicacionDestinoId = null;
        if (!empty($data['ubicacion_destino_id'])) {
            $ubicacionDestinoId = (int)$data['ubicacion_destino_id'];
        } elseif (!empty($data['ubicacion_destino_codigo'])) {
            $codigo = trim(strtoupper($data['ubicacion_destino_codigo']));
            $ubicacionDestinoId = Ubicacion::where('sucursal_id', $user->sucursal_id)
                ->whereRaw('REPLACE(UPPER(codigo), "-", "") = ?', [str_replace('-', '', $codigo)])
                ->value('id');
        }

        if (!$ubicacionDestinoId) {
            $ubicacionDestinoId = Ubicacion::where('sucursal_id', $user->sucursal_id)
                ->where('tipo_ubicacion', 'Patio')
                ->value('id');
        }

        $detalle->ubicacion_destino_id = $ubicacionDestinoId;
        $detalle->numero_pallet = !empty($data['numero_pallet']) ? (int)$data['numero_pallet'] : null;
        $detalle->aprobado_admin = 1; // Auto-aprobar para visibilidad inmediata en Patio
        $detalle->save();

        if ($detalleOdc) {
            // Actualizar en UNIDADES (cantidad ya contiene unidades convertidas)
            $detalleOdc->cantidad_recibida = max(0, $detalleOdc->cantidad_recibida + $cantidad);
            $detalleOdc->save();
        }

        // ── INVENTARIO EN TIEMPO REAL (PALLET POR PALLET) ────────────────────
        try {
            \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
            
            // 1. Registrar Movimiento de Entrada
            \App\Models\MovimientoInventario::create([
                'empresa_id'  => $user->empresa_id,
                'sucursal_id' => $user->sucursal_id,
                'producto_id' => $producto->id,
                'tipo_movimiento' => 'Entrada',
                'referencia_tipo' => 'Cap. Móvil ODC: ' . $odc->numero_odc,
                'cantidad'    => $cantidad,
                'fecha_movimiento' => date('Y-m-d'),
                'hora_inicio'      => date('H:i:s'),
                'ubicacion_destino_id' => $ubicacionDestinoId,
                'auxiliar_id' => $user->id,
                'numero_pallet' => $detalle->numero_pallet,
                'lote' => $data['lote'] ?? 'N/A',
            ]);

            // 2. Afectar Tabla de Inventarios (Disponible en Patio)
            $inv = \App\Models\Inventario::firstOrNew([
                'empresa_id'   => $user->empresa_id,
                'sucursal_id'  => $user->sucursal_id,
                'producto_id'  => $producto->id,
                'ubicacion_id' => $ubicacionDestinoId,
                'lote'         => $data['lote'] ?? 'N/A',
                'estado'       => 'Disponible',
                'numero_pallet' => $detalle->numero_pallet,
            ]);
            $inv->cantidad           = ($inv->cantidad ?? 0) + $cantidad;
            $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
            $inv->fecha_vencimiento  = $detalle->fecha_vencimiento ?? $inv->fecha_vencimiento;
            $inv->save();

            \Illuminate\Database\Capsule\Manager::connection()->commit();
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            // Log error pero permitimos retornar 201 porque el detalle manual ya se guardó
            error_log("Error en inventario real-time: " . $e->getMessage());
        }

        return $this->json($response, [
            'error' => false,
            'message' => 'Registro operativo de recepción guardado',
            'data' => [
                'recepcion'     => $recepcion,
                'detalle'       => $detalle,
                'odc_detalle'   => $detalleOdc,
                'conversion'    => [
                    'cajas'          => $cantidadCajas,
                    'unidades_caja'  => $cajasUnd,
                    'total_unidades' => $cantidad,
                ],
            ]
        ], 201);
    }

    /**
     * POST /api/recepciones/{id}/confirm
     * Cierra la recepción y afecta el inventario (ledger)
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;

        $recepcion = Recepcion::with('detalles')->find($id);
        if (!$recepcion || $recepcion->sucursal_id !== $user->sucursal_id) {
            return $this->json($response, ['error' => true, 'message' => 'Recepción no encontrada.'], 404);
        }

        if ($recepcion->estado !== 'Borrador') {
            return $this->json($response, ['error' => true, 'message' => "La recepción ya se encuentra {$recepcion->estado}."], 400);
        }

        \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
        
        try {
            $recepcion->estado = 'Cerrada'; // El estado en DB sigue siendo Cerrado para reportes
            $recepcion->hora_fin = date('H:i:s');
            // Nota: En el frontend se mostrará como "En Patio" si no esta aprobado (según recepcion.js)
            $recepcion->save();

            // Resolver ubicación Patio una sola vez (para todas las líneas)
            $patio = Ubicacion::where('sucursal_id', $recepcion->sucursal_id)
                ->where('tipo_ubicacion', 'Patio')
                ->first();

            $guard = new InventoryGuard(
                $recepcion->empresa_id,
                $recepcion->sucursal_id,
                $user->id
            );

            foreach ($recepcion->detalles as $linea) {
                // ── EVITAR DUPLICIDAD ─────────────────────────────────────────
                // Si la línea ya fue aprobada (procesada en tiempo real), 
                // saltamos para no duplicar el stock en inventario.
                if ($linea->aprobado_admin) {
                    continue;
                }

                // ── Validar tolerancia de recepción vs ODC ──────────────────
                if ($linea->orden_compra_detalle_id) {
                    $check = $guard->canReceive($linea->orden_compra_detalle_id, $linea->cantidad_recibida);
                    if (!$check['ok']) {
                        \Illuminate\Database\Capsule\Manager::connection()->rollBack();
                        return $this->json($response, [
                            'error'   => true,
                            'message' => $check['message'],
                            'linea_id' => $linea->id,
                        ], 422);
                    }
                }

                // Log inmutable de movimiento
                \App\Models\MovimientoInventario::create([
                    'empresa_id'      => $recepcion->empresa_id,
                    'sucursal_id'     => $recepcion->sucursal_id,
                    'producto_id'     => $linea->producto_id,
                    'tipo_movimiento' => 'Entrada',
                    'cantidad'        => $linea->cantidad_recibida,
                    'lote'            => $linea->lote,
                    'referencia_tipo' => 'recepciones',
                    'referencia_id'   => $recepcion->id,
                    'auxiliar_id'     => $user->id,
                    'observaciones'   => 'Recepción ' . $recepcion->numero_recepcion,
                    'fecha_movimiento' => date('Y-m-d'),
                    'hora_fin'        => date('H:i:s'),
                ]);

                // ── PALLET AUTO-DISPONIBLE ───────────────────────────────────
                $ubicacionInventario = $linea->ubicacion_destino_id ?: ($patio->id ?? null);
                if ($ubicacionInventario) {
                    $inv = \App\Models\Inventario::firstOrNew([
                        'empresa_id'   => $recepcion->empresa_id,
                        'sucursal_id'  => $recepcion->sucursal_id,
                        'producto_id'  => $linea->producto_id,
                        'ubicacion_id' => $ubicacionInventario,
                        'lote'         => $linea->lote,
                        'estado'       => 'Disponible',
                    ]);
                    $inv->cantidad           = ($inv->cantidad ?? 0) + $linea->cantidad_recibida;
                    $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
                    $inv->fecha_vencimiento  = $linea->fecha_vencimiento ?? $inv->fecha_vencimiento;
                    $inv->save();

                    $linea->aprobado_admin = 1;
                    $linea->save();
                }
            }

            \Illuminate\Database\Capsule\Manager::connection()->commit();
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => 'Error al confirmar: ' . $e->getMessage()], 500);
        }

        return $this->json($response, ['error' => false, 'message' => 'Recepción confirmada. Stock disponible en Patio para ubicación por montacarguista.', 'data' => $recepcion]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/recepciones/detalle/{id}/aprobar
    // Aprueba un pallet/captura individual: mueve el stock de "En Patio" a
    // "Disponible" (listo para ubicación en rack). Requiere rol Supervisor+.
    // ─────────────────────────────────────────────────────────────────────────
    public function aprobarDetalle(Request $request, Response $response, array $args): Response
    {
        $user     = $request->getAttribute('user');
        $detalleId = (int)($args['id'] ?? 0);

        // Supervisor or Admin only
        $rol = strtolower($user->rol ?? '');
        if (!in_array($rol, ['admin', 'supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Se requiere rol Supervisor o Administrador'], 403);
        }

        $detalle = RecepcionDetalle::with('recepcion')->find($detalleId);
        if (!$detalle) {
            return $this->json($response, ['error' => true, 'message' => 'Detalle no encontrado'], 404);
        }

        // Verificar que pertenece a la misma empresa
        $recepcion = $detalle->recepcion;
        if (!$recepcion || $recepcion->empresa_id != $user->empresa_id) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        if ($detalle->aprobado_admin) {
            return $this->json($response, ['error' => false, 'message' => 'Este pallet ya fue aprobado']);
        }

        Capsule::connection()->beginTransaction();
        try {
            // 1. Marcar el detalle como aprobado
            $detalle->aprobado_admin = 1;
            $detalle->save();

            // 2. Buscar el inventario en estado "En Patio" y mover a "Disponible"
            $patio = Ubicacion::where('sucursal_id', $recepcion->sucursal_id)
                ->where('tipo_ubicacion', 'Patio')
                ->first();

            $ubicacionInventario = $detalle->ubicacion_destino_id ?: ($patio->id ?? null);
            $origenCondition = [
                'empresa_id'   => $recepcion->empresa_id,
                'sucursal_id'  => $recepcion->sucursal_id,
                'producto_id'  => $detalle->producto_id,
                'ubicacion_id' => $ubicacionInventario,
                'lote'         => $detalle->lote,
                'estado'       => 'En Patio',
            ];
            if ($detalle->fecha_vencimiento) {
                $origenCondition['fecha_vencimiento'] = $detalle->fecha_vencimiento;
            }

            if ($ubicacionInventario) {
                $invPatio = Inventario::where($origenCondition)->first();

                if ($invPatio) {
                    $cantAprobada = min($detalle->cantidad_recibida, $invPatio->cantidad);

                    if ($invPatio->cantidad <= $cantAprobada) {
                        $invPatio->delete();
                    } else {
                        $invPatio->decrement('cantidad', $cantAprobada);
                    }

                    $invDisp = Inventario::firstOrNew([
                        'empresa_id'   => $recepcion->empresa_id,
                        'sucursal_id'  => $recepcion->sucursal_id,
                        'producto_id'  => $detalle->producto_id,
                        'ubicacion_id' => $ubicacionInventario,
                        'lote'         => $detalle->lote,
                        'estado'       => 'Disponible',
                    ]);
                    $invDisp->cantidad           = ($invDisp->cantidad ?? 0) + $cantAprobada;
                    $invDisp->cantidad_reservada = $invDisp->cantidad_reservada ?? 0;
                    $invDisp->fecha_vencimiento  = $detalle->fecha_vencimiento ?? $invDisp->fecha_vencimiento;
                    $invDisp->save();
                } else {
                    Inventario::firstOrCreate(
                        [
                            'empresa_id'   => $recepcion->empresa_id,
                            'sucursal_id'  => $recepcion->sucursal_id,
                            'producto_id'  => $detalle->producto_id,
                            'ubicacion_id' => $ubicacionInventario,
                            'lote'         => $detalle->lote,
                            'estado'       => 'Disponible',
                        ],
                        [
                            'cantidad'           => $detalle->cantidad_recibida,
                            'cantidad_reservada' => 0,
                            'fecha_vencimiento'  => $detalle->fecha_vencimiento,
                        ]
                    );
                }
            }

            Capsule::connection()->commit();
        } catch (\Exception $e) {
            Capsule::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }

        return $this->json($response, ['error' => false, 'message' => 'Pallet aprobado — stock disponible para ubicar']);
    }

    // ── Redundant json() removed (now inherited from BaseController)

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /recepcion-detalle/{id}  — Actualizar cantidad de un pallet capturado
    // ─────────────────────────────────────────────────────────────────────────
    public function actualizarDetalle(Request $request, Response $response, array $args): Response
    {
        $user  = $request->getAttribute('user');
        $detId = (int)($args['id'] ?? 0);

        $det = RecepcionDetalle::find($detId);
        if (!$det) {
            return $this->json($response, ['error' => true, 'message' => 'Pallet no encontrado'], 404);
        }

        // Verificar que el pallet pertenece a la empresa del usuario
        $recepcion = \App\Models\Recepcion::find($det->recepcion_id);
        if (!$recepcion || $recepcion->empresa_id != $user->empresa_id) {
            return $this->json($response, ['error' => true, 'message' => 'No autorizado'], 403);
        }

        $body = (array)($request->getParsedBody() ?? []);
        $qty  = isset($body['cantidad_recibida']) ? (float)$body['cantidad_recibida'] : null;

        if ($qty === null || $qty < 0) {
            return $this->json($response, ['error' => true, 'message' => 'Cantidad inválida'], 422);
        }

        $det->cantidad_recibida = $qty;
        if (isset($body['lote'])) {
            $det->lote = $body['lote'] ?: null;
        }
        if (isset($body['fecha_vencimiento'])) {
            $det->fecha_vencimiento = $body['fecha_vencimiento'] ?: null;
        }
        if (isset($body['ubicacion_destino_id'])) {
            $det->ubicacion_destino_id = (int)$body['ubicacion_destino_id'] ?: null;
        }
        $det->save();

        return $this->json($response, [
            'error'   => false,
            'message' => 'Cantidad actualizada',
            'detalle' => ['id' => $det->id, 'cantidad_recibida' => $det->cantidad_recibida],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /recepciones/detalle/{id}  — Eliminar un pallet capturado
    // ─────────────────────────────────────────────────────────────────────────
    public function eliminarDetalle(Request $request, Response $response, array $args): Response
    {
        $user  = $request->getAttribute('user');
        $detId = (int)($args['id'] ?? 0);

        $det = RecepcionDetalle::find($detId);
        if (!$det) {
            return $this->json($response, ['error' => true, 'message' => 'Pallet no encontrado'], 404);
        }

        // Verificar empresa
        $recepcion = \App\Models\Recepcion::find($det->recepcion_id);
        if (!$recepcion || $recepcion->empresa_id != $user->empresa_id) {
            return $this->json($response, ['error' => true, 'message' => 'No autorizado'], 403);
        }

        // Solo se puede eliminar si no está aprobado
        if (!empty($det->aprobado_admin)) {
            return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar un pallet ya aprobado'], 422);
        }

        $det->delete();

        return $this->json($response, ['error' => false, 'message' => 'Pallet eliminado']);
    }

    // ── POST /api/recepciones/{id}/aprobar ────────────────────────────────────
    // Aprueba la recepción completa (todos los pallets). Solo Supervisor/Admin.
    public function aprobar(Request $request, Response $response, array $args): Response
    {
        $user     = $request->getAttribute('user');
        $id       = $args['id'] ?? null;
        $rol      = strtolower($user->rol ?? '');

        if (!in_array($rol, ['admin', 'supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Se requiere rol Supervisor o Administrador'], 403);
        }

        $recepcion = Recepcion::with('detalles')->find($id);
        if (!$recepcion || $recepcion->sucursal_id !== $user->sucursal_id) {
            return $this->json($response, ['error' => true, 'message' => 'Recepción no encontrada.'], 404);
        }

        \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
        try {
            foreach ($recepcion->detalles as $linea) {
                if ($linea->aprobado_admin) continue; // already approved

                $linea->aprobado_admin = 1;
                $linea->save();

                // If a destination was set, ensure inventory is at Disponible
                if ($linea->ubicacion_destino_id) {
                    $inv = Inventario::where('empresa_id',   $recepcion->empresa_id)
                        ->where('sucursal_id',  $recepcion->sucursal_id)
                        ->where('producto_id',  $linea->producto_id)
                        ->where('ubicacion_id', $linea->ubicacion_destino_id)
                        ->where('lote', $linea->lote)
                        ->first();
                    if ($inv && $inv->estado !== 'Disponible') {
                        $inv->estado = 'Disponible';
                        $inv->save();
                    }
                }
            }

            $recepcion->aprobado_admin = 1;
            $recepcion->save();

            \Illuminate\Database\Capsule\Manager::connection()->commit();
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => 'Error al aprobar: ' . $e->getMessage()], 500);
        }

        return $this->json($response, ['error' => false, 'message' => 'Recepción aprobada. Stock disponible.', 'data' => $recepcion]);
    }


    // ══════════════════════════════════════════════════
    // Recepcion Dashboard (merged from RecepcionDashboardController)
    // ══════════════════════════════════════════════════

/**
     * GET /api/recepcion/dashboard
     */
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        // 1. Recepciones activas (Borrador o EnProceso)
        $activasQuery = Recepcion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Borrador', 'EnProceso'])
            ->with(['auxiliar:id,nombre', 'cita'])
            ->withCount('detalles')
            ->orderBy('created_at', 'desc');

        $activas = $activasQuery->get();

        // 2. Transformar activas y calcular métricas de tendencia
        $activasData = $activas->map(function($rec) {
            $stats = Capsule::table('recepcion_detalles')
                ->where('recepcion_id', $rec->id)
                ->select(
                    Capsule::raw('SUM(cantidad_esperada) as total_esperado'),
                    Capsule::raw('SUM(cantidad_recibida) as total_recibido')
                )
                ->first();

            $totalEsperado = (float)($stats->total_esperado ?? 0);
            $totalRecibido = (float)($stats->total_recibido ?? 0);
            $porcentaje = $totalEsperado > 0 ? round(($totalRecibido / $totalEsperado) * 100, 1) : 0;

            return [
                'id' => $rec->id,
                'numero_recepcion' => $rec->numero_recepcion,
                'estado' => $rec->estado,
                'proveedor' => $rec->cita->proveedor ?? 'Manual/Directo',
                'auxiliar' => $rec->auxiliar->nombre ?? 'N/A',
                'lineas_count' => $rec->detalles_count,
                'progreso' => $porcentaje,
                'inicio' => $rec->hora_inicio,
                'created_at' => $rec->created_at ? $rec->created_at->toDateTimeString() : null,
            ];
        });

        // 3. Métricas Avanzadas (Promedios y Tiempos)
        $hoy = date('Y-m-d');
        $sieteDiasAtras = date('Y-m-d', strtotime('-7 days'));
        
        // Receptores más eficientes del mes
        // En PostgreSQL no existe TIMESTAMPDIFF ni CONCAT de la misma forma para fechas
        $eficiencia = Capsule::table('recepciones as r')
            ->join('personal as p', 'r.auxiliar_id', '=', 'p.id')
            ->select('p.nombre', Capsule::raw('COUNT(r.id) as total'))
            ->where('r.estado', 'Cerrada')
            ->where('r.sucursal_id', $user->sucursal_id)
            ->groupBy('p.id', 'p.nombre')
            ->get();

        // Tendencia diaria (últimos 7 días)
        $tendencia = Capsule::table('recepciones')
            ->select(Capsule::raw('DATE(created_at) as fecha'), Capsule::raw('COUNT(id) as total'))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereDate('created_at', '>=', $sieteDiasAtras)
            ->groupBy('fecha')
            ->orderBy('fecha', 'asc')
            ->get();

        try {
            // Promedio de tiempo por línea global (hoy)
            $totalHorasEjecutadas = 0;
            $lineasTotales = 0;
            $totalCerradasHoy = Recepcion::where('sucursal_id', $user->sucursal_id)
                ->where('estado', 'Cerrada')
                ->whereDate('created_at', $hoy)
                ->withCount('detalles')
                ->get();

            if ($totalCerradasHoy->count() > 0) {
                $minutosTotales = 0;
                foreach($totalCerradasHoy as $tc) {
                    if ($tc->hora_inicio && $tc->hora_fin && $tc->fecha_movimiento) {
                        $fechaMov = is_object($tc->fecha_movimiento) ? $tc->fecha_movimiento->format('Y-m-d') : $tc->fecha_movimiento;
                        $start = new \DateTime($fechaMov . ' ' . $tc->hora_inicio);
                        $end = new \DateTime($fechaMov . ' ' . $tc->hora_fin);
                        $diff = $start->diff($end);
                        $mins = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
                        $minutosTotales += $mins;
                        $lineasTotales += $tc->detalles_count;
                    }
                }
                $totalHorasEjecutadas = round($minutosTotales / 60, 1);
            }

            // Agregación de unidades recibidas por categoría (Total acumulado para gráfica)
            $categorias_stats = Capsule::table('recepcion_detalles as rd')
                ->join('productos as p', 'rd.producto_id', '=', 'p.id')
                ->join('categoria_productos as cat', 'p.categoria_id', '=', 'cat.id')
                ->select('cat.nombre as categoria', Capsule::raw('SUM(rd.cantidad_recibida) as total'))
                ->whereExists(function($q) use ($user) {
                    $q->select(Capsule::raw(1))
                      ->from('recepciones as r')
                      ->whereColumn('r.id', 'rd.recepcion_id')
                      ->where('r.empresa_id', $user->empresa_id)
                      ->where('r.sucursal_id', $user->sucursal_id);
                })
                ->groupBy('cat.id', 'cat.nombre')
                ->orderBy('total', 'desc')
                ->get();

            return $this->ok($res, [
                'activas' => $activasData,
                'tendencia' => $tendencia,
                'eficiencia' => $eficiencia,
                'categorias_stats' => $categorias_stats,
                'pwa_stats' => [
                    'recepciones_hoy' => $totalCerradasHoy->count(),
                    'odc_pendientes' => OrdenCompra::where('empresa_id', $user->empresa_id)->whereIn('estado', ['Confirmada', 'En Proceso'])->count(),
                    'total_horas' => $totalHorasEjecutadas . "h",
                    'total_lineas' => $lineasTotales,
                    'promedio_tiempo' => $lineasTotales > 0 ? round(($totalHorasEjecutadas * 60) / $lineasTotales, 1) . "m" : "0m"
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error($res, 'Error en Dashboard de Recepción: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/recepcion/dashboard/{id}
     * Detalle completo de una recepción individual
     */
    public function detalle(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $recepcionId = $args['id'] ?? null;

        $recepcion = Recepcion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->with(['auxiliar:id,nombre', 'cita', 'detalles'])
            ->find($recepcionId);

        if (!$recepcion) {
            return $this->notFound($response);
        }

        // Calcular estadísticas de la recepción
        $stats = Capsule::table('recepcion_detalles')
            ->where('recepcion_id', $recepcion->id)
            ->select(
                Capsule::raw('SUM(cantidad_esperada) as total_esperado'),
                Capsule::raw('SUM(cantidad_recibida) as total_recibido'),
                Capsule::raw('SUM(CASE WHEN estado = "BuenEstado" THEN cantidad_recibida ELSE 0 END) as cantidad_buena'),
                Capsule::raw('COUNT(*) as total_lineas'),
                Capsule::raw('SUM(CASE WHEN estado != "BuenEstado" THEN 1 ELSE 0 END) as lineas_con_novedad')
            )
            ->first();

        $totalEsperado = (float)($stats->total_esperado ?? 0);
        $totalRecibido = (float)($stats->total_recibido ?? 0);
        $porcentaje = $totalEsperado > 0 ? round(($totalRecibido / $totalEsperado) * 100, 1) : 0;

        // Detalles completos de líneas
        $detalles = RecepcionDetalle::where('recepcion_id', $recepcion->id)
            ->with(['producto:id,codigo_interno,nombre,unidad'])
            ->get()
            ->map(function ($det) {
                return [
                    'id' => $det->id,
                    'producto' => $det->producto->nombre ?? 'N/A',
                    'codigo' => $det->producto->codigo_interno ?? 'N/A',
                    'esperado' => $det->cantidad_esperada,
                    'recibido' => $det->cantidad_recibida,
                    'varianza' => (float)$det->cantidad_recibida - (float)$det->cantidad_esperada,
                    'estado' => $det->estado,
                    'notas' => $det->novedades ?? null,
                    'lote' => $det->lote ?? null,
                    'fv' => $det->fecha_vencimiento ?? null,
                ];
            });

        return $this->ok($response, [
            'recepcion' => [
                'id' => $recepcion->id,
                'numero' => $recepcion->numero_recepcion,
                'estado' => $recepcion->estado,
                'auxiliar' => $recepcion->auxiliar->nombre ?? 'N/A',
                'proveedor' => $recepcion->cita->proveedor ?? 'Manual/Directo',
                'inicio' => $recepcion->hora_inicio,
                'fin' => $recepcion->hora_fin,
                'created_at' => $recepcion->created_at ? $recepcion->created_at->toDateTimeString() : null,
            ],
            'estadisticas' => [
                'total_esperado' => $totalEsperado,
                'total_recibido' => $totalRecibido,
                'porcentaje_recepcion' => $porcentaje,
                'cantidad_buena' => (float)($stats->cantidad_buena ?? 0),
                'total_lineas' => (int)($stats->total_lineas ?? 0),
                'lineas_con_novedad' => (int)($stats->lineas_con_novedad ?? 0),
            ],
            'detalles' => $detalles,
        ]);
    }

    /**
     * GET /api/recepcion/analytics/{id}
     * Análisis de varianza por ODC/Orden de Compra
     */
    public function getOdcAnalytics(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $odcId = $args['id'] ?? null;

        $odc = OrdenCompra::where('empresa_id', $user->empresa_id)
            ->with('detalles')
            ->find($odcId);

        if (!$odc) {
            return $this->notFound($response);
        }

        // Análisis: esperado vs recibido por línea
        $analisis = $odc->detalles->map(function ($linea) {
            $recibidos = Capsule::table('recepcion_detalles')
                ->where('odc_detalle_id', $linea->id)
                ->where('estado', 'BuenEstado')
                ->sum('cantidad_recibida') ?? 0;

            $novedad = Capsule::table('recepcion_detalles')
                ->where('odc_detalle_id', $linea->id)
                ->where('estado', '!=', 'BuenEstado')
                ->sum('cantidad_recibida') ?? 0;

            $esperado = (float)$linea->cantidad;
            $varianza = (float)$recibidos - $esperado;
            $porcentaje = $esperado > 0 ? round(($recibidos / $esperado) * 100, 1) : 0;

            return [
                'producto' => $linea->producto->nombre ?? 'N/A',
                'codigo' => $linea->producto->codigo_interno ?? 'N/A',
                'esperado' => $esperado,
                'recibido_bueno' => (float)$recibidos,
                'recibido_novedad' => (float)$novedad,
                'recibido_total' => (float)$recibidos + (float)$novedad,
                'varianza' => $varianza,
                'porcentaje' => $porcentaje,
                'estado' => $varianza === 0 ? 'OK' : ($varianza > 0 ? 'Exceso' : 'Falta'),
            ];
        });

        // Totales generales de la ODC
        $totalEsperado = $odc->detalles->sum('cantidad');
        $totalRecibido = Capsule::table('recepcion_detalles')
            ->where('odc_id', $odc->id)
            ->sum('cantidad_recibida') ?? 0;
        
        $variantesCount = $analisis->filter(fn($a) => $a['varianza'] != 0)->count();

        return $this->ok($response, [
            'odc' => [
                'id' => $odc->id,
                'numero' => $odc->numero_odc,
                'estado' => $odc->estado,
                'proveedor' => $odc->proveedor->nombre ?? 'N/A',
            ],
            'resumen' => [
                'total_esperado' => (float)$totalEsperado,
                'total_recibido' => (float)$totalRecibido,
                'varianza_total' => (float)$totalRecibido - (float)$totalEsperado,
                'lineas_totales' => $odc->detalles->count(),
                'lineas_con_varianza' => $variantesCount,
                'tasa_cumplimiento' => $totalEsperado > 0 ? round(($totalRecibido / $totalEsperado) * 100, 1) : 0,
            ],
            'lineas' => $analisis->values(),
        ]);
    }


    // ══════════════════════════════════════════════════
    // Recepcion Control Panel (merged from RecepcionControlPanelController)
    // ══════════════════════════════════════════════════

public function getControlPanelData(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $odcId = $params['odc_id'] ?? null;
        $auxiliarId = $params['auxiliar_id'] ?? null;
        $proveedorId = $params['proveedor_id'] ?? null;
        $categoriaId = $params['categoria_id'] ?? null;

        try {
            $query = OrdenCompra::where('ordenes_compra.empresa_id', $user->empresa_id)
                ->whereIn('ordenes_compra.estado', ['En Proceso', 'Confirmada']);

            if ($odcId) {
                $query->where('ordenes_compra.id', $odcId);
            }
            if ($auxiliarId) {
                $query->whereHas('auxiliares', function ($q) use ($auxiliarId) {
                    $q->where('personal.id', $auxiliarId);
                });
            }
            if ($proveedorId) {
                $query->where('ordenes_compra.proveedor_id', $proveedorId);
            }
            if ($categoriaId) {
                $query->whereHas('detalles.producto.categoria', function ($q) use ($categoriaId) {
                    $q->where('id', $categoriaId);
                });
            }

            $odcs = $query->with([
                'proveedor:id,razon_social',
                'auxiliares:id,nombre',
                'detalles' => function ($q) {
                    $q->select('id', 'orden_compra_id', 'producto_id', 'cantidad_solicitada', 'cantidad_recibida', 'estado_aprobacion');
                },
                'detalles.producto:id,nombre,codigo_interno'
            ])->select('ordenes_compra.*')->get();

            $kpis = $this->calculateKpis($odcs);
            $kpis['total_odcs'] = $odcs->count();
            $kpis['total_lineas'] = $odcs->sum(fn($odc) => $odc->detalles->count());

            $hoy = Carbon::now();
            $fechaLimite = Carbon::now()->addDays(60);

            $inventarios = Inventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereNotNull('fecha_vencimiento')
                ->whereBetween('fecha_vencimiento', [$hoy->format('Y-m-d'), $fechaLimite->format('Y-m-d')])
                ->where('cantidad', '>', 0)
                ->with(['producto:id,nombre,codigo_interno', 'ubicacion:id,codigo'])
                ->orderBy('fecha_vencimiento', 'asc')
                ->get(['id', 'producto_id', 'ubicacion_id', 'lote', 'fecha_vencimiento', 'cantidad']);

            $proximosVencer = $inventarios->map(function ($inv) use ($hoy) {
                $fecha = $inv->fecha_vencimiento instanceof \DateTime
                    ? $inv->fecha_vencimiento
                    : new \DateTime($inv->fecha_vencimiento);
                $dias = (int)$hoy->diff($fecha)->format('%r%a');
                return [
                    'id' => $inv->id,
                    'producto' => $inv->producto->nombre ?? 'N/A',
                    'codigo' => $inv->producto->codigo_interno ?? 'N/A',
                    'ubicacion' => $inv->ubicacion->codigo ?? 'N/A',
                    'lote' => $inv->lote ?? 'N/A',
                    'fecha_vencimiento' => $inv->fecha_vencimiento?->format('Y-m-d'),
                    'cantidad' => $inv->cantidad,
                    'dias_vencer' => $dias,
                ];
            });

            $kpis['proximos_vencer'] = $proximosVencer->count();
            $ranking = $this->getAuxiliarRanking($user->empresa_id, $user->sucursal_id, $params);

            $filters = $this->getAvailableFilters($user->empresa_id, $user->sucursal_id);

            return $this->ok($response, [
                'odcs' => $odcs,
                'kpis' => $kpis,
                'ranking_auxiliares' => $ranking,
                'filters' => $filters,
                'proximos_vencer' => $proximosVencer,
            ]);

        } catch (\Exception $e) {
            error_log("Error en RecepcionController (ControlPanel): " . $e->getMessage() . " on line " . $e->getLine() . " in file " . $e->getFile());
            return $this->error($response, "Error al obtener datos del panel de control: " . $e->getMessage(), 500);
        }
    }

    private function calculateKpis($odcs)
    {
        $totalLineas = 0;
        $lineasRecibidas = 0;
        $totalCantidadSolicitada = 0;
        $totalCantidadRecibida = 0;
        $lineasFaltantes = 0;
        $cantidadFaltante = 0;
        $tiempoTranscurrido = '00:00:00';

        if ($odcs->isEmpty()) {
            return [
                'pct_recibo_referencia' => 0,
                'pct_recibo_cantidad' => 0,
                'tiempo_transcurrido' => $tiempoTranscurrido,
                'total_lineas_faltantes' => 0,
                'total_cantidad_faltante' => 0,
            ];
        }

        foreach ($odcs as $odc) {
            $totalLineas += $odc->detalles->count();
            $totalCantidadSolicitada += $odc->detalles->sum('cantidad_solicitada');
            $totalCantidadRecibida += $odc->detalles->sum('cantidad_recibida');

            foreach ($odc->detalles as $detalle) {
                if ($detalle->cantidad_recibida > 0) {
                    $lineasRecibidas++;
                }
                if ($detalle->cantidad_recibida < $detalle->cantidad_solicitada) {
                    $lineasFaltantes++;
                    $cantidadFaltante += $detalle->cantidad_solicitada - $detalle->cantidad_recibida;
                }
            }

            if ($odc->fecha_inicio_recibo) {
                $inicio = new \DateTime($odc->fecha_inicio_recibo);
                $ahora = new \DateTime();
                $diff = $ahora->diff($inicio);
                $tiempoTranscurrido = $diff->format('%H:%I:%S');
            }
        }

        $pctReciboReferencia = $totalLineas > 0 ? round(($lineasRecibidas / $totalLineas) * 100, 2) : 0;
        $pctReciboCantidad = $totalCantidadSolicitada > 0 ? round(($totalCantidadRecibida / $totalCantidadSolicitada) * 100, 2) : 0;

        return [
            'pct_recibo_referencia' => $pctReciboReferencia,
            'pct_recibo_cantidad' => $pctReciboCantidad,
            'tiempo_transcurrido' => $tiempoTranscurrido,
            'total_lineas_faltantes' => $lineasFaltantes,
            'total_cantidad_faltante' => $cantidadFaltante,
        ];
    }

    private function getAuxiliarRanking($empresaId, $sucursalId, $params)
    {
        $query = Personal::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('rol', 'Auxiliar')
            ->where('activo', 1);

        if (!empty($params['auxiliar_id'])) {
            $query->where('id', $params['auxiliar_id']);
        }

        $auxiliares = $query->get(['id', 'nombre'])->map(function($aux) {
            // Conteo de recepciones en proceso
            $aux->recepciones_count = Capsule::table('recepciones')
                ->where('auxiliar_id', $aux->id)
                ->where('estado', 'En Proceso')
                ->count();
                
            // Suma de unidades recibidas (solo de recepciones en proceso)
            $aux->total_unidades_recibidas = Capsule::table('recepcion_detalles as rd')
                ->join('recepciones as r', 'r.id', '=', 'rd.recepcion_id')
                ->where('r.auxiliar_id', $aux->id)
                ->where('r.estado', 'En Proceso')
                ->sum('rd.cantidad_recibida') ?: 0;
                
            return $aux;
        })->sortByDesc('total_unidades_recibidas')->values();

        return $auxiliares;
    }

    private function getAvailableFilters($empresaId, $sucursalId)
    {
        $odcs = OrdenCompra::where('empresa_id', $empresaId)
            ->whereIn('estado', ['En Proceso', 'Confirmada'])
            ->get(['id', 'numero_odc']);

        $auxiliares = Personal::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('rol', 'Auxiliar')
            ->where('activo', 1)
            ->get(['id', 'nombre']);

        $proveedores = Capsule::table('proveedores')
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->get(['id', 'razon_social as nombre']);
            
        $categorias = Capsule::table('categoria_productos')
            ->where('empresa_id', $empresaId)
            ->get(['id', 'nombre']);

        return [
            'odcs' => $odcs,
            'auxiliares' => $auxiliares,
            'proveedores' => $proveedores,
            'categorias' => $categorias,
        ];
    }

    public function aprobarLinea(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $lineaId = $args['id'];

        $linea = OrdenCompraDetalle::with('ordenCompra')->find($lineaId);

        if (!$linea || $linea->ordenCompra->empresa_id !== $user->empresa_id) {
            return $this->error($response, 'Línea de ODC no encontrada o no autorizada.', 404);
        }

        if ($linea->estado_aprobacion === 'Aprobado') {
            return $this->error($response, 'La línea ya ha sido aprobada.', 400);
        }

        Capsule::connection()->beginTransaction();
        try {
            $linea->estado_aprobacion = 'Aprobado';
            $linea->save();

            $recepcionDetalles = Capsule::table('recepcion_detalles')
                ->where('orden_compra_detalle_id', $linea->id)
                ->get();
            
            $patio = Ubicacion::where('sucursal_id', $user->sucursal_id)
                ->where('tipo_ubicacion', 'Patio')
                ->first();

            foreach ($recepcionDetalles as $rd) {
                $invPatio = Inventario::where('producto_id', $rd->producto_id)
                    ->where('lote', $rd->lote)
                    ->where('fecha_vencimiento', $rd->fecha_vencimiento)
                    ->where('ubicacion_id', $patio->id)
                    ->where('estado', 'En Patio')
                    ->first();

                if ($invPatio && $invPatio->cantidad >= $rd->cantidad_recibida) {
                    $invPatio->cantidad -= $rd->cantidad_recibida;
                    if ($invPatio->cantidad <= 0) {
                        $invPatio->delete();
                    } else {
                        $invPatio->save();
                    }

                    $invDisponible = Inventario::firstOrNew([
                        'empresa_id' => $linea->ordenCompra->empresa_id,
                        'sucursal_id' => $user->sucursal_id,
                        'producto_id' => $rd->producto_id,
                        'ubicacion_id' => $patio->id,
                        'lote' => $rd->lote,
                        'fecha_vencimiento' => $rd->fecha_vencimiento,
                        'estado' => 'Disponible',
                    ]);
                    $invDisponible->cantidad += $rd->cantidad_recibida;
                    $invDisponible->save();
                }
            }

            Capsule::connection()->commit();
            return $this->ok($response, ['message' => 'Línea aprobada y lista para ubicar.']);

        } catch (\Exception $e) {
            Capsule::connection()->rollBack();
            error_log("Error al aprobar línea: " . $e->getMessage());
            return $this->error($response, 'Error al aprobar la línea: ' . $e->getMessage(), 500);
        }
    }

    public function agregarLinea(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $odcId = $args['id'];
        $data = $request->getParsedBody();

        $odc = OrdenCompra::find($odcId);
        if (!$odc || $odc->empresa_id !== $user->empresa_id) {
            return $this->error($response, 'ODC no encontrada.', 404);
        }
        if ($odc->estado !== 'En Proceso') {
            return $this->error($response, 'Solo se pueden agregar líneas a ODC en proceso.', 400);
        }

        $producto = Producto::find($data['producto_id']);
        if (!$producto) {
            return $this->error($response, 'Producto no encontrado.', 404);
        }

        $linea = new OrdenCompraDetalle();
        $linea->orden_compra_id = $odcId;
        $linea->producto_id = $data['producto_id'];
        $linea->cantidad_solicitada = $data['cantidad_solicitada'];
        $linea->cantidad_recibida = 0;
        $linea->save();

        return $this->ok($response, $linea, 'Línea agregada a la ODC.');
    }

    public function editarLinea(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $lineaId = $args['id'];
        $data = $request->getParsedBody();

        $linea = OrdenCompraDetalle::with('ordenCompra')->find($lineaId);
        if (!$linea || $linea->ordenCompra->empresa_id !== $user->empresa_id) {
            return $this->error($response, 'Línea no encontrada.', 404);
        }

        if (isset($data['cantidad_recibida'])) {
            $linea->cantidad_recibida = $data['cantidad_recibida'];
        }
        if (isset($data['cantidad_solicitada'])) {
            $linea->cantidad_solicitada = $data['cantidad_solicitada'];
        }
        
        $linea->save();

        return $this->ok($response, $linea, 'Línea de ODC actualizada.');
    }

    public function eliminarLinea(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $lineaId = $args['id'];

        $linea = OrdenCompraDetalle::with('ordenCompra')->find($lineaId);
        if (!$linea || $linea->ordenCompra->empresa_id !== $user->empresa_id) {
            return $this->error($response, 'Línea no encontrada.', 404);
        }

        if ($linea->cantidad_recibida > 0) {
            return $this->error($response, 'No se puede eliminar una línea que ya tiene cantidad recibida.', 400);
        }

        $linea->delete();

        return $this->ok($response, null, 'Línea de ODC eliminada.');
    }

}
