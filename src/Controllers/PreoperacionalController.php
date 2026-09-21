<?php

namespace App\Controllers;

use App\Models\Preoperacional;
use App\Models\PreoperacionalItem;
use App\Models\PreoperacionalFoto;
use App\Models\PreoperacionalItemFoto;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Registro preoperacional de vehículos (a pedido explícito, 2026-08-21/22).
 * Encabezado (fecha/vehículo/conductor/ruta) + lista de chequeo fija de 12
 * ítems calificados Cumple/No Cumple, enfocada en limpieza y orden del
 * vehículo antes de salir a ruta. El ítem "Temperatura" admite foto puntual
 * de evidencia (lectura del termómetro/Termo King); además, la inspección
 * completa admite VARIAS fotos generales de evidencia (preoperacional_fotos).
 */
class PreoperacionalController extends BaseController
{
    // Lista fija de la inspección — no configurable por Admin (no se pidió);
    // si en el futuro se necesita editable, esto se movería a un maestro.
    public const ITEMS = [
        ['clave' => 'cabina',            'nombre' => 'Cabina'],
        ['clave' => 'termo_king',        'nombre' => 'Termo King llega encendido'],
        ['clave' => 'llega_tiempo',      'nombre' => 'Llega a tiempo'],
        ['clave' => 'bpm_conductor',     'nombre' => 'BPM Conductor'],
        ['clave' => 'temperatura',       'nombre' => 'Temperatura', 'requiere_foto' => true],
        ['clave' => 'caja_frigorifica',  'nombre' => 'Caja frigorífica'],
        ['clave' => 'piso',              'nombre' => 'Piso'],
        ['clave' => 'estibas',           'nombre' => 'Estibas'],
        ['clave' => 'paredes',           'nombre' => 'Paredes'],
        ['clave' => 'mamparas',          'nombre' => 'Mamparas'],
        ['clave' => 'techo',             'nombre' => 'Techo'],
        ['clave' => 'puertas',           'nombre' => 'Puertas'],
        ['clave' => 'desinfeccion_vehiculo', 'nombre' => 'Desinfección Vehículo', 'requiere_producto' => true],
    ];

    // Únicos productos de desinfección válidos — a pedido explícito
    // (2026-09-17), obligatorio solo cuando el ítem "Desinfección Vehículo"
    // se califica Cumple.
    public const PRODUCTOS_DESINFECCION = ['Acido Peracetico', 'Amonio Cuaternario'];

    // ── GET /api/preoperacional/items ──────────────────────────────────────
    // Catálogo fijo de la inspección, para que el frontend no lo duplique a mano.
    public function items(Request $r, Response $res): Response
    {
        return $this->ok($res, self::ITEMS);
    }

    // ── POST /api/preoperacional ────────────────────────────────────────────
    // multipart/form-data: fecha, vehiculo, conductor, ruta, observaciones,
    // items (JSON string: [{clave, calificacion, detalle_no_conformidad,
    // accion_correctiva}, ...]), foto_temperatura (archivo opcional),
    // foto_item_<clave>[] (0+ archivos por ítem, típicamente el/los NC).
    public function crear(Request $r, Response $res): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucId     = $user->sucursal_id;
        $data      = $r->getParsedBody() ?? [];

        $fecha     = trim($data['fecha'] ?? '');
        $vehiculo  = trim($data['vehiculo'] ?? '');
        $conductor = trim($data['conductor'] ?? '');
        $ruta      = trim($data['ruta'] ?? '');
        $obs       = trim($data['observaciones'] ?? '');

        if ($fecha === '' || $vehiculo === '' || $conductor === '') {
            return $this->error($res, 'Fecha, vehículo y conductor son obligatorios.', 422);
        }

        $itemsRaw = json_decode($data['items'] ?? '[]', true);
        if (!is_array($itemsRaw) || empty($itemsRaw)) {
            return $this->error($res, 'La lista de chequeo es obligatoria.', 422);
        }

