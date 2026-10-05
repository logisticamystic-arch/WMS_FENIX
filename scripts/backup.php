<?php
/**
 * scripts/backup.php — Punto de entrada para backup automático diario WMS Fénix
 *
 * Ejecuta: backup de PostgreSQL (comprimido, formato custom) + archivos uploads/
 *
 * ─── CÓMO PROGRAMARLO ────────────────────────────────────────────────────────
 *
 * WINDOWS (desarrollo XAMPP):
 *   Ejecutar UNA sola vez como Administrador en PowerShell:
 *     cd C:\xampp\htdocs\WMS_FENIX
 *     powershell -ExecutionPolicy Bypass -File scripts\setup-scheduler-windows.ps1
 *   Esto crea la tarea "WMS Fénix — Backup Diario" a las 04:00 AM.
 *
 * LINUX / UBUNTU (producción):
 *   Ejecutar UNA sola vez como root o con sudo:
 *     bash /var/www/WMS_FENIX/scripts/setup-cron-linux.sh
 *   Esto instala el cron en /etc/cron.d/wms-backup (04:00 AM diario).
 *
 * PRUEBA MANUAL (cualquier plataforma):
 *   C:\xampp\php\php.exe scripts\backup.php         (Windows)
 *   php scripts/backup.php                           (Linux)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Hora de Colombia: el php.ini de XAMPP trae Europe/Berlin (slots dia_XX y log corridos)
date_default_timezone_set('America/Bogota');

$rootDir = dirname(__DIR__);
chdir($rootDir);

// ── Cargar .env manualmente (sin Slim) ───────────────────────────────────────
if (file_exists($rootDir . '/.env')) {
    foreach (file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
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

// ── Autoloader ───────────────────────────────────────────────────────────────
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/Helpers/BackupHelper.php';

// ── Log helper ───────────────────────────────────────────────────────────────
$logFile = $rootDir . '/backups/backup.log';

function bkpLog(string $level, string $msg, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}" . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// Rotar log si supera 5 MB
if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
    rename($logFile, str_replace('.log', '_' . date('Ymd') . '.log', $logFile));
}

// ── Ejecutar backup ───────────────────────────────────────────────────────────
bkpLog('INFO', '=== Inicio backup WMS Fénix ===', $logFile);

$fallo = false;
$db = $files = $tms = [];

// 1) WMS: PostgreSQL + archivos (uploads/, docs/)
try {
    $result = \App\Helpers\BackupHelper::run();

    $db    = $result['db'];
    $files = $result['files'];

    bkpLog('OK', "BD → {$db['archivo']} ({$db['tamaño_kb']} KB) | Archivos → {$files['archivo']} ({$files['tamaño_kb']} KB) | Total: {$result['total_kb']} KB", $logFile);
} catch (\Throwable $e) {
    $fallo = true;
    bkpLog('ERROR', 'WMS: ' . $e->getMessage(), $logFile);
}

// 2) TMS: MySQL tms_fenix (incluye fotos y firmas) — 2026-10-04. Aislado: si
//    falla no impide el backup del WMS, pero marca la corrida como fallida.
try {
    $tms = \App\Helpers\BackupHelper::runTms();
    bkpLog('OK', "TMS → tms/{$tms['archivo']} ({$tms['tamaño_kb']} KB)", $logFile);
} catch (\Throwable $e) {
    $fallo = true;
    bkpLog('ERROR', 'TMS: ' . $e->getMessage(), $logFile);
}

// 3) Copia externa (OneDrive) — 2026-10-04. Los backups locales viven en el
//    mismo disco que el sistema; esta copia protege ante daño del disco/equipo.
//    BD del WMS y del TMS: misma rotación de 30 días (dia_XX).
//    Archivos del WMS (~220 MB): solo el ÚLTIMO, para no llenar OneDrive.
//    ponytail: un solo snapshot de archivos; agregar semanales si se necesita historial.
$copiaDir = trim((string)($_ENV['BACKUP_COPIA_DIR'] ?? ''));
if ($copiaDir !== '') {
    $copias = [];
    if (!empty($db['ruta']))    $copias[] = [$db['ruta'],    'wms_db'       . DIRECTORY_SEPARATOR . basename($db['ruta'])];
    if (!empty($tms['ruta']))   $copias[] = [$tms['ruta'],   'tms_db'       . DIRECTORY_SEPARATOR . basename($tms['ruta'])];
    if (!empty($files['ruta'])) $copias[] = [$files['ruta'], 'wms_archivos' . DIRECTORY_SEPARATOR . 'ultimo_archivos.tar.gz'];
    try {
        if (!is_dir($copiaDir)) throw new \RuntimeException("La carpeta de copia no existe: {$copiaDir}");
        $okCopias = [];
        foreach ($copias as [$origen, $rel]) {
            if (!is_file($origen)) continue;
            $destino = $copiaDir . DIRECTORY_SEPARATOR . $rel;
            if (!is_dir(dirname($destino))) mkdir(dirname($destino), 0755, true);
            // Copiar a .part y renombrar: OneDrive nunca sube un archivo a medias
            $tmp = $destino . '.part';
            if (!copy($origen, $tmp)) throw new \RuntimeException("No se pudo copiar {$origen}");
            if (filesize($tmp) !== filesize($origen)) { @unlink($tmp); throw new \RuntimeException("Copia incompleta de {$origen}"); }
            if (is_file($destino)) unlink($destino);
            rename($tmp, $destino);
            $okCopias[] = $rel;
        }
        bkpLog('OK', 'Copia externa → ' . $copiaDir . ' (' . implode(', ', $okCopias) . ')', $logFile);
    } catch (\Throwable $e) {
        $fallo = true;
        bkpLog('ERROR', 'Copia externa: ' . $e->getMessage(), $logFile);
    }
}

bkpLog($fallo ? 'ERROR' : 'INFO', $fallo ? '=== Backup con ERRORES (revisar arriba) ===' : '=== Backup completado con éxito ===', $logFile);
exit($fallo ? 1 : 0);
