<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Personal;
use App\Models\Empresa;
use Firebase\JWT\JWT;

class AuthController
{
    /**
     * POST /api/auth/login
     * Espera: { documento, pin, nit }
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $documento = $data['documento'] ?? '';
        $pin = $data['pin'] ?? '';
        $nit = $data['nit'] ?? '900000001'; // Default = Prooriente nit

        if (empty($documento) || empty($pin)) {
            return $this->json($response, ['error' => true, 'message' => 'Documento y PIN son requeridos.'], 400);
        }

        // Buscar empresa por NIT
        $empresa = Empresa::where('nit', $nit)->where('activo', true)->first();
        if (!$empresa) {
            return $this->json($response, ['error' => true, 'message' => 'Empresa inactiva o no encontrada.'], 401);
        }

        // Buscar operador
        $user = Personal::with('sucursal')->where('empresa_id', $empresa->id)
            ->where('documento', $documento)
            ->where('activo', true)
            ->first();

        if (!$user) {
            return $this->json($response, ['error' => true, 'message' => 'Credenciales inválidas.'], 401);
        }

        if (!$user->verifyPin($pin)) {
            error_log("LOGIN FAIL: authentication failed for user ID " . $user->id);
            return $this->json($response, ['error' => true, 'message' => 'Credenciales inválidas.'], 401);
        }

        // Actualizar ultimo login
        $user->ultimo_login = date('Y-m-d H:i:s');
        $user->save();

        // Generar JWT
        $config = require __DIR__ . '/../../config/app.php';
        $payload = [
            'iss' => $config['url'],
            'aud' => $config['url'],
            'iat' => time(),
            'exp' => time() + (3600 * 24), // 24 horas para dev
            'uid' => $user->id,
            'rol' => $user->rol,
            'emp' => $user->empresa_id,
            'suc' => $user->sucursal_id
        ];

        $token = JWT::encode($payload, $config['jwt']['secret'], 'HS256');

        // Extraer permisos (optimización para PWA)
        $permisos = \App\Models\RolPermiso::with('permiso')
            ->where('empresa_id', $user->empresa_id)
            ->where('rol', $user->rol)
            ->where('concedido', true)
            ->get()
            ->map(function ($rp) {
                if (!$rp->permiso) {
                    error_log("ORPHAN PERMISO for Rol: " . $rp->rol . " RP ID: " . $rp->id);
                    return 'unknown.unknown';
                }
                return $rp->permiso->modulo . '.' . $rp->permiso->accion;
            })->toArray();
        error_log("PERMISOS MAP READY");

        return $this->json($response, [
            'error' => false,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'rol' => $user->rol,
                'sucursal' => $user->sucursal ? $user->sucursal->nombre : null
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
        $user = $request->getAttribute('user'); // Inyectado por JwtMiddleware
        
        $permisos = \App\Models\RolPermiso::with('permiso')
            ->where('empresa_id', $user->empresa_id)
            ->where('rol', $user->rol)
            ->where('concedido', true)
            ->get()
            ->map(function ($rp) {
                return $rp->permiso->modulo . '.' . $rp->permiso->accion;
            })->toArray();

        return $this->json($response, [
            'error' => false,
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'rol' => $user->rol,
                'empresa_id' => $user->empresa_id,
                'sucursal_id' => $user->sucursal_id,
            ],
            'permisos' => $permisos
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
