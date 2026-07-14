<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Miscelaneo;
use App\Models\MiscelaneoFoto;

class MiscelaneoController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $query  = Miscelaneo::with('fotos')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('sucursal_id', $this->getEffectiveSucursalId($user, $request));

        if (!empty($params['estado'])) {
            $query->where('estado', $params['estado']);
        }
        if (!empty($params['cliente_id'])) {
            $query->where('cliente_id', $params['cliente_id']);
        }
        if (!empty($params['pendientes_cliente'])) {
            $query->where('cliente_id', $params['pendientes_cliente'])
                  ->whereIn('estado', [Miscelaneo::ESTADO_RECIBIDO, Miscelaneo::ESTADO_ASIGNADO]);
        }

        $items = $query->orderBy('created_at', 'desc')->get();
        return $this->json($response, ['error' => false, 'data' => $items]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $item = Miscelaneo::with('fotos')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);

        if (!$item) return $this->error($response, 'No encontrado', 404);
        return $this->json($response, ['error' => false, 'data' => $item]);
    }

    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        if (empty($data['proveedor']) || empty($data['articulo']) || empty($data['cantidad'])) {
            return $this->error($response, 'Proveedor, artículo y cantidad son obligatorios');
        }
        if (empty($data['cliente_id'])) {
            return $this->error($response, 'La Sucursal de Entrega es obligatoria');
        }

        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $lastNum = Miscelaneo::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->max('id');
        $numero = 'MSC-' . str_pad(($lastNum ?? 0) + 1, 6, '0', STR_PAD_LEFT);

        $item = Miscelaneo::create([
            'empresa_id'       => $empresaId,
            'sucursal_id'      => $sucursalId,
            'numero_recepcion' => $numero,
            'proveedor'        => $data['proveedor'],
            'articulo'         => $data['articulo'],
            'cantidad'         => (float)$data['cantidad'],
            'unidad_medida'    => $data['unidad_medida'] ?? 'UN',
            'observaciones'    => $data['observaciones'] ?? null,
            'recibido_por'     => $user->id,
            'cliente_id'       => (int)$data['cliente_id'],
            'cliente_nombre'   => $data['cliente_nombre'] ?? null,
            'estado'           => Miscelaneo::ESTADO_RECIBIDO,
        ]);

        return $this->created($response, $item, 'Misceláneo recibido correctamente');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $item = Miscelaneo::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$item) return $this->error($response, 'No encontrado', 404);

        if ($item->estado === Miscelaneo::ESTADO_DESPACHADO) {
            return $this->error($response, 'No se puede editar un misceláneo ya despachado');
        }

        $fillable = ['proveedor', 'articulo', 'cantidad', 'unidad_medida', 'observaciones', 'cliente_id', 'cliente_nombre'];
        foreach ($fillable as $field) {
            if (isset($data[$field])) $item->$field = $data[$field];
        }

        if (isset($data['cliente_id']) && $data['cliente_id']) {
            $item->estado = Miscelaneo::ESTADO_ASIGNADO;
        }

        $item->save();
        return $this->ok($response, $item->load('fotos'), 'Misceláneo actualizado');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $item = Miscelaneo::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$item) return $this->error($response, 'No encontrado', 404);

        if ($item->estado === Miscelaneo::ESTADO_DESPACHADO) {
            return $this->error($response, 'No se puede eliminar un misceláneo ya despachado');
        }

        MiscelaneoFoto::where('miscelaneo_id', $item->id)->get()->each(function ($foto) {
            $path = dirname(__DIR__, 2) . '/public' . $foto->url;
            if (file_exists($path)) @unlink($path);
            $foto->delete();
        });

        $item->delete();
        return $this->ok($response, null, 'Misceláneo eliminado');
    }

    public function uploadFotos(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $item = Miscelaneo::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$item) return $this->error($response, 'No encontrado', 404);

        $uploadedFiles = $request->getUploadedFiles();
        $files = $uploadedFiles['fotos'] ?? [];
        if (!is_array($files)) $files = [$files];

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/miscelaneos';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $saved = [];
        foreach ($files as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) continue;
            $ext  = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION) ?: 'jpg';
            $name = 'msc_' . $item->id . '_' . uniqid() . '.' . $ext;
            $file->moveTo($uploadDir . '/' . $name);
            $foto = MiscelaneoFoto::create([
                'miscelaneo_id' => $item->id,
                'url'           => '/uploads/miscelaneos/' . $name,
            ]);
            $saved[] = $foto;
        }

        return $this->ok($response, $saved, count($saved) . ' foto(s) guardada(s)');
    }

    public function deleteFoto(Request $request, Response $response, array $args): Response
    {
        $foto = MiscelaneoFoto::find($args['foto_id']);
        if (!$foto) return $this->error($response, 'Foto no encontrada', 404);

        $path = dirname(__DIR__, 2) . '/public' . $foto->url;
        if (file_exists($path)) @unlink($path);
        $foto->delete();
        return $this->ok($response, null, 'Foto eliminada');
    }

    public function marcarDespachado(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $item = Miscelaneo::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$item) return $this->error($response, 'No encontrado', 404);

        $item->estado      = Miscelaneo::ESTADO_DESPACHADO;
        $item->despacho_id = $data['despacho_id'] ?? null;
        $item->save();

        return $this->ok($response, $item, 'Misceláneo marcado como despachado');
    }

    public function pendientesPorCliente(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $clienteId = $args['cliente_id'];

        $pendientes = Miscelaneo::with('fotos')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('cliente_id', $clienteId)
            ->whereIn('estado', [Miscelaneo::ESTADO_RECIBIDO, Miscelaneo::ESTADO_ASIGNADO])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->json($response, [
            'error'      => false,
            'data'       => $pendientes,
            'hay_pendientes' => $pendientes->isNotEmpty(),
            'total'      => $pendientes->count(),
        ]);
    }
}
