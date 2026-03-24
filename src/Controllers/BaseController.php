<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use App\Helpers\AuditLogger;
use App\Helpers\ExcelExporter;

/**
 * BaseController — Clase base para todos los controladores WMS.
 * Provee: json(), exportCsv(), audit(), isAdmin(), requireAdmin().
 */
abstract class BaseController
{
    // ── Respuesta JSON ────────────────────────────────────────────────────────

    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
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
        return isset($user->rol) && $user->rol === 'Admin';
    }

    protected function isSupervisorOrAbove($user): bool
    {
        return in_array($user->rol ?? '', ['Admin', 'Supervisor']);
    }

    /**
     * Verifica que el usuario sea Admin; si no, retorna 403.
     * Uso: if ($deny = $this->requireAdmin($user, $response)) return $deny;
     */
    protected function requireAdmin($user, Response $response): ?Response
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden($response, 'Solo el Administrador puede realizar esta acción');
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

    // ── Filtros de fecha comunes ───────────────────────────────────────────────

    /**
     * Extrae y valida fecha_inicio / fecha_fin de los query params.
     * Por defecto: últimos 30 días.
     */
    protected function getDateRange(array $params): array
    {
        $inicio = $params['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fin    = $params['fecha_fin']    ?? date('Y-m-d');

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
}
