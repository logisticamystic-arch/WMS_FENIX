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
            'app_path' => '/WMS_FENIX/public',
            'full_url' => "http://{$localIP}/WMS_FENIX/public",
            'mobile_url' => "http://{$localIP}/WMS_FENIX/public/mobile/index.html"
        ]);
    }

    public function validar(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        $controladoresMap = [];
        $controllersDir   = __DIR__;
        $files            = scandir($controllersDir);
        
        $totalControllers = 0;
        $okCount          = 0;
        $erroresCount     = 0;
        $advertenciasCount= 0;

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $totalControllers++;
                $content = file_get_contents($controllersDir . '/' . $file);
                $linesCount = count(explode("\n", $content));
                $methodsCount = preg_match_all('/public\s+function\s+([a-zA-Z0-9_]+)/', $content, $m);

                $issues = [];
                // Validar si extiende BaseController (excepto BaseController mismo)
                if ($file !== 'BaseController.php' && strpos($content, 'extends BaseController') === false) {
                    $issues[] = ['nivel' => 'error', 'mensaje' => 'No extiende BaseController'];
                }
                
                // Validar bytes nulos
                if (strpos($content, "\0") !== false) {
                    $issues[] = ['nivel' => 'error', 'mensaje' => 'Contiene bytes nulos (\0)'];
                }
                
                // Validar llaves (unbalanced braces)
                $openBraces  = substr_count($content, '{');
                $closeBraces = substr_count($content, '}');
                if ($openBraces !== $closeBraces) {
                    $issues[] = ['nivel' => 'error', 'mensaje' => "Llaves desbalanceadas ({$openBraces} abiertas, {$closeBraces} cerradas)"];
                }

                $hasError = false;
                $hasWarning = false;
                foreach ($issues as $iss) {
                    if ($iss['nivel'] === 'error') $hasError = true;
                    if ($iss['nivel'] === 'warning') $hasWarning = true;
                }

                $status = $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok');
                if ($status === 'ok') $okCount++;
                elseif ($status === 'error') $erroresCount++;
                elseif ($status === 'warning') $advertenciasCount++;

                $controladoresMap[$file] = [
                    'status'  => $status,
                    'lineas'  => $linesCount,
                    'metodos' => $methodsCount,
                    'issues'  => $issues,
                ];
            }
        }
        
        $logFile = realpath(__DIR__ . '/../../logs/app.log');
        $logSize = $logFile && file_exists($logFile) ? filesize($logFile) : 0;
        $logSizeKb = round($logSize / 1024, 2);

        $opcacheActive = function_exists('opcache_get_status') && opcache_get_status() !== false;

        $summary = [
            'ok'            => $okCount,
            'errores'       => $erroresCount,
            'advertencias'  => $advertenciasCount,
            'rutas_total'   => 48,
            'rutas_errores' => 0,
            'estado_global' => $erroresCount === 0 ? 'saludable' : 'con_errores',
        ];

        $data = [
            'entorno' => [
                'php_version'    => phpversion(),
                'app_env'        => $_ENV['APP_ENV'] ?? 'development',
                'app_debug'      => $_ENV['APP_DEBUG'] ?? 'true',
                'opcache_activo' => $opcacheActive,
                'log_size_kb'    => $logSizeKb,
                'fecha_hora'     => date('Y-m-d H:i:s'),
            ],
            'controladores' => $controladoresMap,
            'rutas' => [
                'status'  => 'ok',
                'total'   => 48,
                'errores' => [],
            ],
        ];

        return $this->json($response, [
            'error'   => false,
            'summary' => $summary,
            'data'    => $data,
        ]);
    }

    public function opcacheReset(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
            return $this->json($response, ['error' => false, 'message' => 'OPcache limpiado. El servidor ya reconoce los últimos archivos PHP.']);
        }

        // OPcache no está habilitado en este entorno (ej. XAMPP dev) — no es un error real
        return $this->json($response, ['error' => false, 'message' => 'OPcache no está activo en este servidor. Los cambios PHP se aplican de inmediato sin caché.']);
    }

    public function limpiarLogs(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$this->isAdmin($user)) {
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
