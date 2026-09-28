<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * TmsController — Integration endpoints for the TMS (Transportation Management System).
 * All routes are protected by ApiKeyMiddleware (X-API-Key header).
 *
 * Response envelope:
 *   { "ok": true, "data": [...], "meta": { "empresa_id": N, "ts": "..." } }
 */
class TmsController extends BaseController
{
    // ── Stock snapshot ────────────────────────────────────────────────────────

    public function stock(Request $request, Response $response): Response
    {
        $empresaId = $request->getAttribute('empresa_id');
        $params    = $request->getQueryParams();
        $page      = max(1, (int)($params['page'] ?? 1));
        $perPage   = min(500, max(10, (int)($params['per_page'] ?? 100)));

        try {
            $query = DB::table('inventarios as i')
                ->join('productos as p', 'p.id', '=', 'i.producto_id')
                ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                ->where('i.empresa_id', $empresaId)
                ->where('i.cantidad', '>', 0)
                ->select([
                    'i.id',
                    'i.producto_id',
                    'p.codigo_interno',
                    'p.nombre as producto_nombre',
                    'p.unidad_medida',
                    'i.lote',
                    'i.fecha_vencimiento',
                    'i.cantidad',
                    'i.ubicacion_id',
                    'u.codigo as ubicacion',
                    'i.updated_at',
                ]);

            // Optional: filter by product code (already parameterized by QueryBuilder)
            if (!empty($params['codigo'])) {
                $query->where('p.codigo_interno', 'like', '%' . $params['codigo'] . '%');
            }

            $total  = $query->count();
            $items  = $query->orderBy('p.codigo_interno')
                            ->offset(($page - 1) * $perPage)
                            ->limit($perPage)
                            ->get()
                            ->toArray();

            return $this->tmsOk($response, $items, [
                'empresa_id' => $empresaId,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'pages'      => (int)ceil($total / $perPage),
            ]);
        } catch (\Exception $e) {
            error_log('TmsController::stock error: ' . $e->getMessage());
            return $this->error($response, 'Error al obtener stock.', 500);
        }
    }

    // ── Active outbound orders for TMS ────────────────────────────────────────

    public function ordenes(Request $request, Response $response): Response
    {
        $empresaId = $request->getAttribute('empresa_id');
        $params    = $request->getQueryParams();
        $estado    = $params['estado'] ?? 'En proceso';

        try {
        $ordenes = DB::table('orden_pickings as op')
            ->leftJoin('personal as p', 'p.id', '=', 'op.auxiliar_id')
            ->where('op.empresa_id', $empresaId)
            ->where('op.estado', $estado)
            ->select([
                'op.id',
                'op.numero_orden',
                'op.cliente as cliente_nombre',
                'op.estado',
                'op.prioridad',
                'op.fecha_requerida',
                'op.created_at',
                'op.auxiliar_id',
                'p.nombre as operador',
            ])
            ->orderBy('op.prioridad', 'desc')
            ->orderBy('op.created_at', 'asc')
            ->get()
            ->toArray();

        return $this->tmsOk($response, $ordenes, ['empresa_id' => $empresaId]);
        } catch (\Exception $e) {
            error_log('TmsController::ordenes error: ' . $e->getMessage());
            return $this->error($response, 'Error al obtener órdenes.', 500);
        }
    }

    // ── Dispatched shipments ──────────────────────────────────────────────────

    public function despachos(Request $request, Response $response): Response
    {
        $empresaId = $request->getAttribute('empresa_id');
        $params    = $request->getQueryParams();

        try {
        [$inicio, $fin] = $this->getDateRange($params);

        $despachos = DB::table('despachos as d')
            ->leftJoin('personal as p', 'p.id', '=', 'd.auxiliar_id')
            ->where('d.empresa_id', $empresaId)
            ->whereBetween('d.created_at', [$inicio, $fin])
            ->select([
                'd.id',
                'd.numero_despacho',
                'd.estado',
                'd.cliente as cliente_nombre',
                'd.auxiliar_id',
                'p.nombre as operador',
                'd.created_at',
                'd.updated_at',
            ])
            ->orderBy('d.created_at', 'desc')
            ->get()
            ->toArray();

        return $this->tmsOk($response, $despachos, [
            'empresa_id'   => $empresaId,
            'fecha_inicio' => $inicio,
            'fecha_fin'    => $fin,
        ]);
        } catch (\Exception $e) {
            error_log('TmsController::despachos error: ' . $e->getMessage());
            return $this->error($response, 'Error al obtener despachos.', 500);
        }
    }

