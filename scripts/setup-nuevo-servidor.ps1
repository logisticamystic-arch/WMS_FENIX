# ============================================================
#  WMS Fénix — Setup Servidor Nuevo Windows 11
#  Ejecutar como ADMINISTRADOR en el servidor destino
#  powershell -ExecutionPolicy Bypass -File scripts\setup-nuevo-servidor.ps1
# ============================================================

param(
    [string]$AppPath      = "C:\xampp\htdocs\WMS_FENIX",
    [string]$XamppPath    = "C:\xampp",
    [string]$PgVersion    = "18",
    [string]$DbName       = "wms_fenix",
    [string]$DbUser       = "postgres",
    [string]$DbPass       = "",        # Se pedirá interactivamente si queda vacío
    [string]$AppUrl       = "",        # Ej: http://192.168.1.100/WMS_FENIX/public
    [switch]$SkipDbRestore            # Usar si la BD ya fue restaurada manualmente
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Step  { param($n, $msg) Write-Host "`n[$n] $msg" -ForegroundColor Cyan }
function Write-Ok    { param($msg)     Write-Host "    OK: $msg"    -ForegroundColor Green }
function Write-Warn  { param($msg)     Write-Host "    WARN: $msg"  -ForegroundColor Yellow }
function Write-Fail  { param($msg)     Write-Host "    ERROR: $msg" -ForegroundColor Red; exit 1 }

# ── 0. Verificar que se ejecuta como Administrador ─────────────────────────────
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)
if (-not $isAdmin) { Write-Fail "Ejecuta PowerShell como Administrador." }

Write-Host ""
Write-Host "============================================" -ForegroundColor Magenta
Write-Host "  WMS Fénix — Setup Servidor Windows 11   " -ForegroundColor Magenta
Write-Host "============================================" -ForegroundColor Magenta

# ── 1. Verificar XAMPP ────────────────────────────────────────────────────────
Write-Step 1 "Verificando XAMPP"

$PhpExe   = "$XamppPath\php\php.exe"
$ApacheExe = "$XamppPath\apache\bin\httpd.exe"

if (-not (Test-Path $PhpExe))    { Write-Fail "PHP no encontrado en $PhpExe. Instala XAMPP desde https://www.apachefriends.org/" }
if (-not (Test-Path $ApacheExe)) { Write-Fail "Apache no encontrado en $XamppPath. Verifica la instalación de XAMPP." }

$phpVer = & $PhpExe -r "echo PHP_VERSION;"
Write-Ok "PHP $phpVer en $PhpExe"

# Verificar extensiones PHP requeridas
$reqs = @("pdo_pgsql", "pdo", "mbstring", "json", "openssl", "fileinfo")
foreach ($ext in $reqs) {
    $ok = & $PhpExe -m 2>$null | Select-String -Pattern "^$ext$" -Quiet
    if ($ok) { Write-Ok "Extensión: $ext" }
    else      { Write-Warn "Extensión PHP '$ext' no está habilitada — actívala en $XamppPath\php\php.ini" }
}

# ── 2. Verificar PostgreSQL ───────────────────────────────────────────────────
Write-Step 2 "Verificando PostgreSQL"

$pgBinPaths = @(
    "C:\Program Files\PostgreSQL\$PgVersion\bin",
    "C:\Program Files\PostgreSQL\17\bin",
    "C:\Program Files\PostgreSQL\16\bin",
    "C:\Program Files\PostgreSQL\15\bin"
)
$PgBin = $null
foreach ($p in $pgBinPaths) {
    if (Test-Path "$p\pg_dump.exe") { $PgBin = $p; break }
}
if (-not $PgBin) {
    Write-Fail "PostgreSQL no encontrado. Instálalo desde https://www.postgresql.org/download/windows/"
}
Write-Ok "PostgreSQL encontrado en $PgBin"

$psqlExe    = "$PgBin\psql.exe"
$pgDumpExe  = "$PgBin\pg_dump.exe"
$pgRestoreExe = "$PgBin\pg_restore.exe"

# ── 3. Pedir contraseña de PostgreSQL si no fue pasada ───────────────────────
Write-Step 3 "Configurando acceso a PostgreSQL"

if ($DbPass -eq "") {
    $securePwd = Read-Host "Contraseña para el usuario PostgreSQL '$DbUser'" -AsSecureString
    $DbPass    = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
                     [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePwd))
}

# Verificar conexión
$env:PGPASSWORD = $DbPass
$testConn = & $psqlExe -h 127.0.0.1 -U $DbUser -c "SELECT 1;" -t 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Fail "No se pudo conectar a PostgreSQL. Verifica que el servicio está corriendo y la contraseña es correcta."
}
Write-Ok "Conexión a PostgreSQL exitosa"

