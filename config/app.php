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
        'secret' => (function() use ($env) {
            $s = $env('JWT_SECRET', '');
            if ($s === '' || $s === 'change_this_secret' || $s === 'CAMBIAR_POR_CLAVE_SEGURA_32_CHARS_MINIMO') {
                throw new \RuntimeException('JWT_SECRET no configurado — edite .env antes de iniciar');
            }
            return $s;
        })(),
        'expiry' => (int)($env('JWT_EXPIRY', 28800)),
    ],
    'uploads' => [
        'productos' => __DIR__ . '/../public/uploads/productos/',
    ],
    'allow_user_deletion' => filter_var($env('ALLOW_USER_DELETION', 'false'), FILTER_VALIDATE_BOOLEAN),
];
