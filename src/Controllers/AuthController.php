<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Personal;
use App\Models\Empresa;
use Firebase\JWT\JWT;

class AuthController extends BaseController
{
    /**
     * POST /api/auth/login
     * Espera: { documento, pin, nit }
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $documento = trim($data['documento'] ?? '');
        $pin = trim($data['pin'] ?? '');
        $empresaId = trim($data['empresa_id'] ?? '');
        $nit = trim($data['nit'] ?? '');

        if (empty($documento) || empty($pin)) {
            return $this->json($response, ['error' => true, 'message' => 'Documento y PIN son requeridos.'], 400);
        }

        // Buscar usuario sin el scope de tenant para diagnóstico detallado
        $user = Personal::withoutTenantScope()
            ->with(['sucursal', 'empresa'])
            ->where('documento', $documento)
            ->first();

        if (!$user) {
            $msg = "Acceso denegado: El documento '{$documento}' no se encuentra registrado en el sistema Fénix.";
            if (!empty($nit)) {
                $msg .= " Verifique si el usuario pertenece a la empresa con NIT {$nit}.";
            }
            return $this->json($response, ['error' => true, 'message' => $msg], 401);
        }

        if (!$user->activo) {
            return $this->json($response, ['error' => true, 'message' => "Acceso denegado: El usuario '{$user->nombre}' se encuentra INACTIVO. Contacte al administrador."], 401);
        }

        // Obtener empresa si se proporcionó NIT o ID
        $selectedEmpresa = null;
        if (!empty($empresaId)) {
            $selectedEmpresa = Empresa::where('id', (int)$empresaId)->where('activo', true)->first();
        } elseif (!empty($nit)) {
            $selectedEmpresa = Empresa::where('nit', $nit)->where('activo', true)->first();
        }

        // Validaciones de pertenencia
        if (!$user->isSuperAdmin()) {
            if (!$selectedEmpresa) {
                return $this->json($response, ['error' => true, 'message' => 'Debe seleccionar una empresa válida para ingresar.'], 401);
            }
            if ($user->empresa_id !== $selectedEmpresa->id) {
                return $this->json($response, ['error' => true, 'message' => "Acceso denegado: El usuario '{$user->nombre}' no está vinculado a la empresa '{$selectedEmpresa->razon_social}' (NIT: {$selectedEmpresa->nit})."], 401);
            }
        }

        if (!$user->verifyPin($pin)) {
            return $this->json($response, ['error' => true, 'message' => "PIN incorrecto para el usuario '{$user->nombre}'. Intente nuevamente."], 401);
        }

        // Determinar ID de empresa para el contexto (JWT)
        // Si es SuperAdmin y seleccionó una empresa, usamos esa. Si no, su propia empresa_id.
        $contextEmpresaId = $selectedEmpresa ? $selectedEmpresa->id : $user->empresa_id;
        $contextEmpresaNombre = $selectedEmpresa ? $selectedEmpresa->razon_social : ($user->empresa ? $user->empresa->razon_social : 'SISTEMA GLOBAL');

        // Actualizar ultimo login
        $user->ultimo_login = date('Y-m-d H:i:s');
        $user->save();

        // Generar JWT
        $config = require __DIR__ . '/../../config/app.php';
        $payload = [
            'iss' => $config['url'],
            'aud' => $config['url'],
            'iat' => time(),
            'exp' => time() + (3600 * 24), // 24 horas
            'uid' => $user->id,
            'rol' => $user->rol,
            'emp' => $contextEmpresaId,
            'suc' => $user->sucursal_id
        ];

        $token = JWT::encode($payload, $config['jwt']['secret'], 'HS256');

        // Extraer permisos
        if ($user->isSuperAdmin()) {
            $permisos = \App\Models\Permiso::all()
                ->map(function ($permiso) {
                    return $permiso->modulo . '.' . $permiso->accion;
                })->toArray();
        } else {
            $permisos = \App\Models\RolPermiso::with('permiso')
                ->where('empresa_id', $user->empresa_id)
                ->where('rol', $user->rol)
                ->where('concedido', true)
                ->get()
                ->map(function ($rp) {
                    return $rp ? $rp->permiso->modulo . '.' . $rp->permiso->accion : 'unknown';
                })->toArray();
        }

        return $this->json($response, [
            'error' => false,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'rol' => $user->rol,
                'empresa_id' => $contextEmpresaId,
                'empresa_nombre' => $contextEmpresaNombre,
                'sucursal_id' => $user->sucursal_id,
                'sucursal_nombre' => $user->sucursal ? $user->sucursal->nombre : null,
                'empresa' => $selectedEmpresa ? [
                    'id' => $selectedEmpresa->id,
                    'razon_social' => $selectedEmpresa->razon_social,
                    'nit' => $selectedEmpresa->nit,
                ] : ($user->empresa ? [
                    'id' => $user->empresa->id,
                    'razon_social' => $user->empresa->razon_social,
                    'nit' => $user->empresa->nit,
                ] : null),
            ],
            'permisos' => $permisos
        ]);
    }

    /**
     * GET /api/auth/me
     * Retorna datos del usuario basándose en el JWT
     */
    public function me(Request $request, Response $response): Response
    {
        $userJwt = $request->getAttribute('user'); 
        $user = Personal::with(['sucursal', 'empresa'])->find($userJwt->uid ?? $userJwt->id);
        
        if (!$user) return $this->json($response, ['error' => true, 'message' => 'Usuario no encontrado'], 404);

        // Identificar empresa del contexto (JWT)
        $empId = $userJwt->emp ?? $user->empresa_id;
        $empresaInfo = $user->empresa;
        if ($empId && (!$empresaInfo || $empresaInfo->id != $empId)) {
            $empresaInfo = Empresa::find($empId);
        }

        if ($user->isSuperAdmin()) {
            $permisos = \App\Models\Permiso::all()
                ->map(fn($p) => $p->modulo . '.' . $p->accion)->toArray();
        } else {
            $permisos = \App\Models\RolPermiso::with('permiso')
                ->where('empresa_id', $user->empresa_id)
                ->where('rol', $user->rol)
                ->where('concedido', true)
                ->get()
                ->map(fn($rp) => $rp->permiso ? $rp->permiso->modulo . '.' . $rp->permiso->accion : 'unknown')
                ->toArray();
        }

        return $this->json($response, [
            'error' => false,
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'rol' => $user->rol,
                'empresa_id' => $empId,
                'empresa_nombre' => $empresaInfo ? $empresaInfo->razon_social : 'SISTEMA GLOBAL',
                'sucursal_id' => $user->sucursal_id,
                'sucursal_nombre' => $user->sucursal ? $user->sucursal->nombre : null,
                'empresa' => $empresaInfo ? [
                    'id' => $empresaInfo->id,
                    'razon_social' => $empresaInfo->razon_social,
                    'nit' => $empresaInfo->nit,
                ] : null,
            ],
            'permisos' => $permisos
        ]);
    }

    /**
     * PUT /api/auth/pin
     * Cambia el PIN del usuario autenticado.
     * Body: { pin_actual, pin_nuevo }
     */
    public function cambiarPin(Request $request, Response $response): Response
    {
        $userJwt = $request->getAttribute('user');
        $data     = $request->getParsedBody() ?? [];

        $pinActual = trim($data['pin_actual'] ?? '');
        $pinNuevo  = trim($data['pin_nuevo']  ?? '');

        if (empty($pinActual) || empty($pinNuevo)) {
            return $this->json($response, ['error' => true, 'message' => 'PIN actual y nuevo son requeridos.'], 400);
        }
        if (strlen($pinNuevo) < 4) {
            return $this->json($response, ['error' => true, 'message' => 'El PIN nuevo debe tener al menos 4 dígitos.'], 422);
        }

        $user = Personal::find($userJwt->id);
        if (!$user || !$user->verifyPin($pinActual)) {
            return $this->json($response, ['error' => true, 'message' => 'PIN actual incorrecto.'], 401);
        }

        $user->pin = Personal::hashPin($pinNuevo);
        $user->save();

        return $this->json($response, ['error' => false, 'message' => 'PIN actualizado correctamente.']);
    }

}