# ── 4. Crear base de datos ─────────────────────────────────────────────────────
Write-Step 4 "Creando base de datos '$DbName'"

$dbExists = & $psqlExe -h 127.0.0.1 -U $DbUser -tAc "SELECT 1 FROM pg_database WHERE datname='$DbName';" 2>&1
if ($dbExists -match "1") {
    Write-Warn "La base de datos '$DbName' ya existe — se omite la creación."
} else {
    & $psqlExe -h 127.0.0.1 -U $DbUser -c "CREATE DATABASE $DbName ENCODING 'UTF8' LC_COLLATE 'Spanish_Colombia.1252' LC_CTYPE 'Spanish_Colombia.1252' TEMPLATE template0;" 2>&1
    if ($LASTEXITCODE -ne 0) {
        # Intentar sin locale específico
        & $psqlExe -h 127.0.0.1 -U $DbUser -c "CREATE DATABASE $DbName ENCODING 'UTF8';" 2>&1
    }
    Write-Ok "Base de datos '$DbName' creada"
}

# ── 5. Restaurar backup de BD (si existe) ─────────────────────────────────────
Write-Step 5 "Restaurando base de datos"

if ($SkipDbRestore) {
    Write-Warn "Restauración omitida por parámetro -SkipDbRestore"
} else {
    # Buscar el backup más reciente en backups/db/
    $backupDir = "$AppPath\backups\db"
    if (Test-Path $backupDir) {
        $latestBackup = Get-ChildItem "$backupDir\dia_*.dump" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        if ($latestBackup) {
            Write-Host "    Restaurando desde: $($latestBackup.FullName)" -ForegroundColor Yellow
            & $pgRestoreExe -h 127.0.0.1 -U $DbUser -d $DbName -F c --no-owner --no-privileges "$($latestBackup.FullName)" 2>&1
            if ($LASTEXITCODE -eq 0) {
                Write-Ok "Base de datos restaurada desde $($latestBackup.Name)"
            } else {
                Write-Warn "Restauración con advertencias — verifica los datos manualmente."
            }
        } else {
            Write-Warn "No se encontró backup .dump en $backupDir"
            Write-Warn "Copia el backup manualmente y ejecuta:"
            Write-Warn "  pg_restore -h 127.0.0.1 -U $DbUser -d $DbName -F c --no-owner archivo.dump"
        }
    } else {
        Write-Warn "Carpeta $backupDir no existe. Restaura la BD manualmente."
    }
}

# ── 6. Configurar .env ────────────────────────────────────────────────────────
Write-Step 6 "Configurando .env"

$envFile    = "$AppPath\.env"
$envExample = "$AppPath\.env.example"

if (-not (Test-Path $envFile)) {
    if (Test-Path $envExample) {
        Copy-Item $envExample $envFile
        Write-Ok ".env creado desde .env.example"
    } else {
        Write-Fail ".env.example no encontrado en $AppPath"
    }
}

# Leer y actualizar valores en .env
$envContent = Get-Content $envFile -Raw

# Detectar IP de la máquina para APP_URL
if ($AppUrl -eq "") {
    $ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.InterfaceAlias -notmatch 'Loopback' -and $_.PrefixOrigin -eq 'Dhcp'} | Select-Object -First 1).IPAddress
    if (-not $ip) { $ip = "127.0.0.1" }
    $AppUrl = "http://$ip/WMS_FENIX/public"
    Write-Warn "APP_URL auto-detectada: $AppUrl"
}

# Actualizar variables clave
$updates = @{
    "DB_DRIVER"            = "pgsql"
    "DB_HOST"              = "127.0.0.1"
    "DB_PORT"              = "5432"
    "DB_NAME"              = $DbName
    "DB_USER"              = $DbUser
    "DB_PASS"              = $DbPass
    "DB_CHARSET"           = "utf8"
    "DB_SSLMODE"           = "disable"
    "APP_ENV"              = "production"
    "APP_DEBUG"            = "false"
    "APP_URL"              = $AppUrl
}

foreach ($key in $updates.Keys) {
    $val = $updates[$key]
    if ($envContent -match "(?m)^$key=") {
        $envContent = $envContent -replace "(?m)^$key=.*$", "$key=$val"
    } else {
        $envContent += "`n$key=$val"
    }
}

Set-Content $envFile $envContent -Encoding UTF8
Write-Ok ".env configurado (APP_URL=$AppUrl)"

# ── 7. Instalar dependencias Composer ─────────────────────────────────────────
Write-Step 7 "Verificando dependencias Composer"

