<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Certificacion;
use App\Models\CertificacionDetalle;
use Illuminate\Database\Capsule\Manager as Capsule;

class OutboundController extends BaseController
{
    /**
     * POST /api/certificaciones/start
     */
    public function startCertificacion(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $cert = Certificacion::create([
            'empresa_id' => $this->getEffectiveEmpresaId($user, $request),
            'usuario_id' => $user->id,
            'tipo' => $data['tipo'], // 'Consolidado' or 'Detalle'
            'fecha_inicio' => date('Y-m-d H:i:s'),
            'observaciones' => $data['observaciones'] ?? null,
        ]);

        return $this->ok($response, ['id' => $cert->id]);
    }

    /**
     * POST /api/certificaciones/{id}/linea
     */
    public function addCertificacionLinea(Request $request, Response $response, array $args): Response
    {
        $user   = $request->getAttribute('user');
        $certId = $args['id'];
        $data   = $request->getParsedBody();

        // Validar tenant ANTES de insertar: sin este filtro, cualquier usuario autenticado
        // de cualquier empresa podía adivinar un certificacion_id ajeno e insertarle líneas.
        $cert = Certificacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($certId);
        if (!$cert) return $this->error($response, 'Certificación no encontrada', 404);

        CertificacionDetalle::create([
            'certificacion_id' => $cert->id,
            'producto_id'      => $data['producto_id'],
            'cliente_id'       => $data['cliente_id'] ?? null,
            'cantidad_esperada'=> $data['cantidad_esperada'],
            'cantidad_contada' => $data['cantidad_contada'],
        ]);

        return $this->ok($response, null, 'Línea registrada');
    }

    /**
     * POST /api/certificaciones/{id}/end
     */
    public function endCertificacion(Request $request, Response $response, array $args): Response
    {
        $user   = $request->getAttribute('user');
        $certId = $args['id'];
        // Filtrado por empresa_id: sin esto, cualquier usuario autenticado podía cerrar/leer
        // la certificación de otra empresa con solo adivinar el id (fuga cross-tenant).
        $cert = Certificacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($certId);
        if (!$cert) return $this->error($response, 'Certificación no encontrada', 404);

        $cert->fecha_fin = date('Y-m-d H:i:s');

        $hasDiff = CertificacionDetalle::where('certificacion_id', $certId)
            ->whereColumn('cantidad_esperada', '!=', 'cantidad_contada')
            ->exists();

        $cert->diferencias = $hasDiff;
        $cert->save();

        return $this->ok($response, ['diferencias' => $hasDiff]);
    }

    /**
     * GET /api/certificaciones/reporte
     */
    public function getCertificacionesReport(Request $request, Response $response): Response
    {
        $user  = $request->getAttribute('user');
        $certs = Certificacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->with(['detalles.producto', 'detalles.cliente'])
            ->orderBy('created_at', 'desc')
            ->get();
        return $this->ok($response, $certs);
    }
}
