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
            // SuperAdmin sees all companies by default; empresa_id can be scoped via query param
            $empresaId = (isset($query['empresa_id']) && $query['empresa_id'] !== '')
                ? (int)$query['empresa_id']
                : null;
            $sucursalId = (isset($query['sucursal_id']) && $query['sucursal_id'] !== '')
                ? (int)$query['sucursal_id']
                : null;
        }

        if ($empresaId !== null) {
            TenantContext::setCurrentTenant($empresaId, $sucursalId ?? null);
        }

        return $handler->handle($request);
    }
}
