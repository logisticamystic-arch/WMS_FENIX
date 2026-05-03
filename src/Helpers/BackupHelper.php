<?php

namespace App\Helpers;

/**
 * BackupHelper — Backup automático de BD con retención rolling.
 *
 * Estrategia: guarda un archivo por día del mes (dia_01.sql … dia_30.sql).
 * Al llegar al día 31 vuelve a día_01, sobrescribiendo el del mes anterior.
 * Con BACKUP_RETENTION_DAYS=30 nunca acumula más de 30 archivos.
 *
 * Uso programático:
 *   \App\Helpers\BackupHelper::run();     // ejecuta el backup
 *   \App\Helpers\BackupHelper::listar();  // lista backups existentes
 *
 * Uso desde cron (Windows Task Scheduler en XAMPP):
 *   php C:\xampp\htdocs\WMS_FENIX\scripts\backup.php
 */
class BackupHelper
{
    /**
     * Ejecuta el backup de la base de datos actual.
     * Retorna array con metadata del archivo generado.
     *
     * @throws \RuntimeException si mysqldump falla o la BD no es soportada
     */
    public static function run(): array
    {
        $cfg = self::config();
        $dir = self::backupDir();

        // Nombre rolling: dia_01.sql … dia_30.sql
        $retention = (int)($cfg['retention'] ?? 30);
        $daySlot   = (int)date('d') % $retention;
        $daySlot   = $daySlot === 0 ? $retention : $daySlot;
        $filename  = sprintf('dia_%02d.sql', $daySlot);
        $filepath  = $dir . DIRECTORY_SEPARATOR . $filename;

        if ($cfg['driver'] === 'mysql') {
            self::dumpMysql($cfg, $filepath);
        } elseif ($cfg['driver'] === 'pgsql') {
            self::dumpPgsql($cfg, $filepath);
        } else {
            throw new \RuntimeException("Driver '{$cfg['driver']}' no soportado para backup.");
        }

        $size = file_exists($filepath) ? filesize($filepath) : 0;

        // Registrar en log
        if (function_exists('wmsLog')) {
            wmsLog('INFO', "Backup generado: {$filename} (" . round($size / 1024, 1) . " KB)");
        }

        return [
            'archivo'   => $filename,
            'ruta'      => $filepath,
            'slot'      => $daySlot,
            'tamaño_kb' => round($size / 1024, 1),
            'fecha'     => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Lista todos los archivos de backup con su metadata.
     */
    public static function listar(): array
    {
        $dir   = self::backupDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . 'dia_*.sql') ?: [];
        $list  = [];
        foreach ($files as $f) {
            $stat = stat($f);
            $list[] = [
                'archivo'    => basename($f),
                'tamaño_kb'  => round(($stat['size'] ?? 0) / 1024, 1),
                'modificado' => date('Y-m-d H:i:s', $stat['mtime'] ?? 0),
            ];
        }
        usort($list, fn($a, $b) => $a['archivo'] <=> $b['archivo']);
        return $list;
    }

    // ── Privados ─────────────────────────────────────────────────────────────

    private static function config(): array
    {
        $env = function (string $k, string $d = ''): string {
            return $_ENV[$k] ?? $_SERVER[$k] ?? (getenv($k) ?: $d);
        };
        return [
            'driver'    => $env('DB_DRIVER', 'mysql'),
            'host'      => $env('DB_HOST', '127.0.0.1'),
            'port'      => $env('DB_PORT', '3306'),
            'name'      => $env('DB_NAME', 'WMS_FENIX'),
            'user'      => $env('DB_USER', 'root'),
            'pass'      => $env('DB_PASS', ''),
            'retention' => $env('BACKUP_RETENTION_DAYS', '30'),
        ];
    }

    private static function backupDir(): string
    {
        $env = function (string $k, string $d = ''): string {
            return $_ENV[$k] ?? $_SERVER[$k] ?? (getenv($k) ?: $d);
        };
        $rel = $env('BACKUP_DIR', 'backups');
        // Resolver relativo a la raíz del proyecto (un nivel arriba de /public)
        $root = dirname(__DIR__, 2);
        $dir  = $root . DIRECTORY_SEPARATOR . trim($rel, '/\\');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return realpath($dir) ?: $dir;
    }

    private static function dumpMysql(array $cfg, string $filepath): void
    {
        // Buscar mysqldump en rutas típicas de XAMPP
        $candidates = [
            'mysqldump',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/Applications/XAMPP/xamppfiles/bin/mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];
        $bin = 'mysqldump';
        foreach ($candidates as $c) {
            if (@is_executable($c) || (stripos(PHP_OS, 'WIN') !== false && file_exists($c))) {
                $bin = $c;
                break;
            }
        }

        $pass = $cfg['pass'] !== ''
            ? '-p' . escapeshellarg($cfg['pass'])
            : '';

        // Escribir .cnf temporal para no exponer password en línea de comandos
        $cnfFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_backup_' . getmypid() . '.cnf';
        file_put_contents($cnfFile,
            "[client]\nhost={$cfg['host']}\nport={$cfg['port']}\n" .
            "user={$cfg['user']}\npassword={$cfg['pass']}\n"
        );
        chmod($cnfFile, 0600);

        $cmd = sprintf(
            '%s --defaults-file=%s --single-transaction --routines --triggers --result-file=%s %s 2>&1',
            escapeshellcmd($bin),
            escapeshellarg($cnfFile),
            escapeshellarg($filepath),
            escapeshellarg($cfg['name'])
        );

        exec($cmd, $output, $code);
        @unlink($cnfFile);

        if ($code !== 0) {
            throw new \RuntimeException("mysqldump falló (código {$code}): " . implode("\n", $output));
        }
    }

    private static function dumpPgsql(array $cfg, string $filepath): void
    {
        $cmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -F p -f %s %s 2>&1',
            escapeshellarg($cfg['pass']),
            escapeshellarg($cfg['host']),
            escapeshellarg($cfg['port']),
            escapeshellarg($cfg['user']),
            escapeshellarg($filepath),
            escapeshellarg($cfg['name'])
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new \RuntimeException("pg_dump falló (código {$code}): " . implode("\n", $output));
        }
    }
}
