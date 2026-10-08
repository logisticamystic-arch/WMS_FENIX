# ============================================================
#  Tarea programada "WMS Fenix - Cierre Recepciones" (ejecutar como ADMINISTRADOR)
#
#  Todos los días a las 23:30, como SYSTEM: cierra los recibos sin ODC en Borrador
#  cuyas capturas ya están todas en inventario (scripts\cerrar_recepciones_dia.php).
#  Si el equipo estaba apagado a esa hora, corre al encender.
#  Log: E:\xampp\htdocs\WMS_FENIX\logs\cierre_recepciones.log
#
#  USO (PowerShell como administrador):
#    powershell -ExecutionPolicy Bypass -File E:\xampp\htdocs\WMS_FENIX\scripts\instalar_tarea_cierre_recepciones.ps1
# ============================================================
$ErrorActionPreference = 'Stop'
$php    = 'E:\xampp\php\php.exe'
$proj   = 'E:\xampp\htdocs\WMS_FENIX'
$script = "$proj\scripts\cerrar_recepciones_dia.php"
$nombre = 'WMS Fenix - Cierre Recepciones'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Write-Host 'Debe ejecutarse como ADMINISTRADOR.' -ForegroundColor Red; exit 1 }
foreach ($f in $php, $script) { if (-not (Test-Path $f)) { Write-Host "No existe: $f" -ForegroundColor Red; exit 1 } }

$accion  = New-ScheduledTaskAction -Execute $php -Argument "`"$script`"" -WorkingDirectory $proj
$disparo = New-ScheduledTaskTrigger -Daily -At '23:30'
$sistema = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$ajustes = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
             -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 10) `
             -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $nombre -Action $accion -Trigger $disparo -Principal $sistema -Settings $ajustes -Force `
    -Description 'Cierra los recibos sin ODC en Borrador con todas sus capturas ya en inventario. Log: logs\cierre_recepciones.log' | Out-Null
Write-Host "Tarea `"$nombre`" creada (23:30 diario, SYSTEM)." -ForegroundColor Green

# Prueba en modo simulación (no cierra nada)
& $php $script --dry-run
Get-ScheduledTask -TaskName $nombre | Get-ScheduledTaskInfo | Format-Table TaskName, LastRunTime, NextRunTime -AutoSize
