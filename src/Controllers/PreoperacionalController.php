<?php

namespace App\Controllers;

use App\Models\Preoperacional;
use App\Models\PreoperacionalItem;
use App\Models\PreoperacionalFoto;
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
    ];

    // ── GET /api/preoperacional/items ──────────────────────────────────────
    // Catálogo fijo de la inspección, para que el frontend no lo duplique a mano.
    public function items(Request $r, Response $res): Response
    {
        return $this->ok($res, self::ITEMS);
    }

    // ── POST /api/preoperacional ────────────────────────────────────────────
    // multipart/form-data: fecha, vehiculo, conductor, ruta, observaciones,
    // items (JSON string: [{clave, calificacion}, ...]), foto_temperatura (archivo opcional).
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
        $porClave = [];
        foreach ($itemsRaw as $it) {
            $clave = $it['clave'] ?? '';
            $cal   = strtoupper(trim($it['calificacion'] ?? ''));
            if ($clave !== '' && in_array($cal, ['C', 'NC'], true)) {
                $porClave[$clave] = $cal;
            }
        }
        $faltantes = [];
        foreach (self::ITEMS as $def) {
            if (!isset($porClave[$def['clave']])) $faltantes[] = $def['nombre'];
        }
        if (!empty($faltantes)) {
            return $this->error($res, 'Falta calificar: ' . implode(', ', $faltantes), 422);
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

                foreach (self::ITEMS as $def) {
                    PreoperacionalItem::create([
                        'preoperacional_id' => $preop->id,
                        'item_clave'        => $def['clave'],
                        'item_nombre'       => $def['nombre'],
                        'calificacion'      => $porClave[$def['clave']],
                        'foto_url'          => ($def['clave'] === 'temperatura') ? $fotoTemperaturaUrl : null,
                    ]);
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
            ->with(['items', 'fotos', 'creador:id,nombre'])
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
}
