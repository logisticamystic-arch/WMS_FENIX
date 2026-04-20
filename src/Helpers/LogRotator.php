<?php

namespace App\Helpers;

/**
 * LogRotator — Mantiene el archivo de logs en un tamaño manejable.
 * Evita que app.log crezca indefinidamente en el entorno XAMPP.
 */
class LogRotator
{
    /**
     * Verifica el tamaño del log y lo trunca si excede el límite.
     * Solo se ejecuta ocasionalmente para no afectar el rendimiento.
     * 
     * @param string $logPath Ruta absoluta al archivo .log
     * @param int $maxLines  Cantidad de líneas finales a conservar
     * @param int $maxSize   Tamaño máximo en bytes (ej: 5MB)
     */
    public static function checkAndRotate(string $logPath, int $maxLines = 5000, int $maxSize = 5242880): void
    {
        // 1. Verificar si el archivo existe
        if (!file_exists($logPath)) {
            return;
        }

        // 2. Verificar tamaño
        if (filesize($logPath) <= $maxSize) {
            return;
        }

        try {
            // 3. Leer todas las líneas
            $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                return;
            }

            // 4. Tomar solo las últimas $maxLines
            $count = count($lines);
            if ($count > $maxLines) {
                $tail = array_slice($lines, -$maxLines);
                
                // 5. Sobrescribir el archivo
                file_put_contents($logPath, implode(PHP_EOL, $tail) . PHP_EOL);
                
                // Opcional: Registrar que se rotó el log
                error_log("[LogRotator] Rotación automática ejecutada: de {$count} a {$maxLines} líneas.");
            }
        } catch (\Throwable $e) {
            // No queremos que una falla en rotación rompa la app
            error_log("[LogRotator] Error: " . $e->getMessage());
        }
    }
}
