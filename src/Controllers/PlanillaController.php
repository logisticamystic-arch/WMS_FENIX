<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * PlanillaController — Gestión de archivos de planilla para certificación por cliente.
 *
 * Flujo:
 *  1. POST /api/planillas/importar   → Sube CSV/Excel, parsea y guarda lineas_planilla
 *  2. GET  /api/planillas            → Lista archivos importados
 *  3. GET  /api/planillas/{id}       → Cabecera + planillas agrupadas
 *  4. POST /api/planillas/cert/iniciar       → Inicia certificación de una planilla
 *  5. POST /api/planillas/cert/{id}/linea    → Auxiliar registra cantidad de un producto
 *  6. POST /api/planillas/cert/{id}/finalizar→ Finaliza la certificación
 *  7. GET  /api/planillas/cert/dashboard     → Dashboard admin/supervisor
 */
class PlanillaController extends BaseController
{
    // ── GET /api/planillas ────────────────────────────────────────────────────
    public function listar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $archivos = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->orderBy('created_at', 'desc')
            ->get();
        return $this->ok($res, $archivos);
    }

    // ── GET /api/planillas/{id} ───────────────────────────────────────────────
    public function ver(Request $r, Response $res, array $a): Response
    {
        $user    = $r->getAttribute('user');
        $archivo = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->find((int)$a['id']);
        if (!$archivo) return $this->notFound($res);

        // Agrupar planillas con totales
        $planillas = DB::table('lineas_planilla')
            ->where('archivo_id', $archivo->id)
            ->select(
                'numero_planilla',
                DB::raw('COUNT(*) as total_lineas'),
                DB::raw('SUM(cantidad) as total_unidades'),
                DB::raw('COUNT(DISTINCT producto_codigo) as total_productos'),
                DB::raw('SUM(valor_producto) as valor_total')
            )
            ->groupBy('numero_planilla')
            ->orderBy('numero_planilla')
            ->get();

        // Estado de certificación por planilla
        $certs = DB::table('cert_planillas')
            ->where('archivo_id', $archivo->id)
            ->select('numero_planilla', 'estado', 'auxiliar_id', 'hora_inicio', 'hora_fin')
            ->get()
            ->keyBy('numero_planilla');

        $planillasConEstado = $planillas->map(function ($p) use ($certs) {
            $cert = $certs[$p->numero_planilla] ?? null;
            $p->estado_cert = $cert ? $cert->estado : 'Pendiente';
            $p->cert_id     = $cert ? $cert->id ?? null : null;
            return $p;
        });

        return $this->ok($res, [
            'archivo'   => $archivo,
            'planillas' => $planillasConEstado,
        ]);
    }

    // ── POST /api/planillas/importar ─────────────────────────────────────────
    public function importar(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $files = $r->getUploadedFiles();
        $file  = $files['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'No se recibió archivo válido');
        }

        $originalName = $file->getClientFilename();
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx', 'xls'])) {
            return $this->error($res, 'Formato no soportado. Use CSV o Excel');
        }

        // Leer el archivo como texto CSV
        $content = $file->getStream()->getContents();
        // Detect BOM and encoding
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3); // Strip UTF-8 BOM
        }
        // Try to convert from Windows-1252 if needed
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r?\n/', trim($content));
        if (count($lines) < 2) {
            return $this->error($res, 'El archivo está vacío o no tiene datos');
        }

        // Detect delimiter (semicolon or comma)
        $delim = (substr_count($lines[0], ';') > substr_count($lines[0], ',')) ? ';' : ',';

        // Parse header row — find column indexes
        $header = array_map(fn($h) => mb_strtolower(trim(str_replace(['"', "'"], '', $h))), str_getcsv($lines[0], $delim));
        $colMap  = [
            'numero_factura'  => $this->_findCol($header, ['numero factura', 'factura', 'numero_factura', 'nrofactura']),
            'documento'       => $this->_findCol($header, ['documento', 'doc']),
            'numero_planilla' => $this->_findCol($header, ['planilla', 'numero planilla', 'nroplanilla', 'planilla #']),
            'asesor'          => $this->_findCol($header, ['asesor', 'vendedor', 'comercial']),
            'producto_nombre' => $this->_findCol($header, ['producto', 'nombre producto', 'descripcion', 'barra', 'nombre']),
            'producto_codigo' => $this->_findCol($header, ['codigo', 'cod', 'ref', 'referencia']),
            'cantidad'        => $this->_findCol($header, ['cantid', 'cantidad', 'cant', 'qty', 'unidades']),
            'costo'           => $this->_findCol($header, ['costo', 'cost', 'precio']),
            'descuento'       => $this->_findCol($header, ['descuento', 'dto', 'descto', 'disc']),
            'valor_producto'  => $this->_findCol($header, ['valor producto', 'valor', 'total', 'importe', 'vr producto']),
            'pedido'          => $this->_findCol($header, ['pedido', 'nro pedido', 'order', 'orden']),
        ];

        // Log detected headers for debugging
        if (function_exists('wmsLog')) {
            wmsLog('INFO', 'Planilla import: encabezados detectados', [
                'archivo' => $originalName,
                'delim'   => $delim === ';' ? 'punto_y_coma' : 'coma',
                'headers' => $header,
                'colMap'  => array_filter($colMap, fn($v) => $v !== null),
                'missing' => array_keys(array_filter($colMap, fn($v) => $v === null)),
            ]);
        }

        // Require at minimum: planilla, producto, cantidad
        if ($colMap['numero_planilla'] === null || $colMap['producto_nombre'] === null || $colMap['cantidad'] === null) {
            $faltantes = [];
            if ($colMap['numero_planilla'] === null) $faltantes[] = 'Planilla';
            if ($colMap['producto_nombre']  === null) $faltantes[] = 'Producto';
            if ($colMap['cantidad']         === null) $faltantes[] = 'Cantidad';
            $errMsg = 'Columnas requeridas no encontradas: ' . implode(', ', $faltantes)
                    . '. Encabezados detectados: ' . implode(' | ', $header);
            if (function_exists('wmsLog')) wmsLog('WARN', 'Planilla import rechazado: ' . $errMsg);
            return $this->error($res, $errMsg);
        }

        try {
            DB::beginTransaction();

            $now2 = date('Y-m-d H:i:s');
            $archivo = DB::table('archivos_planilla')->insertGetId([
                'empresa_id'      => $user->empresa_id,
                'sucursal_id'     => $user->sucursal_id,
                'nombre_archivo'  => $originalName,
                'total_lineas'    => 0,
                'total_planillas' => 0,
                'estado'          => 'Importada',
                'importado_por'   => $user->id,
                'created_at'      => $now2,
                'updated_at'      => $now2,
            ]);

            $lineas = [];
            $planillasSet = [];
            $now = date('Y-m-d H:i:s');

            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) continue;
                $cols = str_getcsv($line, $delim);
                $cols = array_map(fn($c) => trim(str_replace(['"'], '', $c)), $cols);

                $planilla = $colMap['numero_planilla'] !== null ? ($cols[$colMap['numero_planilla']] ?? '') : '';
                $producto = $colMap['producto_nombre'] !== null ? ($cols[$colMap['producto_nombre']] ?? '') : '';
                if (empty($planilla) || empty($producto)) continue;

                $planillasSet[$planilla] = true;
                $lineas[] = [
                    'archivo_id'      => $archivo,
                    'empresa_id'      => $user->empresa_id,
                    'sucursal_id'     => $user->sucursal_id,
                    'numero_factura'  => $colMap['numero_factura']  !== null ? ($cols[$colMap['numero_factura']]  ?? null) : null,
                    'documento'       => $colMap['documento']       !== null ? ($cols[$colMap['documento']]       ?? null) : null,
                    'numero_planilla' => $planilla,
                    'asesor'          => $colMap['asesor']          !== null ? ($cols[$colMap['asesor']]          ?? null) : null,
                    'producto_codigo' => $colMap['producto_codigo'] !== null ? ($cols[$colMap['producto_codigo']] ?? null) : null,
                    'producto_nombre' => $producto,
                    'cantidad'        => (float)str_replace([',', '$', ' '], ['', '', ''], $colMap['cantidad'] !== null ? ($cols[$colMap['cantidad']] ?? 0) : 0),
                    'costo'           => (float)str_replace([',', '$', ' '], ['', '', ''], $colMap['costo']     !== null ? ($cols[$colMap['costo']]     ?? 0) : 0),
                    'descuento'       => (float)str_replace([',', '$', ' '], ['', '', ''], $colMap['descuento'] !== null ? ($cols[$colMap['descuento']] ?? 0) : 0),
                    'valor_producto'  => (float)str_replace([',', '$', ' '], ['', '', ''], $colMap['valor_producto'] !== null ? ($cols[$colMap['valor_producto']] ?? 0) : 0),
                    'pedido'          => $colMap['pedido']          !== null ? ($cols[$colMap['pedido']]          ?? null) : null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];

                // Insert in batches of 200
                if (count($lineas) >= 200) {
                    DB::table('lineas_planilla')->insert($lineas);
                    $lineas = [];
                }
            }
            if (!empty($lineas)) {
                DB::table('lineas_planilla')->insert($lineas);
            }

            $totalLineas = DB::table('lineas_planilla')->where('archivo_id', $archivo)->count();
            $totalPlanillas = count($planillasSet);

            DB::table('archivos_planilla')->where('id', $archivo)->update([
                'total_lineas'    => $totalLineas,
                'total_planillas' => $totalPlanillas,
                'updated_at'      => $now,
            ]);

            DB::commit();

            $this->audit($user, 'picking', 'importar_planilla', 'archivos_planilla', $archivo,
                null, ['archivo' => $originalName, 'lineas' => $totalLineas, 'planillas' => $totalPlanillas],
                "Planilla '{$originalName}' importada: {$totalLineas} líneas, {$totalPlanillas} planillas");

            return $this->created($res, ['archivo_id' => $archivo, 'planillas' => $totalPlanillas, 'lineas' => $totalLineas],
                "Archivo importado: {$totalLineas} líneas en {$totalPlanillas} planillas");

        } catch (\Exception $e) {
            DB::rollBack();
            if (function_exists('wmsLog')) {
                wmsLog('ERROR', 'Planilla import falló: ' . $e->getMessage(), [
                    'archivo' => $originalName,
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
            return $this->error($res, 'Error al importar: ' . $e->getMessage());
        }
    }

    // ── POST /api/planillas/cert/iniciar ─────────────────────────────────────
    public function iniciarCertificacion(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $archivoId      = (int)($data['archivo_id'] ?? 0);
        $numeroPlanilla = trim($data['numero_planilla'] ?? '');
        if (!$archivoId || !$numeroPlanilla) {
            return $this->error($res, 'archivo_id y numero_planilla requeridos');
        }

        $archivo = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->find($archivoId);
        if (!$archivo) return $this->notFound($res, 'Archivo no encontrado');

        // Check existing active certification for this planilla
        $existing = DB::table('cert_planillas')
            ->where('archivo_id', $archivoId)
            ->where('numero_planilla', $numeroPlanilla)
            ->where('estado', 'EnProceso')
            ->first();
        if ($existing) {
            return $this->ok($res, $existing, 'Certificación ya en proceso');
        }

        // Get products for this planilla (summed)
        $productos = DB::table('lineas_planilla')
            ->where('archivo_id', $archivoId)
            ->where('numero_planilla', $numeroPlanilla)
            ->select('producto_codigo', 'producto_nombre', DB::raw('SUM(cantidad) as cantidad_esperada'))
            ->groupBy('producto_codigo', 'producto_nombre')
            ->orderBy('producto_nombre')
            ->get();

        if ($productos->isEmpty()) {
            return $this->error($res, 'No se encontraron productos para esta planilla');
        }

        try {
            DB::beginTransaction();

            $certId = DB::table('cert_planillas')->insertGetId([
                'empresa_id'      => $user->empresa_id,
                'sucursal_id'     => $user->sucursal_id,
                'archivo_id'      => $archivoId,
                'numero_planilla' => $numeroPlanilla,
                'auxiliar_id'     => $user->id,
                'estado'          => 'EnProceso',
                'fecha'           => date('Y-m-d'),
                'hora_inicio'     => date('H:i:s'),
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $detalles = $productos->map(fn($p) => [
                'cert_id'              => $certId,
                'producto_codigo'      => $p->producto_codigo,
                'producto_nombre'      => $p->producto_nombre,
                'cantidad_esperada'    => $p->cantidad_esperada,
                'cantidad_certificada' => 0,
                'es_correcto'          => false,
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
            ])->toArray();
            DB::table('cert_planilla_det')->insert($detalles);

            // Update archivo estado
            DB::table('archivos_planilla')->where('id', $archivoId)
                ->update(['estado' => 'EnCertificacion', 'updated_at' => date('Y-m-d H:i:s')]);

            DB::commit();

            $cert = DB::table('cert_planillas')->find($certId);
            $cert->detalles = DB::table('cert_planilla_det')
                ->where('cert_id', $certId)
                ->get()
                ->map(function ($d) use ($user) {
                    // Auxiliar NO ve cantidad esperada
                    if (!in_array($user->rol, ['Admin', 'Supervisor'])) {
                        $d->cantidad_esperada = null;
                    }
                    return $d;
                });

            return $this->created($res, $cert, "Certificación iniciada para planilla {$numeroPlanilla}");
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/planillas/cert/{id}/linea ──────────────────────────────────
    public function registrarLinea(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $certId = (int)$a['id'];
        $data   = $r->getParsedBody() ?? [];

        $cert = DB::table('cert_planillas')
            ->where('empresa_id', $user->empresa_id)
            ->find($certId);
        if (!$cert) return $this->notFound($res);
        if ($cert->estado !== 'EnProceso') {
            return $this->error($res, 'Esta certificación ya fue finalizada');
        }

        $detId    = (int)($data['detalle_id'] ?? 0);
        $cantidad = (float)($data['cantidad_certificada'] ?? 0);
        if ($cantidad < 0) return $this->error($res, 'La cantidad no puede ser negativa');

        $det = DB::table('cert_planilla_det')->find($detId);
        if (!$det || $det->cert_id !== $certId) {
            return $this->notFound($res, 'Línea no encontrada');
        }

        $correcto = abs($cantidad - $det->cantidad_esperada) < 0.001;
        DB::table('cert_planilla_det')->where('id', $detId)->update([
            'cantidad_certificada' => $cantidad,
            'es_correcto'          => $correcto ? 1 : 0,
            'observaciones'        => $data['observaciones'] ?? null,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        return $this->ok($res, [
            'es_correcto'       => $correcto,
            'cantidad_esperada' => in_array($user->rol, ['Admin', 'Supervisor']) ? $det->cantidad_esperada : null,
            'mensaje'           => $correcto ? 'Cantidad CORRECTA' : 'DIFERENCIA — verifique el conteo',
        ]);
    }

    // ── POST /api/planillas/cert/{id}/finalizar ──────────────────────────────
    public function finalizarCertificacion(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $certId = (int)$a['id'];
        $data   = $r->getParsedBody() ?? [];

        $cert = DB::table('cert_planillas')
            ->where('empresa_id', $user->empresa_id)
            ->find($certId);
        if (!$cert) return $this->notFound($res);
        if ($cert->estado !== 'EnProceso') {
            return $this->error($res, 'No está en proceso');
        }

        // Check if all lines certified
        $detalles    = DB::table('cert_planilla_det')->where('cert_id', $certId)->get();
        $hayNovedad  = $detalles->contains(fn($d) => !$d->es_correcto && $d->cantidad_certificada > 0);
        $hayPendiente= $detalles->contains(fn($d) => $d->cantidad_certificada == 0);

        $estado = $hayNovedad ? 'ConNovedad' : 'Completada';

        DB::table('cert_planillas')->where('id', $certId)->update([
            'estado'        => $estado,
            'hora_fin'      => date('H:i:s'),
            'observaciones' => $data['observaciones'] ?? null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // Check if all planillas in this archivo are certified
        $archivoId    = $cert->archivo_id;
        $totalPlan    = DB::table('lineas_planilla')->where('archivo_id', $archivoId)
                          ->distinct()->count('numero_planilla');
        $certCompletas = DB::table('cert_planillas')
                           ->where('archivo_id', $archivoId)
                           ->whereIn('estado', ['Completada', 'ConNovedad'])
                           ->count();
        if ($certCompletas >= $totalPlan) {
            DB::table('archivos_planilla')->where('id', $archivoId)
                ->update(['estado' => 'Certificada', 'updated_at' => date('Y-m-d H:i:s')]);
        }

        return $this->ok($res, ['estado' => $estado, 'hay_novedad' => $hayNovedad],
            $hayNovedad ? 'Certificación con novedades' : 'Certificación completada correctamente');
    }

    // ── GET /api/planillas/cert/dashboard ────────────────────────────────────
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $params = $r->getQueryParams();
        $archivoId = (int)($params['archivo_id'] ?? 0);

        // Only show archivos that are Separado or further (picking complete)
        $archivos = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->whereIn('estado', ['Separado', 'EnCertificacion', 'Certificada'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        if (!$archivoId && $archivos->isNotEmpty()) {
            $archivoId = $archivos->first()->id;
        }

        $planillaStats = collect();
        if ($archivoId) {
            // Stats por planilla
            $totalPorPlanilla = DB::table('lineas_planilla')
                ->where('archivo_id', $archivoId)
                ->select('numero_planilla',
                    DB::raw('SUM(cantidad) as total_unidades'),
                    DB::raw('COUNT(DISTINCT producto_codigo) as total_productos'))
                ->groupBy('numero_planilla')
                ->get()
                ->keyBy('numero_planilla');

            $certs = DB::table('cert_planillas as c')
                ->leftJoin('personal as p', 'c.auxiliar_id', '=', 'p.id')
                ->where('c.archivo_id', $archivoId)
                ->select('c.*', 'p.nombre as auxiliar_nombre')
                ->get()
                ->keyBy('numero_planilla');

            // Get all distinct planillas from lineas
            $planillasNombres = DB::table('lineas_planilla')
                ->where('archivo_id', $archivoId)
                ->distinct()
                ->pluck('numero_planilla');

            $planillaStats = $planillasNombres->map(function ($np) use ($totalPorPlanilla, $certs, $archivoId) {
                $stats = $totalPorPlanilla[$np] ?? null;
                $cert  = $certs[$np] ?? null;

                $novedades = 0;
                if ($cert) {
                    $novedades = DB::table('cert_planilla_det')
                        ->where('cert_id', $cert->id)
                        ->where('es_correcto', 0)
                        ->where('cantidad_certificada', '>', 0)
                        ->count();
                }

                return [
                    'numero_planilla'    => $np,
                    'total_unidades'     => $stats->total_unidades ?? 0,
                    'total_productos'    => $stats->total_productos ?? 0,
                    'estado_cert'        => $cert?->estado ?? 'Pendiente',
                    'auxiliar'           => $cert?->auxiliar_nombre ?? null,
                    'hora_inicio'        => $cert?->hora_inicio ?? null,
                    'hora_fin'           => $cert?->hora_fin ?? null,
                    'novedades'          => $novedades,
                    'cert_id'            => $cert?->id ?? null,
                ];
            });
        }

        $summary = [
            'total'           => $planillaStats->count(),
            'pendientes'      => $planillaStats->where('estado_cert', 'Pendiente')->count(),
            'en_proceso'      => $planillaStats->where('estado_cert', 'EnProceso')->count(),
            'completadas'     => $planillaStats->where('estado_cert', 'Completada')->count(),
            'con_novedad'     => $planillaStats->where('estado_cert', 'ConNovedad')->count(),
        ];

        return $this->ok($res, [
            'archivos'       => $archivos,
            'archivo_id'     => $archivoId,
            'planillas'      => $planillaStats->values(),
            'summary'        => $summary,
        ]);
    }

    // ── GET /api/planillas/cert/{id} ─────────────────────────────────────────
    public function verCertificacion(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $certId = (int)$a['id'];

        $cert = DB::table('cert_planillas as c')
            ->leftJoin('personal as p', 'c.auxiliar_id', '=', 'p.id')
            ->where('c.empresa_id', $user->empresa_id)
            ->where('c.id', $certId)
            ->select('c.*', 'p.nombre as auxiliar_nombre')
            ->first();
        if (!$cert) return $this->notFound($res);

        $detalles = DB::table('cert_planilla_det')->where('cert_id', $certId)->get()
            ->map(function ($d) use ($user) {
                if (!in_array($user->rol, ['Admin', 'Supervisor'])) {
                    $d->cantidad_esperada = null;
                }
                return $d;
            });

        $cert->detalles = $detalles;
        return $this->ok($res, $cert);
    }

    // ── GET /api/planillas/progreso ───────────────────────────────────────────
    /** Returns per-planilla picking progress for the supervisor dashboard. */
    public function planillaProgreso(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $params = $r->getQueryParams();
        $archivoId = (int)($params['archivo_id'] ?? 0);

        $q = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->orderBy('created_at', 'desc');
        if ($archivoId) $q->where('id', $archivoId);

        $archivos = $q->get();

        $result = $archivos->map(function ($archivo) {
            // Count distinct planilla numbers
            $planillas = DB::table('lineas_planilla')
                ->where('archivo_id', $archivo->id)
                ->select(
                    'numero_planilla',
                    DB::raw('COUNT(*) as total_lineas'),
                    DB::raw('SUM(cantidad) as total_unidades')
                )
                ->groupBy('numero_planilla')
                ->get();

            // Count picking orders for this archivo
            $ordenes = DB::table('orden_pickings')
                ->where('archivo_id', $archivo->id)
                ->select('planilla_numero', 'estado',
                    DB::raw('COUNT(*) as total'),
                    DB::raw('COUNT(DISTINCT auxiliar_id) as auxiliares'))
                ->groupBy('planilla_numero', 'estado')
                ->get();

            $ordensByPlanilla = [];
            foreach ($ordenes as $o) {
                $num = $o->planilla_numero ?? 'sin_planilla';
                if (!isset($ordensByPlanilla[$num])) {
                    $ordensByPlanilla[$num] = ['total' => 0, 'completadas' => 0, 'en_proceso' => 0];
                }
                $ordensByPlanilla[$num]['total'] += $o->total;
                if ($o->estado === 'Completada') $ordensByPlanilla[$num]['completadas'] += $o->total;
                if ($o->estado === 'EnProceso')  $ordensByPlanilla[$num]['en_proceso']  += $o->total;
            }

            $planillasConProgreso = $planillas->map(function ($p) use ($ordensByPlanilla) {
                $stats = $ordensByPlanilla[$p->numero_planilla] ?? null;
                $pct = 0;
                if ($stats && $stats['total'] > 0) {
                    $pct = round(($stats['completadas'] / $stats['total']) * 100);
                }
                return [
                    'numero_planilla' => $p->numero_planilla,
                    'total_lineas'    => $p->total_lineas,
                    'total_unidades'  => $p->total_unidades,
                    'ordenes_total'   => $stats['total']       ?? 0,
                    'ordenes_comp'    => $stats['completadas'] ?? 0,
                    'ordenes_proc'    => $stats['en_proceso']  ?? 0,
                    'pct_completado'  => $pct,
                    'asignada'        => $stats !== null,
                ];
            });

            $totalLineas    = $planillas->sum('total_lineas');
            $ordenesTotales = collect(array_values($ordensByPlanilla))->sum('total');
            $ordenesComp    = collect(array_values($ordensByPlanilla))->sum('completadas');

            return [
                'archivo'         => $archivo,
                'planillas'       => $planillasConProgreso->values(),
                'pct_archivo'     => $ordenesTotales > 0
                    ? round(($ordenesComp / $ordenesTotales) * 100) : 0,
                'total_unidades'  => $planillas->sum('total_unidades'),
                'total_lineas'    => $totalLineas,
            ];
        });

        return $this->ok($res, $result->values());
    }

    // ── POST /api/planillas/asignar ───────────────────────────────────────────
    /** Creates picking orders from planilla lines and assigns to an auxiliar. */
    public function asignar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if (!$this->isSupervisorOrAbove($user)) {
            return $this->forbidden($res, 'Solo supervisores pueden asignar picking');
        }

        $data = $r->getParsedBody() ?? [];
        $archivoId    = (int)($data['archivo_id'] ?? 0);
        $planillas    = $data['planillas'] ?? [];   // array of numero_planilla strings
        $auxiliarId   = (int)($data['auxiliar_id'] ?? 0);
        $modo         = $data['modo'] ?? 'por_planilla'; // 'consolidado' | 'por_planilla'
        $filtroMarca  = trim($data['filtro_marca']    ?? '');
        $filtroPasillo= trim($data['filtro_pasillo']  ?? '');

        if (!$archivoId) return $this->error($res, 'archivo_id requerido');
        if (!$auxiliarId) return $this->error($res, 'auxiliar_id requerido');

        $archivo = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->find($archivoId);
        if (!$archivo) return $this->notFound($res, 'Archivo no encontrado');

        // Build lines query
        $qLineas = DB::table('lineas_planilla')
            ->where('archivo_id', $archivoId);
        if (!empty($planillas)) {
            $qLineas->whereIn('numero_planilla', $planillas);
        }
        if ($filtroMarca) {
            $qLineas->where('asesor', 'like', "%{$filtroMarca}%");
        }

        $lineas = $qLineas->get();
        if ($lineas->isEmpty()) {
            return $this->error($res, 'No hay líneas que coincidan con los filtros seleccionados');
        }

        try {
            DB::beginTransaction();
            $now = date('Y-m-d H:i:s');
            $hoy = date('Y-m-d');
            $creadas = [];

            if ($modo === 'consolidado') {
                // One order for all selected planillas
                $numeroOrden = 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                $planillaLabel = count($planillas) > 0
                    ? implode(',', array_slice($planillas, 0, 3)) . (count($planillas) > 3 ? '...' : '')
                    : 'CONSOLIDADO';

                $ordenId = DB::table('orden_pickings')->insertGetId([
                    'empresa_id'       => $user->empresa_id,
                    'sucursal_id'      => $user->sucursal_id,
                    'numero_orden'     => $numeroOrden,
                    'cliente'          => 'Consolidado ' . $planillaLabel,
                    'planilla_numero'  => $planillaLabel,
                    'archivo_id'       => $archivoId,
                    'auxiliar_id'      => $auxiliarId,
                    'estado'           => 'Pendiente',
                    'prioridad'        => 5,
                    'fecha_movimiento' => $hoy,
                    'hora_inicio'      => date('H:i:s'),
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                // Sum quantities by product code/name
                $sumados = [];
                foreach ($lineas as $l) {
                    $key = $l->producto_codigo ?: $l->producto_nombre;
                    if (!isset($sumados[$key])) {
                        $sumados[$key] = ['nombre' => $l->producto_nombre, 'codigo' => $l->producto_codigo, 'cantidad' => 0];
                    }
                    $sumados[$key]['cantidad'] += $l->cantidad;
                }

                foreach ($sumados as $item) {
                    $productoId = $this->_resolveProducto($user->empresa_id, $item['codigo'], $item['nombre']);
                    DB::table('picking_detalles')->insert([
                        'orden_picking_id'    => $ordenId,
                        'producto_id'         => $productoId ?? 1,
                        'ubicacion_id'        => 0,
                        'cantidad_solicitada' => (int)ceil($item['cantidad']),
                        'cantidad_pickeada'   => 0,
                        'estado'              => 'Pendiente',
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                }
                $creadas[] = $ordenId;

            } else {
                // One order per planilla number
                $porPlanilla = [];
                foreach ($lineas as $l) {
                    $porPlanilla[$l->numero_planilla][] = $l;
                }

                foreach ($porPlanilla as $numPlanilla => $lns) {
                    // Check if order already exists for this planilla + archivo
                    $existe = DB::table('orden_pickings')
                        ->where('archivo_id', $archivoId)
                        ->where('planilla_numero', $numPlanilla)
                        ->exists();
                    if ($existe) continue;

                    $numeroOrden = 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                    $ordenId = DB::table('orden_pickings')->insertGetId([
                        'empresa_id'       => $user->empresa_id,
                        'sucursal_id'      => $user->sucursal_id,
                        'numero_orden'     => $numeroOrden,
                        'cliente'          => 'Planilla ' . $numPlanilla,
                        'planilla_numero'  => $numPlanilla,
                        'archivo_id'       => $archivoId,
                        'auxiliar_id'      => $auxiliarId,
                        'estado'           => 'Pendiente',
                        'prioridad'        => 5,
                        'fecha_movimiento' => $hoy,
                        'hora_inicio'      => date('H:i:s'),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);

                    // Sum by product within this planilla
                    $sumados = [];
                    foreach ($lns as $l) {
                        $key = $l->producto_codigo ?: $l->producto_nombre;
                        if (!isset($sumados[$key])) {
                            $sumados[$key] = ['nombre' => $l->producto_nombre, 'codigo' => $l->producto_codigo, 'cantidad' => 0];
                        }
                        $sumados[$key]['cantidad'] += $l->cantidad;
                    }

                    foreach ($sumados as $item) {
                        $productoId = $this->_resolveProducto($user->empresa_id, $item['codigo'], $item['nombre']);
                        DB::table('picking_detalles')->insert([
                            'orden_picking_id'    => $ordenId,
                            'producto_id'         => $productoId ?? 1,
                            'ubicacion_id'        => 0,
                            'cantidad_solicitada' => (int)ceil($item['cantidad']),
                            'cantidad_pickeada'   => 0,
                            'estado'              => 'Pendiente',
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ]);
                    }
                    $creadas[] = $ordenId;
                }
            }

            if (empty($creadas)) {
                DB::rollBack();
                return $this->error($res, 'Todas las planillas seleccionadas ya tienen órdenes asignadas');
            }

            // Mark archivo as EnPicking
            DB::table('archivos_planilla')->where('id', $archivoId)
                ->update(['estado' => 'EnPicking', 'updated_at' => $now]);

            DB::commit();

            return $this->created($res, [
                'ordenes_creadas' => count($creadas),
                'orden_ids'       => $creadas,
            ], count($creadas) . ' orden(es) de picking creada(s) correctamente');

        } catch (\Exception $e) {
            DB::rollBack();
            if (function_exists('wmsLog')) wmsLog('ERROR', 'Planilla asignar: ' . $e->getMessage());
            return $this->error($res, 'Error al asignar: ' . $e->getMessage());
        }
    }

    /** Try to find producto_id by codigo or name. Returns null if not found. */
    private function _resolveProducto(int $empresaId, ?string $codigo, string $nombre): ?int
    {
        if ($codigo) {
            $p = DB::table('productos')->where('empresa_id', $empresaId)
                ->where('codigo_interno', $codigo)->value('id');
            if ($p) return $p;
            // Try EAN
            $p = DB::table('producto_eans')->where('codigo_ean', $codigo)->value('producto_id');
            if ($p) return $p;
        }
        // Fuzzy name match
        $p = DB::table('productos')->where('empresa_id', $empresaId)
            ->whereRaw('LOWER(nombre) = LOWER(?)', [$nombre])->value('id');
        return $p ?: null;
    }

    // ── Helper: find column index in header ──────────────────────────────────
    private function _findCol(array $header, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim($candidate));
            foreach ($header as $idx => $h) {
                if (mb_strtolower(trim($h)) === $candidate) return $idx;
            }
        }
        // Partial match fallback
        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim($candidate));
            foreach ($header as $idx => $h) {
                if (str_contains(mb_strtolower($h), $candidate) || str_contains($candidate, mb_strtolower($h))) {
                    return $idx;
                }
            }
        }
        return null;
    }

    // ── POST /api/planillas/{id}/habilitar-cert ───────────────────────────────
    // Supervisor/Admin puede forzar un archivo a 'Separado' para habilitar
    // certificación anticipada (picking no completado al 100%).
    // Solo se certificarán los productos que ya estén separados.
    public function habilitarCertificacion(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if (!$this->isSupervisorOrAbove($user)) {
            return $this->forbidden($res, 'Solo supervisores pueden habilitar certificación anticipada');
        }

        $archivo = DB::table('archivos_planilla')
            ->where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('id', (int)$a['id'])
            ->first();

        if (!$archivo) return $this->notFound($res);

        if (!in_array($archivo->estado, ['Importada', 'EnPicking'])) {
            return $this->error($res,
                "El archivo está en estado '{$archivo->estado}'. Solo se puede habilitar desde Importada o EnPicking.");
        }

        // Calcular progreso de picking para incluir en la respuesta
        $totalLineas     = DB::table('orden_pickings')->where('archivo_id', $archivo->id)->count();
        $completadas     = DB::table('orden_pickings')
            ->where('archivo_id', $archivo->id)
            ->where('estado', 'Completada')->count();

        DB::table('archivos_planilla')
            ->where('id', $archivo->id)
            ->update(['estado' => 'Separado', 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit($user, 'planilla', 'habilitar_cert', 'archivos_planilla', $archivo->id,
            ['estado' => $archivo->estado], ['estado' => 'Separado'],
            "Certificación habilitada anticipadamente por supervisor. Picking: {$completadas}/{$totalLineas} órdenes");

        return $this->ok($res, [
            'estado'      => 'Separado',
            'progreso_picking' => "{$completadas}/{$totalLineas} órdenes completadas",
        ], 'Certificación habilitada. Solo se certificarán los productos ya separados.');
    }
}
