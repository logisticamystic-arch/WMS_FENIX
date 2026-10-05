# ============================================================
#  Tareas programadas de WMS Fénix (ejecutar como ADMINISTRADOR)
#
#  1) "WMS Fenix - Backup Diario" — 04:00 todos los días, como SYSTEM
#     (corre aunque nadie tenga sesión iniciada). Respalda:
#       backups\db\dia_XX.dump     PostgreSQL wms_fenix
#       backups\files\dia_XX.tar.gz uploads + docs del WMS
#       backups\tms\dia_XX.sql.gz  MySQL tms_fenix (fotos y firmas incluidas)
#     Rota 30 días. Si el equipo estaba apagado a las 04:00, corre al encender.
#  2) "WMS Fenix - Generar Alertas" — se corrige la ruta (seguía en C:\xampp
#     desde la migración a E: del 2026-09-07 y fallaba con 0x8007010B).
#
#  Por qué (2026-10-04): no existía tarea de backup desde la migración a E:
#  — último backup del WMS 2026-09-07; el TMS nunca tuvo backup.
#
#  USO (PowerShell como administrador):
#    powershell -ExecutionPolicy Bypass -File E:\xampp\htdocs\WMS_FENIX\scripts\instalar_tareas_programadas.ps1
# ============================================================
$ErrorActionPreference = 'Stop'
$php  = 'E:\xampp\php\php.exe'
$proj = 'E:\xampp\htdocs\WMS_FENIX'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Write-Host 'Debe ejecutarse como ADMINISTRADOR.' -ForegroundColor Red; exit 1 }
foreach ($f in $php, "$proj\scripts\backup.php", "$proj\scripts\generar_alertas.php") {
    if (-not (Test-Path $f)) { Write-Host "No existe: $f" -ForegroundColor Red; exit 1 }
}

$sistema  = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$ajustes  = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
              -ExecutionTimeLimit (New-TimeSpan -Hours 1) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 10) `
              -MultipleInstances IgnoreNew

# ── 1) Backup diario ─────────────────────────────────────────
$accion  = New-ScheduledTaskAction -Execute $php -Argument "`"$proj\scripts\backup.php`"" -WorkingDirectory $proj
$disparo = New-ScheduledTaskTrigger -Daily -At '04:00'
Register-ScheduledTask -TaskName 'WMS Fenix - Backup Diario' -Action $accion -Trigger $disparo `
    -Principal $sistema -Settings $ajustes -Force `
    -Description 'Backup diario WMS (PostgreSQL + uploads) y TMS (MySQL). Rota 30 días en E:\xampp\htdocs\WMS_FENIX\backups. Log: backups\backup.log' | Out-Null
Write-Host 'Tarea "WMS Fenix - Backup Diario" creada (04:00, SYSTEM).' -ForegroundColor Green

# ── 2) Corregir ruta de Generar Alertas (conserva su horario) ─
$alertas = Get-ScheduledTask -TaskName 'WMS Fenix - Generar Alertas' -ErrorAction SilentlyContinue
if ($alertas) {
    $nueva = New-ScheduledTaskAction -Execute $php -Argument "`"$proj\scripts\generar_alertas.php`"" -WorkingDirectory $proj
    Set-ScheduledTask -TaskName 'WMS Fenix - Generar Alertas' -Action $nueva | Out-Null
    Write-Host 'Tarea "WMS Fenix - Generar Alertas" corregida a E:\xampp.' -ForegroundColor Green
}

# ── 3) Prueba inmediata del backup ───────────────────────────
Write-Host 'Ejecutando el backup una vez para verificar (unos 10-20 s)...'
Start-ScheduledTask -TaskName 'WMS Fenix - Backup Diario'
$t = 0
do { Start-Sleep 3; $t += 3; $st = (Get-ScheduledTask -TaskName 'WMS Fenix - Backup Diario').State } while ($st -eq 'Running' -and $t -lt 300)
$info = Get-ScheduledTaskInfo -TaskName 'WMS Fenix - Backup Diario'
if ($info.LastTaskResult -eq 0) { Write-Host 'Backup de prueba: OK' -ForegroundColor Green }
else { Write-Host ('Backup de prueba: ERROR 0x{0:X} — ver {1}\backups\backup.log' -f $info.LastTaskResult, $proj) -ForegroundColor Red }
Get-Content "$proj\backups\backup.log" -Tail 5
Get-ScheduledTask -TaskName 'WMS Fenix - *' | Get-ScheduledTaskInfo | Format-Table TaskName, LastRunTime, LastTaskResult, NextRunTime -AutoSize
