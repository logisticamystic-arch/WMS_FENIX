<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Eloquent\Builder;
use App\Helpers\AuditLogger;
use App\Helpers\ExcelExporter;
use App\Helpers\TenantContext;

/**
 * BaseController — Clase base para todos los controladores WMS.
 * Provee: json(), exportCsv(), audit(), isAdmin(), requireAdmin().
 */
abstract class BaseController
{
    // ── Respuesta JSON ────────────────────────────────────────────────────────

    public function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Registra la rotación de logs usando register_shutdown_function para que
     * no bloquee el path crítico de la respuesta JSON.
     * Llamar desde index.php una vez por proceso, no en cada request.
     */
    public static function scheduleLogRotation(): void
    {
        register_shutdown_function(function () {
            if (mt_rand(1, 200) === 1) {
                $log = dirname(__DIR__, 2) . '/logs/app.log';
                \App\Helpers\LogRotator::checkAndRotate($log);
            }
        });
    }

    protected function ok(Response $response, $data = null, string $message = 'OK'): Response
    {
        $body = ['error' => false, 'message' => $message];
        if ($data !== null) $body['data'] = $data;
        return $this->json($response, $body);
    }

    protected function created(Response $response, $data = null, string $message = 'Creado con éxito'): Response
    {
        $body = ['error' => false, 'message' => $message];
        if ($data !== null) $body['data'] = $data;
        return $this->json($response, $body, 201);
    }

    protected function error(Response $response, string $message, int $status = 400): Response
    {
        return $this->json($response, ['error' => true, 'message' => $message], $status);
    }

    protected function notFound(Response $response, string $message = 'Registro no encontrado'): Response
    {
        return $this->error($response, $message, 404);
    }

    protected function forbidden(Response $response, string $message = 'No tienes permiso para esta acción'): Response
    {
        return $this->error($response, $message, 403);
    }

    // ── Export CSV/Excel ──────────────────────────────────────────────────────

    protected function exportCsv(Response $response, array $headers, array $rows, string $filename): Response
    {
        return ExcelExporter::download($response, $headers, $rows, $filename);
    }

    // ── Auditoría ─────────────────────────────────────────────────────────────

    protected function audit(
        $user,
        string  $modulo,
        string  $accion,
        ?string $tabla     = null,
        ?int    $id        = null,
        ?array  $anterior  = null,
        ?array  $nuevo     = null,
        ?string $desc      = null
    ): void {
        AuditLogger::log(
            $user->empresa_id,
            $user->id ?? null,
            $modulo,
            $accion,
            $tabla,
            $id,
            $anterior,
            $nuevo,
            $desc
        );
    }

    // ── Autorización ──────────────────────────────────────────────────────────

    protected function isAdmin($user): bool
    {
        return isset($user->rol) && in_array($user->rol, ['Admin', 'SuperAdmin'], true);
    }

    protected function isSuperAdmin($user): bool
    {
        return isset($user->rol) && strcasecmp($user->rol, 'SuperAdmin') === 0;
    }

    protected function isSupervisorOrAbove($user): bool
    {
        return isset($user->rol) && in_array($user->rol, [
            'SuperAdmin', 'Admin', 'Supervisor', 'Jefe',
        ], true);
    }

    protected function getEffectiveEmpresaId($user, Request $request): ?int
    {
        if ($this->isSuperAdmin($user) && isset($request->getQueryParams()['empresa_id'])) {
            return (int)$request->getQueryParams()['empresa_id'];
        }

        return $request->getAttribute('empresa_id')
            ?? $user->empresa_id ?? TenantContext::getEmpresaId();
    }

    protected function getEffectiveSucursalId($user, Request $request): ?int
    {
        if ($this->isSuperAdmin($user) && isset($request->getQueryParams()['sucursal_id'])) {
            return (int)$request->getQueryParams()['sucursal_id'];
        }

        return $request->getAttribute('sucursal_id')
            ?? $user->sucursal_id ?? TenantContext::getSucursalId();
    }

    protected function getEffectiveTenantIds($user, Request $request): array
    {
        return [
            $this->getEffectiveEmpresaId($user, $request),
            $this->getEffectiveSucursalId($user, $request),
        ];
    }

