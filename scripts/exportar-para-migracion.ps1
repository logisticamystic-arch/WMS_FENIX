# ============================================================
#  WMS Fénix — Exportar para migración al nuevo servidor
#  Ejecutar en la máquina ACTUAL (origen) como Administrador
#  powershell -ExecutionPolicy Bypass -File scripts\exportar-para-migracion.ps1
# ============================================================

param(
    [string]$AppPath   = "C:\xampp\htdocs\WMS_FENIX",
    [string]$DbName    = "wms_fenix",
    [string]$DbUser    = "postgres",
    [string]$DbPass    = "",
    [string]$OutputDir = "C:\WMS_MIGRACION"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Step { param($n, $msg) Write-Host "`n[$n] $msg" -ForegroundColor Cyan }
function Write-Ok   { param($msg)     Write-Host "    OK: $msg"   -ForegroundColor Green }
function Write-Warn { param($msg)     Write-Host "    WARN: $msg" -ForegroundColor Yellow }

Write-Host ""
Write-Host "============================================" -ForegroundColor Magenta
Write-Host "  WMS Fénix — Exportación para migración  " -ForegroundColor Magenta
Write-Host "============================================" -ForegroundColor Magenta

# ── Pedir contraseña si no fue pasada ─────────────────────────────────────────
if ($DbPass -eq "") {
    $securePwd = Read-Host "Contraseña PostgreSQL para '$DbUser'" -AsSecureString
    $DbPass    = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
                     [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePwd))
}

$env:PGPASSWORD = $DbPass
$Timestamp = Get-Date -Format "yyyyMMdd_HHmm"

# ── 1. Crear carpeta de exportación ──────────────────────────────────────────
Write-Step 1 "Creando carpeta de exportación"

New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
New-Item -ItemType Directory -Path "$OutputDir\db" -Force | Out-Null
New-Item -ItemType Directory -Path "$OutputDir\archivos" -Force | Out-Null
Write-Ok "Carpeta destino: $OutputDir"

# ── 2. Exportar PostgreSQL ────────────────────────────────────────────────────
Write-Step 2 "Exportando base de datos PostgreSQL '$DbName'"

$pgBinPaths = @(
    "C:\Program Files\PostgreSQL\18\bin",
    "C:\Program Files\PostgreSQL\17\bin",
    "C:\Program Files\PostgreSQL\16\bin",
    "C:\Program Files\PostgreSQL\15\bin"
)
$PgBin = $null
foreach ($p in $pgBinPaths) {
    if (Test-Path "$p\pg_dump.exe") { $PgBin = $p; break }
}
if (-not $PgBin) { Write-Error "PostgreSQL no encontrado."; exit 1 }

$dumpFile = "$OutputDir\db\${DbName}_${Timestamp}.dump"
& "$PgBin\pg_dump.exe" -h 127.0.0.1 -U $DbUser -F c -f $dumpFile $DbName 2>&1

if ($LASTEXITCODE -eq 0) {
    $size = [Math]::Round((Get-Item $dumpFile).Length / 1MB, 2)
    Write-Ok "BD exportada: $dumpFile ($size MB)"
} else {
    Write-Error "pg_dump falló."
}

# ── 3. Comprimir archivos de la aplicación ────────────────────────────────────
Write-Step 3 "Comprimiendo archivos de la aplicación"

$zipFile = "$OutputDir\archivos\WMS_FENIX_${Timestamp}.zip"

# Excluir: vendor/, backups/, .git/, logs/, .env (se configura en destino)
$excludes = @("vendor", ".git", "backups", "logs", ".env", ".env.local", "brain", "scratch")

Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipStream = [System.IO.Compression.ZipFile]::Open($zipFile, 'Create')

Get-ChildItem -Path $AppPath -Recurse | Where-Object {
    $relative  = $_.FullName.Substring($AppPath.Length + 1)
    $rootPart  = $relative.Split([IO.Path]::DirectorySeparatorChar)[0]
    -not ($excludes -contains $rootPart) -and -not $_.PSIsContainer
} | ForEach-Object {
    $entryName = $_.FullName.Substring($AppPath.Length + 1)
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipStream, $_.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
}

$zipStream.Dispose()

$zipSize = [Math]::Round((Get-Item $zipFile).Length / 1MB, 2)
Write-Ok "Archivos comprimidos: $zipFile ($zipSize MB)"

