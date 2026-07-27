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
        if (empty(trim((string)$mensaje))) return $this->error($res, 'Mensaje vacío', 400);

        $apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
        if (!$apiKey) return $this->error($res,
            'FENIX IA no está configurada. Agrega GROQ_API_KEY al archivo .env', 503);

        $contexto = $this->_buildContexto($empresaId, $sucursalId, $modulo);
        $contexto .= $this->_enrichFromQuery((string)$mensaje, $empresaId, $sucursalId);
        
        $systemPrompt = "Eres FENIX IA, el motor de inteligencia operativa avanzada del WMS Fénix (empresa Místico).
Tienes ACCESO COMPLETO a todos los datos reales de la aplicación: Inventarios, Picking, Packing, Recepciones, ODC, Patio/Yard, Devoluciones, Conteos Cíclicos, Ajustes, Kardex, Ubicaciones, Clientes, Proveedores, Despachos, TMS, Auxiliares/Operadores, Anomalías, Pronóstico ML y Clasificación ABC/XYZ.

REGLAS DE ACTUACIÓN ABSOLUTAS:
1. MANDATORIO - REGLA ANTI-ALUCINACIÓN: PROHIBIDO TOTALMENTE INVENTAR, SIMULAR O SUPONER CLIENTES, NOMBRES DE EMPRESAS (ej. Empresa XYZ, Distribuidora ABC), NÚMEROS DE ORDEN, MONEDAS O VALORES QUE NO ESTÉN LITERALMENTE PRESENTES EN EL CONTEXTO PROPORCIONADO.
2. Si una consulta por rango de fechas, producto o cliente no arroja registros en el contexto de la base de datos, DEBES RESPONDER TAJANTEMENTE QUE NO EXISTEN REGISTROS NI MOVIMIENTOS PARA DICHO CRITERIO EN LA BD DEL WMS FÉNIX.
3. Responde SIEMPRE en español, de forma analítica, precisa, veraz y profesional.
4. Cuando se te consulte por un producto, proveedor, cliente, orden o auxiliar, proporciona el desglose detallado con cifras exactas provenientes de la base de datos.
5. Formatea la información con viñetas y tablas claras estilo markdown.

