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

        $query = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->withCount('detalles')
            ->orderBy('id', 'desc')
            ->limit($limit);

        if (!empty($params['estado'])) {
            $query->where('estado', $params['estado']);
        }
        if (isset($params['odc_id'])) {
            if ($params['odc_id'] === 'null' || $params['odc_id'] === '') {
                $query->whereNull('odc_id')->with('auxiliar');
            } else {
                $query->where('odc_id', $params['odc_id'])->with('detalles.producto');
            }
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

        $recepcion = Recepcion::with(['detalles.producto', 'detalles.ubicacionDestino'])->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
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
            ->where('recepciones.empresa_id', $this->getEffectiveEmpresaId($user, $request))
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

        $recepcion = Recepcion::with('detalles')->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
        if (!$recepcion) {
            return $this->json($response, ['error' => true, 'message' => 'Recepción no encontrada'], 404);
        }
        try {
            Capsule::connection()->beginTransaction();

            // Revertir inventario de cada línea aprobada (sin-ODC y ODC con stock en Patio)
            if ($recepcion->detalles->isNotEmpty()) {
                foreach ($recepcion->detalles as $detalle) {
                    if (!$detalle->aprobado_admin) continue; // sólo líneas que crearon stock
                    $loteKey = $detalle->lote ?? 'N/A';
                    $inv = \App\Models\Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $detalle->producto_id)
                        ->where('ubicacion_id', $detalle->ubicacion_destino_id)
                        ->where('lote', $loteKey)
                        ->when($detalle->numero_pallet, fn($q) => $q->where('numero_pallet', $detalle->numero_pallet))
                        ->when(!$detalle->numero_pallet, fn($q) => $q->whereNull('numero_pallet'))
                        ->first();

                    if ($inv) {
                        $newQty = max(0, ($inv->cantidad ?? 0) - (float)$detalle->cantidad_recibida);
                        if ($newQty == 0) {
                            $inv->delete();
                        } else {
                            $inv->cantidad = $newQty;
                            // Recalcular descomposición UND/TOTAL tras anulación
                            $_anulUpc        = max(1, (int)($detalle->cajas_por_unidad ?? 1));
                            $inv->cantidad_cajas = (int)floor((float)$newQty / $_anulUpc);
                            $inv->saldos         = fmod((float)$newQty, (float)$_anulUpc);
                            $inv->save();
                        }

                        \App\Models\MovimientoInventario::create([
                            'empresa_id'           => $this->getEffectiveEmpresaId($user, $request),
                            'sucursal_id'          => $user->sucursal_id,
                            'producto_id'          => $detalle->producto_id,
                            'tipo_movimiento'      => 'AjusteNegativo',
                            'referencia_tipo'      => $recepcion->odc_id ? 'ODC' : 'SinODC',
                            'referencia_id'        => $recepcion->id,
                            'cantidad'             => (float)$detalle->cantidad_recibida,
                            'fecha_movimiento'     => date('Y-m-d'),
                            'hora_inicio'          => date('H:i:s'),
                            'ubicacion_destino_id' => $detalle->ubicacion_destino_id,
                            'auxiliar_id'          => $user->id,
                            'numero_pallet'        => $detalle->numero_pallet,
                            'lote'                 => $loteKey,
                            'observaciones'        => 'Anulación recepción ' . $recepcion->numero_recepcion,
                        ]);
                    }
                }
            }

            Capsule::table('recepcion_detalles')->where('recepcion_id', $recepcion->id)->delete();
            $recepcion->delete();

            Capsule::connection()->commit();
            return $this->json($response, ['error' => false, 'message' => 'Recepción eliminada correctamente']);
        } catch (\Exception $e) {
            Capsule::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => 'Error al eliminar: ' . $e->getMessage()], 500);
        }
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
            if (!$cita || $cita->empresa_id !== $this->getEffectiveEmpresaId($user, $request) || $cita->sucursal_id !== $user->sucursal_id) {
                return $this->json($response, ['error' => true, 'message' => 'Cita inválida.'], 400);
            }
            $cita->estado = 'EnCurso';
            $cita->save();
        }

        $recepcion = new Recepcion();
        $recepcion->empresa_id = $this->getEffectiveEmpresaId($user, $request);
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
        if (!$recepcion || $recepcion->empresa_id !== $this->getEffectiveEmpresaId($user, $request) || $recepcion->sucursal_id !== $user->sucursal_id || $recepcion->estado !== 'Borrador') {
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
        
        $guard = new InventoryGuard($this->getEffectiveEmpresaId($user, $request), $user->sucursal_id, $user->id);
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
        $detalle->cantidad_cajas     = $cantidad_cajas ?? ceil((float)$cantidad_recibida / $cajasUnd);
        $detalle->cajas_por_unidad   = $cajasUnd;
        $detalle->lote = $data['lote'] ?? null;
        $detalle->fecha_vencimiento = $fecha_vencimiento;
        $detalle->estado_mercancia = $data['estado_mercancia'] ?? 'BuenEstado';
        $detalle->novedad_motivo = $data['novedad_motivo'] ?? null;

        // Por defecto va a zona PATIO
        $patio_id = \App\Models\Ubicacion::where('sucursal_id', $user->sucursal_id)
                                ->where('tipo_ubicacion', 'Patio')
                                ->value('id');

        if (!$patio_id) {
            return $this->json($response, [
                'error'   => true,
                'message' => 'No existe ubicación de tipo Patio para esta sucursal. Créela antes de registrar recepciones.',
            ], 422);
        }

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

        $odc = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find((int)$data['odc_id']);
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

        $producto = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find((int)$data['producto_id']);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto inválido'], 404);
        }

        // ── Conversión cajas → unidades ──────────────────────────────────────
        // Priorizamos la 'cantidad' enviada como unidades totales.
        // 'cantidad_cajas' se guarda como referencia del empaque original.
        $cajasUnd      = max(1, (int)($producto->unidades_caja ?? 1));
        $cantidad      = (float)($data['cantidad'] ?? 0);
        $cantidadCajas = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : ceil((float)$cantidad / $cajasUnd);

        // Si el cliente explícitamente pide modo cajas (legacy), recalculamos
        if ($cantidad <= 0) {
            return $this->json($response, ['error' => true, 'message' => 'La cantidad debe ser mayor a cero'], 400);
        }

        // ── Validación estricta vs ODC (InventoryGuard) ─────────────────────
        $detalleOdc = OrdenCompraDetalle::where('orden_compra_id', $odc->id)
            ->where('producto_id', $producto->id)
            ->first();

        if ($detalleOdc) {
            $guard = new InventoryGuard($this->getEffectiveEmpresaId($user, $request), $user->sucursal_id, $user->id);
            $check = $guard->canReceive($detalleOdc->id, $cantidad);
            if (!$check['ok']) {
                return $this->json($response, [
                    'error'   => true,
                    'message' => 'EXCEDENTE BLOQUEADO: ' . $check['message']
                ], 422);
            }
        }

        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('sucursal_id', $user->sucursal_id)
            ->where('odc_id', $odc->id)
            ->where('auxiliar_id', $user->id)
            ->where('estado', 'Borrador')
            ->first();

        if (!$recepcion) {
            $recepcion = new Recepcion();
            $recepcion->empresa_id = $this->getEffectiveEmpresaId($user, $request);
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
        $guard = new InventoryGuard($this->getEffectiveEmpresaId($user, $request), $user->sucursal_id, $user->id);
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
                ->whereRaw("REPLACE(UPPER(codigo), '-', '') = ?", [str_replace('-', '', $codigo)])
                ->value('id');
        }

        if (!$ubicacionDestinoId) {
            $ubicacionDestinoId = Ubicacion::where('sucursal_id', $user->sucursal_id)
                ->where('tipo_ubicacion', 'Patio')
                ->value('id');
        }

        if (!$ubicacionDestinoId) {
            return $this->json($response, [
                'error'   => true,
                'message' => 'No existe ubicación de tipo Patio para esta sucursal. Créela antes de registrar recepciones.',
            ], 422);
        }

        $detalle->ubicacion_destino_id = $ubicacionDestinoId;
        $detalle->numero_pallet = !empty($data['numero_pallet']) ? (int)$data['numero_pallet'] : null;
        $detalle->aprobado_admin = 1; // Auto-aprobar para visibilidad inmediata en Patio

        // ── INVENTARIO EN TIEMPO REAL (PALLET POR PALLET) ────────────────────
        try {
            \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

            $detalle->save();

            if ($detalleOdc) {
                // Actualizar en UNIDADES (cantidad ya contiene unidades convertidas)
                $detalleOdc->cantidad_recibida = max(0, $detalleOdc->cantidad_recibida + $cantidad);
                $detalleOdc->save();
            }

            // 1. Registrar Movimiento de Entrada
            \App\Models\MovimientoInventario::create([
                'empresa_id'  => $this->getEffectiveEmpresaId($user, $request),
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
            // lockForUpdate: evita "lost update" si dos capturas concurrentes (dos móviles,
            // o esta misma ruta llamada dos veces) suman cantidad sobre la misma fila a la vez.
            $_invKeyPatio = [
                'empresa_id'   => $this->getEffectiveEmpresaId($user, $request),
                'sucursal_id'  => $user->sucursal_id,
                'producto_id'  => $producto->id,
                'ubicacion_id' => $ubicacionDestinoId,
                'lote'         => $data['lote'] ?? 'N/A',
                'estado'       => 'Disponible',
                'numero_pallet' => $detalle->numero_pallet,
            ];
            $inv = \App\Models\Inventario::where($_invKeyPatio)->lockForUpdate()->first();
            if (!$inv) {
                $inv = new \App\Models\Inventario($_invKeyPatio);
            }
            $inv->cantidad           = ($inv->cantidad ?? 0) + $cantidad;
            $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
            $inv->fecha_vencimiento  = $detalle->fecha_vencimiento ?? $inv->fecha_vencimiento;
            // ── Arquitectura UND/TOTAL: descomponer cantidad en cajas + saldos ──
            $_invCajasUnd        = max(1, (int)($producto->unidades_caja ?? 1));
            $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $_invCajasUnd);
            $inv->saldos         = fmod((float)$inv->cantidad, (float)$_invCajasUnd);
            $inv->save();

            \Illuminate\Database\Capsule\Manager::connection()->commit();
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            error_log("Error en recepcion operativa transaccion: " . $e->getMessage());
            return $this->json($response, [
                'error' => true,
                'message' => 'Error al guardar recepción: ' . $e->getMessage(),
            ], 500);
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
                'inventario'    => [
                    'cantidad'        => $inv->cantidad,
                    'cantidad_cajas'  => $inv->cantidad_cajas,
                    'saldos'          => $inv->saldos,
                ],
            ]
        ], 201);
    }

    /**
     * GET /api/recepciones/buscar-qr
     * Busca un producto a partir de texto QR con formato CODIGO/FECHA_VENCIMIENTO.
     * Retorna el producto encontrado y la fecha de vencimiento parseada.
     */
    public function buscarProductoPorQr(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $qr     = trim($params['q'] ?? '');

        if ($qr === '') {
            return $this->json($response, ['error' => true, 'message' => 'Parámetro q requerido'], 400);
        }

        // ── Separar código y fecha ───────────────────────────────────────────
        $code     = '';
        $rawDate  = '';
        $rawLote  = '';

        if (strpos($qr, ',') !== false) {
            // Formato Comas: CODIGO, SOMETHING, FECHA_VENC, SOMETHING, LOTE_DATE, ...
            $parts = explode(',', $qr);
            $code = trim($parts[0] ?? '');
            
            // Fecha Vencimiento: Tercer campo (index 2)
            $rawDate = trim($parts[2] ?? '');
            
            // Lote: Quinto campo (index 4) - También puede ser una fecha
            $rawLote = trim($parts[4] ?? '');
            if ($rawLote !== '') {
                $parsedLoteDate = $this->_parsearFechaQr($rawLote);
                if ($parsedLoteDate) {
                    $rawLote = $parsedLoteDate;
                }
            }
        } else {
            // Formato Legacy: CODIGO/FECHA_VENCIMIENTO
            $slashPos = strpos($qr, '/');
            $code     = $slashPos !== false ? trim(substr($qr, 0, $slashPos)) : trim($qr);
            $rawDate  = $slashPos !== false ? trim(substr($qr, $slashPos + 1)) : '';
        }

        // ── Parsear fecha de vencimiento ─────────────────────────────────────
        $fechaVenc = null;
        if ($rawDate !== '') {
            $fechaVenc = $this->_parsearFechaQr($rawDate);
        }

        // ── Buscar producto: EAN exacto → código_interno → parcial ───────────
        $prod = null;
        if ($code !== '') {
            // 1. EAN exacto
            $eanRec = \App\Models\ProductoEan::where('codigo_ean', $code)->first();
            if ($eanRec) {
                $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($eanRec->producto_id);
            }
            // 2. código_interno exacto
            if (!$prod) {
                $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                    ->where('codigo_interno', $code)->first();
            }
            // 3. EAN sufijo (últimos 10 chars)
            if (!$prod && strlen($code) > 6) {
                $eanRec = \App\Models\ProductoEan::where('codigo_ean', 'like', '%' . substr($code, -10))->first();
                if ($eanRec) {
                    $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($eanRec->producto_id);
                }
            }
            // 4. Búsqueda por nombre si no era un código (fallback texto libre)
            if (!$prod) {
                $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                    ->where(function($q) use ($code) {
                        $q->where('nombre', 'like', "%{$code}%")
                          ->orWhere('codigo_interno', 'like', "%{$code}%");
                    })->first();
            }
        }

        if (!$prod) {
            return $this->json($response, [
                'error'   => true,
                'message' => "Producto no encontrado para el código: '{$code}'",
                'code'    => $code,
                'fecha_raw' => $rawDate,
            ], 404);
        }

        return $this->json($response, [
            'error'   => false,
            'data'    => [
                'producto'         => $prod,
                'fecha_vencimiento'=> $fechaVenc,
                'fecha_raw'        => $rawDate,
                'lote_raw'         => $rawLote,
                'code_qr'          => $code,
            ],
        ]);
    }

    /**
     * Parsea textos de fecha en formatos variados → YYYY-MM-DD o null.
     * Soporta: YYYYMMDD, DDMMYYYY, DD/MM/YYYY, YYYY-MM-DD, YYYY/MM/DD, DD-MM-YYYY, etc.
     */
    private function _parsearFechaQr(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') return null;

        // 1. Si ya tiene formato YYYY-MM-DD, retornarlo tal cual
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        // 2. Intentar parseo directo con Carbon (maneja /, -, y formatos estándar)
        try {
            return Carbon::parse(str_replace('/', '-', $s))->format('Y-m-d');
        } catch (\Exception $e) {}

        // 3. Limpiar todo lo que no sea números para formatos pegados (20261231, 31122026)
        $clean = preg_replace('/[^0-9]/', '', $s);
        
        // 8 dígitos: YYYYMMDD
        if (strlen($clean) === 8 && preg_match('/^(19|20|21)\d{6}$/', $clean)) {
            try { return Carbon::createFromFormat('Ymd', $clean)->format('Y-m-d'); } catch (\Exception $e) {}
        }
        
        // 8 dígitos: DDMMYYYY
        if (strlen($clean) === 8 && preg_match('/^\d{4}(19|20|21)\d{2}$/', $clean)) {
            try { return Carbon::createFromFormat('dmY', $clean)->format('Y-m-d'); } catch (\Exception $e) {}
        }

        // 6 dígitos: DDMMYY (asumimos siglo 21 si es > 20)
        if (strlen($clean) === 6) {
            try { return Carbon::createFromFormat('dmy', $clean)->format('Y-m-d'); } catch (\Exception $e) {}
        }

        // 7. Soporte para fechas seriales de Excel (ej: 46232)
        if (is_numeric($s) && (int)$s > 30000 && (int)$s < 70000) {
            try {
                return Carbon::create(1899, 12, 30)->addDays((int)$s)->format('Y-m-d');
            } catch (\Exception $e) {}
        }

        return null;
    }

    /**
     * POST /api/recepciones/sin-odc
     * Captura operativa SIN Orden de Compra (modo ciego).
     * Misma lógica que detallesOperativa() pero sin requerir ni validar ODC.
     */
    public function detallesOperativaSinOdc(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];

        if (empty($data['producto_id']) || empty($data['cantidad'])) {
            return $this->json($response, ['error' => true, 'message' => 'Campos requeridos: producto_id, cantidad'], 400);
        }

        $producto = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find((int)$data['producto_id']);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto inválido'], 404);
        }

        $cajasUnd = max(1, (int)($producto->unidades_caja ?? 1));

        // Priorizar ingreso por U/E: si viene cantidad_ue y el producto tiene factor_udm, convertir a unidades
        if (!empty($data['cantidad_ue']) && $producto->tieneUdm()) {
            $cantidad = $producto->calcularUnidades((float)$data['cantidad_ue']);
        } else {
            $cantidad = (float)$data['cantidad'];
        }

        $cantidadCajas = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : ceil((float)$cantidad / $cajasUnd);

        if ($cantidad <= 0) {
            return $this->json($response, ['error' => true, 'message' => 'La cantidad debe ser mayor a cero'], 400);
        }

        // Validar fecha de vencimiento (si el producto la requiere)
        $fechaVenc = $this->estandarizarFecha($data['fecha_vencimiento'] ?? null);
        $guard = new InventoryGuard($this->getEffectiveEmpresaId($user, $request), $user->sucursal_id, $user->id);
        $checkDate = $guard->checkExpirationMandatory($producto->id, $fechaVenc);
        if (!$checkDate['ok']) {
            return $this->json($response, ['error' => true, 'message' => $checkDate['message']], 422);
        }

        // Buscar o crear Recepción sin ODC del día en Borrador para este auxiliar
        $hoy = date('Y-m-d');
        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereNull('odc_id')
            ->where('auxiliar_id', $user->id)
            ->where('estado', 'Borrador')
            ->whereDate('fecha_movimiento', $hoy)
            ->first();

        if (!$recepcion) {
            $recepcion = new Recepcion();
            $recepcion->empresa_id       = $this->getEffectiveEmpresaId($user, $request);
            $recepcion->sucursal_id      = $user->sucursal_id;
            $recepcion->odc_id           = null;
            $recepcion->numero_recepcion = Recepcion::generarNumero($user->sucursal_id);
            $recepcion->auxiliar_id      = $user->id;
            $recepcion->modo_ciego       = true;
            $recepcion->estado           = 'Borrador';
            $recepcion->fecha_movimiento = $hoy;
            $recepcion->hora_inicio      = date('H:i:s');
            $recepcion->observaciones    = 'Recepción sin Orden de Compra';
            $recepcion->save();
        }

        // Resolver ubicación destino
        $ubicacionDestinoId = null;
        if (!empty($data['ubicacion_destino_id'])) {
            $ubicacionDestinoId = (int)$data['ubicacion_destino_id'];
        } elseif (!empty($data['ubicacion_destino_codigo'])) {
            $codigo = trim(strtoupper($data['ubicacion_destino_codigo']));
            $ubicacionDestinoId = Ubicacion::where('sucursal_id', $user->sucursal_id)
                ->whereRaw("REPLACE(UPPER(codigo), '-', '') = ?", [str_replace('-', '', $codigo)])
                ->value('id');
        }
        if (!$ubicacionDestinoId) {
            $ubicacionDestinoId = Ubicacion::where('sucursal_id', $user->sucursal_id)
                ->where('tipo_ubicacion', 'Patio')->value('id');
        }

        if (!$ubicacionDestinoId) {
            return $this->json($response, [
                'error'   => true,
                'message' => 'No existe ubicación de tipo Patio para esta sucursal. Créela antes de registrar recepciones.',
            ], 422);
        }

        $detalle = new RecepcionDetalle();
        $detalle->recepcion_id       = $recepcion->id;
        $detalle->producto_id        = $producto->id;
        $detalle->cantidad_esperada  = 0;
        $detalle->cantidad_recibida  = $cantidad;
        $detalle->cantidad_cajas     = $cantidadCajas;
        $detalle->cajas_por_unidad   = $cajasUnd;
        $detalle->lote               = $data['lote'] ?? null;
        $detalle->fecha_vencimiento  = $fechaVenc;
        $detalle->estado_mercancia   = $data['estado_mercancia'] ?? 'BuenEstado';
        $detalle->novedad_motivo     = $data['novedad_motivo'] ?? null;
        $detalle->ubicacion_destino_id = $ubicacionDestinoId;
        $detalle->numero_pallet      = !empty($data['numero_pallet']) ? (int)$data['numero_pallet'] : null;
        $detalle->aprobado_admin     = 1;
        $detalle->save();

        // Inventario en tiempo real
        try {
            \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

            \App\Models\MovimientoInventario::create([
                'empresa_id'           => $this->getEffectiveEmpresaId($user, $request),
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $producto->id,
                'tipo_movimiento'      => 'Entrada',
                'referencia_tipo'      => 'Recepción Sin ODC: ' . $recepcion->numero_recepcion,
                'cantidad'             => $cantidad,
                'fecha_movimiento'     => $hoy,
                'hora_inicio'          => date('H:i:s'),
                'ubicacion_destino_id' => $ubicacionDestinoId,
                'auxiliar_id'          => $user->id,
                'numero_pallet'        => $detalle->numero_pallet,
                'lote'                 => $data['lote'] ?? 'N/A',
            ]);

            $_invKeySinOdc = [
                'empresa_id'    => $this->getEffectiveEmpresaId($user, $request),
                'sucursal_id'   => $user->sucursal_id,
                'producto_id'   => $producto->id,
                'ubicacion_id'  => $ubicacionDestinoId,
                'lote'          => $data['lote'] ?? 'N/A',
                'estado'        => 'Disponible',
                'numero_pallet' => $detalle->numero_pallet,
            ];
            $inv = \App\Models\Inventario::where($_invKeySinOdc)->lockForUpdate()->first();
            if (!$inv) {
                $inv = new \App\Models\Inventario($_invKeySinOdc);
            }
            $inv->cantidad           = ($inv->cantidad ?? 0) + $cantidad;
            $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
            $inv->fecha_vencimiento  = $detalle->fecha_vencimiento ?? $inv->fecha_vencimiento;
            // ── Arquitectura UND/TOTAL: leer cajas/saldos del body o inferir ──
            $_bodyCajas  = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : 0;
            $_bodySaldos = isset($data['saldos']) ? (float)$data['saldos'] : 0.0;
            if ($_bodyCajas === 0 && $_bodySaldos == 0.0 && $inv->cantidad > 0) {
                // Compatibilidad: inferir descomposición a partir del total acumulado
                $_invUpc     = max(1, (int)($producto->unidades_caja ?? 1));
                $_bodyCajas  = (int)floor((float)$inv->cantidad / $_invUpc);
                $_bodySaldos = fmod((float)$inv->cantidad, (float)$_invUpc);
            }
            $inv->cantidad_cajas = $_bodyCajas;
            $inv->saldos         = $_bodySaldos;
            $inv->save();

            \Illuminate\Database\Capsule\Manager::connection()->commit();
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            error_log('Error inventario sin-ODC: ' . $e->getMessage());
        }

        return $this->json($response, [
            'error'   => false,
            'message' => 'Captura sin ODC registrada correctamente',
            'data'    => [
                'recepcion'  => $recepcion,
                'detalle'    => $detalle,
                'conversion' => [
                    'cajas'          => $cantidadCajas,
                    'unidades_caja'  => $cajasUnd,
                    'total_unidades' => $cantidad,
                ],
                'inventario' => [
                    'cantidad'       => $inv->cantidad,
                    'cantidad_cajas' => $inv->cantidad_cajas,
                    'saldos'         => $inv->saldos,
                ],
            ],
        ], 201);
    }

    // ── PATCH /api/recepciones/{id}/detalle/{detalleId} ──────────────────────
    // Edita la cantidad de una línea de recepción sin ODC y ajusta el inventario.
    public function actualizarDetalleSinOdc(Request $r, Response $res, array $a): Response
    {
        $user        = $r->getAttribute('user');
        $recepcionId = (int)($a['id']        ?? 0);
        $detalleId   = (int)($a['detalleId'] ?? 0);
        $body        = (array)($r->getParsedBody() ?? []);

        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereNull('odc_id')
            ->where('estado', 'Borrador')
            ->find($recepcionId);
        if (!$recepcion) {
            return $this->json($res, ['error'=>true,'message'=>'Recepción sin ODC no encontrada o ya cerrada'], 404);
        }

        $rol = strtolower($user->rol ?? '');
        if ($recepcion->auxiliar_id !== $user->id && !in_array($rol, ['admin','supervisor','superadmin'])) {
            return $this->json($res, ['error'=>true,'message'=>'No tiene permiso para modificar esta recepción'], 403);
        }

        $detalle = RecepcionDetalle::where('recepcion_id', $recepcionId)->find($detalleId);
        if (!$detalle) {
            return $this->json($res, ['error'=>true,'message'=>'Línea no encontrada'], 404);
        }

        $nuevaCantidad = (float)($body['cantidad_recibida'] ?? $body['cantidad'] ?? 0);
        if ($nuevaCantidad <= 0) {
            return $this->json($res, ['error'=>true,'message'=>'La cantidad debe ser mayor a cero'], 400);
        }

        $upc         = max(1, (int)($detalle->cajas_por_unidad ?? 1));
        $nuevasCajas = isset($body['cantidad_cajas']) ? (int)$body['cantidad_cajas'] : ceil((float)$nuevaCantidad / $upc);
        $delta       = $nuevaCantidad - (float)$detalle->cantidad_recibida;

        try {
            Capsule::connection()->beginTransaction();

            if (abs($delta) > 0.001) {
                $loteKey = $detalle->lote ?? 'N/A';

                $inv = \App\Models\Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                    ->where('sucursal_id', $user->sucursal_id)
                    ->where('producto_id', $detalle->producto_id)
                    ->where('ubicacion_id', $detalle->ubicacion_destino_id)
                    ->where('lote', $loteKey)
                    ->where('estado', 'Disponible')
                    ->lockForUpdate()
                    ->first();

                if ($inv) {
                    $inv->cantidad = max(0, ($inv->cantidad ?? 0) + $delta);
                    // Recalcular descomposición UND/TOTAL tras el ajuste
                    $_ajusteUpc      = max(1, (int)($detalle->cajas_por_unidad ?? 1));
                    $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $_ajusteUpc);
                    $inv->saldos         = fmod((float)$inv->cantidad, (float)$_ajusteUpc);
                    $inv->save();
                } elseif ($delta > 0) {
                    $_ajusteUpc  = max(1, (int)($detalle->cajas_por_unidad ?? 1));
                    $_ajusteCajas  = (int)floor((float)$delta / $_ajusteUpc);
                    $_ajusteSaldos = fmod((float)$delta, (float)$_ajusteUpc);
                    $inv = new \App\Models\Inventario([
                        'empresa_id'         => $this->getEffectiveEmpresaId($user, $r),
                        'sucursal_id'        => $user->sucursal_id,
                        'producto_id'        => $detalle->producto_id,
                        'ubicacion_id'       => $detalle->ubicacion_destino_id,
                        'lote'               => $loteKey,
                        'estado'             => 'Disponible',
                        'cantidad'           => $delta,
                        'cantidad_cajas'     => $_ajusteCajas,
                        'saldos'             => $_ajusteSaldos,
                        'cantidad_reservada' => 0,
                        'fecha_vencimiento'  => $detalle->fecha_vencimiento,
                    ]);
                    $inv->save();
                }

                \App\Models\MovimientoInventario::create([
                    'empresa_id'           => $this->getEffectiveEmpresaId($user, $r),
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $detalle->producto_id,
                    'tipo_movimiento'      => $delta >= 0 ? 'AjustePositivo' : 'AjusteNegativo',
                    'referencia_tipo'      => 'SinODC',
                    'referencia_id'        => $recepcion->id,
                    'cantidad'             => (int)round(abs($delta)),
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                    'ubicacion_destino_id' => $detalle->ubicacion_destino_id,
                    'auxiliar_id'          => $user->id,
                    'lote'                 => $loteKey,
                    'observaciones'        => 'Corrección línea recepción ' . $recepcion->numero_recepcion,
                ]);
            }

            $detalle->cantidad_recibida = $nuevaCantidad;
            $detalle->cantidad_cajas    = $nuevasCajas;
            if (array_key_exists('lote', $body))              $detalle->lote              = $body['lote'] ?: null;
            if (array_key_exists('fecha_vencimiento', $body)) $detalle->fecha_vencimiento = $body['fecha_vencimiento'] ?: null;
            if (array_key_exists('estado_mercancia', $body))  $detalle->estado_mercancia  = $body['estado_mercancia'];
            $detalle->save();

            Capsule::connection()->commit();
        } catch (\Exception $e) {
            Capsule::connection()->rollBack();
            return $this->json($res, ['error'=>true,'message'=>'Error actualizando línea: ' . $e->getMessage()], 500);
        }

        $detalle->load('producto');
        return $this->json($res, ['error'=>false,'message'=>'Línea actualizada correctamente','data'=>['detalle'=>$detalle->toArray()]]);
    }

    // ── DELETE /api/recepciones/{id}/detalle/{detalleId} ─────────────────────
    // Elimina una línea de recepción sin ODC y revierte el inventario.
    public function eliminarDetalleSinOdc(Request $r, Response $res, array $a): Response
    {
        $user        = $r->getAttribute('user');
        $recepcionId = (int)($a['id']        ?? 0);
        $detalleId   = (int)($a['detalleId'] ?? 0);

        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereNull('odc_id')
            ->where('estado', 'Borrador')
            ->find($recepcionId);
        if (!$recepcion) {
            return $this->json($res, ['error'=>true,'message'=>'Recepción sin ODC no encontrada o ya cerrada'], 404);
        }

        $rol = strtolower($user->rol ?? '');
        if ($recepcion->auxiliar_id !== $user->id && !in_array($rol, ['admin','supervisor','superadmin'])) {
            return $this->json($res, ['error'=>true,'message'=>'No tiene permiso para modificar esta recepción'], 403);
        }

        $detalle = RecepcionDetalle::where('recepcion_id', $recepcionId)->find($detalleId);
        if (!$detalle) {
            return $this->json($res, ['error'=>true,'message'=>'Línea no encontrada'], 404);
        }

        try {
            Capsule::connection()->beginTransaction();

            $loteKey = $detalle->lote ?? 'N/A';

            // Revertir inventario
            $inv = \App\Models\Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $detalle->producto_id)
                ->where('ubicacion_id', $detalle->ubicacion_destino_id)
                ->where('lote', $loteKey)
                ->where('estado', 'Disponible')
                ->lockForUpdate()
                ->first();

            if ($inv) {
                $newQty = max(0, ($inv->cantidad ?? 0) - (float)$detalle->cantidad_recibida);
                if ($newQty == 0) {
                    $inv->delete();
                } else {
                    $inv->cantidad = $newQty;
                    // Recalcular descomposición UND/TOTAL tras la reversa
                    $_elimUpc        = max(1, (int)($detalle->cajas_por_unidad ?? 1));
                    $inv->cantidad_cajas = (int)floor((float)$newQty / $_elimUpc);
                    $inv->saldos         = fmod((float)$newQty, (float)$_elimUpc);
                    $inv->save();
                }
            }

            // Log de reversa
            \App\Models\MovimientoInventario::create([
                'empresa_id'           => $this->getEffectiveEmpresaId($user, $r),
                'sucursal_id'          => $user->sucursal_id,
                'producto_id'          => $detalle->producto_id,
                'tipo_movimiento'      => 'AjusteNegativo',
                'referencia_tipo'      => 'SinODC',
                'referencia_id'        => $recepcion->id,
                'cantidad'             => (float)$detalle->cantidad_recibida,
                'fecha_movimiento'     => date('Y-m-d'),
                'hora_inicio'          => date('H:i:s'),
                'ubicacion_destino_id' => $detalle->ubicacion_destino_id,
                'auxiliar_id'          => $user->id,
                'lote'                 => $loteKey,
                'observaciones'        => 'Anulación línea recepción ' . $recepcion->numero_recepcion,
            ]);

            $detalle->delete();

            $restantes = RecepcionDetalle::where('recepcion_id', $recepcionId)->count();
            $recepcionEliminada = false;
            if ($restantes === 0) {
                $recepcion->delete();
                $recepcionEliminada = true;
            }

            Capsule::connection()->commit();

            return $this->json($res, [
                'error'   => false,
                'message' => 'Línea eliminada y stock revertido' . ($recepcionEliminada ? '. Recepción vacía eliminada.' : '.'),
                'data'    => ['recepcion_eliminada' => $recepcionEliminada],
            ]);
        } catch (\Exception $e) {
            Capsule::connection()->rollBack();
            return $this->json($res, ['error'=>true,'message'=>'Error eliminando línea: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/recepciones/{id}/confirm
     * Cierra la recepción y afecta el inventario (ledger)
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;

        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->with('detalles')->find($id);
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

            if (!$patio) {
                \Illuminate\Database\Capsule\Manager::connection()->rollBack();
                return $this->json($response, [
                    'error'   => true,
                    'message' => 'No existe ubicación de tipo Patio para esta sucursal. Créela en el módulo de Ubicaciones antes de confirmar recepciones.',
                ], 422);
            }

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
                    $_invKeyConfirm = [
                        'empresa_id'   => $recepcion->empresa_id,
                        'sucursal_id'  => $recepcion->sucursal_id,
                        'producto_id'  => $linea->producto_id,
                        'ubicacion_id' => $ubicacionInventario,
                        'lote'         => $linea->lote,
                        'estado'       => 'Disponible',
                    ];
                    $inv = \App\Models\Inventario::where($_invKeyConfirm)->lockForUpdate()->first();
                    if (!$inv) {
                        $inv = new \App\Models\Inventario($_invKeyConfirm);
                    }
                    $inv->cantidad           = ($inv->cantidad ?? 0) + $linea->cantidad_recibida;
                    $inv->cantidad_reservada = $inv->cantidad_reservada ?? 0;
                    $inv->fecha_vencimiento  = $linea->fecha_vencimiento ?? $inv->fecha_vencimiento;
                    // Recalcular descomposición UND/TOTAL
                    $_confirmUpc         = max(1, (int)($linea->cajas_por_unidad ?? 1));
                    $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $_confirmUpc);
                    $inv->saldos         = fmod((float)$inv->cantidad, (float)$_confirmUpc);
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

        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $detalle = RecepcionDetalle::with('recepcion')
            ->whereHas('recepcion', function($q) use ($empresaId) {
                $q->where('empresa_id', $empresaId);
            })
            ->find($detalleId);
        if (!$detalle) {
            return $this->json($response, ['error' => true, 'message' => 'Detalle no encontrado'], 404);
        }

        $recepcion = $detalle->recepcion;

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
                    // Recalcular descomposición UND/TOTAL
                    $_aprobUpc           = max(1, (int)($detalle->cajas_por_unidad ?? 1));
                    $invDisp->cantidad_cajas = (int)floor((float)$invDisp->cantidad / $_aprobUpc);
                    $invDisp->saldos         = fmod((float)$invDisp->cantidad, (float)$_aprobUpc);
                    $invDisp->save();

                    // Trazabilidad: transición En Patio -> Disponible es un movimiento real de
                    // inventario; debe quedar documentada en el kardex igual que el resto del flujo.
                    \App\Models\MovimientoInventario::create([
                        'empresa_id'           => $recepcion->empresa_id,
                        'sucursal_id'          => $recepcion->sucursal_id,
                        'producto_id'          => $detalle->producto_id,
                        'tipo_movimiento'      => 'Traslado',
                        'cantidad'             => $cantAprobada,
                        'lote'                 => $detalle->lote,
                        'fecha_vencimiento'    => $detalle->fecha_vencimiento,
                        'ubicacion_origen_id'  => $ubicacionInventario,
                        'ubicacion_destino_id' => $ubicacionInventario,
                        'auxiliar_id'          => $user->id,
                        'referencia_tipo'      => 'recepciones',
                        'referencia_id'        => $recepcion->id,
                        'observaciones'        => "Aprobación de pallet: En Patio → Disponible — Recepción {$recepcion->numero_recepcion}",
                        'fecha_movimiento'     => date('Y-m-d'),
                        'hora_inicio'          => date('H:i:s'),
                    ]);
                } else {
                    $_aprobUpc2   = max(1, (int)($detalle->cajas_por_unidad ?? 1));
                    $_aprobCajas2 = (int)floor((float)$detalle->cantidad_recibida / $_aprobUpc2);
                    $_aprobSald2  = fmod((float)$detalle->cantidad_recibida, (float)$_aprobUpc2);
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
                            'cantidad_cajas'     => $_aprobCajas2,
                            'saldos'             => $_aprobSald2,
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

        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        // Buscar el detalle y su recepcion filtrando por empresa
        $det = RecepcionDetalle::whereHas('recepcion', function($q) use ($empresaId) {
            $q->where('empresa_id', $empresaId);
        })->find($detId);
        if (!$det) {
            return $this->json($response, ['error' => true, 'message' => 'Pallet no encontrado'], 404);
        }

        $recepcion = $det->recepcion;
        if (!$recepcion) {
            return $this->json($response, ['error' => true, 'message' => 'No autorizado'], 403);
        }

        $body = (array)($request->getParsedBody() ?? []);
        $qty  = isset($body['cantidad_recibida']) ? (float)$body['cantidad_recibida'] : null;

        if ($qty === null || $qty < 0) {
            return $this->json($response, ['error' => true, 'message' => 'Cantidad inválida'], 422);
        }

        $oldQty     = (float)$det->cantidad_recibida;
        $oldUbicId  = $det->ubicacion_destino_id;

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

        try {
            \Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

            $det->save();

            // Keep inventory in sync when the pallet was already approved
            if ($det->aprobado_admin && $qty != $oldQty && $det->producto_id) {
                $diff    = $qty - $oldQty;
                $ubicId  = $det->ubicacion_destino_id ?? $oldUbicId;
                $inv = \App\Models\Inventario::where('empresa_id',  $recepcion->empresa_id)
                    ->where('sucursal_id',  $recepcion->sucursal_id)
                    ->where('producto_id', $det->producto_id)
                    ->where('ubicacion_id', $ubicId)
                    ->lockForUpdate()
                    ->first();
                if ($inv) {
                    $inv->cantidad = max(0, (float)$inv->cantidad + $diff);
                    if ($inv->cantidad <= 0) {
                        $inv->delete();
                    } else {
                        // Recalcular descomposición UND/TOTAL
                        $_actDetUpc      = max(1, (int)($det->cajas_por_unidad ?? 1));
                        $inv->cantidad_cajas = (int)floor((float)$inv->cantidad / $_actDetUpc);
                        $inv->saldos         = fmod((float)$inv->cantidad, (float)$_actDetUpc);
                        $inv->save();
                    }

                    // Trazabilidad: la corrección de cantidad sobre un pallet ya aprobado
                    // mueve stock real; debe quedar en el kardex igual que cualquier otro ajuste.
                    \App\Models\MovimientoInventario::create([
                        'empresa_id'       => $recepcion->empresa_id,
                        'sucursal_id'      => $recepcion->sucursal_id,
                        'producto_id'      => $det->producto_id,
                        'tipo_movimiento'  => $diff >= 0 ? 'AjustePositivo' : 'AjusteNegativo',
                        'cantidad'         => abs($diff),
                        'lote'             => $det->lote,
                        'ubicacion_destino_id' => $ubicId,
                        'auxiliar_id'      => $user->id,
                        'referencia_tipo'  => 'recepciones',
                        'referencia_id'    => $recepcion->id,
                        'observaciones'    => "Corrección administrativa de cantidad — Recepción {$recepcion->numero_recepcion}",
                        'fecha_movimiento' => date('Y-m-d'),
                        'hora_inicio'      => date('H:i:s'),
                    ]);
                }
            }

            \Illuminate\Database\Capsule\Manager::connection()->commit();
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::connection()->rollBack();
            return $this->json($response, ['error' => true, 'message' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }

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

        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        $det = RecepcionDetalle::whereHas('recepcion', function($q) use ($empresaId) {
            $q->where('empresa_id', $empresaId);
        })->find($detId);
        if (!$det) {
            return $this->json($response, ['error' => true, 'message' => 'Pallet no encontrado'], 404);
        }

        $recepcion = $det->recepcion;
        if (!$recepcion) {
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

        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->with('detalles')->find($id);
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
     * GET /api/recepcion/kpis — resumen rápido para la pantalla de inicio del módulo
     */
    public function kpis(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $hoy  = date('Y-m-d');

        $recHoy = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereDate('created_at', $hoy)
            ->count();

        $pendientes = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Borrador', 'EnProceso'])
            ->count();

        $citasHoy = Capsule::table('citas')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereDate('fecha_cita', $hoy)
            ->count();

        $palletsPatio = Capsule::table('lotes')
            ->join('ubicaciones', 'ubicaciones.id', '=', 'lotes.ubicacion_id')
            ->where('lotes.empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('lotes.sucursal_id', $user->sucursal_id)
            ->where('ubicaciones.tipo_ubicacion', 'patio')
            ->where('lotes.cantidad_actual', '>', 0)
            ->count();

        return $this->ok($res, [
            'rec_hoy'      => $recHoy,
            'pendientes'   => $pendientes,
            'citas_hoy'    => $citasHoy,
            'pallets_patio'=> $palletsPatio,
        ]);
    }

    /**
     * GET /api/recepcion/dashboard
     */
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        // 1. Recepciones activas (Borrador o EnProceso)
        $activasQuery = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
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

            $esSinOdc = is_null($rec->odc_id);
            return [
                'id'               => $rec->id,
                'numero_recepcion' => $rec->numero_recepcion,
                'estado'           => $rec->estado,
                'es_sin_odc'       => $esSinOdc,
                'proveedor'        => $esSinOdc ? 'Sin ODC' : ($rec->cita->proveedor ?? 'Manual/Directo'),
                'auxiliar'         => $rec->auxiliar->nombre ?? 'N/A',
                'lineas_count'     => $rec->detalles_count,
                'progreso'         => $porcentaje,
                'inicio'           => $rec->hora_inicio,
                'created_at'       => $rec->created_at ? $rec->created_at->toDateTimeString() : null,
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
            ->where('r.empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('r.estado', 'Cerrada')
            ->where('r.sucursal_id', $user->sucursal_id)
            ->groupBy('p.id', 'p.nombre')
            ->get();

        // Tendencia diaria (últimos 7 días)
        $tendencia = Capsule::table('recepciones')
            ->select(Capsule::raw('DATE(created_at) as fecha'), Capsule::raw('COUNT(id) as total'))
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $user->sucursal_id)
            ->whereDate('created_at', '>=', $sieteDiasAtras)
            ->groupBy('fecha')
            ->orderBy('fecha', 'asc')
            ->get();

        try {
            // Promedio de tiempo por línea global (hoy)
            $totalHorasEjecutadas = 0;
            $lineasTotales = 0;
            $totalCerradasHoy = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
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
                ->whereExists(function($q) use ($user, $r) {
                    $q->select(Capsule::raw(1))
                      ->from('recepciones as rec')
                      ->whereColumn('rec.id', 'rd.recepcion_id')
                      ->where('rec.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                      ->where('rec.sucursal_id', $user->sucursal_id);
                })
                ->groupBy('cat.id', 'cat.nombre')
                ->orderBy('total', 'desc')
                ->get();

            // Sin-ODC specific stats
            $sinOdcActivas = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->whereNull('odc_id')
                ->where('estado', 'Borrador')
                ->with('auxiliar:id,nombre')
                ->withCount('detalles')
                ->get()
                ->map(fn($rec) => [
                    'id'               => $rec->id,
                    'numero_recepcion' => $rec->numero_recepcion,
                    'auxiliar'         => $rec->auxiliar->nombre ?? 'N/A',
                    'lineas_count'     => $rec->detalles_count,
                    'hora_inicio'      => $rec->hora_inicio,
                    'fecha_movimiento' => $rec->fecha_movimiento,
                ]);
            $sinOdcCerradasHoy = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('sucursal_id', $user->sucursal_id)
                ->whereNull('odc_id')
                ->where('estado', 'Cerrada')
                ->whereDate('fecha_movimiento', $hoy)
                ->count();
            $sinOdcLineasHoy = (int) Capsule::table('recepcion_detalles as rd')
                ->join('recepciones as r', 'r.id', '=', 'rd.recepcion_id')
                ->where('r.empresa_id', $this->getEffectiveEmpresaId($user, $r))
                ->where('r.sucursal_id', $user->sucursal_id)
                ->whereNull('r.odc_id')
                ->whereDate('r.fecha_movimiento', $hoy)
                ->sum('rd.cantidad_recibida');

            return $this->ok($res, [
                'activas'        => $activasData,
                'tendencia'      => $tendencia,
                'eficiencia'     => $eficiencia,
                'categorias_stats' => $categorias_stats,
                'sin_odc'        => [
                    'activas'       => $sinOdcActivas,
                    'cerradas_hoy'  => $sinOdcCerradasHoy,
                    'lineas_hoy'    => $sinOdcLineasHoy,
                ],
                'pwa_stats'      => [
                    'recepciones_hoy' => $totalCerradasHoy->count(),
                    'odc_pendientes'  => OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))->whereIn('estado', ['Confirmada', 'En Proceso'])->count(),
                    'total_horas'     => $totalHorasEjecutadas . "h",
                    'total_lineas'    => $lineasTotales,
                    'promedio_tiempo' => $lineasTotales > 0 ? round(($totalHorasEjecutadas * 60) / $lineasTotales, 1) . "m" : "0m",
                ],
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

        $recepcion = Recepcion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
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

        $odc = OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
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
            $query = OrdenCompra::where('ordenes_compra.empresa_id', $this->getEffectiveEmpresaId($user, $request))
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

            // Normalizar proveedor: el frontend espera proveedor.nombre pero la BD guarda razon_social
            $odcs->each(function($odc) {
                if ($odc->proveedor) {
                    $odc->proveedor->nombre = $odc->proveedor->razon_social;
                }
            });

            $kpis = $this->calculateKpis($odcs);
            $kpis['total_odcs'] = $odcs->count();
            $kpis['total_lineas'] = $odcs->sum(fn($odc) => $odc->detalles->count());

            $hoy = Carbon::now();
            $fechaLimite = Carbon::now()->addDays(60);

            $inventarios = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
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
            $ranking = $this->getAuxiliarRanking($this->getEffectiveEmpresaId($user, $request), $user->sucursal_id, $params);

            $filters = $this->getAvailableFilters($this->getEffectiveEmpresaId($user, $request), $user->sucursal_id);

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

        $auxiliares = $query->get(['id', 'nombre'])->map(function($aux) use ($empresaId, $sucursalId) {
            // Conteo de recepciones en proceso
            $aux->recepciones_count = Capsule::table('recepciones')
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('auxiliar_id', $aux->id)
                ->where('estado', 'En Proceso')
                ->count();

            // Suma de unidades recibidas (solo de recepciones en proceso)
            $aux->total_unidades_recibidas = Capsule::table('recepcion_detalles as rd')
                ->join('recepciones as r', 'r.id', '=', 'rd.recepcion_id')
                ->where('r.empresa_id', $empresaId)
                ->where('r.sucursal_id', $sucursalId)
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

        if (!$linea || $linea->ordenCompra->empresa_id !== $this->getEffectiveEmpresaId($user, $request)) {
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
                $invPatio = Inventario::where('empresa_id', $linea->ordenCompra->empresa_id)
                    ->where('sucursal_id', $user->sucursal_id)
                    ->where('producto_id', $rd->producto_id)
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
        if (!$odc || $odc->empresa_id !== $this->getEffectiveEmpresaId($user, $request)) {
            return $this->error($response, 'ODC no encontrada.', 404);
        }
        if ($odc->estado !== 'En Proceso') {
            return $this->error($response, 'Solo se pueden agregar líneas a ODC en proceso.', 400);
        }

        $producto = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($data['producto_id']);
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
        if (!$linea || $linea->ordenCompra->empresa_id !== $this->getEffectiveEmpresaId($user, $request)) {
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
        if (!$linea || $linea->ordenCompra->empresa_id !== $this->getEffectiveEmpresaId($user, $request)) {
            return $this->error($response, 'Línea no encontrada.', 404);
        }

        if ($linea->cantidad_recibida > 0) {
            return $this->error($response, 'No se puede eliminar una línea que ya tiene cantidad recibida.', 400);
        }

        $linea->delete();

        return $this->ok($response, null, 'Línea de ODC eliminada.');
    }

}
