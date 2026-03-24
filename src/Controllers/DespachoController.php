<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Despacho;
use App\Models\CertificacionDespacho;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * DespachoController — Preparación, certificación y cierre de despachos.
 * Flujo: Preparando → Certificado → Despachado
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

        $despachos = Despacho::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
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
        $d    = Despacho::where('empresa_id', $user->empresa_id)
            ->with('certificaciones.producto')
            ->find($a['id']);
        if (!$d) return $this->notFound($res);
        return $this->ok($res, $d);
    }

    // ── POST /api/despachos ───────────────────────────────────────────────────
    public function store(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        try {
            $despacho = Despacho::create([
                'empresa_id'      => $user->empresa_id,
                'sucursal_id'     => $user->sucursal_id,
                'numero_despacho' => 'DSP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                'cliente'         => $data['cliente']   ?? null,
                'ruta'            => $data['ruta']      ?? null,
                'muelle_id'       => $data['muelle_id'] ?? null,
                'total_bultos'    => $data['total_bultos'] ?? 0,
                'peso_total'      => $data['peso_total']   ?? 0,
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
        $despacho = Despacho::where('empresa_id', $user->empresa_id)->find($a['id']);

        if (!$despacho) return $this->notFound($res);
        if ($despacho->estado === 'Despachado') {
            return $this->error($res, 'El despacho ya fue cerrado');
        }

        $required = ['producto_id', 'cantidad_certificada', 'escaneado_por'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        try {
            Capsule::transaction(function () use ($despacho, $data, $user) {
                $cert = CertificacionDespacho::create([
                    'despacho_id'         => $despacho->id,
                    'producto_id'         => $data['producto_id'],
                    'lote'                => $data['lote'] ?? null,
                    'cantidad_certificada'=> (int)$data['cantidad_certificada'],
                    'escaneado_por'       => $data['escaneado_por'],
                ]);

                // Registrar salida en movimiento_inventarios
                MovimientoInventario::create([
                    'empresa_id'           => $user->empresa_id,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $data['producto_id'],
                    'tipo_movimiento'      => 'SalidaDespacho',
                    'cantidad'             => (int)$data['cantidad_certificada'],
                    'ubicacion_origen_id'  => $data['ubicacion_id'] ?? null,
                    'ubicacion_destino_id' => null,
                    'lote'                 => $data['lote'] ?? null,
                    'usuario_id'           => $user->id,
                    'referencia'           => $despacho->numero_despacho,
                    'observaciones'        => "Despacho {$despacho->numero_despacho}",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_movimiento'      => date('H:i:s'),
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

        $data     = $r->getParsedBody() ?? [];
        $despacho = Despacho::where('empresa_id', $user->empresa_id)->find($a['id']);

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

        $despacho = Despacho::where('empresa_id', $user->empresa_id)->find($a['id']);
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

    // ── GET /api/despachos/{id}/reporte ──────────────────────────────────────
    public function reporte(Request $r, Response $res, array $a): Response
    {
        $user     = $r->getAttribute('user');
        $despacho = Despacho::where('empresa_id', $user->empresa_id)
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
