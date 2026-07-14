<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Despacho;
use App\Models\CertificacionDespacho;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\OrdenPicking;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * DespachoController — Preparación, certificación y cierre de despachos.
 * Flujo: Preparando → Certificado → Despachado → Entregado (liquidar)
 * Cada transición queda en audit_logs y movimiento_inventarios.
 */
class DespachoController extends BaseController
{
    // ── GET /api/despachos ────────────────────────────────────────────────────
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $despachos = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereBetween('fecha_movimiento', [substr($ini, 0, 10), substr($fin, 0, 10)])
            ->when($params['estado'] ?? null, fn($q, $e) => $q->where('estado', $e))
            ->orderBy('fecha_movimiento', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# Despacho', 'Cliente', 'Ruta', 'Estado', 'Bultos', 'Peso (kg)', 'Fecha'];
            $rows = $despachos->map(fn($d) => [
                $d->numero_despacho, $d->cliente ?? '—', $d->ruta ?? '—',
                $d->estado, $d->total_bultos, $d->peso_total, $d->fecha_movimiento,
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'despachos_' . date('Y-m-d'));
        }

        return $this->ok($res, $despachos);
    }

    // ── GET /api/despachos/{id} ───────────────────────────────────────────────
    public function ver(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $d    = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with([
                'certificaciones.producto',
                'ordenes:id,numero_orden,planilla_numero,cliente,sucursal_entrega,estado,estado_certificacion,estado_despacho,fecha_movimiento',
                'rutaObj:id,nombre',
            ])
            ->find($a['id']);
        if (!$d) return $this->notFound($res);
        return $this->ok($res, $d);
    }