if (-not (Test-Path "$AppPath\vendor")) {
    Write-Host "    Instalando dependencias via Composer..." -ForegroundColor Yellow
    $composerPhar = "$XamppPath\php\composer.phar"
    if (Test-Path $composerPhar) {
        & $PhpExe $composerPhar install --no-dev --optimize-autoloader --working-dir=$AppPath 2>&1
    } else {
        Write-Warn "Composer no encontrado. Instala Composer desde https://getcomposer.org/download/"
        Write-Warn "Luego ejecuta: composer install --no-dev --optimize-autoloader"
    }
} else {
    Write-Ok "vendor/ ya existe — dependencias instaladas"
}

# ── 8. Crear carpetas requeridas ──────────────────────────────────────────────
Write-Step 8 "Creando carpetas requeridas"

$dirs = @(
    "$AppPath\logs",
    "$AppPath\backups\db",
    "$AppPath\backups\files",
    "$AppPath\public\uploads\devoluciones"
)
foreach ($d in $dirs) {
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null }
    Write-Ok $d
}

# Crear archivos .gitkeep en carpetas vacías
@("$AppPath\logs\.gitkeep", "$AppPath\backups\.gitkeep") | ForEach-Object {
    if (-not (Test-Path $_)) { New-Item $_ -ItemType File -Force | Out-Null }
}

# ── 9. Verificar configuración Apache / .htaccess ─────────────────────────────
Write-Step 9 "Verificando Apache"

$apacheConf = "$XamppPath\apache\conf\httpd.conf"
if (Test-Path $apacheConf) {
    $confContent = Get-Content $apacheConf -Raw
    if ($confContent -notmatch "mod_rewrite") {
        Write-Warn "Verifica que mod_rewrite esté habilitado en $apacheConf"
        Write-Warn "Busca la línea: #LoadModule rewrite_module modules/mod_rewrite.so"
        Write-Warn "Y quita el # del inicio"
    } else {
        Write-Ok "mod_rewrite detectado en Apache config"
    }

    # Verificar AllowOverride
    if ($confContent -notmatch "AllowOverride All") {
        Write-Warn "Asegúrate que AllowOverride está en 'All' para el directorio htdocs"
    }
}

# ── 10. Ejecutar migraciones pendientes ───────────────────────────────────────
Write-Step 10 "Ejecutando migraciones de BD"

$migrationsUrl = "$AppUrl".Replace("public", "public/api/migrations-run.php")
Write-Host "    URL de migraciones: $migrationsUrl" -ForegroundColor Yellow
Write-Host "    Puedes ejecutarlas desde el navegador (solo localhost):"
Write-Host "    $migrationsUrl" -ForegroundColor Cyan

# ── 11. Instalar backup automático 04:00 AM ───────────────────────────────────
Write-Step 11 "Instalando backup automático (04:00 AM)"

$TaskName   = "WMS Fenix - Backup Diario"
$ScriptPath = "$AppPath\scripts\backup.php"

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$Action    = New-ScheduledTaskAction -Execute $PhpExe -Argument $ScriptPath -WorkingDirectory $AppPath
$Trigger   = New-ScheduledTaskTrigger -Daily -At "04:00"
$Settings  = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Hours 2) -StartWhenAvailable -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 15)
$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Principal $Principal -Description "Backup diario WMS Fenix 04:00 AM" -Force | Out-Null
Write-Ok "Tarea '$TaskName' programada a las 04:00 AM"

# ── RESUMEN FINAL ──────────────────────────────────────────────────────────────
$env:PGPASSWORD = ""

Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "  SETUP COMPLETADO EXITOSAMENTE            " -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Aplicacion : $AppUrl" -ForegroundColor Cyan
Write-Host "  Base datos : PostgreSQL @ 127.0.0.1:5432/$DbName" -ForegroundColor Cyan
Write-Host "  Backup     : diario 04:00 AM en $AppPath\backups\" -ForegroundColor Cyan
Write-Host "  Log        : $AppPath\logs\app.log" -ForegroundColor Cyan
Write-Host ""
Write-Host "PROXIMOS PASOS:" -ForegroundColor Yellow
Write-Host "  1. Abre un navegador y ve a: $AppUrl" -ForegroundColor Yellow
Write-Host "  2. Ejecuta migraciones en:   $AppUrl/../api/migrations-run.php" -ForegroundColor Yellow
Write-Host "  3. Habilita mod_rewrite si Apache da error 404" -ForegroundColor Yellow
Write-Host "  4. Prueba el backup: & '$PhpExe' '$AppPath\scripts\backup.php'" -ForegroundColor Yellow
Write-Host ""
