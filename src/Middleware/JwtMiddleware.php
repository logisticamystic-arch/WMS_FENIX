<?php

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * JwtMiddleware — Autentica el Bearer token en cada request protegida.
 *
 * Optimizaciones:
 *  - Configuración cacheada en propiedad estática (evita require por request)
 *  - SELECT solo columnas necesarias (no carga el modelo completo)
 *  - Tracking de actividad throttled: UPDATE solo si han pasado > 60 s
 *  - Log centralizado en logs/app.log (mismo que index.php)
 */
class JwtMiddleware
{
    /** @var string|null Cached JWT secret — loaded once per process */
    private static ?string $jwtSecret = null;

    /** Log centralizado del proyecto */
    private static string $logFile = '';

    public function __invoke(Request $request, $handler): Response
    {
        self::$logFile = dirname(__DIR__, 3) . '/logs/app.log';

        // ── 1. Extraer token ───────────────────────────────────────────────────
        $header = $request->getHeaderLine('Authorization');
        if (!empty($header) && preg_match('/Bearer\s(\S+)/', $header, $m)) {
            $token = $m[1];
        } else {
            // Fallback query param (útil para exports/downloads)
            $token = $request->getQueryParams()['token'] ?? null;
        }

        if (empty($token)) {
            return $this->unauthorized('Token no proporcionado.');
        }

        // ── 2. Decodificar ─────────────────────────────────────────────────────
        $secret = self::getSecret();

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (ExpiredException $e) {
            $this->writeLog('WARN', 'JWT expirado uid=' . ($decoded->uid ?? '?'));
            return $this->unauthorized('Token expirado. Por favor inicie sesión nuevamente.');
        } catch (\Exception $e) {
            $this->writeLog('WARN', 'JWT inválido: ' . $e->getMessage());
            return $this->unauthorized('Token inválido.');
        }

        // ── 3. Cargar usuario (solo columnas necesarias) ────────────────────────
        $uid  = (int)($decoded->uid ?? 0);
        $user = Capsule::table('personal')
            ->select([
                'id', 'empresa_id', 'sucursal_id', 'nombre', 'documento',
                'rol', 'activo', 'ultima_actividad',
            ])
            ->where('id', $uid)
            ->first();

        if (!$user || !$user->activo) {
            $this->writeLog('WARN', "Usuario inactivo/eliminado uid={$uid}");
            return $this->unauthorized('Usuario inactivo o eliminado.');
        }

        // ── 4. Tracking de actividad throttled (máx. 1 UPDATE por minuto) ──────
        $lastTs  = strtotime($user->ultima_actividad ?? '2000-01-01');
        $elapsed = time() - $lastTs;
        if ($elapsed > 60) {
            Capsule::table('personal')
                ->where('id', $uid)
                ->update(['ultima_actividad' => date('Y-m-d H:i:s')]);
        }

        // ── 5. Inyectar usuario en la request ──────────────────────────────────
        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Lee el JWT_SECRET una sola vez por proceso (static cache).
     * Evita el require config/app.php en cada request.
     */
    private static function getSecret(): string
    {
        if (self::$jwtSecret === null) {
            self::$jwtSecret = getenv('JWT_SECRET')
                ?: ($_ENV['JWT_SECRET'] ?? ($_SERVER['JWT_SECRET'] ?? 'change_this_secret'));
        }
        return self::$jwtSecret;
    }

    private function writeLog(string $level, string $msg): void
    {
        if (self::$logFile) {
            @file_put_contents(
                self::$logFile,
                '[' . date('Y-m-d H:i:s') . "] [{$level}] [JWT] {$msg}" . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => true, 'message' => $message]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
