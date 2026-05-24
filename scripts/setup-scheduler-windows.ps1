# setup-scheduler-windows.ps1
# Configura backup diario WMS Fénix a las 04:00 AM en Windows Task Scheduler
#
# EJECUTAR UNA SOLA VEZ como Administrador:
#   powershell -ExecutionPolicy Bypass -File scripts\setup-scheduler-windows.ps1

$TaskName    = "WMS Fenix - Backup Diario"
$PhpExe      = "C:\xampp\php\php.exe"
$ScriptPath  = "C:\xampp\htdocs\WMS_FENIX\scripts\backup.php"
$LogPath     = "C:\xampp\htdocs\WMS_FENIX\backups\backup.log"
$RunTime     = "04:00"

# Verificar que PHP existe
if (-not (Test-Path $PhpExe)) {
    Write-Error "No se encontró PHP en: $PhpExe"
    Write-Error "Ajusta la variable `$PhpExe en este script."
    exit 1
}

# Verificar que el script existe
if (-not (Test-Path $ScriptPath)) {
    Write-Error "No se encontró el script en: $ScriptPath"
    exit 1
}

# Eliminar tarea anterior si existe
$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Eliminando tarea anterior '$TaskName'..."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

# Crear carpeta de backups si no existe
$BackupDir = "C:\xampp\htdocs\WMS_FENIX\backups"
if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
}

# Definir acción: ejecutar PHP con el script de backup
$Action = New-ScheduledTaskAction `
    -Execute $PhpExe `
    -Argument $ScriptPath `
    -WorkingDirectory "C:\xampp\htdocs\WMS_FENIX"

# Disparador: diario a las 04:00 AM
$Trigger = New-ScheduledTaskTrigger -Daily -At $RunTime

# Configuración: ejecutar aunque el usuario no esté logueado, sin expirar
$Settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -RestartCount 2 `
    -RestartInterval (New-TimeSpan -Minutes 15) `
    -StartWhenAvailable

# Principal: ejecutar con permisos del sistema (SYSTEM)
$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

# Registrar la tarea
$Task = Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger $Trigger `
    -Settings $Settings `
    -Principal $Principal `
    -Description "Backup diario automatico WMS Fenix: PostgreSQL + archivos uploads. Ejecuta a las 04:00 AM. Script: $ScriptPath" `
    -Force

if ($Task) {
    Write-Host ""
    Write-Host "======================================================" -ForegroundColor Green
    Write-Host "  Tarea programada creada exitosamente:" -ForegroundColor Green
    Write-Host "  Nombre  : $TaskName" -ForegroundColor Cyan
    Write-Host "  Hora    : 04:00 AM (diario)" -ForegroundColor Cyan
    Write-Host "  PHP     : $PhpExe" -ForegroundColor Cyan
    Write-Host "  Script  : $ScriptPath" -ForegroundColor Cyan
    Write-Host "  Log     : $LogPath" -ForegroundColor Cyan
    Write-Host "======================================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Para probar ahora mismo:" -ForegroundColor Yellow
    Write-Host "  Start-ScheduledTask -TaskName '$TaskName'" -ForegroundColor Yellow
    Write-Host "  Get-Content '$LogPath' -Wait" -ForegroundColor Yellow
} else {
    Write-Error "Error al crear la tarea programada."
    exit 1
}
