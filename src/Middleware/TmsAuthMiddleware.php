<?php

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\TenantContext;

/**
 * TmsAuthMiddleware — Autenticación dual para rutas /api/tms/*.
 *
 * Intenta primero JWT (admin UI):
 *   Authorization: Bearer <token>
 *
 * Si no hay JWT, intenta ApiKey (machine-to-machine):
 *   X-API-Key: <key>    |    ?api_key=<key>
 *
 * Inyecta en la request:
 *   auth_type    → 'jwt' | 'apikey'
 *   user         → stdClass (si JWT)
 *   api_key      → stdClass (si ApiKey)
 *   empresa_id   → int (en ambos casos)
 */
class TmsAuthMiddleware
{
    /** @var string|null JWT secret static cache */
    private static ?string $jwtSecret = null;

    public function __invoke(Request $request, $handler): Response
    {
        $logFile = dirname(__DIR__, 3) . '/logs/app.log';

        // ── Intento JWT ────────────────────────────────────────────────────────
        $bearerHeader = $request->getHeaderLine('Authorization');
        if (!empty($bearerHeader) && preg_match('/Bearer\s(\S+)/', $bearerHeader, $m)) {
            $result = $this->tryJwt($m[1], $logFile);
            if ($result['ok']) {
                $request = $request
                    ->withAttribute('auth_type',  'jwt')
                    ->withAttribute('user',        $result['user'])
                    ->withAttribute('empresa_id',  $result['user']->empresa_id)
                    ->withAttribute('sucursal_id', $result['user']->sucursal_id);
                TenantContext::setCurrentTenant($result['user']->empresa_id, $result['user']->sucursal_id);
                return $handler->handle($request);
            }
            // JWT present but invalid → fail immediately (don't fall through to ApiKey)
            return $this->unauthorized($result['error']);
        }

        // ── Intento ApiKey ─────────────────────────────────────────────────────
        $apiKey = $request->getHeaderLine('X-API-Key')
            ?: ($request->getQueryParams()['api_key'] ?? '');

        if (!empty($apiKey)) {
            $result = $this->tryApiKey($apiKey, $logFile);
            if ($result['ok']) {
                $request = $request
                    ->withAttribute('auth_type', 'apikey')
                    ->withAttribute('api_key',   $result['record'])
                    ->withAttribute('empresa_id', $result['record']->empresa_id);
                return $handler->handle($request);
            }
            return $this->unauthorized($result['error']);
        }

        // ── Sin credenciales ───────────────────────────────────────────────────
        return $this->unauthorized('Autenticación requerida (Bearer token o X-API-Key).');
    }

    // ── JWT ────────────────────────────────────────────────────────────────────

    private function tryJwt(string $token, string $logFile): array
    {
        $secret = self::getSecret();
        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $uid     = (int)($decoded->uid ?? 0);

            $user = Capsule::table('personal')
                ->select(['id', 'empresa_id', 'sucursal_id', 'nombre', 'rol', 'activo'])
                ->where('id', $uid)
                ->first();

            if (!$user || !$user->activo) {
                return ['ok' => false, 'error' => 'Usuario inactivo o eliminado.'];
            }
            return ['ok' => true, 'user' => $user];
        } catch (ExpiredException $e) {
            return ['ok' => false, 'error' => 'Token expirado.'];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Token inválido.'];
        }
    }

    // ── ApiKey ─────────────────────────────────────────────────────────────────

    private function tryApiKey(string $rawKey, string $logFile): array
    {
        $keyHash = hash('sha256', $rawKey);
        try {
            $record = Capsule::table('api_keys')
                ->where('key_hash', $keyHash)
                ->where('activo', 1)
                ->first();

            if (!$record) {
                return ['ok' => false, 'error' => 'API key inválida o revocada.'];
            }

            // Throttled ultimo_uso: solo escribe si han pasado > 60 s
            $lastUsed = strtotime($record->ultimo_uso ?? '2000-01-01');
            if (time() - $lastUsed > 60) {
                Capsule::table('api_keys')
                    ->where('id', $record->id)
                    ->update(['ultimo_uso' => date('Y-m-d H:i:s')]);
            }

            return ['ok' => true, 'record' => $record];
        } catch (\Exception $e) {
            @file_put_contents(
                $logFile,
                '[' . date('Y-m-d H:i:s') . "] [ERROR] [TmsAuth/ApiKey] " . $e->getMessage() . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            return ['ok' => false, 'error' => 'Error de autenticación interna.'];
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function getSecret(): string
    {
        if (self::$jwtSecret === null) {
            self::$jwtSecret = getenv('JWT_SECRET')
                ?: ($_ENV['JWT_SECRET'] ?? ($_SERVER['JWT_SECRET'] ?? 'change_this_secret'));
        }
        return self::$jwtSecret;
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => true, 'message' => $message]));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="WMS TMS"');
    }
}
