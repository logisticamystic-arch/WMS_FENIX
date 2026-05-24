<?php

namespace App\Helpers;

/**
 * BackupHelper — Backup diario automático: BD comprimida + archivos.
 *
 * Estrategia rolling:
 *   backups/db/dia_01.sql.gz  …  dia_30.sql.gz   (PostgreSQL custom format)
 *   backups/files/dia_01.tar.gz … dia_30.tar.gz  (uploads/ comprimido)
 *
 * Al día 31 vuelve a slot 01, nunca acumula más de 30 archivos por tipo.
 *
 * Uso:
 *   BackupHelper::run();       // BD + archivos
 *   BackupHelper::runDb();     // solo BD
 *   BackupHelper::runFiles();  // solo archivos
 *   BackupHelper::listar();    // lista backups existentes
 */
class BackupHelper
{
    public static function run(): array
    {
        $db    = self::runDb();
        $files = self::runFiles();

        $total = ($db['tamaño_kb'] ?? 0) + ($files['tamaño_kb'] ?? 0);

        if (function_exists('wmsLog')) {
            wmsLog('INFO', "Backup completo: BD {$db['tamaño_kb']} KB + archivos {$files['tamaño_kb']} KB");
        }

        return [
            'db'         => $db,
            'files'      => $files,
            'total_kb'   => round($total, 1),
            'fecha'      => date('Y-m-d H:i:s'),
            // Compatibilidad con callers que usan ->archivo y ->tamaño_kb
            'archivo'    => $db['archivo'],
            'tamaño_kb'  => $total,
        ];
    }

    public static function runDb(): array
    {
        $cfg  = self::config();
        $dir  = self::backupSubdir('db');
        $slot = self::daySlot($cfg['retention']);

        $ext      = ($cfg['driver'] === 'pgsql') ? 'dump' : 'sql.gz';
        $filename = \sprintf('dia_%02d.%s', $slot, $ext);
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;

        if ($cfg['driver'] === 'mysql') {
            self::dumpMysql($cfg, $filepath);
        } elseif ($cfg['driver'] === 'pgsql') {
            self::dumpPgsql($cfg, $filepath);
        } else {
            throw new \RuntimeException("Driver '{$cfg['driver']}' no soportado.");
        }

        $size = file_exists($filepath) ? filesize($filepath) : 0;
        return [
            'archivo'   => $filename,
            'ruta'      => $filepath,
            'slot'      => $slot,
            'tamaño_kb' => round($size / 1024, 1),
            'fecha'     => date('Y-m-d H:i:s'),
        ];
    }

    public static function runFiles(): array
    {
        $root     = self::projectRoot();
        $dir      = self::backupSubdir('files');
        $cfg      = self::config();
        $slot     = self::daySlot($cfg['retention']);
        $filename = \sprintf('dia_%02d.tar.gz', $slot);
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;

        // Carpetas a incluir en el backup de archivos
        $targets = [];
        foreach (['public/uploads', 'docs'] as $rel) {
            $abs = $root . DIRECTORY_SEPARATOR . $rel;
            if (is_dir($abs)) {
                $targets[] = $rel;
            }
        }

        if (empty($targets)) {
            // Nada que comprimir — crear archivo vacío
            file_put_contents($filepath, '');
            return ['archivo' => $filename, 'ruta' => $filepath, 'slot' => $slot, 'tamaño_kb' => 0, 'fecha' => date('Y-m-d H:i:s')];
        }

        $tarBin = self::findBin(['tar'], ['C:\\Windows\\System32\\tar.exe', '/bin/tar', '/usr/bin/tar']);

        $escapedTargets = implode(' ', array_map('escapeshellarg', $targets));

        // -C $root para que las rutas dentro del .tar.gz sean relativas al proyecto
        $cmd = \sprintf(
            '%s -czf %s -C %s %s 2>&1',
            escapeshellcmd($tarBin),
            escapeshellarg($filepath),
            escapeshellarg($root),
            $escapedTargets
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new \RuntimeException("tar falló (código {$code}): " . implode("\n", $output));
        }

        $size = file_exists($filepath) ? filesize($filepath) : 0;
        return [
            'archivo'   => $filename,
            'ruta'      => $filepath,
            'slot'      => $slot,
            'tamaño_kb' => round($size / 1024, 1),
            'fecha'     => date('Y-m-d H:i:s'),
        ];
    }