        // Validar contra el catálogo fijo: todos los ítems deben venir, con
        // calificación C/NC — no se confía en lo que mande el cliente sin cruzarlo.
        // A pedido explícito (2026-09-17): si la calificación es "No Cumple", el
        // detalle de la no conformidad Y la acción correctiva son OBLIGATORIOS
        // — se valida acá en el servidor, no solo en el formulario móvil, para
        // que una llamada directa al API no se salte la regla (mismo criterio
        // ya aplicado a lote/vencimiento en AjusteUbicacionController).
        $porClave = [];
        foreach ($itemsRaw as $it) {
            $clave = $it['clave'] ?? '';
            $cal   = strtoupper(trim($it['calificacion'] ?? ''));
            if ($clave === '' || !in_array($cal, ['C', 'NC'], true)) continue;

            $detalle = trim((string)($it['detalle_no_conformidad'] ?? ''));
            $accion  = trim((string)($it['accion_correctiva'] ?? ''));
            if ($cal === 'NC' && ($detalle === '' || $accion === '')) {
                $nombreItem = current(array_filter(self::ITEMS, fn($d) => $d['clave'] === $clave))['nombre'] ?? $clave;
                return $this->error($res, "\"{$nombreItem}\" está marcado como No Cumple: el detalle de la no conformidad y la acción correctiva son obligatorios.", 422);
            }

            // A pedido explícito (2026-09-17): si "Desinfección Vehículo" se
            // califica Cumple, el producto usado es obligatorio y debe venir
            // del catálogo fijo — no se confía en texto libre del cliente.
            $producto = trim((string)($it['producto_desinfeccion'] ?? ''));
            if ($clave === 'desinfeccion_vehiculo' && $cal === 'C' && !in_array($producto, self::PRODUCTOS_DESINFECCION, true)) {
                return $this->error($res, '"Desinfección Vehículo" está marcado como Cumple: debe seleccionar el producto usado (Ácido Peracético o Amonio Cuaternario).', 422);
            }

            $porClave[$clave] = [
                'calificacion'           => $cal,
                'detalle_no_conformidad' => $cal === 'NC' ? $detalle : null,
                'accion_correctiva'      => $cal === 'NC' ? $accion  : null,
                'producto_desinfeccion'  => ($clave === 'desinfeccion_vehiculo' && $cal === 'C') ? $producto : null,
            ];
        }
        $faltantes = [];
        foreach (self::ITEMS as $def) {
            if (!isset($porClave[$def['clave']])) $faltantes[] = $def['nombre'];
        }
        if (!empty($faltantes)) {
            return $this->error($res, 'Falta calificar: ' . implode(', ', $faltantes), 422);
        }