    // ── POST /api/despachos ───────────────────────────────────────────────────
    public function store(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $data = $r->getParsedBody() ?? [];

        try {
            $despacho = Despacho::create([
                'empresa_id'      => $empresaId,
                'sucursal_id'     => $sucursalId,
                'numero_despacho' => Despacho::generarNumero($sucursalId),
                'cliente'         => $data['cliente']      ?? null,
                'ruta_id'         => !empty($data['ruta_id']) ? (int)$data['ruta_id'] : null,
                'ruta'            => $data['ruta'] ?? (
                    !empty($data['ruta_id'])
                        ? (\App\Models\Ruta::find((int)$data['ruta_id'])?->nombre ?? null)
                        : null
                ),
                'conductor'       => $data['conductor']    ?? null,
                'placa'           => $data['placa']        ?? null,
                'muelle_id'       => $data['muelle_id']    ?? null,
                'total_bultos'    => $data['total_bultos'] ?? 0,
                'peso_total'      => $data['peso_total']   ?? 0,
                'observaciones'   => $data['observaciones'] ?? null,
                'auxiliar_id'     => $data['auxiliar_id']  ?? null,
                'fecha_movimiento'=> $data['fecha']        ?? date('Y-m-d'),
                'hora_inicio'     => date('H:i:s'),
                'estado'          => 'Preparando',
            ]);

            $this->audit($user, 'despacho', 'crear', 'despachos', $despacho->id,
                null, $despacho->toArray(), "Despacho {$despacho->numero_despacho} creado");

            return $this->created($res, $despacho);
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/despachos/{id}/certificar ───────────────────────────────────
    // Registra cada producto certificado y descuenta inventario.
    public function certify(Request $r, Response $res, array $a): Response
    {
        $user     = $r->getAttribute('user');
        $data     = $r->getParsedBody() ?? [];
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);

        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Despachado') {
            return $this->error($res, 'El despacho ya fue cerrado');
        }

        $required = ['producto_id', 'cantidad_certificada', 'escaneado_por'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        try {
            Capsule::transaction(function () use ($despacho, $data, $user, $empresaId, $sucursalId) {
                $cert = CertificacionDespacho::create([
                    'despacho_id'         => $despacho->id,
                    'producto_id'         => $data['producto_id'],
                    'lote'                => $data['lote'] ?? null,
                    'cantidad_certificada'=> (float)$data['cantidad_certificada'],
                    'escaneado_por'       => $data['escaneado_por'],
                ]);

                // Descontar stock del inventario físico
                $inv = \App\Models\Inventario::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('producto_id', $data['producto_id'])
                    ->where('ubicacion_id', $data['ubicacion_id'] ?? null)
                    ->lockForUpdate()
                    ->first();
                if ($inv) {
                    $inv->cantidad = max(0, ($inv->cantidad ?? 0) - (float)$data['cantidad_certificada']);
                    $inv->save();
                }

                // Registrar salida en movimiento_inventarios
                MovimientoInventario::create([
                    'empresa_id'           => $empresaId,
                    'sucursal_id'          => $sucursalId,
                    'producto_id'          => $data['producto_id'],
                    'tipo_movimiento'      => 'Salida',
                    'cantidad'             => (float)$data['cantidad_certificada'],
                    'ubicacion_origen_id'  => $data['ubicacion_id'] ?? null,
                    'ubicacion_destino_id' => null,
                    'lote'                 => $data['lote'] ?? null,
                    'auxiliar_id'          => $user->id,
                    'referencia_tipo'      => 'despachos',
                    'referencia_id'        => $despacho->id,
                    'observaciones'        => "Despacho {$despacho->numero_despacho}",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_fin'             => date('H:i:s'),
                ]);

                if ($despacho->estado === 'Preparando') {
                    $despacho->estado = 'Certificado';
                    $despacho->save();
                }
            });

            $this->audit($user, 'despacho', 'certificar_item', 'despachos', $despacho->id,
                null, $data, "Producto {$data['producto_id']} certificado en {$despacho->numero_despacho}");

            return $this->ok($res, null, 'Producto certificado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/despachos/{id}/cerrar ───────────────────────────────────────
    public function close(Request $r, Response $res, array $a): Response
    {
        $user     = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $data     = $r->getParsedBody() ?? [];
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);

        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Despachado') {
            return $this->error($res, 'El despacho ya está cerrado');
        }

        $despacho->estado   = 'Despachado';
        $despacho->hora_fin = date('H:i:s');
        if (!empty($data['total_bultos'])) $despacho->total_bultos = $data['total_bultos'];
        if (!empty($data['peso_total']))   $despacho->peso_total   = $data['peso_total'];
        $despacho->save();

        $this->audit($user, 'despacho', 'cerrar', 'despachos', $despacho->id,
            ['estado' => 'Certificado'], ['estado' => 'Despachado'],
            "Despacho {$despacho->numero_despacho} cerrado");

        return $this->ok($res, $despacho, 'Despacho cerrado exitosamente');
    }

    // ── DELETE /api/despachos/{id} — solo Admin ───────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Despachado') {
            return $this->error($res, 'No se puede eliminar un despacho ya despachado');
        }

        $snapshot = $despacho->toArray();
        $despacho->certificaciones()->delete();
        $despacho->delete();

        $this->audit($user, 'despacho', 'eliminar', 'despachos', $a['id'],
            $snapshot, null, "Despacho {$snapshot['numero_despacho']} eliminado por Admin");

        return $this->ok($res, null, 'Despacho eliminado');
    }

    // ── POST /api/despachos/{id}/pedidos ─────────────────────────────────────
    // Asocia ordenes de picking al despacho y las marca como 'Despachado'.
    public function agregarPedidos(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId= $this->getEffectiveSucursalId($user, $r);
        $data      = $r->getParsedBody() ?? [];

        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Entregado') {
            return $this->error($res, 'El despacho ya fue liquidado');
        }

        $ordenIds = array_filter(array_map('intval', (array)($data['orden_ids'] ?? [])));
        if (empty($ordenIds)) {
            return $this->error($res, 'Debe seleccionar al menos un pedido');
        }

