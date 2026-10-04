<?php

namespace App\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Recogidas de devolución desde Punto de Venta (a pedido de Camilo, 2026-09-30).
 *
 * Sigue la "Política Interna de Gestión de Logística Inversa y Devoluciones":
 *  - El PDV monta la solicitud en la plataforma BEE → aquí se digita su consecutivo.
 *  - El documento debe traer referencia, descripción, cantidad y el MOTIVO REAL (4.1).
 *  - Causales y requisitos por causal (4.2) — ver CAUSALES.
 *  - Transporte recoge ÚNICAMENTE lo relacionado en el documento (4.3).
 *  - Se imprime y lo firman el PDV y el transportador (6.1) → remisión de RECOGIDA
 *    en media carta con firmas de quien entrega / quien recibe y espacio para sello.
 */
class DevolucionRecogidaController extends BaseController
{
    /**
     * Causales de la política (sección 4.2). requiere_autorizacion: exige previa
     * autorización de Calidad o Líder del CDP descrita en Observaciones.
     * permite_destruccion: puede quedar "Destruir en PDV" (sección 5).
     */
    const CAUSALES = [
        ['codigo' => 'ERROR_PEDIDO',      'nombre' => 'Error en Pedido',              'grupo' => 'Error en Pedido / Traspaso', 'requiere_autorizacion' => false, 'permite_destruccion' => false,
         'requisito' => 'Producto en perfecto estado, empaque original limpio, sellado y sin adhesivos ni rayones. Vencimiento no inferior al mínimo vigente en el CEDI.'],
        ['codigo' => 'TRASPASO_ALMACEN',  'nombre' => 'Traspaso de Almacén',          'grupo' => 'Error en Pedido / Traspaso', 'requiere_autorizacion' => false, 'permite_destruccion' => false,
         'requisito' => 'Producto en perfecto estado, empaque original limpio, sellado y sin adhesivos ni rayones. Vencimiento no inferior al mínimo vigente en el CEDI.'],
        ['codigo' => 'CALIDAD',           'nombre' => 'Calidad',                      'grupo' => 'Calidad / Mal Estado', 'requiere_autorizacion' => true, 'permite_destruccion' => true,
         'requisito' => 'Separado e identificado; protegido si perdió el empaque. Requiere autorización previa de Calidad o Líder CDP.'],
        ['codigo' => 'CALIDAD_CDP',       'nombre' => 'Calidad CDP',                  'grupo' => 'Calidad / Mal Estado', 'requiere_autorizacion' => true, 'permite_destruccion' => true,
         'requisito' => 'Separado e identificado; protegido si perdió el empaque. Requiere autorización previa de Calidad o Líder CDP.'],
        ['codigo' => 'CALIDAD_LOGISTICA', 'nombre' => 'Calidad Logística',            'grupo' => 'Calidad / Mal Estado', 'requiere_autorizacion' => true, 'permite_destruccion' => true,
         'requisito' => 'Separado e identificado; protegido si perdió el empaque. Requiere autorización previa de Calidad o Líder CDP.'],
        ['codigo' => 'MAL_ESTADO',        'nombre' => 'Mal Estado',                   'grupo' => 'Calidad / Mal Estado', 'requiere_autorizacion' => true, 'permite_destruccion' => true,
         'requisito' => 'Separado e identificado; protegido si perdió el empaque. Requiere autorización previa de Calidad o Líder CDP.'],
        ['codigo' => 'CADUCADO',          'nombre' => 'Caducado / Fecha Vencimiento', 'grupo' => 'Caducado', 'requiere_autorizacion' => true, 'permite_destruccion' => true,
         'requisito' => 'Requiere autorización previa de Calidad o Líder CDP. Si se autoriza destruir en PDV, seguir el numeral 5 (registro fotográfico).'],
        ['codigo' => 'FALTANTES',         'nombre' => 'Faltantes',                    'grupo' => 'Faltantes', 'requiere_autorizacion' => false, 'permite_destruccion' => false,
         'requisito' => 'Registrar en BEE el mismo día de la entrega. La remisión de entrega debe estar firmada con la novedad del faltante.'],
    ];

