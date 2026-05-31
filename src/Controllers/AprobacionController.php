<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\AprobacionVencimiento;
use Illuminate\Database\Capsule\Manager as Capsule;

class AprobacionController extends BaseController
{
    // ── GET /api/aprobaciones/vencimiento/pendientes ──────────────────────────
    // Admin/Supervisor: lista solicitudes pendientes de la empresa/sucursal
    public function pendientes(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $rows = Capsule::table('aprobaciones_vencimiento as av')
            ->join('productos as p',        'p.id',  '=', 'av.producto_id')
            ->join('personal as sol',        'sol.id', '=', 'av.solicitado_por')
            ->where('av.empresa_id',  $empresaId)
            ->where('av.sucursal_id', $sucursalId)
            ->where('av.estado', 'pendiente')
            ->orderBy('av.created_at', 'asc')
            ->select([
                'av.id',
                'av.producto_id',
                'p.nombre as producto_nombre',
                'p.codigo_interno as producto_codigo',
                'av.lote',
                'av.dias_restantes',
                'av.solicitado_por',
                Capsule::raw("CONCAT(sol.nombre) as auxiliar_nombre"),
                'av.created_at',
            ])
            ->get();

        return $this->ok($res, $rows);
    }

    // ── POST /api/aprobaciones/{id}/resolver ──────────────────────────────────
    // Admin/Supervisor: aprobar o rechazar
    public function resolver(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $r->getParsedBody() ?? [];
        if (empty($data['decision']) || !in_array($data['decision'], ['aprobada', 'rechazada'])) {
            return $this->error($res, 'decision debe ser "aprobada" o "rechazada"');
        }

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $aprobacion = AprobacionVencimiento::where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('estado', 'pendiente')
            ->find((int)$a['id']);

        if (!$aprobacion) {
            return $this->notFound($res, 'Solicitud no encontrada o ya resuelta');
        }

        $aprobacion->estado      = $data['decision'];
        $aprobacion->aprobado_por = $user->id;
        if ($data['decision'] === 'aprobada') {
            $aprobacion->valid_until = date('Y-m-d');
        }
        $aprobacion->save();

        $this->audit($user, 'aprobacion_vencimiento', $data['decision'],
            'aprobaciones_vencimiento', $aprobacion->id, null,
            ['decision' => $data['decision']],
            "Solicitud de vencimiento #{$aprobacion->id} {$data['decision']}");

        return $this->ok($res, $aprobacion, 'Solicitud ' . $data['decision']);
    }

    // ── GET /api/aprobaciones/{id}/estado ─────────────────────────────────────
    // Auxiliar: polling — retorna estado actual de su solicitud
    public function estado(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $aprobacion = AprobacionVencimiento::where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)$a['id']);
        if (!$aprobacion) return $this->notFound($res);

        // Solo el solicitante o un supervisor puede consultar
        $isSupervisor = $this->isSupervisorOrAbove($user);
        if (!$isSupervisor && $aprobacion->solicitado_por !== $user->id) {
            return $this->forbidden($res);
        }

        return $this->ok($res, [
            'id'             => $aprobacion->id,
            'estado'         => $aprobacion->estado,
            'dias_restantes' => $aprobacion->dias_restantes,
        ]);
    }

    // ── DELETE /api/aprobaciones/{id} ─────────────────────────────────────────
    // Auxiliar: cancela su propia solicitud pendiente
    public function cancelar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');

        $aprobacion = AprobacionVencimiento::where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('solicitado_por', $user->id)
            ->where('estado', 'pendiente')
            ->find((int)$a['id']);

        if (!$aprobacion) {
            return $this->notFound($res, 'Solicitud no encontrada o ya resuelta');
        }

        $aprobacion->estado = 'rechazada';
        $aprobacion->save();

        return $this->ok($res, null, 'Solicitud cancelada');
    }
}
