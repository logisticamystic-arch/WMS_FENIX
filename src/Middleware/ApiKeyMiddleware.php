<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ApiKeyMiddleware — Machine-to-machine authentication for TMS integration.
 * Reads X-API-Key header, validates against api_keys table.
 */
class ApiKeyMiddleware
{
    public function __invoke(Request $request, $handler): Response
    {
        $apiKey = $request->getHeaderLine('X-API-Key');

        // Also allow ?api_key= query param for easy testing
        if (empty($apiKey)) {
            $apiKey = $request->getQueryParams()['api_key'] ?? '';
        }

        if (empty($apiKey)) {
            return $this->unauthorized('API key requerida. Use el header X-API-Key.');
        }

        // Hash the incoming key and look it up
        $keyHash = hash('sha256', $apiKey);

        try {
            $record = DB::table('api_keys')
                ->where('key_hash', $keyHash)
                ->where('activo', 1)
                ->first();

            if (!$record) {
                return $this->unauthorized('API key inválida o revocada.');
            }

            // Update last_used_at (non-blocking — best-effort)
            DB::table('api_keys')
                ->where('id', $record->id)
                ->update(['last_used_at' => date('Y-m-d H:i:s')]);

            // Inject API key record into request attributes
            $request = $request->withAttribute('api_key', $record);
            $request = $request->withAttribute('empresa_id', $record->empresa_id);

        } catch (\Exception $e) {
            error_log('ApiKeyMiddleware error: ' . $e->getMessage());
            return $this->serverError('Error de autenticación interna.');
        }

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error'   => true,
            'message' => $message,
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    private function serverError(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error'   => true,
            'message' => $message,
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}