    private function causal(string $codigo): ?array
    {
        foreach (self::CAUSALES as $c) if ($c['codigo'] === $codigo) return $c;
        return null;
    }

    // ── GET /api/devoluciones/recogidas/causales ─────────────────────────────
    public function causales(Request $r, Response $res): Response
    {
        return $this->ok($res, self::CAUSALES);
    }

    // ── GET /api/devoluciones/recogidas/puntos-venta?q= ──────────────────────
    // Búsqueda dinámica de PDV: maestro de clientes + sucursales de entrega
    // reales de los pedidos (90 días), sin el sufijo " - Ped. N" y sin duplicar.
    public function puntosVenta(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $q = trim($r->getQueryParams()['q'] ?? '');
        $like = '%' . mb_strtoupper($q) . '%';

        $out = [];
        $clientes = Capsule::table('clientes')
            ->where('empresa_id', $empresaId)->where('activo', true)
            ->when($q !== '', fn($x) => $x->whereRaw('UPPER(razon_social) LIKE ?', [$like]))
            ->orderBy('razon_social')->limit(20)
            ->get(['id', 'razon_social', 'ciudad', 'direccion', 'frecuencia']);
        foreach ($clientes as $c) {
            $out[mb_strtoupper(trim($c->razon_social))] = [
                'cliente_id' => $c->id, 'nombre' => trim($c->razon_social),
                'detalle' => trim(implode(' · ', array_filter([$c->ciudad, $c->direccion, $c->frecuencia ? "Ruta: {$c->frecuencia}" : null]))),
                'frecuencia' => $c->frecuencia,
            ];
        }
        $pedidos = Capsule::table('orden_pickings')
            ->where('empresa_id', $empresaId)
            ->whereNotNull('sucursal_entrega')
            ->where('created_at', '>=', date('Y-m-d', strtotime('-90 days')))
            ->when($q !== '', fn($x) => $x->whereRaw('UPPER(sucursal_entrega) LIKE ?', [$like]))
            ->distinct()->limit(60)->pluck('sucursal_entrega');
        foreach ($pedidos as $s) {
            $nombre = trim(preg_replace('/\s*-\s*Ped\.\s*\d+\s*$/i', '', (string)$s));
            if ($nombre === '' || stripos($nombre, 'sin identificar') !== false) continue;
            $k = mb_strtoupper($nombre);
            if (!isset($out[$k])) $out[$k] = ['cliente_id' => null, 'nombre' => $nombre, 'detalle' => 'Sucursal de entrega', 'frecuencia' => null];
        }
        ksort($out);
        return $this->ok($res, array_slice(array_values($out), 0, 25));
    }

