<?php
/**
 * Application Configuration
 */

// Helper: lee de getenv(), $_ENV y $_SERVER (compatible con Dotenv createImmutable)
$env = static function(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
};

return [
    'env'       => $env('APP_ENV',   'development'),
    'debug'     => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url'       => $env('APP_URL',   'http://localhost/WMS_FENIX/public'),
    'jwt' => [
        'secret' => $env('JWT_SECRET', 'change_this_secret'),
        'expiry' => (int)($env('JWT_EXPIRY', 28800)),
    ],
    'uploads' => [
        'productos' => __DIR__ . '/../public/uploads/productos/',
    ],
];
