<?php
/**
 * scripts/backup_db.php — Backup diario de PostgreSQL con retención 7 días
 *
 * Genera: backups/backup_YYYY-MM-DD_HHMMSS.sql.gz
 * Retención: elimina backups con más de 7 días de antigüedad.
 * Log:       backups/backup.log
 *
 * Uso manual:
 *   C:\xampp\php\php.exe scripts\backup_db.php
 *
 * Programado:
 *   scripts\backup_daily.bat  (llamar desde Task Scheduler de Windows)
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);

// ── Cargar .env manualmente ─────────────────────────────────────────────────
if (file_exists($rootDir . '/.env')) {
    foreach (file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if (!isset($_ENV[$k])) {
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}

// ── Configuración ───────────────────────────────────────────────────────────
$env = fn(string $key, string $default = ''): string
    => $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) ?: $default);

$dbHost = $env('DB_HOST', '127.0.0.1');
$dbPort = $env('DB_PORT', '5432');
$dbName = $env('DB_NAME', 'wms_fenix');
$dbUser = $env('DB_USER', 'postgres');
$dbPass = $env('DB_PASS', '');

$backupDir    = $rootDir . DIRECTORY_SEPARATOR . 'backups';
$logFile      = $backupDir . DIRECTORY_SEPARATOR . 'backup.log';
$retentionDays = 30;

// ── Crear directorio de backups si no existe ────────────────────────────────
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// ── Funciones auxiliares ────────────────────────────────────────────────────

function logBackup(string $level, string $msg, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}" . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function findPgDump(): string
{
    // Rutas conocidas de pg_dump en Windows
    $candidates = [
        'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe',
        'C:\\Program Files\\PostgreSQL\\17\\bin\\pg_dump.exe',
        'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
        'C:\\Program Files\\PostgreSQL\\15\\bin\\pg_dump.exe',
        'C:\\Program Files (x86)\\PostgreSQL\\18\\bin\\pg_dump.exe',
        'C:\\Program Files (x86)\\PostgreSQL\\17\\bin\\pg_dump.exe',
        // Linux / macOS
        '/usr/bin/pg_dump',
        '/usr/local/bin/pg_dump',
        '/usr/lib/postgresql/18/bin/pg_dump',
        '/usr/lib/postgresql/17/bin/pg_dump',
        '/usr/lib/postgresql/16/bin/pg_dump',
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    // Intentar con PATH del sistema
    $which = shell_exec(
        (PHP_OS_FAMILY === 'Windows' ? 'where pg_dump' : 'which pg_dump') . ' 2>/dev/null'
    );
    if ($which && trim($which)) {
        return trim(explode("\n", $which)[0]);
    }

    throw new RuntimeException(
        'No se encontró pg_dump. Instale PostgreSQL o agregue su bin/ al PATH del sistema.'
    );
}

// ── Rotar log si supera 5 MB ────────────────────────────────────────────────
if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
    rename($logFile, str_replace('.log', '_' . date('Ymd') . '.log', $logFile));
}

// ── Inicio ──────────────────────────────────────────────────────────────────
logBackup('INFO', '=== Inicio backup_db.php ===', $logFile);

try {
    // 1. Localizar pg_dump
    $pgDump = findPgDump();
    logBackup('INFO', "pg_dump encontrado: {$pgDump}", $logFile);

    // 2. Generar nombre del archivo
    $timestamp = date('Y-m-d_His');
    $filename  = "backup_{$timestamp}.sql.gz";
    $filepath  = $backupDir . DIRECTORY_SEPARATOR . $filename;

    // 3. Ejecutar pg_dump con compresión gzip
    //    Usamos -F p (plain) + gzip para obtener .sql.gz
    //    PGPASSWORD se pasa como variable de entorno para no exponer en procesos
    if (PHP_OS_FAMILY === 'Windows') {
        // En Windows: cmd /c con PGPASSWORD en el mismo contexto
        // Usamos -F p (plain text) y piping a gzip
        // Primero verificamos si gzip existe
        $gzipPaths = [
            'C:\\Program Files\\Git\\usr\\bin\\gzip.exe',
            'C:\\xampp\\cygwin\\bin\\gzip.exe',
            'C:\\Windows\\System32\\gzip.exe',
        ];
        $gzipBin = null;
        foreach ($gzipPaths as $gp) {
            if (file_exists($gp)) {
                $gzipBin = $gp;
                break;
            }
        }
        if (!$gzipBin) {
            $gzipCheck = shell_exec('where gzip 2>NUL');
            if ($gzipCheck && trim($gzipCheck)) {
                $gzipBin = trim(explode("\n", $gzipCheck)[0]);
            }
        }

        if ($gzipBin) {
            // pg_dump plain | gzip > archivo.sql.gz
            $quotedPgDump = '"' . str_replace('/', '\\', $pgDump) . '"';
            $quotedGzip   = '"' . str_replace('/', '\\', $gzipBin) . '"';
            $cmd = sprintf(
                'cmd /c "set PGPASSWORD=%s && %s -h %s -p %s -U %s -F p %s | %s > %s" 2>&1',
                str_replace('"', '""', $dbPass),
                $quotedPgDump,
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                $quotedGzip,
                escapeshellarg(str_replace('/', '\\', $filepath))
            );
        } else {
            // Sin gzip: usar pg_dump formato custom (-Fc) que ya incluye compresión
            // Renombrar extensión a .dump
            $filename = "backup_{$timestamp}.dump";
            $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

            $quotedPgDump = '"' . str_replace('/', '\\', $pgDump) . '"';
            $cmd = sprintf(
                'cmd /c "set PGPASSWORD=%s && %s -h %s -p %s -U %s -F c -f %s %s" 2>&1',
                str_replace('"', '""', $dbPass),
                $quotedPgDump,
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg(str_replace('/', '\\', $filepath)),
                escapeshellarg($dbName)
            );

            logBackup('WARN', 'gzip no encontrado, usando formato custom de pg_dump (.dump)', $logFile);
        }
    } else {
        // Linux/macOS: PGPASSFILE + pipe a gzip
        $pgpassFile = sys_get_temp_dir() . '/wms_pgpass_' . getmypid();
        file_put_contents($pgpassFile, "{$dbHost}:{$dbPort}:{$dbName}:{$dbUser}:{$dbPass}\n");
        chmod($pgpassFile, 0600);

        $cmd = sprintf(
            'PGPASSFILE=%s %s -h %s -p %s -U %s -F p %s | gzip > %s 2>&1',
            escapeshellarg($pgpassFile),
            escapeshellarg($pgDump),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($filepath)
        );
    }

    logBackup('INFO', "Ejecutando pg_dump para '{$dbName}'...", $logFile);
    exec($cmd, $output, $exitCode);

    // Limpiar archivo temporal de contraseña en Linux
    if (isset($pgpassFile)) {
        @unlink($pgpassFile);
    }

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "pg_dump falló (código {$exitCode}): " . implode("\n", $output)
        );
    }

    if (!file_exists($filepath) || filesize($filepath) === 0) {
        throw new RuntimeException("El archivo de backup está vacío o no se creó: {$filepath}");
    }

    $sizeKb = round(filesize($filepath) / 1024, 1);
    logBackup('OK', "Backup BD creado: {$filename} ({$sizeKb} KB)", $logFile);

    // 4. Backup de archivos (uploads/)
    $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . 'uploads';
    if (is_dir($uploadsDir)) {
        $uploadsFilename = "uploads_{$timestamp}.zip";
        $uploadsFilepath = $backupDir . DIRECTORY_SEPARATOR . $uploadsFilename;

        if (PHP_OS_FAMILY === 'Windows') {
            // Usar PowerShell Compress-Archive (disponible en Windows 10+)
            $cmdUploads = sprintf(
                'powershell -NoProfile -Command "Compress-Archive -Path \'%s\' -DestinationPath \'%s\' -Force" 2>&1',
                str_replace("'", "''", $uploadsDir),
                str_replace("'", "''", $uploadsFilepath)
            );
        } else {
            $cmdUploads = sprintf(
                'zip -r %s %s 2>&1',
                escapeshellarg($uploadsFilepath),
                escapeshellarg($uploadsDir)
            );
        }

        exec($cmdUploads, $outUploads, $exitUploads);

        if ($exitUploads === 0 && file_exists($uploadsFilepath) && filesize($uploadsFilepath) > 0) {
            $uploadsSizeKb = round(filesize($uploadsFilepath) / 1024, 1);
            logBackup('OK', "Backup archivos creado: {$uploadsFilename} ({$uploadsSizeKb} KB)", $logFile);
        } else {
            logBackup('WARN', 'No se pudo crear backup de uploads/: ' . implode(' ', $outUploads), $logFile);
        }
    } else {
        logBackup('INFO', 'Directorio uploads/ no encontrado, se omite backup de archivos', $logFile);
    }

    // 5. Limpieza: eliminar backups con más de N días
    $cutoffTime = time() - ($retentionDays * 86400);
    $cleaned    = 0;

    $patterns = [
        $backupDir . DIRECTORY_SEPARATOR . 'backup_*.sql.gz',
        $backupDir . DIRECTORY_SEPARATOR . 'backup_*.dump',
        $backupDir . DIRECTORY_SEPARATOR . 'uploads_*.zip',
    ];

    foreach ($patterns as $pattern) {
        $oldFiles = glob($pattern) ?: [];
        foreach ($oldFiles as $oldFile) {
            if (filemtime($oldFile) < $cutoffTime) {
                $oldName = basename($oldFile);
                if (@unlink($oldFile)) {
                    logBackup('INFO', "Backup eliminado (retención {$retentionDays}d): {$oldName}", $logFile);
                    $cleaned++;
                } else {
                    logBackup('WARN', "No se pudo eliminar: {$oldName}", $logFile);
                }
            }
        }
    }

    if ($cleaned > 0) {
        logBackup('INFO', "Limpieza: {$cleaned} backup(s) antiguos eliminados", $logFile);
    }

    logBackup('INFO', '=== Backup completado con éxito ===', $logFile);
    exit(0);

} catch (Throwable $e) {
    logBackup('ERROR', $e->getMessage(), $logFile);
    logBackup('ERROR', '=== Backup FALLÓ ===', $logFile);
    exit(1);
}
