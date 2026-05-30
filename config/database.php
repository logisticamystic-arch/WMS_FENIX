<?php
/**
 * Database Configuration — WMS Fénix
 *
 * PostgreSQL: DB_DRIVER=pgsql  DB_PORT=5432  DB_CHARSET=utf8
 */

$env = fn(string $key, string $default = ''): string
    => $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) ?: $default);

$driver = $env('DB_DRIVER', 'mysql');

$config = [
    'driver'   => $driver,
    'host'     => $env('DB_HOST', '127.0.0.1'),
    'port'     => (int) $env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
    'database' => $env('DB_NAME', 'wms_fenix'),
    'username' => $env('DB_USER', 'root'),
    'password' => $env('DB_PASS', ''),
    'prefix'   => '',

    /*
     * Opciones PDO para robustez y performance.
     * PDO::ATTR_PERSISTENT → reutiliza conexiones en solicitudes sucesivas
     *   (mejora significativa en XAMPP/Apache con múltiples workers).
     * PDO::ATTR_ERRMODE    → lanza excepciones (no errores silenciosos).
     * PDO::ATTR_TIMEOUT    → corta si MySQL no responde en 5 s.
     */
    'options' => [
        \PDO::ATTR_PERSISTENT         => true,
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES   => false,   // sentencias preparadas reales
        \PDO::ATTR_TIMEOUT            => 5,
    ],
];

if ($driver === 'pgsql') {
    $config['charset']  = $env('DB_CHARSET', 'utf8');
    $config['sslmode']  = $env('DB_SSLMODE', 'prefer');
    // Equivalente al MYSQL_ATTR_INIT_COMMAND: fuerza zona horaria Bogotá en cada conexión PG
    $config['options'][\PDO::ATTR_PERSISTENT] = false; // persistent + pgsql causa problemas de timezone
    $config['options']['application_name'] = 'WMS_FENIX';
    // Timezone se aplica vía afterConnect en bootstrap/app.php o directamente aquí:
    $config['search_path'] = 'public';
    $config['timezone'] = 'America/Bogota';
} else {
    // MySQL / MariaDB
    $config['charset']   = $env('DB_CHARSET',    'utf8mb4');
    $config['collation'] = $env('DB_COLLATION',  'utf8mb4_unicode_ci');
    $config['strict']    = true;    // evita truncamientos silenciosos
    $config['engine']    = null;    // hereda default de la tabla (InnoDB)

    // Fuerza utf8mb4 a nivel de PDO (charset en DSN no siempre es suficiente)
    $config['options'][\PDO::MYSQL_ATTR_INIT_COMMAND] =
        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
      . "time_zone = '-05:00', "          // UTC-5 Bogotá
      . "sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
      .            "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";
}

return $config;
