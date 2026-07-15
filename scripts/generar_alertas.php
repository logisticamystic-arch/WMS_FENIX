<?php
/**
 * scripts/generar_alertas.php — Runner de línea de comandos para el motor de alertas
 *
 * Antes de esta corrección, AlertasController::generar() solo se disparaba manualmente
 * (botón en el panel, protegido por rol Supervisor). Este script ejecuta el mismo
 * escaneo para TODAS las empresas/sucursales activas, pensado para correr automático
 * vía Task Scheduler (Windows) — igual que scripts/backup.php.
 *
 * ─── CÓMO PROGRAMARLO ────────────────────────────────────────────────────────
 * Ejecutar UNA sola vez como Administrador en PowerShell:
 *   cd C:\xampp\htdocs\WMS_FENIX
 *   powershell -ExecutionPolicy Bypass -File scripts\setup-scheduler-alertas-windows.ps1
 * Esto crea la tarea "WMS Fenix - Generar Alertas" cada 6 horas.
 *
 * PRUEBA MANUAL:
 *   C:\xampp\php\php.exe scripts\generar_alertas.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);
require $rootDir . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Controllers\AlertasController;

$logFile = $rootDir . '/logs/generar_alertas.log';
$log = function (string $msg) use ($logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
};

$log('=== Inicio generación de alertas ===');

try {
    $sucursales = Capsule::table('sucursales')
        ->where('activo', true)
        ->select('id', 'empresa_id', 'nombre')
        ->get();

    $controller = new AlertasController();
    $totalAlertas = 0;

    foreach ($sucursales as $s) {
        try {
            $n = $controller->generarParaSucursal((int)$s->empresa_id, (int)$s->id);
            $totalAlertas += $n;
            $log("Sucursal #{$s->id} ({$s->nombre}): {$n} alertas activas");
        } catch (\Throwable $e) {
            $log("ERROR sucursal #{$s->id}: " . $e->getMessage());
        }
    }

    $log("=== Fin — {$totalAlertas} alertas activas en total ===");
} catch (\Throwable $e) {
    $log('ERROR FATAL: ' . $e->getMessage());
    exit(1);
}
