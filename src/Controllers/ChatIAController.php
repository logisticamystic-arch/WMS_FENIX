<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Illuminate\Database\Capsule\Manager as Capsule;

class ChatIAController extends BaseController
{
    public function mensaje(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $body       = (array)($r->getParsedBody() ?? []);
        $mensaje    = trim($body['mensaje'] ?? '');
        $historial  = $body['historial'] ?? [];
        $modulo     = $body['modulo'] ?? 'general';

        if (!$mensaje) return $this->error($res, 'Mensaje vacío', 400);

        $apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
        if (!$apiKey) return $this->error($res,
            'FENIX IA no está configurada. Agrega GROQ_API_KEY al archivo .env', 503);

        $contexto = $this->_buildContexto($empresaId, $sucursalId, $modulo);
        $contexto .= $this->_enrichFromQuery($mensaje, $empresaId, $sucursalId);

        $systemPrompt = "Eres FENIX IA, el asistente operativo inteligente del sistema WMS Fénix de gestión logística de almacenes de la empresa Místico.
Tu misión es ayudar al equipo de logística con consultas sobre inventarios, picking, despachos, devoluciones, trazabilidad y toda la operación del almacén.

REGLAS:
- Responde SIEMPRE en español, de forma concisa y profesional
- Cuando des cifras, explica su significado operativo
- Si detectas anomalías en los datos del contexto, menciónalo proactivamente
- Puedes hacer análisis, sugerencias de mejora y recomendaciones operativas
- Módulos del sistema: Picking, Despacho, Recepción, Inventario, Devoluciones, Trazabilidad, Almacenamiento, Reportes
- Si el usuario saluda o hace preguntas generales, responde amigablemente y ofrece ayuda operativa

CONTEXTO OPERATIVO EN TIEMPO REAL (datos de la BD del WMS al momento de esta consulta):
{$contexto}";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice((array)$historial, -10) as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) continue;
            $messages[] = ['role' => $msg['role'], 'content' => (string)$msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $mensaje];

        $payload = json_encode([
            'model'       => 'llama-3.3-70b-versatile',
            'messages'    => $messages,
            'max_tokens'  => 1500,
            'temperature' => 0.7,
        ]);

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) return $this->error($res, "Error de conexión con Groq: {$curlErr}", 503);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            return $this->error($res, "Error de Groq: {$errMsg}", 503);
        }

        $texto = $data['choices'][0]['message']['content'] ?? 'Sin respuesta de la IA';

        return $this->ok($res, [
            'respuesta' => $texto,
            'tokens'    => $data['usage'] ?? null,
            'modulo'    => $modulo,
        ]);
    }

    // ── Enriquecimiento dinámico según la consulta del usuario ────────────────
    private function _enrichFromQuery(string $msg, int $eId, int $sId): string
    {
        $extra  = '';
        $msgLow = mb_strtolower($msg, 'UTF-8');

        $stopwords = ['que', 'hay', 'del', 'los', 'las', 'con', 'para', 'por', 'una', 'uno',
                      'como', 'esta', 'tiene', 'inventario', 'stock', 'referencia', 'referencias',
                      'producto', 'productos', 'palabra', 'actualmente', 'disponible', 'cuantos',
                      'cuanto', 'tenemos', 'dame', 'muestra', 'consulta', 'busca', 'ver', 'listar',
                      'total', 'todas', 'todos', 'cuales', 'cual', 'cuando', 'donde', 'quien',
                      'activo', 'activa', 'actual', 'hoy', 'dia', 'mes', 'año'];

        // Extraer todos los términos candidatos ≥3 chars no stopword
        $terminos = array_values(array_unique(array_filter(
            preg_split('/\s+/', $msgLow),
            fn($p) => mb_strlen($p, 'UTF-8') >= 3 && !in_array($p, $stopwords)
        )));

        // Buscar cada término en productos con ILIKE (nativo PostgreSQL, sin extensiones)
        foreach ($terminos as $termino) {
            try {
                // Buscar con stock
                $conStock = Capsule::table('inventarios as i')
                    ->join('productos as p', 'p.id', '=', 'i.producto_id')
                    ->where('i.empresa_id', $eId)->where('i.sucursal_id', $sId)
                    ->where('i.cantidad', '>', 0)
                    ->where(fn($q) => $q->whereRaw('p.nombre ILIKE ?', ["%{$termino}%"])
                                       ->orWhereRaw('p.codigo_interno ILIKE ?', ["%{$termino}%"]))
                    ->selectRaw('p.codigo_interno, p.nombre, p.unidades_caja, SUM(i.cantidad) as total, SUM(i.cantidad_reservada) as reservado')
                    ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'p.unidades_caja')
                    ->orderByDesc('total')->limit(15)->get();

                if ($conStock->isNotEmpty()) {
                    $lineas = $conStock->map(function ($p) {
                        $disp  = (float)$p->total - (float)$p->reservado;
                        $upc   = max(1, (int)($p->unidades_caja ?? 1));
                        $cajas = $upc > 1 ? ' (' . round($p->total / $upc, 1) . ' cj)' : '';
                        return "[{$p->codigo_interno}] {$p->nombre}: {$p->total} und{$cajas} — disponible={$disp} und, reservado={$p->reservado} und";
                    })->implode('; ');
                    $extra .= "\nSTOCK_BUSQUEDA(\"{$termino}\"): {$lineas}";
                    break;
                }

                // Sin stock pero el producto existe
                $sinStock = Capsule::table('productos as p')
                    ->where(fn($q) => $q->whereRaw('p.nombre ILIKE ?', ["%{$termino}%"])
                                       ->orWhereRaw('p.codigo_interno ILIKE ?', ["%{$termino}%"]))
                    ->select('p.codigo_interno', 'p.nombre')->limit(5)->get();

                if ($sinStock->isNotEmpty()) {
                    $lineas = $sinStock->map(fn($p) => "[{$p->codigo_interno}] {$p->nombre}: sin stock disponible")->implode('; ');
                    $extra .= "\nSTOCK_BUSQUEDA(\"{$termino}\"): {$lineas}";
                    break;
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        // Faltantes detallados
        if (preg_match('/\bfaltante/i', $msg)) {
            try {
                $falt = Capsule::table('picking_faltantes as pf')
                    ->join('productos as p', 'p.id', '=', 'pf.producto_id')
                    ->where('pf.empresa_id', $eId)->where('pf.sucursal_id', $sId)
                    ->select('p.codigo_interno', 'p.nombre', 'pf.cantidad_faltante', 'pf.created_at')
                    ->orderByDesc('pf.created_at')->limit(20)->get();
                if ($falt->isNotEmpty()) {
                    $lineas = $falt->map(fn($f) =>
                        "[{$f->codigo_interno}] {$f->nombre}: {$f->cantidad_faltante} und desde " . date('d/m/Y', strtotime($f->created_at))
                    )->implode('; ');
                    $extra .= "\nLISTA_FALTANTES_DETALLE: {$lineas}";
                } else {
                    $extra .= "\nLISTA_FALTANTES_DETALLE: sin faltantes activos";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        // Vencimientos detallados
        if (preg_match('/\b(venc|caduci|expir)/i', $msg)) {
            try {
                $hoy  = date('Y-m-d');
                $venc = Capsule::table('inventarios as i')
                    ->join('productos as p', 'p.id', '=', 'i.producto_id')
                    ->where('i.empresa_id', $eId)->where('i.sucursal_id', $sId)
                    ->where('i.cantidad', '>', 0)->whereNotNull('i.fecha_vencimiento')
                    ->where('i.fecha_vencimiento', '<=', date('Y-m-d', strtotime('+30 days')))
                    ->selectRaw('p.codigo_interno, p.nombre, SUM(i.cantidad) as qty, MIN(i.fecha_vencimiento) as fv')
                    ->groupBy('p.id', 'p.codigo_interno', 'p.nombre')
                    ->orderBy('fv')->limit(20)->get();
                if ($venc->isNotEmpty()) {
                    $lineas = $venc->map(function ($v) use ($hoy) {
                        $dias = (int)((strtotime($v->fv) - strtotime($hoy)) / 86400);
                        $tag  = $dias < 0 ? 'VENCIDO hace ' . abs($dias) . ' días' : "vence en {$dias} días";
                        return "[{$v->codigo_interno}] {$v->nombre}: {$v->qty} und — {$tag} (fecha: " . date('d/m/Y', strtotime($v->fv)) . ')';
                    })->implode('; ');
                    $extra .= "\nDETALLE_VENCIMIENTOS_30DIAS: {$lineas}";
                } else {
                    $extra .= "\nDETALLE_VENCIMIENTOS_30DIAS: ninguno en los próximos 30 días";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        // Planilla específica
        if (preg_match('/planilla\s+([A-Z0-9\-]+)/i', $msg, $m)) {
            try {
                $plan = Capsule::table('orden_pickings as op')
                    ->join('picking_detalles as pd', 'pd.orden_picking_id', '=', 'op.id')
                    ->join('productos as p', 'p.id', '=', 'pd.producto_id')
                    ->where('op.empresa_id', $eId)->where('op.sucursal_id', $sId)
                    ->where('op.planilla_numero', $m[1])
                    ->select('op.estado', 'p.nombre', 'pd.cantidad_solicitada', 'pd.estado as det_estado')
                    ->limit(20)->get();
                if ($plan->isNotEmpty()) {
                    $lineas = $plan->map(fn($d) => "{$d->nombre}: {$d->cantidad_solicitada} cj ({$d->det_estado})")->implode('; ');
                    $extra .= "\nDETALLE_PLANILLA({$m[1]}): estado={$plan->first()->estado}, items: {$lineas}";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        return $extra;
    }

    // ── Contexto operativo en tiempo real ─────────────────────────────────────
    private function _buildContexto(int $empresaId, int $sucursalId, string $modulo): string
    {
        $hoy  = date('Y-m-d');
        $hora = date('d/m/Y H:i');

        try {
            $inv = Capsule::table('inventarios')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->selectRaw('COALESCE(SUM(cantidad),0) as total, COALESCE(SUM(cantidad_reservada),0) as reservado, COUNT(DISTINCT producto_id) as productos, COUNT(DISTINCT ubicacion_id) as ubicaciones')
                ->first();

            $pk = Capsule::table('orden_pickings')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->selectRaw("
                    COUNT(CASE WHEN estado='Pendiente' THEN 1 END) as pend,
                    COUNT(CASE WHEN estado='EnProceso' THEN 1 END) as proc,
                    COUNT(CASE WHEN estado='Completada' AND DATE(updated_at)='{$hoy}' THEN 1 END) as hoy_comp
                ")->first();

            $faltantes = Capsule::table('picking_faltantes')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->count();

            $devPend = Capsule::table('devoluciones')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->where('estado', 'Pendiente')->count();

            $movHoy = Capsule::table('movimiento_inventarios')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->where('fecha_movimiento', $hoy)->count();

            $vencidos = Capsule::table('inventarios')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->where('cantidad', '>', 0)->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<', $hoy)->count();

            $vencen15 = Capsule::table('inventarios')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->where('cantidad', '>', 0)->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '>=', $hoy)
                ->where('fecha_vencimiento', '<=', date('Y-m-d', strtotime('+15 days')))->count();

            $movResumen = Capsule::table('movimiento_inventarios')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->where('fecha_movimiento', $hoy)
                ->selectRaw('tipo_movimiento, COUNT(*) as cnt, COALESCE(SUM(cantidad),0) as total')
                ->groupBy('tipo_movimiento')->get()
                ->map(fn($m) => "{$m->tipo_movimiento}: {$m->cnt} mov ({$m->total} und)")->implode(', ');

            $topStock = Capsule::table('inventarios as i')
                ->join('productos as p', 'p.id', '=', 'i.producto_id')
                ->where('i.empresa_id', $empresaId)->where('i.sucursal_id', $sucursalId)
                ->where('i.cantidad', '>', 0)
                ->selectRaw('p.codigo_interno, p.nombre, SUM(i.cantidad) as qty')
                ->groupBy('p.id', 'p.codigo_interno', 'p.nombre')
                ->orderByDesc('qty')->limit(5)->get()
                ->map(fn($p) => "[{$p->codigo_interno}] {$p->nombre}: {$p->qty} und")->implode('; ');

            $disp = (float)$inv->total - (float)$inv->reservado;

            $ctx  = "Fecha/hora: {$hora}\n";
            $ctx .= "INVENTARIO: total={$inv->total} und, disponible={$disp} und, reservado={$inv->reservado} und, productos_con_stock={$inv->productos}, ubicaciones_ocupadas={$inv->ubicaciones}\n";
            $ctx .= "TOP_PRODUCTOS_POR_STOCK: {$topStock}\n";
            $ctx .= "PICKING: ordenes_pendientes={$pk->pend}, en_proceso={$pk->proc}, completadas_hoy={$pk->hoy_comp}, faltantes_activos={$faltantes}\n";
            $ctx .= "MOVIMIENTOS_HOY: total={$movHoy}" . ($movResumen ? ", detalle={$movResumen}" : '') . "\n";
            $ctx .= "DEVOLUCIONES_PENDIENTES: {$devPend}\n";
            $ctx .= "VENCIMIENTOS: lotes_vencidos_con_stock={$vencidos}, lotes_vencen_proximos_15dias={$vencen15}\n";

            if ($modulo === 'picking') {
                $planillas = Capsule::table('orden_pickings')
                    ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->selectRaw("planilla_numero, estado, COUNT(*) as ordenes, MAX(fecha_requerida::text) as fecha_req")
                    ->groupBy('planilla_numero', 'estado')->orderBy('planilla_numero')->limit(10)->get()
                    ->map(fn($p) => "Planilla {$p->planilla_numero}: {$p->ordenes} órdenes estado={$p->estado} sep=" . ($p->fecha_req ? date('d/m/Y', strtotime($p->fecha_req)) : '—'))
                    ->implode('; ');
                $ctx .= "PLANILLAS_ACTIVAS: {$planillas}\n";
            } elseif ($modulo === 'despacho') {
                $sesiones = Capsule::table('packing_sesiones')
                    ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                    ->selectRaw('estado, COUNT(*) as cnt')->groupBy('estado')->get()
                    ->map(fn($s) => "{$s->estado}:{$s->cnt}")->implode(', ');
                $ctx .= "SESIONES_PACKING: {$sesiones}\n";
            } elseif ($modulo === 'devoluciones') {
                $devs = Capsule::table('devoluciones')
                    ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                    ->selectRaw('estado, COUNT(*) as cnt')->groupBy('estado')->get()
                    ->map(fn($d) => "{$d->estado}:{$d->cnt}")->implode(', ');
                $ctx .= "DEVOLUCIONES_POR_ESTADO: {$devs}\n";
            } elseif ($modulo === 'inventario') {
                $ubicOcup = Capsule::table('inventarios')
                    ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                    ->where('cantidad', '>', 0)->distinct()->count('ubicacion_id');
                $ctx .= "UBICACIONES_OCUPADAS: {$ubicOcup}\n";
            }

            return $ctx;
        } catch (\Throwable $e) {
            return "Fecha/hora: {$hora}\n[Error al obtener contexto del WMS: {$e->getMessage()}]";
        }
    }
}
