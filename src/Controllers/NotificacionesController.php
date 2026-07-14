<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class NotificacionesController extends BaseController
{
    /**
     * GET /api/notificaciones
     * Lista notificaciones del usuario autenticado con paginación
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $soloNoLeidas = ($params['no_leidas'] ?? 'false') === 'true';
        $pagina = max(1, (int)($params['pagina'] ?? 1));
        $perPage = min(50, max(10, (int)($params['por_pagina'] ?? 20)));

        $query = DB::table('notificaciones')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($soloNoLeidas) {
            $query->where('leida', false);
        }

        $total = $query->count();
        $items = $query->offset(($pagina - 1) * $perPage)->limit($perPage)->get();

        // Badge count (total no leídas)
        $badge = DB::table('notificaciones')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->where('leida', false)
            ->count();

        return $this->json($response, [
            'error' => false,
            'data' => $items,
            'badge' => $badge,
            'paginacion' => [
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $perPage,
                'total_paginas' => (int)ceil($total / $perPage),
            ]
        ]);
    }

    /**
     * GET /api/notificaciones/badge
     * Solo retorna el contador de no leídas (polling ultra-liviano)
     */
    public function badge(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        $count = DB::table('notificaciones')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->where('leida', false)
            ->count();

        $pendientes = DB::table('notificaciones')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->where('leida', false)
            ->where('completada', false)
            ->whereIn('tipo', ['tarea', 'picking', 'inventario'])
            ->select('id', 'tipo', 'titulo', 'mensaje', 'modulo', 'referencia_tipo', 'referencia_id', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Limpieza periódica: ~5% de las llamadas purgan notificaciones leídas > 90 días
        if (rand(1, 20) === 1) {
            $this->_purgarLeidas($this->getEffectiveEmpresaId($user, $request));
        }

        return $this->json($response, [
            'error' => false,
            'badge' => $count,
            'pendientes' => $pendientes
        ]);
    }

    /**
     * PUT /api/notificaciones/{id}/leer
     */
    public function marcarLeida(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = (int)$args['id'];

        $rows = DB::table('notificaciones')
            ->where('id', $id)
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->update(['leida' => true, 'leida_en' => date('Y-m-d H:i:s')]);

        return $this->json($response, ['error' => false, 'updated' => $rows > 0]);
    }

    /**
     * PUT /api/notificaciones/leer-todas
     */
    public function marcarTodasLeidas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        DB::table('notificaciones')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->where('leida', false)
            ->update(['leida' => true, 'leida_en' => date('Y-m-d H:i:s')]);

        return $this->json($response, ['error' => false, 'message' => 'Todas marcadas como leídas']);
    }

    /**
     * PUT /api/notificaciones/{id}/completar
     */
    public function marcarCompletada(Request $request, Response $response, array $args): Response
    {
        $user     = $request->getAttribute('user');
        $id       = (int)$args['id'];
        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        $notif = DB::table('notificaciones')
            ->where('id', $id)
            ->where('empresa_id', $empresaId)
            ->where('personal_id', $user->id)
            ->first();

        if (!$notif) {
            return $this->json($response, ['error' => true, 'message' => 'Notificación no encontrada'], 404);
        }

        // Evitar re-completar (previene loops de notificaciones)
        if ($notif->completada) {
            return $this->json($response, ['error' => false, 'updated' => false, 'message' => 'Ya estaba completada']);
        }

        DB::table('notificaciones')
            ->where('id', $id)
            ->update([
                'leida'      => true,
                'completada' => true,
                'leida_en'   => date('Y-m-d H:i:s')
            ]);

        // Solo cascada para tareas (no para info/picking/inventario — evita loops)
        if ($notif->tipo === 'tarea') {
            try {
                $admins = DB::table('personal')
                    ->where('empresa_id', $empresaId)
                    ->whereIn('rol', ['Admin', 'Supervisor', 'SuperAdmin'])
                    ->where('activo', true)
                    ->where('id', '!=', $user->id) // no notificar al mismo ejecutor
                    ->orderBy('id')
                    ->limit(5)                      // máximo 5 supervisores
                    ->pluck('id');

                $now = date('Y-m-d H:i:s');
                $rows = [];
                foreach ($admins as $adminId) {
                    $rows[] = [
                        'empresa_id'      => $empresaId,
                        'sucursal_id'     => $notif->sucursal_id ?? $user->sucursal_id,
                        'personal_id'     => $adminId,
                        'emisor_id'       => $user->id,
                        'tipo'            => 'info',
                        'titulo'          => 'Tarea completada: ' . mb_substr($notif->titulo, 0, 150),
                        'mensaje'         => ($user->nombre ?? 'Auxiliar') . ' completó: ' . mb_substr($notif->titulo, 0, 120),
                        'modulo'          => $notif->modulo,
                        'referencia_tipo' => $notif->referencia_tipo,
                        'referencia_id'   => $notif->referencia_id,
                        'link_accion'     => $notif->link_accion ?? null,
                        'sonido'          => 0, // sin sonido para info-cascada
                        'leida'           => 0,
                        'completada'      => 0,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }
                if ($rows) DB::table('notificaciones')->insert($rows);
            } catch (\Throwable $e) {
                error_log('marcarCompletada notif error: ' . $e->getMessage());
            }
        }

        return $this->json($response, ['error' => false, 'updated' => true]);
    }

    /**
     * DELETE /api/notificaciones/{id}
     */
    public function eliminar(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = (int)$args['id'];

        DB::table('notificaciones')
            ->where('id', $id)
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $user->id)
            ->delete();

        return $this->json($response, ['error' => false, 'message' => 'Eliminada']);
    }

    /**
     * POST /api/notificaciones/enviar  (Admin/Supervisor only)
     * Enviar notificación manual a uno o varios usuarios
     * Body: { personal_ids: [], titulo, mensaje, tipo, referencia_tipo?, referencia_id?, link_accion?, sonido? }
     */
    public function enviar(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $rol = $user->rol ?? '';
        if (!in_array($rol, ['Admin', 'Supervisor', 'Analista'])) {
            return $this->json($response, ['error' => true, 'message' => 'Sin permiso para enviar notificaciones'], 403);
        }

        $data = $request->getParsedBody() ?? [];
        $personalIds = $data['personal_ids'] ?? [];
        $titulo  = trim($data['titulo'] ?? '');
        $mensaje = trim($data['mensaje'] ?? '');
        $tipo    = $data['tipo'] ?? 'info';

        if (empty($personalIds) || empty($titulo) || empty($mensaje)) {
            return $this->json($response, ['error' => true, 'message' => 'personal_ids, titulo y mensaje son requeridos'], 400);
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($personalIds as $pid) {
            $rows[] = [
                'empresa_id'      => $this->getEffectiveEmpresaId($user, $request),
                'sucursal_id'     => $user->sucursal_id,
                'personal_id'     => (int)$pid,
                'emisor_id'       => $user->id,
                'tipo'            => $tipo,
                'titulo'          => $titulo,
                'mensaje'         => $mensaje,
                'link_accion'     => $data['link_accion'] ?? null,
                'modulo'          => $data['modulo'] ?? null,
                'referencia_tipo' => $data['referencia_tipo'] ?? null,
                'referencia_id'   => isset($data['referencia_id']) ? (int)$data['referencia_id'] : null,
                'sonido'          => ($data['sonido'] ?? true) ? 1 : 0,
                'leida'           => 0,
                'completada'      => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        DB::table('notificaciones')->insert($rows);

        return $this->json($response, [
            'error'   => false,
            'message' => count($rows) . ' notificación(es) enviadas',
            'total'   => count($rows)
        ]);
    }

    /**
     * DELETE /api/notificaciones/limpiar-leidas
     * Elimina todas las notificaciones leídas del usuario actual
     */
    public function eliminarLeidas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        $deleted = DB::table('notificaciones')
            ->where('empresa_id', $empresaId)
            ->where('personal_id', $user->id)
            ->where('leida', true)
            ->delete();

        return $this->json($response, [
            'error'   => false,
            'deleted' => $deleted,
            'message' => "{$deleted} notificación(es) eliminadas"
        ]);
    }

    /**
     * Purga notificaciones leídas > 90 días (mantenimiento silencioso).
     */
    private function _purgarLeidas(int $empresaId): void
    {
        try {
            $corte = date('Y-m-d H:i:s', strtotime('-90 days'));
            DB::table('notificaciones')
                ->where('empresa_id', $empresaId)
                ->where('leida', true)
                ->where('created_at', '<', $corte)
                ->delete();
        } catch (\Throwable $e) {
            error_log('notif purge error: ' . $e->getMessage());
        }
    }

    /**
     * Static helper: enviar notificación programática desde otros controladores
     */
    public static function crear(
        int    $empresaId,
        int    $personalId,
        string $titulo,
        string $mensaje,
        string $tipo          = 'info',
        ?int   $emisorId      = null,
        ?string $referenciaTipo = null,
        ?int   $referenciaId  = null,
        ?string $linkAccion   = null,
        ?string $modulo       = null,
        bool   $sonido        = true,
        ?int   $sucursalId    = null
    ): void {
        try {
            DB::table('notificaciones')->insert([
                'empresa_id'      => $empresaId,
                'sucursal_id'     => $sucursalId,
                'personal_id'     => $personalId,
                'emisor_id'       => $emisorId,
                'tipo'            => $tipo,
                'titulo'          => $titulo,
                'mensaje'         => $mensaje,
                'link_accion'     => $linkAccion,
                'modulo'          => $modulo,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id'   => $referenciaId,
                'sonido'          => $sonido ? 1 : 0,
                'leida'           => 0,
                'completada'      => 0,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            error_log("WMS Notificacion error: " . $e->getMessage());
        }
    }

}
