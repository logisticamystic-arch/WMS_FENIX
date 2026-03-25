<?php
/**
 * Database Configuration
 * Loads from .env, supports MySQL (Phase 1) and PostgreSQL (Phase 2)
 */

// Compatible con Dotenv createImmutable ($_ENV) y getenv() del sistema
$env = function(string $key, string $default = ''): string {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
};

return [
    'driver'    => $env('DB_DRIVER', 'mysql'),
    'host'      => $env('DB_HOST', '127.0.0.1'),
    'port'      => $env('DB_PORT', '3306'),
    'database'  => $env('DB_NAME', 'prooriente_wms'),
    'username'  => $env('DB_USER', 'root'),
    'password'  => $env('DB_PASS', ''),
    'charset'   => $env('DB_CHARSET', 'utf8mb4'),
    'collation' => $env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'    => '',
];