    protected function addTenantConstraints(Builder $query, $user, Request $request): Builder
    {
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        if ($empresaId !== null) {
            $query->where($query->getModel()->getTable() . '.empresa_id', $empresaId);
        }
        if ($sucursalId !== null) {
            $query->where($query->getModel()->getTable() . '.sucursal_id', $sucursalId);
        }

        return $query;
    }

    /**
     * Verifica que el usuario sea Admin; si no, retorna 403.
     * Uso: if ($deny = $this->requireAdmin($user, $response)) return $deny;
     */
    protected function requireAdmin($user, Response $response): ?Response
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden($response, 'Solo el Administrador o SuperAdmin puede realizar esta acción');
        }
        return null;
    }

    protected function requireSupervisor($user, Response $response): ?Response
    {
        if (!$this->isSupervisorOrAbove($user)) {
            return $this->forbidden($response, 'Se requiere rol Supervisor o Administrador');
        }
        return null;
    }

    protected function requireSelectedTenantForSuperAdmin($user, Request $request, Response $response, bool $requireSucursal = false): ?Response
    {
        if (!$this->isSuperAdmin($user)) {
            return null;
        }

        $params = $request->getQueryParams();
        if (!isset($params['empresa_id']) || trim((string)$params['empresa_id']) === '') {
            return $this->error($response, 'SuperAdmin debe filtrar la empresa con el parámetro empresa_id.');
        }

        if ($requireSucursal && (!isset($params['sucursal_id']) || trim((string)$params['sucursal_id']) === '')) {
            return $this->error($response, 'SuperAdmin debe filtrar la sucursal con el parámetro sucursal_id.');
        }

        return null;
    }

    // ── Filtros de fecha comunes ───────────────────────────────────────────────

    /**
     * Extrae y valida fecha_inicio / fecha_fin de los query params.
     * Por defecto: últimos 30 días.
     */
    protected function getDateRange(array $params): array
    {
        $inicio = $params['fecha_inicio'] ?? $params['from'] ?? $params['desde'] ?? date('Y-m-d', strtotime('-30 days'));
        $fin    = $params['fecha_fin']    ?? $params['to']   ?? $params['hasta'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
            $inicio = date('Y-m-d', strtotime('-30 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
            $fin = date('Y-m-d');
        }

        return [$inicio, $fin . ' 23:59:59'];
    }

    // ── Paginación ────────────────────────────────────────────────────────────

    /**
     * Returns pagination metadata array.
     * Usage: $meta = $this->paginateMeta($total, $page, $perPage);
     */
    protected function paginateMeta(int $total, int $page, int $perPage): array
    {
        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Extract safe page/per_page from query params.
     * Returns [page, perPage].
     */
    protected function getPagination(array $params, int $defaultPerPage = 50, int $maxPerPage = 500): array
    {
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min($maxPerPage, max(1, (int)($params['per_page'] ?? $defaultPerPage)));
        return [$page, $perPage];
    }

    // ── Validación ────────────────────────────────────────────────────────────

    /**
     * Check that all $required keys are present and non-empty in $data.
     * Returns list of missing field names.
     */
    protected function missingFields(array $data, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Convenience: return 400 error if any required fields are missing.
     * Usage: if ($deny = $this->requireFields($body, ['nombre','precio'], $response)) return $deny;
     */
    protected function requireFields(array $data, array $required, Response $response): ?Response
    {
        $missing = $this->missingFields($data, $required);
        if (!empty($missing)) {
            return $this->error($response, 'Campos requeridos: ' . implode(', ', $missing));
        }
        return null;
    }

    // ── Sanitización ─────────────────────────────────────────────────────────

    /**
     * Strip tags and trim a string value. Returns '' on null.
     */
    protected function sanitizeStr(?string $value): string
    {
        return trim(strip_tags((string)($value ?? '')));
    }

    /**
     * Sanitize an entire flat array: strip tags + trim all string values.
     * Non-string values are left untouched.
     */
    protected function sanitizeArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = is_string($v) ? $this->sanitizeStr($v) : $v;
        }
        return $out;
    }

    /**
     * Cast value to positive integer, return null if invalid.
     */
    protected function posInt($value): ?int
    {
        $v = (int)$value;
        return $v > 0 ? $v : null;
    }
    /**
     * Detecta si la conexión actual es PostgreSQL.
     */
    protected function isPg(): bool
    {
        return \Illuminate\Database\Capsule\Manager::connection()->getDriverName() === 'pgsql';
    }
}
