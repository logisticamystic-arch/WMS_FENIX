<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Producto;
use App\Models\BloqueoLote;
use App\Models\Inventario;

class BloqueoController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        $productosBloqueados = Producto::withoutTenantScope()
            ->where('empresa_id', $empresaId)
            ->where('bloqueado', true)
            ->select('id', 'codigo_interno', 'nombre', 'bloqueado', 'bloqueo_motivo')
            ->get();

        $lotesBloqueados = BloqueoLote::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->with('producto:id,codigo_interno,nombre')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->ok($response, [
            'productos' => $productosBloqueados,
            'lotes'     => $lotesBloqueados,
        ]);
    }

    public function bloquearProducto(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->error($response, 'Solo administradores pueden bloquear productos', 403);

        $data = $request->getParsedBody();
        $p = Producto::withoutTenantScope()
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$p) return $this->error($response, 'Producto no encontrado', 404);

        $p->bloqueado      = true;
        $p->bloqueo_motivo = $data['motivo'] ?? 'Bloqueado por calidad';
        $p->save();

        $this->audit($user, 'bloqueo', 'bloquear_producto', 'productos', $p->id,
            null, ['bloqueado' => true, 'motivo' => $p->bloqueo_motivo],
            "Producto {$p->codigo_interno} bloqueado: {$p->bloqueo_motivo}");

        return $this->ok($response, $p, 'Producto bloqueado');
    }

    public function desbloquearProducto(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->error($response, 'Solo administradores', 403);

        $p = Producto::withoutTenantScope()
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$p) return $this->error($response, 'Producto no encontrado', 404);

        $motivoPrevio = $p->bloqueo_motivo;
        $p->bloqueado      = false;
        $p->bloqueo_motivo = null;
        $p->save();

        $this->audit($user, 'bloqueo', 'desbloquear_producto', 'productos', $p->id,
            ['bloqueado' => true, 'motivo' => $motivoPrevio], ['bloqueado' => false],
            "Producto {$p->codigo_interno} desbloqueado (motivo previo: {$motivoPrevio})");

        return $this->ok($response, $p, 'Producto desbloqueado');
    }

    public function bloquearLote(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->error($response, 'Solo administradores', 403);

        $data = $request->getParsedBody();
        if (empty($data['producto_id']) || empty($data['lote'])) {
            return $this->error($response, 'Producto y lote son obligatorios');
        }

        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        // Filtra por activo=true: con soft-delete, un lote ya desbloqueado antes
        // debe poder volver a bloquearse (la fila inactiva no cuenta como duplicado).
        $exists = BloqueoLote::where('empresa_id', $empresaId)
            ->where('producto_id', $data['producto_id'])
            ->where('lote', $data['lote'])
            ->where('activo', true)
            ->first();

        if ($exists) return $this->error($response, 'Este lote ya está bloqueado');

        $bl = BloqueoLote::create([
            'empresa_id'   => $empresaId,
            'producto_id'  => $data['producto_id'],
            'lote'         => $data['lote'],
            'motivo'       => $data['motivo'] ?? 'Bloqueado por calidad',
            'bloqueado_por' => $user->id,
            'activo'       => true,
        ]);

        $this->audit($user, 'bloqueo', 'bloquear_lote', 'bloqueo_lotes', $bl->id,
            null, $bl->toArray(), "Lote {$bl->lote} del producto {$bl->producto_id} bloqueado: {$bl->motivo}");

        return $this->created($response, $bl, 'Lote bloqueado');
    }

    public function desbloquearLote(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->error($response, 'Solo administradores', 403);

        $data = $request->getParsedBody() ?? [];
        $bl = BloqueoLote::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('activo', true)
            ->find($args['id']);
        if (!$bl) return $this->error($response, 'Bloqueo no encontrado', 404);

        // Soft-delete: antes se borraba físicamente y no quedaba rastro de que el
        // bloqueo existió — crítico ante un recall o auditoría regulatoria de calidad.
        $bl->activo            = false;
        $bl->desbloqueado_por  = $user->id;
        $bl->desbloqueado_at   = date('Y-m-d H:i:s');
        $bl->motivo_desbloqueo = $data['motivo'] ?? null;
        $bl->save();

        $this->audit($user, 'bloqueo', 'desbloquear_lote', 'bloqueo_lotes', $bl->id,
            ['activo' => true], ['activo' => false, 'motivo_desbloqueo' => $bl->motivo_desbloqueo],
            "Lote {$bl->lote} del producto {$bl->producto_id} desbloqueado");

        return $this->ok($response, null, 'Lote desbloqueado');
    }

    public function inventarioBloqueado(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $prodsBloqueados = Producto::withoutTenantScope()
            ->where('empresa_id', $empresaId)
            ->where('bloqueado', true)
            ->pluck('id');

        $lotesBloqueados = BloqueoLote::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->get(['producto_id', 'lote']);

        $query = Inventario::with(['producto:id,codigo_interno,nombre,bloqueado,bloqueo_motivo', 'ubicacion:id,codigo,zona'])
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad', '>', 0);

        $query->where(function ($q) use ($prodsBloqueados, $lotesBloqueados) {
            $q->whereIn('producto_id', $prodsBloqueados);
            foreach ($lotesBloqueados as $bl) {
                $q->orWhere(function ($w) use ($bl) {
                    $w->where('producto_id', $bl->producto_id)->where('lote', $bl->lote);
                });
            }
        });

        $items = $query->orderBy('producto_id')->get();

        return $this->ok($response, $items);
    }
}