    // ── GET /api/devoluciones/recogidas ──────────────────────────────────────
    public function index(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $p = $r->getQueryParams();
        $fIni = $p['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fFin = $p['fecha_fin'] ?? date('Y-m-d');
        $q    = trim($p['q'] ?? '');

        $rows = Capsule::table('devolucion_recogidas as dr')
            ->leftJoin('personal as pe', 'pe.id', '=', 'dr.creado_por')
            ->where('dr.empresa_id', $empresaId)
            ->where('dr.sucursal_id', $user->sucursal_id)
            ->whereBetween('dr.fecha_solicitud', [$fIni, $fFin])
            ->when($q !== '', fn($x) => $x->where(function ($w) use ($q) {
                $like = '%' . mb_strtoupper($q) . '%';
                $w->whereRaw('UPPER(dr.punto_venta) LIKE ?', [$like])
                  ->orWhereRaw('UPPER(dr.consecutivo_bee) LIKE ?', [$like])
                  ->orWhereRaw('UPPER(dr.numero) LIKE ?', [$like]);
            }))
            ->selectRaw("dr.*, pe.nombre as creado_por_nombre,
                (select count(*) from devolucion_recogida_lineas l where l.recogida_id = dr.id) as referencias,
                (select string_agg(distinct l.causal, ', ') from devolucion_recogida_lineas l where l.recogida_id = dr.id) as causales")
            ->orderByDesc('dr.id')->limit(500)->get();
        return $this->ok($res, $rows);
    }

    // ── POST /api/devoluciones/recogidas ─────────────────────────────────────
    public function store(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $d = $r->getParsedBody() ?? [];

        $bee = trim((string)($d['consecutivo_bee'] ?? ''));
        $pdv = trim((string)($d['punto_venta'] ?? ''));
        $lineas = is_array($d['lineas'] ?? null) ? $d['lineas'] : [];
        $obs = trim((string)($d['observaciones'] ?? ''));
        $autorizado = trim((string)($d['autorizado_por'] ?? ''));

        if ($bee === '') return $this->error($res, 'Digite el consecutivo BEE de la solicitud de devolución.', 422);
        if ($pdv === '') return $this->error($res, 'Seleccione el punto de venta / sucursal que devuelve.', 422);
        if (!$lineas)    return $this->error($res, 'Agregue al menos una referencia a recoger.', 422);
        if (Capsule::table('devolucion_recogidas')->where('empresa_id', $empresaId)->where('consecutivo_bee', $bee)->where('estado', '!=', 'Anulada')->exists()) {
            return $this->error($res, "El consecutivo BEE {$bee} ya tiene una recogida registrada.", 409);
        }

        $limpias = [];
        $requiereAut = false;
        foreach ($lineas as $i => $l) {
            $n = $i + 1;
            $prod = Capsule::table('productos')->where('empresa_id', $empresaId)->where('id', (int)($l['producto_id'] ?? 0))
                ->first(['id', 'nombre', 'unidades_caja', 'factor_udm', 'controla_vencimiento']);
            if (!$prod) return $this->error($res, "Línea {$n}: seleccione una referencia válida.", 422);
            $causal = $this->causal((string)($l['causal'] ?? ''));
            if (!$causal) return $this->error($res, "Línea {$n} ({$prod->nombre}): seleccione la causal de la devolución.", 422);

            $upc = ((float)$prod->factor_udm > 0) ? (float)$prod->factor_udm : max(1, (float)($prod->unidades_caja ?? 1));
            $cajas  = max(0, round((float)($l['cantidad_cajas'] ?? 0), 2));
            $saldos = max(0, round((float)($l['saldos'] ?? 0), 3));
            $und    = round($cajas * $upc + $saldos, 3);
            if ($und <= 0) return $this->error($res, "Línea {$n} ({$prod->nombre}): la cantidad debe ser mayor a cero.", 422);

            $disp = ($l['disposicion'] ?? 'Recoger') === 'Destruir en PDV' && $causal['permite_destruccion'] ? 'Destruir en PDV' : 'Recoger';
            if ($causal['requiere_autorizacion']) $requiereAut = true;

            $fv = !empty($l['fecha_vencimiento']) ? substr((string)$l['fecha_vencimiento'], 0, 10) : null;
            $limpias[] = [
                'producto_id' => $prod->id, 'cantidad_cajas' => $cajas, 'saldos' => $saldos, 'cantidad' => $und,
                'lote' => trim((string)($l['lote'] ?? '')) ?: null, 'fecha_vencimiento' => $fv,
                'causal' => $causal['nombre'], 'disposicion' => $disp,
                'observacion' => mb_substr(trim((string)($l['observacion'] ?? '')), 0, 255) ?: null,
            ];
        }
        // Política 4.2: Calidad / Mal Estado / Caducado exigen autorización previa
        // de Calidad o Líder del CDP, descrita en Observaciones.
        if ($requiereAut && ($autorizado === '' || $obs === '')) {
            return $this->error($res, 'Hay referencias con causal de Calidad, Mal Estado o Caducado: según la política deben tener autorización previa. Indique quién autorizó (Calidad o Líder CDP) y descríbala en Observaciones.', 422);
        }

        try {
            $id = Capsule::transaction(function () use ($empresaId, $user, $bee, $pdv, $d, $obs, $autorizado, $limpias) {
                $now = date('Y-m-d H:i:s');
                // N.º interno RG-000123 = id de la fila (único sin bloqueos; Postgres
                // no admite FOR UPDATE con MAX()). Se inserta con un temporal único.
                $id = Capsule::table('devolucion_recogidas')->insertGetId([
                    'empresa_id' => $empresaId, 'sucursal_id' => $user->sucursal_id,
                    'numero' => 'TMP-' . uniqid(),
                    'consecutivo_bee' => $bee,
                    'cliente_id' => !empty($d['cliente_id']) ? (int)$d['cliente_id'] : null,
                    'punto_venta' => mb_substr($pdv, 0, 150),
                    'fecha_solicitud' => date('Y-m-d'),
                    'fecha_recogida' => !empty($d['fecha_recogida']) ? substr((string)$d['fecha_recogida'], 0, 10) : null,
                    'autorizado_por' => $autorizado ?: null,
                    'observaciones' => $obs ?: null,
                    'estado' => 'Programada', 'creado_por' => $user->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                Capsule::table('devolucion_recogidas')->where('id', $id)
                    ->update(['numero' => 'RG-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT)]);
                foreach ($limpias as $l) {
                    Capsule::table('devolucion_recogida_lineas')->insert($l + ['recogida_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
                }
                return $id;
            });
            $this->audit($user, 'devoluciones', 'crear_recogida', 'devolucion_recogidas', $id, null,
                ['consecutivo_bee' => $bee, 'punto_venta' => $pdv, 'lineas' => count($limpias)], "Recogida BEE {$bee} — {$pdv}");
            return $this->ok($res, $this->cargar($empresaId, $id), 'Recogida registrada');
        } catch (\Throwable $e) {
            return $this->error($res, 'No se pudo registrar la recogida: ' . $e->getMessage(), 500);
        }
    }

    private function cargar(int $empresaId, int $id): ?array
    {
        $cab = Capsule::table('devolucion_recogidas as dr')
            ->leftJoin('personal as pe', 'pe.id', '=', 'dr.creado_por')
            ->where('dr.empresa_id', $empresaId)->where('dr.id', $id)
            ->first(['dr.*', 'pe.nombre as creado_por_nombre']);
        if (!$cab) return null;
        $lineas = Capsule::table('devolucion_recogida_lineas as l')
            ->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->where('l.recogida_id', $id)->orderBy('l.id')
            ->get(['l.*', 'p.codigo_interno as codigo', 'p.nombre', 'p.unidades_caja', 'p.factor_udm']);
        return ['recogida' => $cab, 'lineas' => $lineas];
    }

    // ── GET /api/devoluciones/recogidas/{id} ─────────────────────────────────
    public function ver(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $this->cargar($this->getEffectiveEmpresaId($user, $r), (int)$a['id']);
        return $data ? $this->ok($res, $data) : $this->notFound($res);
    }

    // ── POST /api/devoluciones/recogidas/{id}/anular ─────────────────────────
    public function anular(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $motivo = trim((string)(($r->getParsedBody() ?? [])['motivo'] ?? ''));
        if ($motivo === '') return $this->error($res, 'Indique el motivo de la anulación.', 422);
        $q = Capsule::table('devolucion_recogidas')->where('empresa_id', $empresaId)->where('id', (int)$a['id'])->where('estado', '!=', 'Anulada');
        $rec = (clone $q)->first(['observaciones']);
        if (!$rec) return $this->notFound($res, 'Recogida no encontrada o ya anulada');
        $q->update(['estado' => 'Anulada', 'observaciones' => trim(($rec->observaciones ?? '') . "\nANULADA: {$motivo}"), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit($user, 'devoluciones', 'anular_recogida', 'devolucion_recogidas', (int)$a['id'], null, ['motivo' => $motivo], 'Recogida anulada');
        return $this->ok($res, null, 'Recogida anulada');
    }

    // ── GET /api/devoluciones/recogidas/{id}/imprimir ────────────────────────
    // Remisión de RECOGIDA en media carta (5,5 × 8,5 in), mismo estilo de las
    // remisiones de despacho (logo, encabezado, info-grid, tabla), 2 copias
    // (PDV y Transporte), firmas de quien entrega / quien recibe y espacio para sello.
    public function imprimir(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $data = $this->cargar($empresaId, (int)$a['id']);
        if (!$data) return $this->notFound($res);
        $c = $data['recogida'];
        $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $empNombre = $this->remisionEmpresaNombre($empresaId);
        $logo = $this->remisionLogoHtml($empNombre);
        $rows = ''; $totCj = 0; $totUnd = 0;
        foreach ($data['lineas'] as $l) {
            $fv = $l->fecha_vencimiento ? date('d/m/Y', strtotime($l->fecha_vencimiento)) : '-';
            $totCj += (float)$l->cantidad_cajas; $totUnd += (float)$l->cantidad;
            $disp = $l->disposicion === 'Destruir en PDV' ? "<div style='font-size:7px;color:#b91c1c;font-weight:800;'>DESTRUIR EN PDV</div>" : '';
            $rows .= "<tr>"
                . "<td style='white-space:nowrap'>{$e($l->codigo)}</td>"
                . "<td>{$e($l->nombre)}" . ($l->observacion ? "<div style='font-size:7px;color:#475569;'>{$e($l->observacion)}</div>" : '') . "</td>"
                . "<td>{$e($l->causal)}{$disp}</td>"
                . "<td style='text-align:right;font-weight:700'>" . (float)$l->cantidad_cajas . "</td>"
                . "<td style='text-align:right'>" . (float)$l->saldos . "</td>"
                . "<td style='text-align:right;font-weight:700'>" . (float)$l->cantidad . "</td>"
                . "<td style='white-space:nowrap'>" . ($l->lote ? $e($l->lote) . '<br>' : '') . "{$fv}</td>"
                . "</tr>";
        }
        $anulada = $c->estado === 'Anulada'
            ? "<div style='border:2px solid #b91c1c;color:#b91c1c;font-weight:900;text-align:center;padding:3px;margin-bottom:5px;font-size:11px;'>DOCUMENTO ANULADO</div>" : '';
        $fRec = $c->fecha_recogida ? date('d/m/Y', strtotime($c->fecha_recogida)) : '__________';
        $obsHtml = $this->remisionObservacionesHtml(trim(($c->autorizado_por ? "Autorizado por: {$c->autorizado_por}\n" : '') . ($c->observaciones ?? '')));

        $copia = fn($n, $para) => "<section class='copia'>
  <div style='text-align:right;font-size:7px;font-weight:800;color:#94a3b8;letter-spacing:.5px;'>COPIA {$n} DE 2 &mdash; {$para}</div>
  {$anulada}
  <div class='header'>
    <div class='header-left'>{$logo}<p style='font-size:11px;color:#1e3a5f;'>REMISI&Oacute;N DE RECOGIDA</p><p>Devoluci&oacute;n desde Punto de Venta</p></div>
    <div class='header-right'>
      <div class='bee'>BEE N&ordm; {$e($c->consecutivo_bee)}</div>
      <strong>{$e($c->numero)}</strong><br>Fecha: " . date('d/m/Y', strtotime($c->fecha_solicitud)) . "
    </div>
  </div>
  <div class='info-grid'>
    <span class='campo'><span class='lbl'>Punto de venta:</span>{$e($c->punto_venta)}</span>
    <span class='campo'><span class='lbl'>Consecutivo BEE:</span>{$e($c->consecutivo_bee)}</span>
    <span class='campo'><span class='lbl'>Fecha recogida:</span>{$fRec}</span>
    <span class='campo'><span class='lbl'>Elabor&oacute;:</span>{$e($c->creado_por_nombre)}</span>
  </div>
  <table style='table-layout:fixed;'>
    <colgroup><col style='width:12%'><col style='width:30%'><col style='width:18%'><col style='width:8%'><col style='width:8%'><col style='width:10%'><col style='width:14%'></colgroup>
    <thead><tr><th>C&oacute;digo</th><th>Descripci&oacute;n</th><th>Causal</th><th style='text-align:right'>Cajas</th><th style='text-align:right'>Saldo</th><th style='text-align:right'>Und</th><th>Lote / F.Venc</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
  <div class='totales'>TOTAL: {$totCj} cj &mdash; {$totUnd} und &mdash; " . count($data['lineas']) . " referencia(s)</div>
  {$obsHtml}
  <div class='nota'>Transporte recoge &uacute;nicamente las referencias y cantidades relacionadas en este documento (Pol&iacute;tica de Log&iacute;stica Inversa, num. 4.3).</div>
  <div class='firmas'>
    <div class='firma'><div class='linea'></div><b>ENTREGA</b><span>Punto de venta &mdash; Nombre y C.C.</span></div>
    <div class='firma'><div class='linea'></div><b>RECIBE</b><span>Transportador &mdash; Nombre y C.C.</span></div>
    <div class='sello'>SELLO</div>
  </div>
</section>";

        // Media carta: 5,5 × 8,5 in. Cada copia ocupa una página y las firmas
        // quedan al pie (margin-top:auto dentro de la columna flex).
        $css = $this->remisionCss() . "
        @page{size:5.5in 8.5in portrait;margin:0.6cm}
        body{padding:0}
        .copia{display:flex;flex-direction:column;min-height:calc(8.5in - 1.3cm);box-sizing:border-box;page-break-after:always;break-after:page}
        .copia:last-of-type{page-break-after:auto;break-after:auto}
        @media screen{.copia{max-width:5.5in;margin:0 auto 18px;border:1px dashed #cbd5e1;padding:10px;min-height:auto}}
        .copia{width:100%;overflow:hidden}
        .copia .header{gap:8px}
        .copia .header-left{min-width:0}
        .copia .header-right{flex-shrink:0;max-width:52%}
        .copia div[style*='12pt']{font-size:9pt!important}
        .bee{font-size:12px;font-weight:900;color:#b91c1c;border:1.5px solid #b91c1c;border-radius:3px;padding:1px 6px;display:inline-block;margin-bottom:3px;word-break:break-all}
        .nota{font-size:7.5px;color:#475569;margin:4px 0}
        .firmas{margin-top:auto;padding-top:28px;display:grid;grid-template-columns:1fr 1fr 90px;gap:14px;align-items:end}
        .firma{text-align:center;font-size:8px}
        .firma .linea{border-top:1px solid #111;margin-bottom:2px}
        .firma span{display:block;color:#475569;font-size:7px}
        .sello{height:70px;border:1.5px dashed #94a3b8;border-radius:4px;display:flex;align-items:flex-end;justify-content:center;font-size:7px;color:#94a3b8;font-weight:800;padding-bottom:3px}";

        $html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Recogida {$e($c->numero)} &mdash; BEE {$e($c->consecutivo_bee)}</title>
<style>{$css}</style></head><body>
<div class='no-print' style='max-width:5.5in;margin:0 auto;'><button onclick='window.print()'>&#128424; Imprimir</button>
<small style='color:#666'>Formato media carta &mdash; 2 copias (Punto de venta y Transporte)</small></div>"
            . $copia(1, 'PUNTO DE VENTA') . $copia(2, 'TRANSPORTE') . "</body></html>";

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }
}