    // ── TMS marks a despacho as En Tránsito ───────────────────────────────────

    public function marcarEnTransito(Request $request, Response $response, array $args): Response
    {
        $empresaId = $request->getAttribute('empresa_id');
        $id        = (int)($args['id'] ?? 0);
        $body      = (array)($request->getParsedBody() ?? []);

        $despacho = DB::table('despachos')
            ->where('id', $id)
            ->where('empresa_id', $empresaId)
            ->first();

        if (!$despacho) {
            return $this->error($response, 'Despacho no encontrado.', 404);
        }

        if ($despacho->estado !== 'Cerrado') {
            return $this->error($response, 'Solo se pueden marcar en tránsito los despachos cerrados.');
        }

        DB::table('despachos')
            ->where('id', $id)
            ->update([
                'estado'             => 'En Tránsito',
                'tms_tracking_code'  => $body['tracking_code'] ?? null,
                'tms_transportista'  => $body['transportista'] ?? null,
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

        // Log the event
        DB::table('audit_logs')->insert([
            'empresa_id'  => $empresaId,
            'usuario_id'  => null,
            'modulo'      => 'TMS',
            'accion'      => 'DESPACHO_EN_TRANSITO',
            'tabla_afectada'       => 'despachos',
            'registro_id' => $id,
            'descripcion' => 'TMS marcó despacho como En Tránsito. Tracking: ' . ($body['tracking_code'] ?? 'N/A'),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->tmsOk($response, ['id' => $id, 'estado' => 'En Tránsito']);
    }

    // ── TMS webhook receiver ──────────────────────────────────────────────────

    public function webhook(Request $request, Response $response): Response
    {
        $empresaId = $request->getAttribute('empresa_id');
        $body      = (array)($request->getParsedBody() ?? []);

        $evento  = strip_tags(trim($body['evento'] ?? ''));
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

        if (empty($evento)) {
            return $this->error($response, 'Campo "evento" requerido.');
        }
        if (strlen($evento) > 50) {
            return $this->error($response, 'Campo "evento" inválido.');
        }

        try {
            // Log every incoming webhook
            DB::table('tms_webhooks')->insert([
                'empresa_id' => $empresaId,
                'evento'     => $evento,
                'payload'    => json_encode($payload),
                'procesado'  => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Process known events. Cada uno puede devolver datos extra para
            // el llamador (p.ej. el consecutivo real de la devolución creada) —
            // sin esto, DEVOLUCION_TMS respondía "recibido:true" incluso cuando
            // la validación interna rechazaba la devolución, dejando al YMS sin
            // forma de saber que en realidad no se creó nada.
            $extra = [];
            switch ($evento) {
                case 'ENTREGA_CONFIRMADA':
                    $this->_procesarEntregaConfirmada($empresaId, $payload);
                    break;
                case 'DEVOLUCION_TMS':
                    $resultado = $this->_procesarDevolucionTms($empresaId, $payload);
                    if (!$resultado['ok']) {
                        return $this->error($response, $resultado['message'], 422);
                    }
                    $extra = $resultado['data'];
                    break;
            }

            return $this->tmsOk($response, array_merge(['evento' => $evento, 'recibido' => true], $extra));
        } catch (\Exception $e) {
            error_log('TmsController::webhook error: ' . $e->getMessage());
            return $this->error($response, 'Error al procesar webhook.', 500);
        }
    }

    // ── API Key management ────────────────────────────────────────────────────

    // Gestión de API keys: solo un usuario JWT con rol Admin/SuperAdmin puede
    // crear/listar/revocar — antes cualquier JWT de empleado activo, o el simple
    // poseedor de una API key filtrada, podía administrar las keys de la empresa.
    private function _requireAdminForKeys(Request $request, Response $response): ?Response
    {
        $user = $request->getAttribute('user');
        if ($request->getAttribute('auth_type') !== 'jwt' || !$this->isAdmin($user)) {
            return $this->forbidden($response, 'Se requiere sesión JWT con rol Admin o SuperAdmin para gestionar API keys.');
        }
        return null;
    }

    public function listKeys(Request $request, Response $response): Response
    {
        if ($deny = $this->_requireAdminForKeys($request, $response)) return $deny;
        $empresaId = $request->getAttribute('empresa_id');

        $keys = DB::table('api_keys')
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->select(['id', 'nombre', 'key_hash', 'permisos', 'activo', 'last_used_at', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        return $this->tmsOk($response, $keys);
    }

    public function createKey(Request $request, Response $response): Response
    {
        if ($deny = $this->_requireAdminForKeys($request, $response)) return $deny;
        $empresaId = $request->getAttribute('empresa_id');
        $body      = (array)($request->getParsedBody() ?? []);

        if (empty($body['nombre'])) {
            return $this->error($response, 'Campo "nombre" requerido.');
        }

        // Generate a cryptographically secure random key
        $plainKey = 'wms_' . bin2hex(random_bytes(24));
        $keyHash  = hash('sha256', $plainKey);

        $id = DB::table('api_keys')->insertGetId([
            'empresa_id'  => $empresaId,
            'nombre'      => htmlspecialchars(strip_tags($body['nombre']), ENT_QUOTES, 'UTF-8'),
            'key_hash'    => $keyHash,
            'permisos'    => json_encode($body['permisos'] ?? ['read']),
            'activo'      => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Return plain key ONCE — it won't be shown again
        return $this->tmsOk($response, [
            'id'          => $id,
            'nombre'      => $body['nombre'],
            'api_key'     => $plainKey,
            'advertencia' => 'Guarda esta clave. No se volverá a mostrar.',
        ]);
    }

    public function revokeKey(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->_requireAdminForKeys($request, $response)) return $deny;
        $empresaId = $request->getAttribute('empresa_id');
        $id        = (int)($args['id'] ?? 0);

        $updated = DB::table('api_keys')
            ->where('id', $id)
            ->where('empresa_id', $empresaId)
            ->update(['activo' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

        if (!$updated) {
            return $this->error($response, 'API key no encontrada.', 404);
        }

        return $this->tmsOk($response, ['id' => $id, 'revocada' => true]);
    }

    // ── Private event processors ──────────────────────────────────────────────

    // Entrega confirmada en el punto de venta (YMS). Desde la v2 del YMS
    // (2026-09-27, visita a sucursal) esto se dispara UNA VEZ POR VISITA, no
    // por pedido — una parada puede cubrir varios pedidos de la misma
    // sucursal el mismo día. El payload trae 'orden_picking_ids' (array); por
    // compatibilidad hacia atrás también acepta el 'orden_picking_id' (singular)
    // que mandaba la v1. Se inserta una fila de entregas_ruta POR pedido,
    // duplicando los datos de la visita (firma/fotos/tiempos son los mismos
    // para todos los pedidos de esa parada) — más simple que espejar una
    // tabla de "visitas" aparte en el WMS solo para esto.
    private function _procesarEntregaConfirmada(int $empresaId, array $payload): void
    {
        $despachoId = (int)($payload['despacho_id'] ?? 0);
        $fecha      = $payload['fecha'] ?? date('Y-m-d H:i:s');

        $ordenIds = $payload['orden_picking_ids'] ?? null;
        if (!is_array($ordenIds) || empty($ordenIds)) {
            $unico = (int)($payload['orden_picking_id'] ?? 0);
            $ordenIds = $unico ? [$unico] : [];
        }

        if ($despachoId) {
            DB::table('despachos')
                ->where('id', $despachoId)
                ->where('empresa_id', $empresaId)
                ->update([
                    'estado'            => 'Entregado',
                    'tms_entregado_at'  => $fecha,
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);
        }

        $fotos = is_array($payload['fotos'] ?? null) ? json_encode(array_values($payload['fotos'])) : null;
        $geo   = is_array($payload['tracking_geo'] ?? null) ? json_encode($payload['tracking_geo']) : null;

        foreach (array_map('intval', $ordenIds) as $ordenPickingId) {
            if (!$ordenPickingId) continue;

            $orden = DB::table('orden_pickings')
                ->where('id', $ordenPickingId)
                ->where('empresa_id', $empresaId)
                ->first();
            if (!$orden) continue;

            // Mismo criterio de "pedido entregado" que usa DespachoController::liquidar()
            // — se reusa aquí en vez de duplicar la transición de estado.
            DB::table('orden_pickings')
                ->where('id', $ordenPickingId)
                ->update(['estado_despacho' => 'Entregado']);

            DB::table('entregas_ruta')->insert([
                'empresa_id'                => $empresaId,
                'orden_picking_id'          => $ordenPickingId,
                'despacho_id'               => $despachoId ?: null,
                'hora_llegada'              => $payload['hora_llegada'] ?? null,
                'hora_inicio_certificacion' => $payload['hora_inicio'] ?? $payload['hora_inicio_certificacion'] ?? null,
                'hora_descargue'            => $payload['hora_descargue'] ?? null,
                'hora_fin'                  => $payload['hora_fin'] ?? $fecha,
                'hora_salida'               => $payload['hora_salida'] ?? null,
                'tracking_geo'              => $geo,
                'tiempo_demora_minutos'     => $payload['tiempo_demora_minutos'] ?? null,
                'tipo_entrega'              => $payload['tipo_entrega'] ?? null,
                'tiene_novedad'             => !empty($payload['tiene_novedad']),
                'total_novedades'           => $payload['total_novedades'] ?? 0,
                'observaciones'             => $payload['observaciones'] ?? null,
                'nombre_recibe'             => $payload['nombre_recibe'] ?? null,
                'fotos'                     => $fotos,
                'firma'                     => $payload['firma'] ?? null,
                'auxiliar_nombre_ruta'      => $payload['auxiliar_nombre'] ?? null,
                'created_at'                => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Devolución registrada en el punto de venta (YMS). Crea la devolución con
    // la MISMA numeración/consecutivo real que el módulo de Devoluciones del
    // WMS ya usa — nunca un consecutivo inventado por el YMS — reusando
    // DevolucionController::crearDesdeIntegracion() en vez de duplicar la
    // lógica de creación. Queda 'Pendiente' para el mismo triage de calidad
    // (procesar()) que ya usan las devoluciones manuales; no se auto-aprueba.
    private function _procesarDevolucionTms(int $empresaId, array $payload): array
    {
        $sucursalId = (int)($payload['sucursal_id'] ?? 0);
        if (!$sucursalId) {
            $sucursalId = (int)(DB::table('orden_pickings')
                ->where('id', (int)($payload['orden_picking_id'] ?? 0))
                ->value('sucursal_id') ?? 0);
        }
        if (!$sucursalId) {
            return ['ok' => false, 'message' => 'No se pudo determinar la sucursal (sucursal_id u orden_picking_id inválidos).'];
        }

        // El auxiliar de ruta es un usuario del YMS, no necesariamente tiene
        // cuenta en `personal` del WMS — si el payload trae un auxiliar_id
        // válido se usa (trazabilidad real), si no, se cae a cualquier Admin
        // activo de la empresa como responsable técnico del registro; el
        // nombre real del auxiliar queda igual en motivo_general.
        $auxiliarId = (int)($payload['auxiliar_id'] ?? 0);
        $auxiliarValido = $auxiliarId && DB::table('personal')
            ->where('id', $auxiliarId)->where('empresa_id', $empresaId)->where('activo', 1)->exists();
        if (!$auxiliarValido) {
            $auxiliarId = (int)(DB::table('personal')
                ->where('empresa_id', $empresaId)->where('rol', 'Admin')->where('activo', 1)
                ->orderBy('id')->value('id') ?? 0);
        }
        if (!$auxiliarId) {
            return ['ok' => false, 'message' => 'No hay ningún usuario Admin activo en el WMS para asignar como responsable técnico.'];
        }

        $auxNombre = trim((string)($payload['auxiliar_nombre'] ?? ''));
        $motivo    = 'Devolución en punto de venta (YMS)'
            . ($auxNombre !== '' ? " — reportada por {$auxNombre}" : '');

        $detalles = is_array($payload['detalles'] ?? null) ? $payload['detalles'] : [];

        try {
            $devCtrl = new DevolucionController();
            [$devId, $numero, $consecutivo] = $devCtrl->crearDesdeIntegracion(
                $empresaId,
                $sucursalId,
                $auxiliarId,
                'cliente',
                $motivo,
                $detalles,
                !empty($payload['causal_devolucion_id']) ? (int)$payload['causal_devolucion_id'] : null,
                $payload['orden_picking_id'] ?? null
            );
            DB::table('audit_logs')->insert([
                'empresa_id'  => $empresaId,
                'usuario_id'  => null,
                'modulo'      => 'TMS',
                'accion'      => 'DEVOLUCION_YMS',
                'tabla_afectada'       => 'devoluciones',
                'registro_id' => $devId,
                'descripcion' => "Devolución #{$consecutivo} ({$numero}) creada desde YMS.",
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'data' => ['devolucion_id' => $devId, 'numero' => $numero, 'consecutivo' => $consecutivo]];
        } catch (\InvalidArgumentException $e) {
            error_log('TmsController::_procesarDevolucionTms rechazada: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ── TMS response envelope ─────────────────────────────────────────────────

    private function tmsOk(Response $response, $data, array $meta = []): Response
    {
        return $this->json($response, [
            'ok'   => true,
            'data' => $data,
            'meta' => array_merge(['ts' => date('Y-m-d H:i:s')], $meta),
        ]);
    }
}