        // Firma del conductor — obligatoria para cerrar el formulario, mismo
        // patrón de archivo multipart que el resto de fotos del módulo.
        $filesCheck = $r->getUploadedFiles();
        if (!isset($filesCheck['firma']) || $filesCheck['firma']->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'La firma del conductor es obligatoria.', 422);
        }

        try {
            $preop = Capsule::transaction(function () use (
                $empresaId, $sucId, $fecha, $vehiculo, $conductor, $ruta, $obs, $user, $porClave, $r
            ) {
                $preop = Preoperacional::create([
                    'empresa_id'    => $empresaId,
                    'sucursal_id'   => $sucId,
                    'fecha'         => $fecha,
                    'vehiculo'      => $vehiculo,
                    'conductor'     => $conductor,
                    'ruta'          => $ruta ?: null,
                    'observaciones' => $obs ?: null,
                    'creado_por'    => $user->id,
                ]);

                // Foto de evidencia del ítem "Temperatura" — mismo patrón de
                // MiscelaneoController::uploadFotos (multipart, moveTo, ruta con / inicial).
                $fotoTemperaturaUrl = null;
                $files = $r->getUploadedFiles();
                if (isset($files['foto_temperatura']) && $files['foto_temperatura']->getError() === UPLOAD_ERR_OK) {
                    $file = $files['foto_temperatura'];
                    $ext  = pathinfo($file->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION) ?: 'jpg';
                    $dir  = dirname(__DIR__, 2) . '/public/uploads/preoperacional';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $name = 'preop_' . $preop->id . '_temperatura_' . uniqid() . '.' . $ext;
                    $file->moveTo($dir . '/' . $name);
                    $fotoTemperaturaUrl = '/uploads/preoperacional/' . $name;
                }

                // Firma del conductor — ya validada como obligatoria antes de
                // abrir la transacción.
                $dirFirma = dirname(__DIR__, 2) . '/public/uploads/preoperacional';
                if (!is_dir($dirFirma)) mkdir($dirFirma, 0755, true);
                $firmaFile = $files['firma'];
                $extFirma  = pathinfo($firmaFile->getClientFilename() ?? 'firma.png', PATHINFO_EXTENSION) ?: 'png';
                $nameFirma = 'preop_' . $preop->id . '_firma_' . uniqid() . '.' . $extFirma;
                $firmaFile->moveTo($dirFirma . '/' . $nameFirma);
                $preop->firma_url = '/uploads/preoperacional/' . $nameFirma;
                $preop->save();

                // Directorio para las fotos de no conformidad por ítem — mismo
                // patrón que el resto de fotos de este módulo.
                $dirItemFotos = dirname(__DIR__, 2) . '/public/uploads/preoperacional';
                if (!is_dir($dirItemFotos)) mkdir($dirItemFotos, 0755, true);

                foreach (self::ITEMS as $def) {
                    $clave = $def['clave'];
                    $info  = $porClave[$clave];

                    $item = PreoperacionalItem::create([
                        'preoperacional_id'      => $preop->id,
                        'item_clave'             => $clave,
                        'item_nombre'            => $def['nombre'],
                        'calificacion'           => $info['calificacion'],
                        'foto_url'               => ($clave === 'temperatura') ? $fotoTemperaturaUrl : null,
                        'detalle_no_conformidad' => $info['detalle_no_conformidad'],
                        'accion_correctiva'      => $info['accion_correctiva'],
                        'producto_desinfeccion'  => $info['producto_desinfeccion'],
                    ]);

                    // Varias fotografías de la no conformidad puntual de este ítem
                    // — a pedido explícito (2026-09-17). Campo multipart por ítem:
                    // foto_item_<clave>[] (0 o más archivos; normalmente solo se
                    // envían para ítems calificados NC, pero no se restringe aquí).
                    $fotosItem = $files["foto_item_{$clave}"] ?? [];
                    if (!is_array($fotosItem)) $fotosItem = [$fotosItem];
                    foreach ($fotosItem as $fotoItem) {
                        if (!$fotoItem || $fotoItem->getError() !== UPLOAD_ERR_OK) continue;
                        $ext  = pathinfo($fotoItem->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION) ?: 'jpg';
                        $name = 'preop_' . $preop->id . '_' . $clave . '_' . uniqid() . '.' . $ext;
                        $fotoItem->moveTo($dirItemFotos . '/' . $name);
                        PreoperacionalItemFoto::create([
                            'preoperacional_item_id' => $item->id,
                            'url'                    => '/uploads/preoperacional/' . $name,
                        ]);
                    }
                }

                // A pedido explícito (2026-08-22): además de la foto puntual de
                // Temperatura, la inspección completa admite VARIAS fotos generales
                // de evidencia (ej. vista general del vehículo, algo que encontró en
                // la revisión) — mismo patrón multi-archivo que MiscelaneoController::
                // uploadFotos, tomadas al final del formulario junto con el resto.
                $fotosGenerales = $files['fotos'] ?? [];
                if (!is_array($fotosGenerales)) $fotosGenerales = [$fotosGenerales];
                if (!empty($fotosGenerales)) {
                    $dirFotos = dirname(__DIR__, 2) . '/public/uploads/preoperacional';
                    if (!is_dir($dirFotos)) mkdir($dirFotos, 0755, true);
                    foreach ($fotosGenerales as $foto) {
                        if (!$foto || $foto->getError() !== UPLOAD_ERR_OK) continue;
                        $ext  = pathinfo($foto->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION) ?: 'jpg';
                        $name = 'preop_' . $preop->id . '_' . uniqid() . '.' . $ext;
                        $foto->moveTo($dirFotos . '/' . $name);
                        PreoperacionalFoto::create([
                            'preoperacional_id' => $preop->id,
                            'url'                => '/uploads/preoperacional/' . $name,
                        ]);
                    }
                }

                return $preop;
            });

            $this->audit($user, 'preoperacional', 'crear', 'preoperacionales', $preop->id, null, [
                'vehiculo' => $vehiculo, 'conductor' => $conductor, 'fecha' => $fecha,
            ], "Preoperacional registrado: vehículo {$vehiculo}, conductor {$conductor}");

            return $this->ok($res, ['id' => $preop->id], 'Preoperacional registrado correctamente');
        } catch (\Throwable $e) {
            return $this->error($res, 'Error al registrar el preoperacional: ' . $e->getMessage(), 500);
        }
    }

    // ── GET /api/preoperacional ──────────────────────────────────────────────
    public function listar(Request $r, Response $res): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $params    = $r->getQueryParams();

        $fIni  = $params['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fFin  = $params['fecha_fin']    ?? date('Y-m-d');
        $veh   = trim($params['vehiculo']  ?? '');
        $cond  = trim($params['conductor'] ?? '');
        $soloNc = ($params['solo_nc'] ?? '') === '1';
        $like  = $this->isPg() ? 'ILIKE' : 'LIKE';

        $query = Preoperacional::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('fecha', [$fIni, $fFin])
            ->withCount(['items as nc_count' => fn($q) => $q->where('calificacion', 'NC')])
            ->with('creador:id,nombre')
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc');

        if ($veh !== '')  $query->where('vehiculo', $like, "%{$veh}%");
        if ($cond !== '') $query->where('conductor', $like, "%{$cond}%");
        if ($soloNc)      $query->whereHas('items', fn($q) => $q->where('calificacion', 'NC'));

        $rows = $query->limit(500)->get();

        return $this->ok($res, $rows);
    }

    // ── GET /api/preoperacional/{id} ─────────────────────────────────────────
    public function ver(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $preop = Preoperacional::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->with(['items.fotos', 'fotos', 'creador:id,nombre'])
            ->find((int)$a['id']);

        if (!$preop) return $this->notFound($res);

        return $this->ok($res, $preop);
    }

    // ── POST /api/preoperacional/{id}/fotos ──────────────────────────────────
    // Permite agregar más fotos de evidencia a un preoperacional ya registrado
    // (además de las que se hayan enviado junto con crear()). multipart/form-data,
    // campo 'fotos' (uno o varios archivos) — mismo patrón que MiscelaneoController::uploadFotos.
    public function uploadFotos(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $preop = Preoperacional::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$preop) return $this->notFound($res);

        $files = $r->getUploadedFiles();
        $fotos = $files['fotos'] ?? [];
        if (!is_array($fotos)) $fotos = [$fotos];

        $dir = dirname(__DIR__, 2) . '/public/uploads/preoperacional';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $guardadas = [];
        foreach ($fotos as $foto) {
            if (!$foto || $foto->getError() !== UPLOAD_ERR_OK) continue;
            $ext  = pathinfo($foto->getClientFilename() ?? 'foto.jpg', PATHINFO_EXTENSION) ?: 'jpg';
            $name = 'preop_' . $preop->id . '_' . uniqid() . '.' . $ext;
            $foto->moveTo($dir . '/' . $name);
            $guardadas[] = PreoperacionalFoto::create([
                'preoperacional_id' => $preop->id,
                'url'                => '/uploads/preoperacional/' . $name,
            ]);
        }

        return $this->ok($res, $guardadas, count($guardadas) . ' foto(s) agregada(s)');
    }

    // ── DELETE /api/preoperacional/{id} ──────────────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $preop = Preoperacional::where('empresa_id', $empresaId)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);

        if (!$preop) return $this->notFound($res);

        $this->audit($user, 'preoperacional', 'eliminar', 'preoperacionales', $preop->id,
            ['vehiculo' => $preop->vehiculo, 'conductor' => $preop->conductor, 'fecha' => $preop->fecha], null,
            "Preoperacional eliminado: vehículo {$preop->vehiculo}, fecha {$preop->fecha}");

        $preop->delete(); // cascada elimina preoperacional_items (ON DELETE CASCADE)

        return $this->ok($res, null, 'Preoperacional eliminado correctamente');
    }

    // ── GET /api/preoperacional/dashboard ────────────────────────────────────
    // KPIs del preoperacional en un rango de fechas — a pedido explícito
    // (2026-09-17): dashboard dedicado (no solo lo que ya muestra el tablero
    // general de Calidad), con % de cumplimiento y tendencia diaria de No
    // Cumple para poder ver si está mejorando o empeorando.
    public function dashboard(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $params     = $r->getQueryParams();
        $fIni = $params['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fFin = $params['fecha_fin']    ?? date('Y-m-d');

        try {
            $pdo = Capsule::connection()->getPdo();

            $stmtKpi = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT p.id)                                                       AS total_inspecciones,
                    COUNT(DISTINCT p.vehiculo)                                                  AS vehiculos_inspeccionados,
                    COUNT(pi.id)                                                                AS total_items,
                    COUNT(CASE WHEN pi.calificacion = 'NC' THEN 1 END)                          AS total_nc
                FROM preoperacionales p
                LEFT JOIN preoperacional_items pi ON pi.preoperacional_id = p.id
                WHERE p.empresa_id = :emp AND p.sucursal_id = :suc
                  AND p.fecha BETWEEN :ini AND :fin
            ");
            $stmtKpi->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':ini' => $fIni, ':fin' => $fFin]);
            $kpi = $stmtKpi->fetch(\PDO::FETCH_ASSOC) ?: [];

            $totalItems = (int)($kpi['total_items'] ?? 0);
            $totalNc    = (int)($kpi['total_nc'] ?? 0);
            $pctCumplimiento = $totalItems > 0 ? round(($totalItems - $totalNc) / $totalItems * 100, 1) : 100.0;

            // Tendencia diaria: inspecciones y No Cumple por día, para graficar.
            $stmtTendencia = $pdo->prepare("
                SELECT p.fecha,
                       COUNT(DISTINCT p.id) AS inspecciones,
                       COUNT(CASE WHEN pi.calificacion = 'NC' THEN 1 END) AS no_cumple
                FROM preoperacionales p
                LEFT JOIN preoperacional_items pi ON pi.preoperacional_id = p.id
                WHERE p.empresa_id = :emp AND p.sucursal_id = :suc
                  AND p.fecha BETWEEN :ini AND :fin
                GROUP BY p.fecha
                ORDER BY p.fecha
            ");
            $stmtTendencia->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':ini' => $fIni, ':fin' => $fFin]);
            $tendencia = $stmtTendencia->fetchAll(\PDO::FETCH_ASSOC);

            // Top ítems que más se marcan No Cumple — para saber dónde enfocar
            // el mantenimiento/la capacitación.
            $stmtTopItems = $pdo->prepare("
                SELECT pi.item_nombre, COUNT(*) AS veces_nc
                FROM preoperacional_items pi
                JOIN preoperacionales p ON p.id = pi.preoperacional_id
                WHERE p.empresa_id = :emp AND p.sucursal_id = :suc
                  AND p.fecha BETWEEN :ini AND :fin
                  AND pi.calificacion = 'NC'
                GROUP BY pi.item_nombre
                ORDER BY veces_nc DESC
                LIMIT 10
            ");
            $stmtTopItems->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':ini' => $fIni, ':fin' => $fFin]);
            $topItems = $stmtTopItems->fetchAll(\PDO::FETCH_ASSOC);

            // Top vehículos con más No Cumple — vista previa rápida de la matriz
            // completa (ver matrizVehiculos()).
            $stmtTopVeh = $pdo->prepare("
                SELECT p.vehiculo, COUNT(CASE WHEN pi.calificacion = 'NC' THEN 1 END) AS total_nc
                FROM preoperacionales p
                LEFT JOIN preoperacional_items pi ON pi.preoperacional_id = p.id
                WHERE p.empresa_id = :emp AND p.sucursal_id = :suc
                  AND p.fecha BETWEEN :ini AND :fin
                GROUP BY p.vehiculo
                HAVING COUNT(CASE WHEN pi.calificacion = 'NC' THEN 1 END) > 0
                ORDER BY total_nc DESC
                LIMIT 5
            ");
            $stmtTopVeh->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':ini' => $fIni, ':fin' => $fFin]);
            $topVehiculos = $stmtTopVeh->fetchAll(\PDO::FETCH_ASSOC);

            return $this->ok($res, [
                'rango' => ['desde' => $fIni, 'hasta' => $fFin],
                'kpis' => [
                    'total_inspecciones'         => (int)($kpi['total_inspecciones'] ?? 0),
                    'vehiculos_inspeccionados'   => (int)($kpi['vehiculos_inspeccionados'] ?? 0),
                    'total_items'                => $totalItems,
                    'total_nc'                   => $totalNc,
                    'pct_cumplimiento'           => $pctCumplimiento,
                ],
                'tendencia'      => $tendencia,
                'top_items_nc'   => $topItems,
                'top_vehiculos'  => $topVehiculos,
            ]);
        } catch (\Throwable $e) {
            error_log('PreoperacionalController::dashboard — ' . $e->getMessage());
            return $this->error($res, 'Error al calcular el dashboard de preoperacional: ' . $e->getMessage(), 500);
        }
    }

    // ── GET /api/preoperacional/matriz-vehiculos ─────────────────────────────
    // Matriz de no conformidades por vehículo — a pedido explícito
    // (2026-09-17): "validar las no conformidades por vehículo y ver las
    // respectivas acciones de mejora y todo el registro fotográfico". Devuelve
    // la matriz agregada (una fila por vehículo) y, en el mismo llamado, el
    // detalle plano de cada No Cumple (con su acción correctiva y fotos) para
    // que el frontend arme el detalle sin otro viaje al servidor.
    public function matrizVehiculos(Request $r, Response $res): Response
    {
        $user       = $r->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $user->sucursal_id;
        $params     = $r->getQueryParams();
        $fIni = $params['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $fFin = $params['fecha_fin']    ?? date('Y-m-d');

        try {
            $pdo = Capsule::connection()->getPdo();

            $stmtMatriz = $pdo->prepare("
                SELECT p.vehiculo,
                       MAX(v.tipo)                                                     AS tipo_vehiculo,
                       COUNT(DISTINCT p.id)                                            AS total_inspecciones,
                       COUNT(CASE WHEN pi.calificacion = 'NC' THEN 1 END)               AS total_nc,
                       MAX(p.fecha)                                                     AS ultima_inspeccion
                FROM preoperacionales p
                LEFT JOIN preoperacional_items pi ON pi.preoperacional_id = p.id
                LEFT JOIN vehiculos v ON v.empresa_id = p.empresa_id AND v.placa = p.vehiculo
                WHERE p.empresa_id = :emp AND p.sucursal_id = :suc
                  AND p.fecha BETWEEN :ini AND :fin
                GROUP BY p.vehiculo
                ORDER BY total_nc DESC, p.vehiculo ASC
            ");
            $stmtMatriz->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':ini' => $fIni, ':fin' => $fFin]);
            $matriz = $stmtMatriz->fetchAll(\PDO::FETCH_ASSOC);

            $stmtNc = $pdo->prepare("
                SELECT pi.id, p.vehiculo, p.fecha, p.conductor, pi.item_nombre,
                       pi.detalle_no_conformidad, pi.accion_correctiva
                FROM preoperacional_items pi
                JOIN preoperacionales p ON p.id = pi.preoperacional_id
                WHERE p.empresa_id = :emp AND p.sucursal_id = :suc
                  AND p.fecha BETWEEN :ini AND :fin
                  AND pi.calificacion = 'NC'
                ORDER BY p.fecha DESC, p.vehiculo ASC
            ");
            $stmtNc->execute([':emp' => $empresaId, ':suc' => $sucursalId, ':ini' => $fIni, ':fin' => $fFin]);
            $noConformidades = $stmtNc->fetchAll(\PDO::FETCH_ASSOC);

            // Fotos de cada no conformidad, en un solo query adicional (evita
            // N+1) — mismo criterio usado en el resto del sistema para traer
            // colecciones hijas de una lista ya resuelta.
            $itemIds = array_column($noConformidades, 'id');
            $fotosPorItem = [];
            if (!empty($itemIds)) {
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmtFotos = $pdo->prepare("
                    SELECT preoperacional_item_id, url
                    FROM preoperacional_item_fotos
                    WHERE preoperacional_item_id IN ($placeholders)
                    ORDER BY id
                ");
                $stmtFotos->execute($itemIds);
                foreach ($stmtFotos->fetchAll(\PDO::FETCH_ASSOC) as $f) {
                    $fotosPorItem[$f['preoperacional_item_id']][] = $f['url'];
                }
            }
            foreach ($noConformidades as &$nc) {
                $nc['fotos'] = $fotosPorItem[$nc['id']] ?? [];
            }
            unset($nc);

            return $this->ok($res, [
                'rango'            => ['desde' => $fIni, 'hasta' => $fFin],
                'matriz'           => $matriz,
                'no_conformidades' => $noConformidades,
            ]);
        } catch (\Throwable $e) {
            error_log('PreoperacionalController::matrizVehiculos — ' . $e->getMessage());
            return $this->error($res, 'Error al calcular la matriz de vehículos: ' . $e->getMessage(), 500);
        }
    }
}
