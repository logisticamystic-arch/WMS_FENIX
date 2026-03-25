<?php

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use App\Models\Personal;

class JwtMiddleware
{
    public function __invoke(Request $request, $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        $logFile = __DIR__ . '/../../../api_error.log';

        if (empty($header) || !preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            // Check query parameter 'token' as fallback (useful for downloads/exports)
            $queryParams = $request->getQueryParams();
            $token = $queryParams['token'] ?? null;
            
            if (!$token) {
                // file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAIL: Token no proporcionado\n", FILE_APPEND);
                return $this->unauthorized('Token no proporcionado.');
            }
        } else {
            $token = $matches[1];
        }

        // Verificar el token
        $config = require __DIR__ . '/../../config/app.php';
        $secret = $config['jwt']['secret'];

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            
            // Validar que el usuario sigue existiendo y está activo
            $user = Personal::find($decoded->uid);
            if (!$user || !$user->activo) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAIL: Usuario inactivo o eliminado\n", FILE_APPEND);
                return $this->unauthorized('Usuario inactivo o eliminado.');
            }

            // Inyectar el usuario en la request
            $request = $request->withAttribute('user', $user);
        } catch (\ExpiredException $e) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAIL: ExpiredException\n", FILE_APPEND);
            return $this->unauthorized('Token expirado. Por favor inicie sesión nuevamente.');
        } catch (\Exception $e) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAIL: Token decode error\n", FILE_APPEND);
            error_log('JWT ERROR: ' . $e->getMessage());
            return $this->unauthorized('Token inválido.');
        }

        // --- Ejecutar el request en el controlador ---
        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => true, 'message' => $message]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
