<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * PerformanceMiddleware — Mide el tiempo de cada request.
 *
 * - Agrega X-Response-Time a TODAS las respuestas
 * - Registra en performance_metrics las que superen $umbralMs
 * - Loguea en logs/app.log para análisis offline
 */
class PerformanceMiddleware
{
    private int $umbralMs;

    public function __construct(int $umbralMs = 1500)
    {
        $this->umbralMs = $umbralMs;
    }

    public function __invoke(Request $request, $handler): Response
    {
        $inicio = microtime(true);
        $response = $handler->handle($request);
        $duracionMs = (int)round((microtime(true) - $inicio) * 1000);
        $memoriaKb  = (int)round(memory_get_peak_usage(true) / 1024);

        $response = $response->withHeader('X-Response-Time', $duracionMs . 'ms');

        if ($duracionMs >= $this->umbralMs) {
            $this->logSlowRequest($request, $response, $duracionMs, $memoriaKb);
        }

        return $response;
    }

    private function logSlowRequest(Request $request, Response $response, int $duracionMs, int $memoriaKb): void
    {
        try {
            $uri       = (string)$request->getUri()->getPath();
            $pattern   = preg_replace('/\/\d+/', '/{id}', $uri);
            $user      = $request->getAttribute('user');
            $empresaId = $user ? ($user->empresa_id ?? null) : null;
            $usuarioId = $user ? ($user->id ?? null) : null;

            if (Capsule::schema()->hasTable('performance_metrics')) {
                Capsule::table('performance_metrics')->insert([
                    'empresa_id'       => $empresaId,
                    'metodo'           => $request->getMethod(),
                    'endpoint'         => $uri,
                    'endpoint_pattern' => $pattern,
                    'duracion_ms'      => $duracionMs,
                    'status_code'      => $response->getStatusCode(),
                    'memoria_kb'       => $memoriaKb,
                    'ip'               => $_SERVER['REMOTE_ADDR'] ?? null,
                    'usuario_id'       => $usuarioId,
                    'created_at'       => date('Y-m-d H:i:s'),
                    'updated_at'       => date('Y-m-d H:i:s'),
                ]);
            }

            $logFile = dirname(__DIR__, 2) . '/logs/app.log';
            @file_put_contents(
                $logFile,
                '[' . date('Y-m-d H:i:s') . "] [SLOW] {$request->getMethod()} {$uri} {$duracionMs}ms {$memoriaKb}KB" . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Exception $e) {
            error_log('PerformanceMiddleware: ' . $e->getMessage());
        }
    }
}