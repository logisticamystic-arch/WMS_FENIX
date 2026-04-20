<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SystemController extends BaseController
{
    /**
     * GET /api/system/connection-info
     * Retorna la IP local del servidor para facilitar la conexión de dispositivos móviles
     */
    public function getConnectionInfo(Request $request, Response $response): Response
    {
        // Intentar obtener la IP local de la interfaz de red
        $localIP = gethostbyname(gethostname());
        
        // En algunos entornos de XAMPP/Windows, gethostbyname puede retornar 127.0.0.1
        // Intentamos una alternativa si es necesario
        if ($localIP === '127.0.0.1' || str_starts_with($localIP, '127.')) {
            $localIP = $_SERVER['SERVER_ADDR'] ?? $localIP;
        }

        return $this->json($response, [
            'error' => false,
            'local_ip' => $localIP,
            'server_port' => $_SERVER['SERVER_PORT'] ?? '80',
            'protocol' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http',
            'app_path' => '/WMS_PROORIENTE/public',
            'full_url' => "http://{$localIP}/WMS_PROORIENTE/public",
            'mobile_url' => "http://{$localIP}/WMS_PROORIENTE/public/mobile/index.html"
        ]);
    }

    public function validar(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || $user->rol !== 'Admin') {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        $resultados = [];
        $controllersDir = __DIR__;
        $files = scandir($controllersDir);
        
        $totalControllers = 0;
        $erroresDetectados = 0;

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $totalControllers++;
                $content = file_get_contents($controllersDir . '/' . $file);
                
                $issues = [];
                // Validar si extiende BaseController (excepto BaseController mismo)
                if ($file !== 'BaseController.php' && strpos($content, 'extends BaseController') === false) {
                    $issues[] = 'No extiende BaseController';
                }
                
                // Validar bytes nulos
                if (strpos($content, "\0") !== false) {
                    $issues[] = 'Contiene bytes nulos (\0)';
                }
                
                // Validar llaves (unbalanced braces)
                $openBraces = substr_count($content, '{');
                $closeBraces = substr_count($content, '}');
                if ($openBraces !== $closeBraces) {
                    $issues[] = "Llaves desbalanceadas ({$openBraces} abiertas, {$closeBraces} cerradas)";
                }
                
                $resultados[] = [
                    'archivo' => $file,
                    'status' => empty($issues) ? 'OK' : 'ERROR',
                    'issues' => $issues
                ];
                
                if (!empty($issues)) {
                    $erroresDetectados++;
                }
            }
        }
        
        $logFile = realpath(__DIR__ . '/../../logs/app.log');
        $logSize = $logFile && file_exists($logFile) ? filesize($logFile) : 0;
        $logSizeMb = round($logSize / 1024 / 1024, 2);

        return $this->json($response, [
            'error' => false,
            'entorno' => [
                'php_version' => phpversion(),
                'app_env' => $_ENV['APP_ENV'] ?? 'N/A',
                'app_debug' => $_ENV['APP_DEBUG'] ?? 'N/A',
                'opcache' => function_exists('opcache_get_status') && opcache_get_status() !== false ? 'Activo' : 'Inactivo',
                'log_size_mb' => $logSizeMb
            ],
            'auditoria' => [
                'total_controladores' => $totalControllers,
                'errores' => $erroresDetectados,
                'detalles' => $resultados
            ]
        ]);
    }

    public function opcacheReset(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || $user->rol !== 'Admin') {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
            return $this->json($response, ['error' => false, 'message' => 'OPcache limpiado exitosamente']);
        }

        return $this->json($response, ['error' => true, 'message' => 'OPcache no está habilitado'], 400);
    }

    public function limpiarLogs(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || $user->rol !== 'Admin') {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        $logDir = realpath(__DIR__ . '/../../logs');
        $logFile = $logDir ? $logDir . '/app.log' : null;
        if ($logFile && file_exists($logFile)) {
            file_put_contents($logFile, '');
            return $this->json($response, ['error' => false, 'message' => 'Logs limpiados exitosamente']);
        }

        return $this->json($response, ['error' => true, 'message' => 'Archivo de logs no encontrado'], 404);
    }
}
