<?php

/**
 * Global helper functions for Fénix WMS.
 */

if (!function_exists('wmsLog')) {
    /**
     * Standardized logging for WMS operations.
     */
    function wmsLog(string $level, string $message, array $context = [])
    {
        $logPath = __DIR__ . '/../../logs/wms_' . date('Y-m-d') . '.log';
        $logDir = dirname($logPath);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $entry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        
        file_put_contents($logPath, $entry, FILE_APPEND);
    }
}
