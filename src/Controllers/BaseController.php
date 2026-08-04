<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Capsule\Manager as Capsule;
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
        return $response->withStatus($status)
                        ->withHeader('Content-Type', 'application/json')
                        ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                        ->withHeader('Pragma', 'no-cache');
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

    // ── Remisión — generador compartido ─────────────────────────────────────
    // Antes existían 3 generadores de remisión independientes (PackingController
    // ::getRemision(), PickingController::certRemisionMultiple()/certRemisionDirecta())
    // con columnas, secciones y numeración distintas — la misma orden podía salir
    // impresa con un formato distinto según qué endpoint la generara. Estos métodos
    // son la única fuente de verdad del contenido de la remisión; los 3 endpoints
    // solo difieren en CÓMO seleccionan las órdenes/ítems, no en cómo se ven.

    /**
     * Nombre comercial de la empresa para el encabezado de la remisión.
     * La tabla empresas usa razon_social, no nombre.
     */
    protected function remisionEmpresaNombre(int $empresaId): string
    {
        $empresa = Capsule::table('empresas')->find($empresaId);
        return ($empresa->razon_social ?? null) ?: 'WMS Fénix';
    }

    /**
     * Logo embebido en base64 (evita problemas de ruta relativa en la ventana
     * de impresión); si no existe el archivo, cae al nombre de la empresa en texto.
     */
    protected function remisionLogoHtml(string $empNombre): string
    {
        $logoFile = dirname(__DIR__, 2) . '/logo.jpg';
        return file_exists($logoFile)
            ? "<img src='data:image/jpeg;base64," . base64_encode(file_get_contents($logoFile)) . "' style='height:36px;object-fit:contain;display:block;margin-bottom:2px;' alt='Logo'>"
            : "<strong style='font-size:14px;color:#1e3a5f;'>" . htmlspecialchars($empNombre) . "</strong>";
    }

    /**
     * Tabla de ítems agrupada por ambiente (Código, Producto, Lote, Cajas,
     * Saldo, Und/Total, F.Venc). Cada item debe traer: codigo, nombre,
     * unidades_caja, cantidad (unidades reales). Opcionalmente cantidad_cajas
     * y saldo (conteo físico real, p.ej. de packing_items) — si vienen, tienen
     * prioridad sobre el cálculo floor/mod; si no, se calculan a partir de
     * unidades_caja. lote y fecha_vencimiento son opcionales (se muestran '-').
     *
     * @param iterable $grouped  Colección/array indexado por nombre de ambiente.
     * @return array{html:string, cj:float, und:float}
     */
    protected function remisionAmbientesHtml($grouped): array
    {
        $totalUnd = 0; $totalCajas = 0; $html = '';
        foreach ($grouped as $ambNombre => $ambItems) {
            $subUnd = 0; $subCj = 0; $rows = '';
            foreach ($ambItems as $it) {
                $upc     = (isset($it->factor_udm) && (float)$it->factor_udm > 0)
                    ? (float)$it->factor_udm
                    : max(1, (float)($it->unidades_caja ?? 1));
                $cantRaw = (float)($it->cantidad ?? 0);
                $cajasDB = (float)($it->cantidad_cajas ?? 0);
                $saldoDB = (float)($it->saldo ?? 0);

                if ($cajasDB > 0 || $saldoDB > 0) {
                    $cajas = $cajasDB;
                    $saldo = $saldoDB;
                    $und   = round(($cajas * $upc) + $saldo, 3);
                } elseif ($upc > 1) {
                    $cajas = (int)floor($cantRaw / $upc);
                    $saldo = round($cantRaw - ($cajas * $upc), 3);
                    $und   = round($cantRaw, 3);
                } else {
                    $cajas = $cantRaw;
                    $saldo = 0;
                    $und   = $cantRaw;
                }

                $fv   = !empty($it->fecha_vencimiento) ? date('d/m/Y', strtotime($it->fecha_vencimiento)) : '-';
                $fc   = !empty($it->fecha_vencimiento) ? '#b91c1c' : '#94a3b8';
                $loteVal = trim((string)($it->lote ?? ''));
                $lote = ($loteVal !== '' && $loteVal !== 'N/A' && $loteVal !== '—' && $loteVal !== '&mdash;') ? $loteVal : '-';

                $subUnd += $und; $subCj += $cajas;
                $rows .= "<tr>"
                    . "<td style='white-space:nowrap'>" . htmlspecialchars($it->codigo ?? '') . "</td>"
                    . "<td>" . htmlspecialchars($it->nombre ?? '') . "</td>"
                    . "<td style='white-space:nowrap'>" . htmlspecialchars($lote) . "</td>"
                    . "<td style='text-align:right;font-weight:700'>{$cajas}</td>"
                    . "<td style='text-align:right;color:#1e3a5f'>{$saldo}</td>"
                    . "<td style='text-align:right;font-weight:700'>{$und}</td>"
                    . "<td style='text-align:center;color:{$fc}'>{$fv}</td></tr>";
            }
            $totalUnd += $subUnd; $totalCajas += $subCj;
            $ambEsc = htmlspecialchars($ambNombre);
            $html .= "<div class='ambiente-block'>"
                . "<div class='ambiente-header'>{$ambEsc} &mdash; {$subCj} cj / {$subUnd} und</div>"
                . "<table style='table-layout:fixed;width:100%;'><colgroup>"
                . "<col style='width:10%;'><col style='width:33%;'><col style='width:12%;'><col style='width:8%;'><col style='width:8%;'><col style='width:12%;'><col style='width:17%;'>"
                . "</colgroup><thead><tr>"
                . "<th>C&oacute;digo</th><th>Producto</th><th>Lote</th>"
                . "<th style='text-align:right'>Cajas</th><th style='text-align:right'>Saldo</th>"
                . "<th style='text-align:right'>Und/Total</th><th style='text-align:center'>F. Venc.</th>"
                . "</tr></thead><tbody>{$rows}</tbody></table></div>";
        }
        return ['html' => $html, 'cj' => $totalCajas, 'und' => $totalUnd];
    }

    /**
     * Sección de "Productos Agotados/Faltantes" para las órdenes indicadas,
     * con causal estructurada (causales_novedad) y a qué pedido pertenece cada uno.
     * Devuelve '' si no hay faltantes que afecten esas órdenes.
     */
    protected function remisionAgotadosHtml(array $ordenIds): string
    {
        if (empty($ordenIds)) return '';

        $rows = Capsule::table('picking_faltantes as pf')
            ->join('productos as p', 'p.id', '=', 'pf.producto_id')
            ->leftJoin('orden_pickings as op', 'op.id', '=', 'pf.orden_picking_id')
            ->leftJoin('causales_novedad as cn', 'cn.id', '=', 'pf.causal_id')
            ->whereIn('pf.orden_picking_id', $ordenIds)
            ->select([
                'p.codigo_interno as codigo',
                'p.nombre',
                Capsule::raw('COALESCE(NULLIF(p.factor_udm, 0), p.unidades_caja, 1) as upc'),
                Capsule::raw('SUM(pf.cantidad_solicitada) as solicitada_cj'),
                Capsule::raw('SUM(pf.cantidad_faltante) as faltante_cj'),
                Capsule::raw("COALESCE(NULLIF(op.numero_factura, ''), op.numero_orden, '-') as pedido"),
                Capsule::raw("STRING_AGG(DISTINCT COALESCE(pf.causa, 'Sin stock'), ', ') as causa"),
                Capsule::raw("STRING_AGG(DISTINCT cn.nombre, ', ') as causal_nombre"),
                Capsule::raw("STRING_AGG(DISTINCT NULLIF(cn.area_responsable, ''), ', ') as responsable"),
            ])
            ->groupBy('p.codigo_interno', 'p.nombre', 'p.factor_udm', 'p.unidades_caja', 'op.numero_factura', 'op.numero_orden')
            ->orderBy('p.nombre')
            ->get();

        if ($rows->isEmpty()) return '';

        $filas = '';
        foreach ($rows as $r) {
            $upc           = max(1, (int)$r->upc);
            $solicitadaUnd = round((float)$r->solicitada_cj * $upc, 2);
            $pendienteUnd  = round((float)$r->faltante_cj * $upc, 2);
            $motivo = $r->causal_nombre
                ? "<b>" . htmlspecialchars($r->causal_nombre) . "</b>" . ($r->causa ? " — " . htmlspecialchars($r->causa) : '')
                : ($r->causa ? htmlspecialchars($r->causa) : 'Sin causa registrada');
            $responsable = ($r->responsable && trim($r->responsable) !== '') ? htmlspecialchars($r->responsable) : '-';
            $filas .= "<tr>"
                . "<td style='white-space:nowrap'>" . htmlspecialchars($r->codigo) . "</td>"
                . "<td>" . htmlspecialchars($r->nombre) . "</td>"
                . "<td style='white-space:nowrap;font-weight:700'>" . htmlspecialchars($r->pedido) . "</td>"
                . "<td style='text-align:right'>{$solicitadaUnd}</td>"
                . "<td style='text-align:right;color:#b91c1c;font-weight:700'>{$pendienteUnd}</td>"
                . "<td>{$motivo}</td>"
                . "<td>{$responsable}</td>"
                . "</tr>";
        }

        return "<div class='agotados-section'><div class='agotados-header'>&#9888; PRODUCTOS AGOTADOS / FALTANTES</div>"
            . "<table style='table-layout:fixed;width:100%;'><colgroup>"
            . "<col style='width:10%;'><col style='width:26%;'><col style='width:11%;'><col style='width:11%;'><col style='width:11%;'><col style='width:19%;'><col style='width:12%;'></colgroup>"
            . "<thead><tr><th>C&oacute;digo</th><th>Producto</th><th>Pedido</th>"
            . "<th style='text-align:right;'>Und Solicitadas</th><th style='text-align:right;'>Und Pendiente</th>"
            . "<th>Causa</th><th>Responsable</th></tr></thead>"
            . "<tbody>{$filas}</tbody></table></div>";
    }

    /**
     * Bloque de "Novedades de Recepción" (filas en blanco para diligenciar a mano).
     */
    protected function remisionNovedadesHtml(): string
    {
        return "<div class='novedades-section'><div class='novedades-header'>NOVEDADES DE RECEPCI&Oacute;N</div>"
            . "<table style='table-layout:fixed;width:100%;'><colgroup>"
            . "<col style='width:12%;'><col style='width:38%;'><col style='width:10%;'><col style='width:40%;'></colgroup>"
            . "<thead><tr><th>C&oacute;digo</th><th>Descripci&oacute;n</th><th style='text-align:right;'>Cantidad</th><th>Motivo</th></tr></thead>"
            . "<tbody>" . str_repeat("<tr style='height:18px'><td></td><td></td><td></td><td></td></tr>", 4)
            . "</tbody></table></div>";
    }

    /**
     * CSS compartido por las remisiones (packing, certificación directa/móvil,
     * consolidada). Altamente optimizado para ahorro de papel y legibilidad.
     */
    protected function remisionCss(): string
    {
        return "@page{size:A4 portrait;margin:12mm 10mm 10mm 10mm}
        @media print{
          .no-print{display:none!important}
          body{margin:0;padding:0;font-size:9px;line-height:1.2}
          .pg-break{page-break-after:always;break-after:page}
          .running-print-header{display:flex!important;position:fixed;top:-8mm;left:0;right:0;height:16px;border-bottom:1.5px solid #1e3a5f;padding-bottom:2px;font-size:8.5px;font-weight:800;color:#1e3a5f;background:#fff;z-index:99999}
          .ambiente-block{page-break-inside:avoid!important;break-inside:avoid-page!important}
          .ambiente-block tr{page-break-inside:avoid!important;break-inside:avoid-page!important}
          .agotados-section,.novedades-section,.firmas{page-break-inside:avoid!important;break-inside:avoid-page!important}
        }
        .running-print-header{display:none}
        body{font-family:Arial,Helvetica,sans-serif;font-size:9.5px;color:#111;margin:0;padding:6px 10px;line-height:1.25}
        .pg-break{page-break-after:always;break-after:page;margin-bottom:12px}
        .header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e3a5f;padding-bottom:4px;margin-bottom:6px}
        .header-left p{margin:0;font-size:9px;font-weight:700;color:#475569}
        .header-right{text-align:right;font-size:9.5px;color:#1e293b}
        .info-grid{display:flex;flex-wrap:wrap;align-items:baseline;gap:3px 16px;margin-bottom:6px;background:#f8fafc;padding:4px 8px;border-radius:4px;border:1px solid #cbd5e1;page-break-inside:avoid;break-inside:avoid-page}
        .info-grid .campo{white-space:nowrap;font-size:9.5px;color:#0f172a}
        .info-grid .lbl{font-weight:800;font-size:8.5px;color:#334155;text-transform:uppercase;letter-spacing:.2px;margin-right:3px}
        .ambientes-grid{display:flex;flex-direction:column;gap:6px}
        .ambiente-block{border:1px solid #cbd5e1;border-radius:3px;overflow:hidden;margin-bottom:6px;page-break-inside:avoid!important;break-inside:avoid-page!important}
        .ambiente-header{background:#1e3a5f;color:#fff;padding:3px 8px;font-weight:800;font-size:9.5px;letter-spacing:.2px;page-break-after:avoid}
        table{width:100%;border-collapse:collapse;margin:0;font-size:8.5px}
        thead{display:table-header-group}
        th,td{border:1px solid #cbd5e1;padding:2.5px 5px;font-size:8.5px;text-align:left;vertical-align:middle}
        th{background:#f1f5f9;font-weight:800;color:#1e293b;white-space:nowrap;padding:3px 5px}
        tr{page-break-inside:avoid!important;break-inside:avoid-page!important}
        tr:nth-child(even) td{background:#f8fafc}
        .totales{border-top:2px solid #1e3a5f;padding:4px 0;font-weight:800;font-size:10.5px;margin-top:6px;color:#1e3a5f}
        .agotados-section{margin-top:8px;border:1.5px solid #b91c1c;border-radius:3px;overflow:hidden;page-break-inside:avoid!important;break-inside:avoid-page!important}
        .agotados-header{background:#b91c1c;color:#fff;padding:3px 8px;font-weight:800;font-size:9.5px;letter-spacing:.2px}
        .novedades-section{margin-top:8px;border:1.5px solid #1e3a5f;border-radius:3px;overflow:hidden;page-break-inside:avoid!important;break-inside:avoid-page!important}
        .novedades-header{background:#1e3a5f;color:#fff;padding:3px 8px;font-weight:800;font-size:9.5px;letter-spacing:.2px}
        .novedades-section td{height:18px}
        .firmas{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:14px;page-break-inside:avoid!important;break-inside:avoid-page!important}
        .firma-line{border-top:1.5px solid #1e3a5f;padding-top:3px;text-align:center;font-size:8.5px;color:#334155}
        .no-print{padding:6px 0;margin-bottom:8px}
        .no-print button{padding:6px 16px;font-size:12px;font-weight:bold;cursor:pointer;background:#1e3a5f;color:#fff;border:none;border-radius:5px;margin-right:8px}";
    }
}
