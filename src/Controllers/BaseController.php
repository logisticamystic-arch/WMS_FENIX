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

    /**
     * Detecta si $content es un archivo BINARIO (Excel real .xlsx/.xls, PDF,
     * etc.) en vez de texto plano CSV/TXT — a pedido explícito de Camilo
     * (2026-09-17): los importadores de archivo de este sistema (ICG,
     * conteo cíclico, etc.) solo saben leer texto plano (str_getcsv sobre
     * líneas); no hay librería de lectura de Excel real instalada (ni
     * siquiera la extensión `zip` de PHP está habilitada en este servidor).
     * Antes, subir un .xlsx real producía un 500 crudo (el contenido binario
     * se leía como "líneas" y algún fragmento binario terminaba insertado en
     * una columna VARCHAR, reventando el INSERT). Ahora se detecta ANTES de
     * parsear y se devuelve un mensaje claro pidiendo guardarlo como CSV.
     */
    protected function esArchivoBinario(string $content): bool
    {
        if ($content === '') return false;
        // Firma ZIP (xlsx/docx/xlsm modernos) o OLE2 (xls/doc legado).
        if (str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "PK\x05\x06") || str_starts_with($content, "PK\x07\x08")) return true;
        if (str_starts_with($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) return true;
        // Heurística general: un archivo de texto real no debería traer bytes
        // NUL ni una proporción alta de bytes de control en los primeros KB.
        $muestra = substr($content, 0, 8192);
        if (str_contains($muestra, "\x00")) return true;
        $controles = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $muestra);
        return $controles > (strlen($muestra) * 0.05);
    }

    protected function errorArchivoBinario(Response $response): Response
    {
        return $this->error($response, 'Este archivo parece ser un Excel binario (.xlsx/.xls) o de otro formato no soportado. Guárdelo como CSV (Archivo → Guardar como → CSV UTF-8) y vuelva a cargarlo.', 422);
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

    /**
     * Verifica un permiso puntual del catálogo (tabla permisos/rol_permisos/
     * personal_permisos) — a diferencia de requireAdmin/requireSupervisor (por
     * rol fijo), este exige "modulo.accion" concedido por el rol del usuario O
     * por un override individual (mismo cálculo que AuthController::login()/me(),
     * ver aplicarOverridesPersonales()). SuperAdmin y Admin siempre pasan,
     * igual que en el bypass ya existente de hasPermiso() en el frontend.
     *
     * A pedido explícito (2026-08-23), usado para gatear el nuevo módulo de
     * Calidad en el servidor — no se aplicó retroactivamente a módulos
     * existentes (hoy el sistema de permisos es enforcement de menú/UI en el
     * frontend, no de API); agregarlo ahora a endpoints ya en producción
     * podría bloquear flujos internos no auditados. Ver auditoría de
     * viabilidad 2026-08-23 antes de extender este helper a otros módulos.
     *
     * Uso: if ($deny = $this->requirePermiso($user, $req, 'calidad', 'ver', $res)) return $deny;
     */
    protected function requirePermiso($user, Request $request, string $modulo, string $accion, Response $response): ?Response
    {
        if ($this->isAdmin($user)) return null; // Admin/SuperAdmin siempre pasan

        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $personalId = (int)($user->uid ?? $user->id ?? 0);
        $rol        = $user->rol ?? '';

        $concedidoPorRol = \Illuminate\Database\Capsule\Manager::table('rol_permisos as rp')
            ->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->where('rp.empresa_id', $empresaId)
            ->where('rp.rol', $rol)
            ->where('p.modulo', $modulo)
            ->where('p.accion', $accion)
            ->where('rp.concedido', true)
            ->exists();

        $override = \Illuminate\Database\Capsule\Manager::table('personal_permisos')
            ->where('empresa_id', $empresaId)
            ->where('personal_id', $personalId)
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->first();

        $concedido = $override ? (bool)$override->concedido : $concedidoPorRol;

        if (!$concedido) {
            return $this->forbidden($response, "No tiene permiso para '{$modulo}.{$accion}'.");
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
            // picking_faltantes es un log de solo-inserción (sin columna de estado/resuelto):
            // si la línea se marcó "Faltante" en su momento pero luego SÍ se separó (backorder,
            // reintento, reposición), el registro queda huérfano y sin este filtro sale como
            // "agotado" en la remisión para siempre, aunque ya se haya entregado completo.
            // Solo se muestra si ese producto todavía tiene AL MENOS una línea 'Faltante' hoy
            // en esa misma orden (auditoría 2026-08-06, incidente planilla 438 / pedido 17183).
            ->whereExists(function ($q) {
                $q->select(Capsule::raw(1))
                  ->from('picking_detalles as pd')
                  ->whereColumn('pd.orden_picking_id', 'pf.orden_picking_id')
                  ->whereColumn('pd.producto_id', 'pf.producto_id')
                  ->where('pd.estado', 'Faltante');
            })
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
            // A pedido explícito (2026-09-18): no mostrar en la remisión filas de
            // "agotados" sin ninguna cantidad real (solicitada Y pendiente en 0) —
            // registros fantasma de líneas rotas por un bug ya corregido (split
            // multi-ubicación), que no aportan información útil al cliente.
            if ($solicitadaUnd <= 0 && $pendienteUnd <= 0) continue;
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

        if ($filas === '') return '';

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
        // CORREGIDO 2026-09-21 (a pedido explícito de Camilo): margin:0 en el
        // @page no era la forma correcta de quitar el pie de página del
        // navegador — dejaba la página sin margen real (contenido pegado al
        // borde del papel) y el "pie de página" del navegador (que Chrome
        // controla por su cuenta desde el diálogo de impresión, no vía CSS) de
        // todos modos podía seguir apareciendo. Se revierte a un margen real de
        // 1cm parejo en los 4 lados. La barra fija (.running-print-header) que
        // se agregaba arriba de cada página con cliente/planilla/fecha se quita
        // del todo (queda display:none siempre) — era la que se estaba
        // confundiendo con el encabezado.
        return "@page{size:A4 portrait;margin:1cm}
        @media print{
          .no-print{display:none!important}
          body{margin:0;padding:0;font-size:9px;line-height:1.2}
          .pg-break{page-break-after:always;break-after:page}
        }
        .running-print-header{display:none!important}
        body{font-family:Arial,Helvetica,sans-serif;font-size:9.5px;color:#111;margin:0;padding:6px 10px;line-height:1.25}
        .pg-break{page-break-after:always;break-after:page;margin-bottom:12px}
        .header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e3a5f;padding-bottom:4px;margin-bottom:6px}
        .header-left p{margin:0;font-size:9px;font-weight:700;color:#475569}
        .header-right{text-align:right;font-size:9.5px;color:#1e293b}
        .info-grid{display:flex;flex-wrap:wrap;align-items:baseline;gap:3px 16px;margin-bottom:6px;background:#f8fafc;padding:4px 8px;border-radius:4px;border:1px solid #cbd5e1;page-break-inside:avoid;break-inside:avoid-page}
        .info-grid .campo{white-space:nowrap;font-size:9.5px;color:#0f172a}
        .info-grid .lbl{font-weight:800;font-size:8.5px;color:#334155;text-transform:uppercase;letter-spacing:.2px;margin-right:3px}
        .ambientes-grid{display:flex;flex-direction:column;gap:6px}
        .ambiente-block{border:1px solid #cbd5e1;border-radius:3px;overflow:hidden;margin-bottom:6px}
        .ambiente-header{background:#1e3a5f;color:#fff;padding:3px 8px;font-weight:800;font-size:9.5px;letter-spacing:.2px;page-break-after:avoid}
        table{width:100%;border-collapse:collapse;margin:0;font-size:8.5px}
        thead{display:table-header-group}
        th,td{border:1px solid #cbd5e1;padding:2.5px 5px;font-size:8.5px;text-align:left;vertical-align:middle}
        th{background:#f1f5f9;font-weight:800;color:#1e293b;white-space:nowrap;padding:3px 5px}
        tr{page-break-inside:avoid!important;break-inside:avoid-page!important}
        tr:nth-child(even) td{background:#f8fafc}
        .totales{border-top:2px solid #1e3a5f;padding:4px 0;font-weight:800;font-size:10.5px;margin-top:6px;margin-bottom:10px;color:#1e3a5f}
        .agotados-section{margin-top:8px;border:1.5px solid #b91c1c;border-radius:3px;overflow:hidden}
        .agotados-header{background:#b91c1c;color:#fff;padding:3px 8px;font-weight:800;font-size:9.5px;letter-spacing:.2px;page-break-after:avoid}
        .novedades-section{margin-top:8px;margin-bottom:10px;border:1.5px solid #1e3a5f;border-radius:3px;overflow:hidden}
        .novedades-header{background:#1e3a5f;color:#fff;padding:3px 8px;font-weight:800;font-size:9.5px;letter-spacing:.2px;page-break-after:avoid}
        .novedades-section td{height:18px}
        .no-print{padding:6px 0;margin-bottom:8px}
        .no-print button{padding:6px 16px;font-size:12px;font-weight:bold;cursor:pointer;background:#1e3a5f;color:#fff;border:none;border-radius:5px;margin-right:8px}";
    }
}
