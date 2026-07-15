# setup-scheduler-alertas-windows.ps1
# Configura la generacion automatica de alertas (vencimientos, bajo minimo, sobre maximo)
# cada 6 horas en Windows Task Scheduler.
#
# EJECUTAR UNA SOLA VEZ como Administrador:
#   powershell -ExecutionPolicy Bypass -File scripts\setup-scheduler-alertas-windows.ps1

$TaskName    = "WMS Fenix - Generar Alertas"
$PhpExe      = "C:\xampp\php\php.exe"
$ScriptPath  = "C:\xampp\htdocs\WMS_FENIX\scripts\generar_alertas.php"
$LogPath     = "C:\xampp\htdocs\WMS_FENIX\logs\generar_alertas.log"

if (-not (Test-Path $PhpExe)) {
    Write-Error "No se encontro PHP en: $PhpExe"
    exit 1
}
if (-not (Test-Path $ScriptPath)) {
    Write-Error "No se encontro el script en: $ScriptPath"
    exit 1
}

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Eliminando tarea anterior '$TaskName'..."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$LogDir = "C:\xampp\htdocs\WMS_FENIX\logs"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

$Action = New-ScheduledTaskAction `
    -Execute $PhpExe `
    -Argument $ScriptPath `
    -WorkingDirectory "C:\xampp\htdocs\WMS_FENIX"

# Disparador: cada 6 horas, empezando a medianoche
$Trigger = New-ScheduledTaskTrigger -Once -At "00:00" `
    -RepetitionInterval (New-TimeSpan -Hours 6) `
    -RepetitionDuration ([TimeSpan]::MaxValue)

$Settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -RestartCount 2 `
    -RestartInterval (New-TimeSpan -Minutes 5) `
    -StartWhenAvailable

$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger `
    -Settings $Settings -Principal $Principal `
    -Description "Escanea inventario y genera alertas de vencimiento, bajo minimo y sobre maximo cada 6 horas."

Write-Host "Tarea '$TaskName' registrada — se ejecutara cada 6 horas."
Write-Host "Log: $LogPath"
