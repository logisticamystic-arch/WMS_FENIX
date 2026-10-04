# ============================================================
#  Instala Apache y MySQL (MariaDB) de XAMPP como SERVICIOS de Windows
#  con arranque automático y reinicio automático si se caen.
#
#  Por qué (2026-10-04): Apache y MySQL corrían como programas sueltos
#  dentro de una sesión de usuario; al cerrarse la sesión morían.
#  MySQL (base del TMS) quedó caído desde el 2026-10-03 14:26 hasta
#  2026-10-04 18:34: el TMS no recibió pedidos y cada sincronización
#  del WMS tardaba 2 s (liquidar una planilla de 13 pedidos = 28 s).
#  PostgreSQL (WMS) ya es servicio automático y por eso nunca se cayó.
#
#  USO: clic derecho en PowerShell → "Ejecutar como administrador", luego:
#     powershell -ExecutionPolicy Bypass -File E:\xampp\htdocs\WMS_FENIX\scripts\instalar_servicios_xampp.ps1
#  Corte esperado: ~10 segundos sin web/TMS mientras se reinician.
# ============================================================
$ErrorActionPreference = 'Stop'
$xampp = 'E:\xampp'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Write-Host 'Debe ejecutarse como ADMINISTRADOR.' -ForegroundColor Red; exit 1 }

function Instalar($nombre, [scriptblock]$instalar) {
    if (Get-Service -Name $nombre -ErrorAction SilentlyContinue) {
        Write-Host "Servicio $nombre ya existe." -ForegroundColor Yellow
    } else {
        & $instalar
        Write-Host "Servicio $nombre instalado." -ForegroundColor Green
    }
    sc.exe config $nombre start= auto | Out-Null
    # Reinicio automático: a los 5 s, 5 s y 30 s tras cada falla; contador se reinicia a diario
    sc.exe failure $nombre reset= 86400 actions= restart/5000/restart/5000/restart/30000 | Out-Null
}

Write-Host '1) Deteniendo Apache y MySQL que corren como programas sueltos...'
Get-Process httpd, mysqld -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep 3

Write-Host '2) Instalando servicios...'
Instalar 'Apache2.4' { & "$xampp\apache\bin\httpd.exe" -k install -n 'Apache2.4' }
Instalar 'mysql'     { & "$xampp\mysql\bin\mysqld.exe" --install mysql "--defaults-file=$xampp\mysql\bin\my.ini" }

Write-Host '3) Iniciando servicios...'
Start-Service 'mysql'
Start-Service 'Apache2.4'
Start-Sleep 3

Get-Service 'Apache2.4', 'mysql', 'postgresql-x64-18' | Format-Table Name, Status, StartType -AutoSize
$web = try { (Invoke-WebRequest 'http://localhost/WMS_FENIX/public/' -UseBasicParsing -TimeoutSec 10).StatusCode } catch { $_.Exception.Message }
Write-Host "WMS responde: $web"
Write-Host 'Listo. Apache, MySQL y PostgreSQL arrancan solos con Windows y se reinician si fallan.' -ForegroundColor Green
