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
                    'p.codigo',
                    'p.nombre as producto_nombre',
                    'p.unidad_medida',
                    'i.lote',
                    'i.fecha_vencimiento',
                    'i.cantidad',
                    'i.ubicacion_id',
                    'u.nombre as ubicacion',
                    'i.updated_at',
                ]);

            // Optional: filter by product code (already parameterized by QueryBuilder)
            if (!empty($params['codigo'])) {
                $query->where('p.codigo', 'like', '%' . $params['codigo'] . '%');
            }

            $total  = $query->count();
            $items  = $query->orderBy('p.codigo')
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
            ->leftJoin('personal as p', 'p.id', '=', 'op.operador_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'op.cliente_id')
            ->where('op.empresa_id', $empresaId)
            ->where('op.estado', $estado)
            ->select([
                'op.id',
                'op.numero_orden',
                'op.cliente_id',
                'c.nombre as cliente_nombre',
                'op.estado',
                'op.prioridad',
                'op.fecha_requerida',
                'op.created_at',
                'op.operador_id',
                DB::raw("CONCAT(p.nombres, ' ', p.apellidos) as operador"),
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
            ->leftJoin('clientes as c', 'c.id', '=', 'd.cliente_id')
            ->leftJoin('personal as p', 'p.id', '=', 'd.operador_id')
            ->where('d.empresa_id', $empresaId)
            ->whereBetween('d.created_at', [$inicio, $fin])
            ->select([
                'd.id',
                'd.numero_despacho',
                'd.estado',
                'd.cliente_id',
                'c.nombre as cliente_nombre',
                'c.direccion as cliente_direccion',
                'd.operador_id',
                DB::raw("CONCAT(p.nombres, ' ', p.apellidos) as operador"),
                'd.observaciones',
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
            'tabla'       => 'despachos',
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

            // Process known events
            switch ($evento) {
                case 'ENTREGA_CONFIRMADA':
                    $this->_procesarEntregaConfirmada($empresaId, $payload);
                    break;
                case 'DEVOLUCION_TMS':
                    $this->_procesarDevolucionTms($empresaId, $payload);
                    break;
            }

            return $this->tmsOk($response, ['evento' => $evento, 'recibido' => true]);
        } catch (\Exception $e) {
            error_log('TmsController::webhook error: ' . $e->getMessage());
            return $this->error($response, 'Error al procesar webhook.', 500);
        }
    }

    // ── API Key management ────────────────────────────────────────────────────

    public function listKeys(Request $request, Response $response): Response
    {
        $empresaId = $request->getAttribute('empresa_id');

        $keys = DB::table('api_keys')
            ->where('empresa_id', $empresaId)
            ->select(['id', 'nombre', 'permisos', 'activo', 'last_used_at', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        return $this->tmsOk($response, $keys);
    }

    public function createKey(Request $request, Response $response): Response
    {
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

    private function _procesarEntregaConfirmada(int $empresaId, array $payload): void
    {
        $despachoId = (int)($payload['despacho_id'] ?? 0);
        if (!$despachoId) return;

        DB::table('despachos')
            ->where('id', $despachoId)
            ->where('empresa_id', $empresaId)
            ->update([
                'estado'        => 'Entregado',
                'tms_entregado_at' => $payload['fecha'] ?? date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
    }

    private function _procesarDevolucionTms(int $empresaId, array $payload): void
    {
        // Placeholder: insert a pre-registered devolucion record
        // Full logic would mirror DevolucionController::store
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
