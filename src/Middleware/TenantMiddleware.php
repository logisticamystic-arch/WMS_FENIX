<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Helpers\TenantContext;

class TenantMiddleware
{
    public function __invoke(Request $request, $handler): Response
    {
        TenantContext::reset();

        $user = $request->getAttribute('user');
        $empresaId = $request->getAttribute('empresa_id');
        $sucursalId = $request->getAttribute('sucursal_id');

        if ($user && isset($user->rol) && strcasecmp($user->rol, 'SuperAdmin') === 0) {
            $query = $request->getQueryParams();
            // SuperAdmin: use query param override if provided
            if (isset($query['empresa_id']) && $query['empresa_id'] !== '') {
                $empresaId = (int)$query['empresa_id'];
                
                // Si el SuperAdmin cambia de empresa, ignorar su sucursal original del JWT
                // a menos que la provea explícitamente en el query string.
                if (isset($query['sucursal_id']) && $query['sucursal_id'] !== '') {
                    $sucursalId = (int)$query['sucursal_id'];
                } else {
                    $sucursalId = null;
                }
            } else {
                // else: keep $empresaId and $sucursalId from JWT
                if (isset($query['sucursal_id']) && $query['sucursal_id'] !== '') {
                    $sucursalId = (int)$query['sucursal_id'];
                }
            }
        }

        if ($empresaId !== null) {
            TenantContext::setCurrentTenant($empresaId, $sucursalId ?? null);
        }

        return $handler->handle($request);
    }
}