# ── 4. Copiar .env.example ────────────────────────────────────────────────────
Write-Step 4 "Copiando .env.example (plantilla de configuración)"
Copy-Item "$AppPath\.env.example" "$OutputDir\.env.example" -Force
Write-Ok ".env.example copiado"

# ── 5. Generar instrucciones ──────────────────────────────────────────────────
Write-Step 5 "Generando instrucciones para el servidor destino"

$instrucciones = @"
WMS Fénix — Instrucciones de migración
Generado: $(Get-Date -Format "yyyy-MM-dd HH:mm")
========================================

CONTENIDO DE ESTA CARPETA:
  db\${DbName}_${Timestamp}.dump   → Backup BD PostgreSQL (formato custom)
  archivos\WMS_FENIX_${Timestamp}.zip → Archivos de la aplicación
  .env.example                      → Plantilla de configuración

PASO 1 — REQUISITOS EN EL SERVIDOR DESTINO
  - XAMPP instalado en C:\xampp
    Descargar: https://www.apachefriends.org/

  - PostgreSQL 15+ instalado
    Descargar: https://www.postgresql.org/download/windows/

  - PHP con extensiones habilitadas en C:\xampp\php\php.ini:
    extension=pdo_pgsql
    extension=pgsql
    extension=mbstring
    extension=openssl
    extension=fileinfo

PASO 2 — COPIAR ARCHIVOS
  1. Descomprimir WMS_FENIX_${Timestamp}.zip en C:\xampp\htdocs\WMS_FENIX\

  2. Copiar el backup de BD:
     db\${DbName}_${Timestamp}.dump → C:\xampp\htdocs\WMS_FENIX\backups\db\

PASO 3 — CONFIGURAR APACHE
  En C:\xampp\apache\conf\httpd.conf:
  1. Descomentar: LoadModule rewrite_module modules/mod_rewrite.so
  2. Cambiar AllowOverride None → AllowOverride All en el bloque de htdocs

PASO 4 — EJECUTAR EL SETUP AUTOMÁTICO
  En PowerShell como Administrador:
  cd C:\xampp\htdocs\WMS_FENIX
  powershell -ExecutionPolicy Bypass -File scripts\setup-nuevo-servidor.ps1

  Este script hace automáticamente:
  - Verifica PHP + extensiones + PostgreSQL
  - Crea la base de datos
  - Restaura el backup
  - Configura el .env con la IP del servidor
  - Instala el backup automático a las 04:00 AM

PASO 5 — VERIFICAR
  Abrir en navegador: http://IP_SERVIDOR/WMS_FENIX/public

  Si hay error 404:
  - Verificar mod_rewrite habilitado en Apache
  - Verificar AllowOverride All en httpd.conf

PASO 6 — EJECUTAR MIGRACIONES (solo si es instalación nueva)
  Desde el navegador del servidor (solo localhost):
  http://localhost/WMS_FENIX/public/api/migrations-run.php

RESTAURACIÓN MANUAL DE BD (alternativa al script):
  set PGPASSWORD=TU_PASSWORD
  "C:\Program Files\PostgreSQL\18\bin\pg_restore.exe" ^
    -h 127.0.0.1 -U postgres -d wms_fenix ^
    -F c --no-owner --no-privileges ^
    "C:\xampp\htdocs\WMS_FENIX\backups\db\${DbName}_${Timestamp}.dump"
"@

$instrucciones | Out-File "$OutputDir\INSTRUCCIONES.txt" -Encoding UTF8
Write-Ok "Instrucciones: $OutputDir\INSTRUCCIONES.txt"

# ── 6. Limpiar variable de entorno ────────────────────────────────────────────
$env:PGPASSWORD = ""

# ── RESUMEN ───────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "  EXPORTACIÓN COMPLETA                     " -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Carpeta de migración: $OutputDir" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Contenido:" -ForegroundColor White
Get-ChildItem $OutputDir -Recurse -File | ForEach-Object {
    $size = [Math]::Round($_.Length / 1MB, 2)
    Write-Host "    $($_.Name)  ($size MB)" -ForegroundColor Gray
}
Write-Host ""
Write-Host "SIGUIENTE PASO:" -ForegroundColor Yellow
Write-Host "  Copia la carpeta '$OutputDir' al nuevo servidor" -ForegroundColor Yellow
Write-Host "  y sigue las instrucciones en INSTRUCCIONES.txt" -ForegroundColor Yellow
Write-Host ""
