<?php
/**
 * scripts/backup.php — Backup diario automático WMS Fénix
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  PROGRAMAR EN WINDOWS (XAMPP) — Task Scheduler              │
 * │                                                             │
 * │  1. Abrir "Programador de tareas" de Windows                │
 * │  2. Crear tarea básica:                                     │
 * │     Nombre: WMS Backup Diario                               │
 * │     Desencadenador: Diario → 02:00 a.m.                     │
 * │     Acción: Iniciar programa                                │
 * │       Programa: C:\xampp\php\php.exe                        │
 * │       Argumentos: C:\xampp\htdocs\WMS_Fénix\scripts\backup.php
 * │  3. En "Condiciones" desmarcar "Solo con corriente alterna" │
 * │                                                             │
 * │  También ejecutable manualmente:                            │
 * │    cd C:\xampp\htdocs\WMS_Fénix                        │
 * │    C:\xampp\php\php.exe scripts\backup.php                  │
 * └─────────────────────────────────────────────────────────────┘
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
$rootDir = dirname(__DIR__);
chdir($rootDir);

// Cargar .env
if (file_exists($rootDir . '/.env')) {
    foreach (file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (!isset($_ENV[$k])) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}

// Autoloader
require_once $rootDir . '/vendor/autoload.php';

// ── Ejecutar backup ───────────────────────────────────────────────────────────
$helper = \App\Helpers\BackupHelper::class;
// Requiere carga manual porque no arrancamos Slim
require_once $rootDir . '/src/Helpers/BackupHelper.php';

try {
    $result = \App\Helpers\BackupHelper::run();
    $msg = "[OK] " . date('Y-m-d H:i:s') . " — Backup generado: {$result['archivo']} ({$result['tamaño_kb']} KB)\n";
    echo $msg;
    // Append a log de backups
    file_put_contents($rootDir . '/backups/backup.log', $msg, FILE_APPEND);
    exit(0);
} catch (\Exception $e) {
    $msg = "[ERROR] " . date('Y-m-d H:i:s') . " — {$e->getMessage()}\n";
    echo $msg;
    file_put_contents($rootDir . '/backups/backup.log', $msg, FILE_APPEND);
    exit(1);
}