        try {
            Capsule::transaction(function () use ($despacho, $ordenIds, $empresaId, $sucursalId) {
                // Verifica que las ordenes sean válidas y de la misma empresa/sucursal
                $ordenes = OrdenPicking::where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->whereIn('id', $ordenIds)
                    ->get();

                foreach ($ordenes as $orden) {
                    // Evita duplicados con sincronización sin detach
                    Capsule::table('despacho_ordenes')->insertOrIgnore([
                        'despacho_id'      => $despacho->id,
                        'orden_picking_id' => $orden->id,
                        'created_at'       => date('Y-m-d H:i:s'),
                        'updated_at'       => date('Y-m-d H:i:s'),
                    ]);
                    // Marca la orden como Despachada
                    $orden->estado_despacho = 'Despachado';
                    $orden->despacho_id     = $despacho->id;
                    $orden->save();
                }
            });

            $this->audit($user, 'despacho', 'agregar_pedidos', 'despachos', $despacho->id,
                null, ['orden_ids' => $ordenIds],
                "Pedidos " . implode(',', $ordenIds) . " asociados al despacho {$despacho->numero_despacho}");

            $despacho->load('ordenes');
            return $this->ok($res, $despacho, 'Pedidos asociados correctamente');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── DELETE /api/despachos/{id}/pedidos/{orden_id} ─────────────────────────
    public function eliminarPedido(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId= $this->getEffectiveSucursalId($user, $r);

        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Entregado') {
            return $this->error($res, 'No se puede modificar un despacho liquidado');
        }

        $ordenId = (int)$a['orden_id'];
        Capsule::table('despacho_ordenes')
            ->where('despacho_id', $despacho->id)
            ->where('orden_picking_id', $ordenId)
            ->delete();

        // Revierte estado_despacho si no está en otro despacho
        $enOtro = Capsule::table('despacho_ordenes')
            ->where('orden_picking_id', $ordenId)
            ->exists();
        if (!$enOtro) {
            OrdenPicking::where('id', $ordenId)
                ->update(['estado_despacho' => null, 'despacho_id' => null]);
        }

        return $this->ok($res, null, 'Pedido removido del despacho');
    }

    // ── POST /api/despachos/{id}/liquidar ─────────────────────────────────────
    // Liquida el despacho: marca estado='Entregado' y ordenes como 'Entregado'.
    public function liquidar(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId= $this->getEffectiveSucursalId($user, $r);

        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Entregado') {
            return $this->error($res, 'El despacho ya fue liquidado');
        }

        try {
            Capsule::transaction(function () use ($despacho) {
                $despacho->estado   = 'Entregado';
                $despacho->hora_fin = date('H:i:s');
                $despacho->save();

                // Marca todas las ordenes del despacho como Entregadas
                $ordenIds = Capsule::table('despacho_ordenes')
                    ->where('despacho_id', $despacho->id)
                    ->pluck('orden_picking_id')
                    ->toArray();

                if (!empty($ordenIds)) {
                    OrdenPicking::whereIn('id', $ordenIds)
                        ->update(['estado_despacho' => 'Entregado']);
                }
            });

            $this->audit($user, 'despacho', 'liquidar', 'despachos', $despacho->id,
                ['estado' => 'Despachado'], ['estado' => 'Entregado'],
                "Despacho {$despacho->numero_despacho} liquidado — pedidos marcados Entregado");

            return $this->ok($res, $despacho, 'Despacho liquidado. Los pedidos han sido marcados como Entregados.');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── GET /api/despachos/{id}/reporte ──────────────────────────────────────
    public function reporte(Request $r, Response $res, array $a): Response
    {
        $user     = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);
        $despacho = Despacho::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with('certificaciones.producto')
            ->find($a['id']);
        if (!$despacho) return $this->notFound($res);

        $headers = ['Producto', 'Código', 'Lote', 'Cantidad Certificada', 'Escaneado Por'];
        $rows = $despacho->certificaciones->map(fn($c) => [
            $c->producto->nombre          ?? '—',
            $c->producto->codigo_interno  ?? '—',
            $c->lote                      ?? '—',
            $c->cantidad_certificada,
            $c->escaneado_por,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows,
            'despacho_' . $despacho->numero_despacho);
    }
}