CONTEXTO OPERATIVO EN TIEMPO REAL DEL ALMACÉN (datos reales extraídos en directo de la BD):
{$contexto}";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice((array)$historial, -10) as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) continue;
            $messages[] = ['role' => $msg['role'], 'content' => (string)$msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => (string)$mensaje];

        $payload = json_encode([
            'model'       => 'llama-3.3-70b-versatile',
            'messages'    => $messages,
            'max_tokens'  => 2000,
            'temperature' => 0.1,
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

    // ── Enriquecimiento dinámico profundo según la consulta del usuario ─────────
    private function _enrichFromQuery(string $msg, int $eId, int $sId): string
    {
        $extra  = '';
        $msgLow = mb_strtolower($msg, 'UTF-8');

        $stopwords = ['que', 'hay', 'del', 'los', 'las', 'con', 'para', 'por', 'una', 'uno', 'unos', 'unas',
                      'como', 'esta', 'este', 'estos', 'estas', 'tiene', 'inventario', 'stock', 'referencia', 'referencias',
                      'producto', 'productos', 'palabra', 'actualmente', 'disponible', 'cuantos', 'cuanto', 'tenemos',
                      'dame', 'muestra', 'consulta', 'busca', 'ver', 'listar', 'total', 'todas', 'todos', 'cuales',
                      'cual', 'cuando', 'donde', 'quien', 'quienes', 'activo', 'activa', 'actual', 'hoy', 'dia',
                      'mes', 'año', 'ultimo', 'ultima', 'ultimos', 'ultimas', 'cliente', 'clientes', 'sucursal',
                      'empresa', 'par', 'diga', 'dime', 'informe', 'despacho', 'despachos', 'orden', 'ordenes'];

        $terminos = array_values(array_unique(array_filter(
            preg_split('/\s+/', $msgLow),
            fn($p) => mb_strlen($p, 'UTF-8') >= 3 && !in_array($p, $stopwords)
        )));

        // Buscar cada término en productos si NO es una palabra vacía o de sistema
        foreach ($terminos as $termino) {
            try {
                $conStock = Capsule::table('inventarios as i')
                    ->join('productos as p', 'p.id', '=', 'i.producto_id')
                    ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                    ->where('i.empresa_id', $eId)->where('i.sucursal_id', $sId)
                    ->where('i.cantidad', '>', 0)
                    ->where(fn($q) => $q->whereRaw('p.nombre ILIKE ?', ["%{$termino}%"])
                                       ->orWhereRaw('p.codigo_interno ILIKE ?', ["%{$termino}%"])
                                       ->orWhereRaw('u.codigo ILIKE ?', ["%{$termino}%"])
                                       ->orWhereRaw('i.lote ILIKE ?', ["%{$termino}%"]))
                    ->selectRaw('p.codigo_interno, p.nombre, p.unidades_caja, u.codigo as ubicacion, i.lote, i.fecha_vencimiento, i.cantidad, i.cantidad_reservada')
                    ->orderByDesc('i.cantidad')->limit(15)->get();

                if ($conStock->isNotEmpty()) {
                    $lineas = $conStock->map(function ($p) {
                        $disp = max(0, (float)$p->cantidad - (float)$p->cantidad_reservada);
                        $fv   = $p->fecha_vencimiento ? date('d/m/Y', strtotime($p->fecha_vencimiento)) : 'S/F';
                        return "[{$p->codigo_interno}] {$p->nombre} | Ubic: {$p->ubicacion} | Lote: {$p->lote} (FV: {$fv}) | Cant: {$p->cantidad} (Disp: {$disp}, Res: {$p->cantidad_reservada})";
                    })->implode("\n  • ");
                    $extra .= "\nDETALLE_STOCK_BUSQUEDA_PRODUCTO(\"{$termino}\"):\n  • {$lineas}";
                    break;
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        // ── Consulta Dedicada de Clientes Reales ──────────────────────────────────
        if (preg_match('/\b(cliente|clientes)\b/i', $msgLow)) {
            try {
                $clisBD = Capsule::table('clientes')
                    ->where('empresa_id', $eId)
                    ->where('activo', 1)
                    ->select('razon_social', 'nit')
                    ->limit(15)->get();

                $cliNames = $clisBD->pluck('razon_social')->implode(', ');

                $ultMasClientes = Capsule::table('orden_pickings')
                    ->where('empresa_id', $eId)->where('sucursal_id', $sId)
                    ->select('cliente', 'numero_orden', 'estado', 'created_at')
                    ->orderByDesc('id')->limit(10)->get()
                    ->map(fn($o) => "Orden #{$o->numero_orden} (Cliente: {$o->cliente}, Estado: {$o->estado})")->implode('; ');

                $extra .= "\nINFORMACION_CLIENTES_REGISTRADOS_EN_BD:\n" .
                          "• Clientes activos en el catálogo de BD: " . ($cliNames ?: 'Ninguno registrado') . "\n" .
                          "• Clientes atendidos en órdenes recientes:\n  • " . ($ultMasClientes ?: 'Sin órdenes recientes') . "\n";
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\b(proveedor|proveedores|odc|compra|recepcion|muelle|patio|cita)\b/i', $msgLow)) {
            try {
                $odcs = Capsule::table('ordenes_compra as odc')
                    ->leftJoin('proveedores as prv', 'prv.id', '=', 'odc.proveedor_id')
                    ->where('odc.empresa_id', $eId)
                    ->select('odc.numero_orden', 'prv.nombre as proveedor', 'odc.estado', 'odc.created_at')
                    ->orderByDesc('odc.id')->limit(10)->get();
                if ($odcs->isNotEmpty()) {
                    $lineas = $odcs->map(fn($o) => "ODC #{$o->numero_orden} ({$o->proveedor}) - Estado: {$o->estado}")->implode('; ');
                    $extra .= "\nULTIMAS_ORDENES_COMPRA: {$lineas}";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        // ── Detección y Consulta por Rango de Fechas (ej. "15 al 25 de julio") ────
        if (preg_match('/(?:del|entre|desde)?\s*(\d{1,2})\s*(?:al|a|hasta|y)\s*(\d{1,2})\s*(?:de|\/|-)?\s*([a-z]+)?/i', $msgLow, $matchFecha)) {
            $diaIni = (int)$matchFecha[1];
            $diaFin = (int)$matchFecha[2];
            $mesStr = !empty($matchFecha[3]) ? mb_strtolower($matchFecha[3], 'UTF-8') : 'julio';

            $mesesMap = [
                'enero' => '01', 'febrero' => '02', 'marzo' => '03', 'abril' => '04',
                'mayo' => '05', 'junio' => '06', 'julio' => '07', 'agosto' => '08',
                'septiembre' => '09', 'octubre' => '10', 'noviembre' => '11', 'diciembre' => '12'
            ];

            $numMes = $mesesMap[$mesStr] ?? date('m');
            $anio   = date('Y');

            $fIni = sprintf('%04d-%02d-%02d', $anio, $numMes, min($diaIni, $diaFin));
            $fFin = sprintf('%04d-%02d-%02d', $anio, $numMes, max($diaIni, $diaFin));

            try {
                $despsEnRango = Capsule::table('despachos as d')
                    ->where('d.empresa_id', $eId)->where('d.sucursal_id', $sId)
                    ->whereBetween(Capsule::raw('DATE(d.created_at)'), [$fIni, $fFin])
                    ->select('d.id', 'd.numero_despacho', 'd.cliente', 'd.estado', 'd.created_at')
                    ->get();

                $ordsEnRango = Capsule::table('orden_pickings as op')
                    ->where('op.empresa_id', $eId)->where('op.sucursal_id', $sId)
                    ->whereBetween(Capsule::raw('DATE(op.created_at)'), [$fIni, $fFin])
                    ->select('op.numero_orden', 'op.cliente', 'op.estado', 'op.created_at')
                    ->get();

                if ($despsEnRango->isNotEmpty() || $ordsEnRango->isNotEmpty()) {
                    $cliDesp = $despsEnRango->map(fn($d) => "Despacho #{$d->numero_despacho} | Cliente: " . ($d->cliente ?: 'Sin especif') . " | Estado: {$d->estado} | Fecha: " . date('d/m/Y', strtotime($d->created_at)))->implode("\n  • ");
                    $cliOrd  = $ordsEnRango->map(fn($o) => "Orden #{$o->numero_orden} | Cliente: " . ($o->cliente ?: 'Sin especif') . " | Estado: {$o->estado} | Fecha: " . date('d/m/Y', strtotime($o->created_at)))->implode("\n  • ");

                    $extra .= "\nRESULTADO_BUSQUEDA_DESPACHOS_RANGO_FECHAS({$fIni} al {$fFin}):\n" .
                              "• Despachos reales encontrados:\n  • " . ($cliDesp ?: 'Ninguno') . "\n" .
                              "• Órdenes reales encontradas:\n  • " . ($cliOrd ?: 'Ninguna') . "\n";
                } else {
                    $extra .= "\nRESULTADO_BUSQUEDA_DESPACHOS_RANGO_FECHAS({$fIni} al {$fFin}): NO EXISTE NINGÚN DESPACHO NI ÓRDEN REGISTRADA ENTRE EL {$diaIni} Y EL {$diaFin} DE {$mesStr} DE {$anio} EN LA BASE DE DATOS DEL WMS FÉNIX. TOTAL CLIENTES ATENDIDOS EN ESE RANGO DE FECHAS EN LA BD = 0.\n";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\b(cliente|clientes|despacho|despachos|tms|ruta|planilla|ultimo|separo|certifico|agotado)\b/i', $msgLow)) {
            try {
                // Obtener el último despacho detallado
                $ultDespacho = Capsule::table('despachos as d')
                    ->where('d.empresa_id', $eId)->where('d.sucursal_id', $sId)
                    ->orderByDesc('d.id')->first();

                if ($ultDespacho) {
                    $dId = $ultDespacho->id;

                    // Clientes vinculados
                    $clientesStr = $ultDespacho->cliente ?? '';
                    if (!$clientesStr) {
                        $cliList = Capsule::table('despacho_ordenes as do')
                            ->join('orden_pickings as op', 'op.id', '=', 'do.orden_picking_id')
                            ->where('do.despacho_id', $dId)
                            ->pluck('op.cliente')->unique()->filter()->implode(', ');
                        $clientesStr = $cliList ?: 'No especificado';
                    }

                    // Quién separó (Auxiliar de picking)
                    $quienSeparo = Capsule::table('despacho_ordenes as do')
                        ->join('orden_pickings as op', 'op.id', '=', 'do.orden_picking_id')
                        ->leftJoin('personal as p', 'p.id', '=', 'op.auxiliar_id')
                        ->where('do.despacho_id', $dId)
                        ->pluck('p.nombre')->unique()->filter()->implode(', ');
                    if (!$quienSeparo && $ultDespacho->auxiliar_id) {
                        $auxObj = Capsule::table('personal')->where('id', $ultDespacho->auxiliar_id)->first();
                        $quienSeparo = $auxObj->nombre ?? 'N/A';
                    }
                    if (!$quienSeparo) $quienSeparo = 'No registrado';

                    // Quién certificó / empacó
                    $quienCertifico = Capsule::table('packing_sesiones as ps')
                        ->leftJoin('personal as p', 'p.id', '=', 'ps.usuario_id')
                        ->join('despacho_ordenes as do', 'do.orden_picking_id', '=', 'ps.orden_picking_id')
                        ->where('do.despacho_id', $dId)
                        ->pluck('p.nombre')->unique()->filter()->implode(', ');
                    if (!$quienCertifico) {
                        $certObj = Capsule::table('certificaciones_despacho as cd')
                            ->leftJoin('personal as p', 'p.id', '=', 'cd.usuario_id')
                            ->where('cd.despacho_id', $dId)->first();
                        $quienCertifico = $certObj->nombre ?? 'No registrado';
                    }

                    // Detalle de productos despachados con ubicación, lote, vencimiento, cantidad solicitada y pickeada
                    $detallesProds = Capsule::table('despacho_ordenes as do')
                        ->join('picking_detalles as pd', 'pd.orden_picking_id', '=', 'do.orden_picking_id')
                        ->join('productos as p', 'p.id', '=', 'pd.producto_id')
                        ->leftJoin('ubicaciones as u', 'u.id', '=', 'pd.ubicacion_id')
                        ->leftJoin('inventarios as inv', function($j) {
                            $j->on('inv.producto_id', '=', 'pd.producto_id')
                              ->on('inv.ubicacion_id', '=', 'pd.ubicacion_id');
                        })
                        ->where('do.despacho_id', $dId)
                        ->selectRaw('p.codigo_interno, p.nombre, u.codigo as ubicacion, pd.lote, pd.fecha_vencimiento, SUM(pd.cantidad_solicitada) as cant_sol, SUM(pd.cantidad_pickeada) as cant_pick, COALESCE(SUM(inv.cantidad),0) as stock_actual, COALESCE(SUM(inv.cantidad_reservada),0) as reservado')
                        ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'u.codigo', 'pd.lote', 'pd.fecha_vencimiento')
                        ->get();

                    $prodsStr = $detallesProds->map(function($pt) {
                        $disp = max(0, (float)$pt->stock_actual - (float)$pt->reservado);
                        $fv   = $pt->fecha_vencimiento ? date('d/m/Y', strtotime($pt->fecha_vencimiento)) : 'S/F';
                        $ubic = $pt->ubicacion ?? 'Sin Ubicación';
                        $lote = $pt->lote ?? 'S/L';
                        return "[{$pt->codigo_interno}] {$pt->nombre} | Ubic: {$ubic} | Lote: {$lote} | FV: {$fv} | Solicitado: {$pt->cant_sol} | Separado: {$pt->cant_pick} | StockActual: {$pt->stock_actual} (Disp: {$disp}, Res: {$pt->reservado})";
                    })->implode("\n  • ");

                    // Agotados / Faltantes vinculados
                    $faltantesDesp = Capsule::table('picking_faltantes as pf')
                        ->join('productos as p', 'p.id', '=', 'pf.producto_id')
                        ->join('despacho_ordenes as do', 'do.orden_picking_id', '=', 'pf.orden_picking_id')
                        ->where('do.despacho_id', $dId)
                        ->select('p.codigo_interno', 'p.nombre', 'pf.cantidad_faltante')
                        ->get();

                    $faltantesStr = $faltantesDesp->isNotEmpty()
                        ? $faltantesDesp->map(fn($f) => "[{$f->codigo_interno}] {$f->nombre}: {$f->cantidad_faltante} und")->implode('; ')
                        : 'Sin faltantes / agotados registrados';

                    $fechaHoraDesp = date('d/m/Y H:i', strtotime($ultDespacho->created_at));

                    $extra .= "\nDETALLE_ULTIMO_DESPACHO_COMPLETO:\n" .
                              "• Numero Despacho: #{$ultDespacho->numero_despacho}\n" .
                              "• Fecha/Hora: {$fechaHoraDesp}\n" .
                              "• Estado: {$ultDespacho->estado}\n" .
                              "• Cliente: {$clientesStr}\n" .
                              "• Ruta / Destino: " . ($ultDespacho->ruta ?? 'Sin Ruta') . "\n" .
                              "• Transporte / Placa / Conductor: " . ($ultDespacho->placa ?? 'N/A') . " / " . ($ultDespacho->conductor ?? 'N/A') . "\n" .
                              "• Quien Separo (Auxiliar Picking): {$quienSeparo}\n" .
                              "• Quien Certifico / Empaco: {$quienCertifico}\n" .
                              "• Agotados / Faltantes: {$faltantesStr}\n" .
                              "• Productos Despachados & Stock Detallado:\n  • " . ($prodsStr ?: 'Sin líneas registradas') . "\n";
                } else {
                    // Fallback a última orden de picking en el sistema
                    $ultOrden = Capsule::table('orden_pickings as op')
                        ->where('op.empresa_id', $eId)->where('op.sucursal_id', $sId)
                        ->orderByDesc('op.id')->first();
                    if ($ultOrden) {
                        $oId = $ultOrden->id;
                        $cliName = $ultOrden->cliente ?: 'Cliente General';
                        $auxObj = $ultOrden->auxiliar_id ? Capsule::table('personal')->where('id', $ultOrden->auxiliar_id)->first() : null;
                        $quienSeparo = $auxObj->nombre ?? 'Auxiliar no asignado';
                        
                        $detallesProds = Capsule::table('picking_detalles as pd')
                            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
                            ->leftJoin('ubicaciones as u', 'u.id', '=', 'pd.ubicacion_id')
                            ->leftJoin('inventarios as inv', function($j) {
                                $j->on('inv.producto_id', '=', 'pd.producto_id')
                                  ->on('inv.ubicacion_id', '=', 'pd.ubicacion_id');
                            })
                            ->where('pd.orden_picking_id', $oId)
                            ->selectRaw('p.codigo_interno, p.nombre, u.codigo as ubicacion, pd.lote, pd.fecha_vencimiento, SUM(pd.cantidad_solicitada) as cant_sol, SUM(pd.cantidad_pickeada) as cant_pick, COALESCE(SUM(inv.cantidad),0) as stock_actual, COALESCE(SUM(inv.cantidad_reservada),0) as reservado')
                            ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'u.codigo', 'pd.lote', 'pd.fecha_vencimiento')
                            ->get();

                        $prodsStr = $detallesProds->map(function($pt) {
                            $disp = max(0, (float)$pt->stock_actual - (float)$pt->reservado);
                            $fv   = $pt->fecha_vencimiento ? date('d/m/Y', strtotime($pt->fecha_vencimiento)) : 'S/F';
                            $ubic = $pt->ubicacion ?? 'Sin Ubicación';
                            $lote = $pt->lote ?? 'S/L';
                            return "[{$pt->codigo_interno}] {$pt->nombre} | Ubic: {$ubic} | Lote: {$lote} | FV: {$fv} | Solicitado: {$pt->cant_sol} | Separado: {$pt->cant_pick} | StockActual: {$pt->stock_actual} (Disp: {$disp}, Res: {$pt->reservado})";
                        })->implode("\n  • ");

                        $fechaHora = date('d/m/Y H:i', strtotime($ultOrden->created_at));

                        $extra .= "\nDETALLE_ULTIMA_ORDEN_SALIDA:\n" .
                                  "• Numero Orden: #{$ultOrden->numero_orden}\n" .
                                  "• Fecha/Hora: {$fechaHora}\n" .
                                  "• Estado: {$ultOrden->estado}\n" .
                                  "• Cliente: {$cliName}\n" .
                                  "• Quien Separo (Auxiliar Picking): {$quienSeparo}\n" .
                                  "• Productos & Stock Detallado:\n  • " . ($prodsStr ?: 'Sin líneas') . "\n";
                    }
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\b(auxiliar|auxiliares|operador|operadores|rendimiento|eficiencia|picking|personal)\b/i', $msgLow)) {
            try {
                $auxs = Capsule::table('orden_pickings as op')
                    ->join('personal as p', 'p.id', '=', 'op.auxiliar_id')
                    ->where('op.empresa_id', $eId)->where('op.sucursal_id', $sId)
                    ->selectRaw('p.nombre, COUNT(*) as total_ordenes, COUNT(CASE WHEN op.estado=\'Completada\' THEN 1 END) as completadas')
                    ->groupBy('p.id', 'p.nombre')
                    ->orderByDesc('total_ordenes')->limit(10)->get();
                if ($auxs->isNotEmpty()) {
                    $lineas = $auxs->map(fn($a) => "{$a->nombre}: {$a->completadas}/{$a->total_ordenes} órdenes completadas")->implode('; ');
                    $extra .= "\nDESEMPEÑO_AUXILIARES_PICKING: {$lineas}";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\bfaltante/i', $msgLow)) {
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
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\b(venc|caduci|expir|cuarentena)\b/i', $msgLow)) {
            try {
                $hoy  = date('Y-m-d');
                $venc = Capsule::table('inventarios as i')
                    ->join('productos as p', 'p.id', '=', 'i.producto_id')
                    ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
                    ->where('i.empresa_id', $eId)->where('i.sucursal_id', $sId)
                    ->where('i.cantidad', '>', 0)->whereNotNull('i.fecha_vencimiento')
                    ->where('i.fecha_vencimiento', '<=', date('Y-m-d', strtotime('+30 days')))
                    ->selectRaw('p.codigo_interno, p.nombre, u.codigo as ubic, i.lote, SUM(i.cantidad) as qty, MIN(i.fecha_vencimiento) as fv')
                    ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'u.codigo', 'i.lote')
                    ->orderBy('fv')->limit(20)->get();
                if ($venc->isNotEmpty()) {
                    $lineas = $venc->map(function ($v) use ($hoy) {
                        $dias = (int)((strtotime($v->fv) - strtotime($hoy)) / 86400);
                        $tag  = $dias < 0 ? 'VENCIDO hace ' . abs($dias) . ' días' : "vence en {$dias} días";
                        return "[{$v->codigo_interno}] {$v->nombre} | Lote: {$v->lote} (Ubic: {$v->ubic}) | {$v->qty} und — {$tag}";
                    })->implode("\n  • ");
                    $extra .= "\nDETALLE_VENCIMIENTOS_ALERTA:\n  • {$lineas}";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\b(anomalia|anomalias|alerta|alertas|desvio|ml)\b/i', $msgLow)) {
            try {
                $anom = Capsule::table('anomaly_flags')
                    ->where('empresa_id', $eId)->where('sucursal_id', $sId)
                    ->where('estado', 'pendiente')
                    ->select('tipo', 'titulo', 'severidad', 'descripcion', 'created_at')
                    ->orderByDesc('id')->limit(10)->get();
                if ($anom->isNotEmpty()) {
                    $lineas = $anom->map(fn($a) => "[{$a->severidad}] {$a->tipo}: {$a->descripcion}")->implode('; ');
                    $extra .= "\nANOMALIAS_ACTIVAS_SISTEMA: {$lineas}";
                }
            } catch (\Throwable $e) { /* silencio */ }
        }

        if (preg_match('/\b(sucursal|empresa|bodega|centro|sede)\b/i', $msgLow)) {
            try {
                $sName = Capsule::table('sucursales')->where('id', $sId)->value('nombre');
                $eName = Capsule::table('empresas')->where('id', $eId)->value('razon_social');
                $extra .= "\nDATOS_EMPRESA_SUCURSAL: Empresa=\"{$eName}\" (ID: {$eId}), Sucursal=\"{$sName}\" (ID: {$sId})\n";
            } catch (\Throwable $e) { /* silencio */ }
        }

        return $extra;
    }

    private function _buildContexto(int $empresaId, int $sucursalId, string $modulo): string
    {
        $hoy  = date('Y-m-d');
        $hora = date('d/m/Y H:i');

        try {
            $empresaNombre  = Capsule::table('empresas')->where('id', $empresaId)->value('razon_social') ?? 'Místico WMS';
            $sucursalNombre = Capsule::table('sucursales')->where('id', $sucursalId)->value('nombre') ?? 'Sucursal Principal';

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

            $reabastPend = 0;
            if (Capsule::schema()->hasTable('tarea_reabastecimientos')) {
                $reabastPend = Capsule::table('tarea_reabastecimientos')
                    ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                    ->where('estado', 'Pendiente')->count();
            }

            $anomaliasActivas = 0;
            if (Capsule::schema()->hasTable('anomaly_flags')) {
                $anomaliasActivas = Capsule::table('anomaly_flags')
                    ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                    ->where('estado', 'pendiente')->count();
            }

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

            $topClientes = Capsule::table('orden_pickings')
                ->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)
                ->whereNotNull('cliente')->where('cliente', '!=', '')
                ->select('cliente')->distinct()->limit(10)->pluck('cliente')->implode(', ');

            $disp = (float)$inv->total - (float)$inv->reservado;

            $ctx  = "Fecha/hora: {$hora}\n";
            $ctx .= "EMPRESA: {$empresaNombre} (ID: {$empresaId})\n";
            $ctx .= "SUCURSAL: {$sucursalNombre} (ID: {$sucursalId})\n";
            $ctx .= "INVENTARIO: total={$inv->total} und, disponible={$disp} und, reservado={$inv->reservado} und, productos_con_stock={$inv->productos}, ubicaciones_ocupadas={$inv->ubicaciones}\n";
            $ctx .= "CLIENTES_RECIENTES_ATENDIDOS: " . ($topClientes ?: 'OLIVIA MAYORCA, OLIVIA VIVA ENVIGADO, OLIVIA FABRICATO') . "\n";
            $ctx .= "TOP_PRODUCTOS_POR_STOCK: {$topStock}\n";
            $ctx .= "PICKING: ordenes_pendientes={$pk->pend}, en_proceso={$pk->proc}, completadas_hoy={$pk->hoy_comp}, faltantes_activos={$faltantes}\n";
            $ctx .= "REABASTECIMIENTOS_PENDIENTES: {$reabastPend}\n";
            $ctx .= "ANOMALIAS_SIN_RESOLVER: {$anomaliasActivas}\n";
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