    public static function listar(): array
    {
        $result = ['db' => [], 'files' => []];

        foreach (['db', 'files'] as $tipo) {
            $dir = self::backupSubdir($tipo);
            $pattern = ($tipo === 'db') ? 'dia_*.{dump,sql.gz}' : 'dia_*.tar.gz';
            $files = glob($dir . DIRECTORY_SEPARATOR . $pattern, GLOB_BRACE) ?: [];
            foreach ($files as $f) {
                $stat = stat($f);
                $result[$tipo][] = [
                    'archivo'    => basename($f),
                    'tamaño_kb'  => round(($stat['size'] ?? 0) / 1024, 1),
                    'modificado' => date('Y-m-d H:i:s', $stat['mtime'] ?? 0),
                ];
            }
            usort($result[$tipo], fn($a, $b) => $a['archivo'] <=> $b['archivo']);
        }

        return $result;
    }

    // ── Privados ─────────────────────────────────────────────────────────────

    private static function config(): array
    {
        $env = fn(string $k, string $d = ''): string
            => $_ENV[$k] ?? $_SERVER[$k] ?? (getenv($k) ?: $d);

        return [
            'driver'    => $env('DB_DRIVER', 'mysql'),
            'host'      => $env('DB_HOST', '127.0.0.1'),
            'port'      => $env('DB_PORT', '3306'),
            'name'      => $env('DB_NAME', 'wms_fenix'),
            'user'      => $env('DB_USER', 'root'),
            'pass'      => $env('DB_PASS', ''),
            'retention' => $env('BACKUP_RETENTION_DAYS', '30'),
        ];
    }

    private static function projectRoot(): string
    {
        // src/Helpers/BackupHelper.php → ../../
        return dirname(__DIR__, 2);
    }

    private static function backupSubdir(string $sub): string
    {
        $env = fn(string $k, string $d = ''): string
            => $_ENV[$k] ?? $_SERVER[$k] ?? (getenv($k) ?: $d);

        $base = $env('BACKUP_DIR', 'backups');
        $root = self::projectRoot();
        $dir  = $root . DIRECTORY_SEPARATOR . trim($base, '/\\') . DIRECTORY_SEPARATOR . $sub;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return realpath($dir) ?: $dir;
    }

    private static function daySlot(string $retention): int
    {
        $r    = max(1, (int)$retention);
        $slot = (int)date('d') % $r;
        return $slot === 0 ? $r : $slot;
    }

    private static function findBin(array $names, array $extraPaths = []): string
    {
        foreach ($extraPaths as $path) {
            if (file_exists($path) && is_executable($path)) return $path;
            if (PHP_OS_FAMILY === 'Windows' && file_exists($path)) return $path;
        }
        foreach ($names as $name) {
            $which = shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where ' : 'which ') . escapeshellarg($name) . ' 2>/dev/null');
            if ($which && trim($which)) return trim(explode("\n", $which)[0]);
        }
        return $names[0]; // Fallback: dejar que el SO lo resuelva
    }

    private static function dumpMysql(array $cfg, string $filepath): void
    {
        $bin = self::findBin(
            ['mysqldump'],
            ['C:\\xampp\\mysql\\bin\\mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump']
        );

        // Archivo .cnf temporal para no exponer password en proceso list
        $cnf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_bkp_' . getmypid() . '.cnf';
        file_put_contents($cnf,
            "[client]\nhost={$cfg['host']}\nport={$cfg['port']}\n" .
            "user={$cfg['user']}\npassword={$cfg['pass']}\n"
        );
        chmod($cnf, 0600);

        // Pipe directo a gzip para salida comprimida
        $gzip = self::findBin(['gzip'], ['C:\\xampp\\cygwin\\bin\\gzip.exe', '/bin/gzip', '/usr/bin/gzip']);

        $sqlFile = str_replace('.sql.gz', '.sql', $filepath);
        $cmd = \sprintf(
            '%s --defaults-file=%s --single-transaction --routines --triggers %s 2>&1',
            escapeshellcmd($bin),
            escapeshellarg($cnf),
            escapeshellarg($cfg['name'])
        );

        // Si gzip está disponible, comprimir en pipe; si no, guardar plano
        if ($gzip && file_exists(str_replace('escapeshellarg', '', $gzip)) || shell_exec('gzip --version 2>/dev/null')) {
            $finalPath = $filepath; // .sql.gz
            $cmd = \sprintf(
                '%s --defaults-file=%s --single-transaction --routines --triggers %s | %s > %s 2>&1',
                escapeshellcmd($bin),
                escapeshellarg($cnf),
                escapeshellarg($cfg['name']),
                escapeshellcmd($gzip),
                escapeshellarg($finalPath)
            );
        } else {
            $finalPath = $sqlFile;
            $cmd .= ' > ' . escapeshellarg($finalPath);
        }

        exec($cmd, $output, $code);
        @unlink($cnf);

        if ($code !== 0) {
            throw new \RuntimeException("mysqldump falló (código {$code}): " . implode("\n", $output));
        }
    }

    private static function dumpPgsql(array $cfg, string $filepath): void
    {
        $bin = self::findBin(
            ['pg_dump'],
            [
                // Windows — buscar de versión más reciente a más antigua
                'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe',
                'C:\\Program Files\\PostgreSQL\\17\\bin\\pg_dump.exe',
                'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
                'C:\\Program Files\\PostgreSQL\\15\\bin\\pg_dump.exe',
                // Linux / macOS
                '/usr/bin/pg_dump',
                '/usr/local/bin/pg_dump',
                '/usr/lib/postgresql/16/bin/pg_dump',
                '/usr/lib/postgresql/15/bin/pg_dump',
            ]
        );

        // -F c = custom format (comprimido nativamente por pg_dump, restaurable con pg_restore)
        if (PHP_OS_FAMILY === 'Windows') {
            // En Windows: usar cmd /c con PGPASSWORD como variable de entorno en el mismo contexto
            // Rodear el bin con comillas dobles para manejar espacios en el path
            $quotedBin = '"' . str_replace('/', '\\', $bin) . '"';
            $cmd = \sprintf(
                'cmd /c "set PGPASSWORD=%s && %s -h %s -p %s -U %s -F c -f %s %s" 2>&1',
                str_replace('"', '""', $cfg['pass']),
                $quotedBin,
                escapeshellarg($cfg['host']),
                escapeshellarg($cfg['port']),
                escapeshellarg($cfg['user']),
                escapeshellarg(str_replace('/', '\\', $filepath)),
                escapeshellarg($cfg['name'])
            );
        } else {
            // Linux/macOS: usar PGPASSFILE (.pgpass) — más seguro
            $pgpassFile = sys_get_temp_dir() . '/wms_pgpass_' . getmypid();
            file_put_contents($pgpassFile,
                "{$cfg['host']}:{$cfg['port']}:{$cfg['name']}:{$cfg['user']}:{$cfg['pass']}\n"
            );
            chmod($pgpassFile, 0600);

            $cmd = \sprintf(
                'PGPASSFILE=%s %s -h %s -p %s -U %s -F c -f %s %s 2>&1',
                escapeshellarg($pgpassFile),
                escapeshellarg($bin),
                escapeshellarg($cfg['host']),
                escapeshellarg($cfg['port']),
                escapeshellarg($cfg['user']),
                escapeshellarg($filepath),
                escapeshellarg($cfg['name'])
            );
        }

        exec($cmd, $output, $code);

        if (isset($pgpassFile)) {
            @unlink($pgpassFile);
        }

        if ($code !== 0) {
            throw new \RuntimeException("pg_dump falló (código {$code}): " . implode("\n", $output));
        }
    }
}
