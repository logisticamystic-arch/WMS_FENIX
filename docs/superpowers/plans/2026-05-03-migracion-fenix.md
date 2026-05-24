# WMS Fénix — Migración desde PROORIENTE (Multi-Tenant) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el contenido React/Python de WMS_FENIX con el sistema PHP/Slim/Eloquent de WMS_PROORIENTE, rebrandeado completamente como "WMS Fénix", conservando logo y paleta de colores propios, sobre MySQL XAMPP.

**Architecture:** PHP 8.2 + Slim 4 + Eloquent ORM sobre MySQL XAMPP. Frontend PWA vanilla JS (sin frameworks). Multi-tenant vía `empresa_id` en todas las tablas. JWT + PIN de 4 dígitos para autenticación.

**Tech Stack:** PHP 8.2 (XAMPP), Slim Framework 4, Laravel Eloquent ORM, MySQL (XAMPP), Firebase JWT, Dotenv, XAMPP Apache con mod_rewrite.

---

## Mapa de archivos afectados

| Archivo | Acción | Motivo |
|---|---|---|
| `C:\xampp\htdocs\WMS_FENIX\frontend\` | Eliminar | Reemplazado por PWA PHP |
| `C:\xampp\htdocs\WMS_FENIX\backend\` | Eliminar | Reemplazado por Slim PHP |
| `C:\xampp\htdocs\WMS_FENIX\.env` | Crear | Config MySQL + Fénix |
| `C:\xampp\htdocs\WMS_FENIX\config\app.php` | Modificar línea 17 | URL default → WMS_FENIX |
| `C:\xampp\htdocs\WMS_FENIX\config\database.php` | Modificar línea 18 | DB default → WMS_FENIX |
| `C:\xampp\htdocs\WMS_FENIX\.htaccess` | Modificar línea 4 | Path → /WMS_FENIX/public/ |
| `C:\xampp\htdocs\WMS_FENIX\public\index.html` | Modificar | Rebrand a Fénix |
| `C:\xampp\htdocs\WMS_FENIX\public\manifest.json` | Reescribir | Nombre + URL Fénix |
| `C:\xampp\htdocs\WMS_FENIX\public\assets\css\desktop.css` | Modificar variables :root | Paleta #0F4C81 |
| `C:\xampp\htdocs\WMS_FENIX\public\assets\css\mobile.css` | Modificar | Paleta #0F4C81 |
| `C:\xampp\htdocs\WMS_FENIX\database\seeds\DatabaseSeeder.php` | Modificar | razon_social + email Fénix |
| Todos los .php/.js/.html (global) | Replace-All | prooriente→fenix |

---

## Task 1: Salvar logo Fénix antes de eliminar frontend

**Files:**
- Read: `C:\xampp\htdocs\WMS_FENIX\frontend\public\assets\logo_fenix.png`
- Create: `C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP\logo_fenix.png`

- [ ] **Step 1: Crear directorio de backup y copiar el logo**

```powershell
New-Item -Path "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP" -ItemType Directory -Force
Copy-Item `
  "C:\xampp\htdocs\WMS_FENIX\frontend\public\assets\logo_fenix.png" `
  "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP\logo_fenix.png" -Force
```

- [ ] **Step 2: Verificar que el logo fue copiado**

```powershell
Test-Path "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP\logo_fenix.png"
```

Expected: `True`

---

## Task 2: Limpiar WMS_FENIX (eliminar React/Python, conservar PHP y git)

**Files:**
- Delete: `C:\xampp\htdocs\WMS_FENIX\frontend\`
- Delete: `C:\xampp\htdocs\WMS_FENIX\backend\`
- Keep: `.git\`, `.superpowers\`, `docs\`, `Skills\`, `WMS_PROORIENTE\`, `WMS_PROORIENTE_BACKUP\`, `WMS_FENIX_BRIEF.md`, `PROMPTS_SUPERPOWERS.md`

- [ ] **Step 1: Eliminar directorios React y Python**

```powershell
$keep = @('.git', '.superpowers', 'docs', 'Skills', 'WMS_PROORIENTE', 'WMS_PROORIENTE_BACKUP', 'WMS_FENIX_BRIEF.md', 'PROMPTS_SUPERPOWERS.md')
$root = "C:\xampp\htdocs\WMS_FENIX"
Get-ChildItem $root | Where-Object { $_.Name -notin $keep } | Remove-Item -Recurse -Force
```

- [ ] **Step 2: Verificar que solo quedan los directorios correctos**

```powershell
Get-ChildItem "C:\xampp\htdocs\WMS_FENIX" -Name | Sort-Object
```

Expected output contiene: `.git`, `.superpowers`, `docs`, `Skills`, `WMS_PROORIENTE`, `WMS_PROORIENTE_BACKUP`, `WMS_FENIX_BRIEF.md`, `PROMPTS_SUPERPOWERS.md`. NO debe aparecer `frontend` ni `backend`.

---

## Task 3: Copiar WMS_PROORIENTE al root de WMS_FENIX

**Files:**
- Source: `C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE\*` (todos los hijos)
- Destination: `C:\xampp\htdocs\WMS_FENIX\`

- [ ] **Step 1: Copiar contenido del PHP a la raíz**

```powershell
$src = "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE"
$dst = "C:\xampp\htdocs\WMS_FENIX"
Get-ChildItem $src | ForEach-Object {
    Copy-Item $_.FullName -Destination $dst -Recurse -Force
}
```

- [ ] **Step 2: Verificar archivos PHP en raíz**

```powershell
Get-ChildItem "C:\xampp\htdocs\WMS_FENIX" -Name | Sort-Object
```

Expected: Debe aparecer `bootstrap.php`, `composer.json`, `public`, `src`, `database`, `config`, `vendor`, `logs`, `backups`, etc.

- [ ] **Step 3: Commit de la base copiada**

```powershell
cd "C:\xampp\htdocs\WMS_FENIX"
git add -A
git commit -m "chore: copy WMS_PROORIENTE PHP base to WMS_FENIX root"
```

---

## Task 4: Configurar .env para MySQL y branding Fénix

**Files:**
- Create/Overwrite: `C:\xampp\htdocs\WMS_FENIX\.env`

- [ ] **Step 1: Escribir .env con configuración MySQL + Fénix**

```powershell
$envContent = @'
# ── Base de Datos (MySQL/XAMPP) ────────────────────────────────
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=WMS_FENIX
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

# ── Autenticación JWT ─────────────────────────────────────────
JWT_SECRET=fenix_wms_enterprise_secret_2026_cambia_en_produccion
JWT_EXPIRY=28800

# ── Aplicación ────────────────────────────────────────────────
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/WMS_FENIX/public

# ── CORS ──────────────────────────────────────────────────────
CORS_ALLOWED_ORIGINS=http://192.168.1.9,http://localhost,http://127.0.0.1

# ── Integración TMS ───────────────────────────────────────────
TMS_WEBHOOK_URL=
TMS_API_KEY=

# ── Backup Automático ─────────────────────────────────────────
BACKUP_DIR=backups
BACKUP_RETENTION_DAYS=30
'@
Set-Content "C:\xampp\htdocs\WMS_FENIX\.env" $envContent -Encoding UTF8
```

- [ ] **Step 2: Verificar .env**

```powershell
Get-Content "C:\xampp\htdocs\WMS_FENIX\.env"
```

Expected: Las líneas `DB_DRIVER=mysql`, `DB_NAME=WMS_FENIX`, `APP_URL=http://localhost/WMS_FENIX/public` deben aparecer.

---

## Task 5: Actualizar .htaccess — ruta del rewrite

**Files:**
- Modify: `C:\xampp\htdocs\WMS_FENIX\.htaccess`

- [ ] **Step 1: Reescribir .htaccess con path WMS_FENIX**

```powershell
$htaccess = @'
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/WMS_FENIX/public/
RewriteRule ^(.*)$ public/$1 [L]
'@
Set-Content "C:\xampp\htdocs\WMS_FENIX\.htaccess" $htaccess -Encoding UTF8
```

- [ ] **Step 2: Verificar**

```powershell
Get-Content "C:\xampp\htdocs\WMS_FENIX\.htaccess"
```

Expected: Debe contener `/WMS_FENIX/public/`, NO `/WMS_PROORIENTE/public/` ni `/Prooriente/`.

---

## Task 6: Actualizar config/app.php y config/database.php

**Files:**
- Modify: `C:\xampp\htdocs\WMS_FENIX\config\app.php` (línea 17)
- Modify: `C:\xampp\htdocs\WMS_FENIX\config\database.php` (línea 18)

- [ ] **Step 1: Actualizar URL default en config/app.php**

```powershell
$appCfg = Get-Content "C:\xampp\htdocs\WMS_FENIX\config\app.php" -Raw
$appCfg = $appCfg -replace "http://localhost/WMS_PROORIENTE/public", "http://localhost/WMS_FENIX/public"
Set-Content "C:\xampp\htdocs\WMS_FENIX\config\app.php" $appCfg -Encoding UTF8 -NoNewline
```

- [ ] **Step 2: Actualizar DB_NAME default en config/database.php**

```powershell
$dbCfg = Get-Content "C:\xampp\htdocs\WMS_FENIX\config\database.php" -Raw
$dbCfg = $dbCfg -replace "'WMS_PROORIENTE'", "'WMS_FENIX'"
$dbCfg = $dbCfg -replace "WMS ProOriente", "WMS Fénix"
$dbCfg = $dbCfg -replace "WMS Prooriente", "WMS Fénix"
Set-Content "C:\xampp\htdocs\WMS_FENIX\config\database.php" $dbCfg -Encoding UTF8 -NoNewline
```

- [ ] **Step 3: Verificar cambios**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\config\app.php" -Pattern "WMS_FENIX"
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\config\database.php" -Pattern "WMS_FENIX"
```

Expected: Ambos comandos muestran al menos una coincidencia.

---

## Task 7: Rebrand en public/index.html (login y UI principal)

**Files:**
- Modify: `C:\xampp\htdocs\WMS_FENIX\public\index.html` (título, textos login, alt images)

- [ ] **Step 1: Reemplazar referencias de marca en index.html**

```powershell
$html = Get-Content "C:\xampp\htdocs\WMS_FENIX\public\index.html" -Raw -Encoding UTF8
$html = $html -replace '<title>WMS Prooriente \| Enterprise</title>', '<title>WMS Fénix | Enterprise</title>'
$html = $html -replace '<title>WMS ProOriente \| Enterprise</title>', '<title>WMS Fénix | Enterprise</title>'
$html = $html -replace 'alt="Pro Oriente"', 'alt="Fénix"'
$html = $html -replace 'alt="Prooriente"', 'alt="Fénix"'
$html = $html -replace '<h1>WMS Prooriente</h1>', '<h1>WMS Fénix</h1>'
$html = $html -replace '<h1>WMS ProOriente</h1>', '<h1>WMS Fénix</h1>'
$html = $html -replace 'WMS Prooriente', 'WMS Fénix'
$html = $html -replace 'WMS ProOriente', 'WMS Fénix'
$html = $html -replace 'Pro Oriente', 'Fénix'
Set-Content "C:\xampp\htdocs\WMS_FENIX\public\index.html" $html -Encoding UTF8 -NoNewline
```

- [ ] **Step 2: Verificar que no queda rastro de Prooriente**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\public\index.html" -Pattern "Prooriente|ProOriente|Pro Oriente" -CaseSensitive
```

Expected: Sin resultados (0 matches).

---

## Task 8: Reescribir manifest.json con branding Fénix

**Files:**
- Overwrite: `C:\xampp\htdocs\WMS_FENIX\public\manifest.json`

- [ ] **Step 1: Escribir nuevo manifest.json**

```powershell
$manifest = @'
{
  "name": "WMS Fénix",
  "short_name": "WMSFénix",
  "description": "Sistema de Gestión de Almacenes — Fénix Enterprise",
  "start_url": "/WMS_FENIX/public/",
  "scope": "/WMS_FENIX/public/",
  "display": "standalone",
  "background_color": "#0f172a",
  "theme_color": "#0F4C81",
  "icons": [
    {
      "src": "assets/icons/icon-192x192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "assets/icons/icon-512x512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
'@
Set-Content "C:\xampp\htdocs\WMS_FENIX\public\manifest.json" $manifest -Encoding UTF8
```

- [ ] **Step 2: Verificar**

```powershell
Get-Content "C:\xampp\htdocs\WMS_FENIX\public\manifest.json"
```

Expected: `"name": "WMS Fénix"`, `"theme_color": "#0F4C81"`, `"start_url": "/WMS_FENIX/public/"`.

---

## Task 9: Actualizar paleta de colores en desktop.css (Azul Fénix)

**Files:**
- Modify: `C:\xampp\htdocs\WMS_FENIX\public\assets\css\desktop.css` (bloque :root + comentario + gradientes)

- [ ] **Step 1: Reemplazar colores primarios y comentario de cabecera**

```powershell
$css = Get-Content "C:\xampp\htdocs\WMS_FENIX\public\assets\css\desktop.css" -Raw -Encoding UTF8

# Comentario de cabecera
$css = $css -replace 'WMS PRO ORIENTE', 'WMS FÉNIX'
$css = $css -replace 'WMS Pro Oriente', 'WMS Fénix'
$css = $css -replace 'WMS Prooriente', 'WMS Fénix'

# Variables :root — primary
$css = $css -replace '--primary:\s+#1a56db', '--primary:       #0F4C81'
$css = $css -replace '--primary-dark:\s+#1e40af', '--primary-dark:  #0a3d69'
$css = $css -replace '--primary-light:\s+#eff6ff', '--primary-light: #e8f0f9'

# Token semántico corporate blue
$css = $css -replace '--color-primary:\s+#1F498B', '--color-primary:    #0F4C81'

# Border-focus (usa azul primario)
$css = $css -replace '--border-focus:\s+#3b82f6', '--border-focus:  #0F4C81'

# Gradiente azul enterprise
$css = $css -replace 'linear-gradient\(135deg, #1a56db, #3b82f6\)', 'linear-gradient(135deg, #0F4C81, #1a73e8)'

Set-Content "C:\xampp\htdocs\WMS_FENIX\public\assets\css\desktop.css" $css -Encoding UTF8 -NoNewline
```

- [ ] **Step 2: Verificar que #1a56db ya no existe**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\public\assets\css\desktop.css" -Pattern "#1a56db|#1F498B|#1e40af" -CaseSensitive
```

Expected: Sin resultados.

- [ ] **Step 3: Verificar que #0F4C81 está presente**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\public\assets\css\desktop.css" -Pattern "#0F4C81"
```

Expected: Al menos 3 coincidencias (--primary, --color-primary, --border-focus).

---

## Task 10: Actualizar mobile.css con paleta Fénix

**Files:**
- Modify: `C:\xampp\htdocs\WMS_FENIX\public\assets\css\mobile.css`

- [ ] **Step 1: Reemplazar colores primarios en mobile.css**

```powershell
$mobileCss = Get-Content "C:\xampp\htdocs\WMS_FENIX\public\assets\css\mobile.css" -Raw -Encoding UTF8
$mobileCss = $mobileCss -replace '#1a56db', '#0F4C81'
$mobileCss = $mobileCss -replace '#1e40af', '#0a3d69'
$mobileCss = $mobileCss -replace '#1F498B', '#0F4C81'
$mobileCss = $mobileCss -replace '#eff6ff', '#e8f0f9'
$mobileCss = $mobileCss -replace 'WMS Prooriente', 'WMS Fénix'
$mobileCss = $mobileCss -replace 'WMS ProOriente', 'WMS Fénix'
Set-Content "C:\xampp\htdocs\WMS_FENIX\public\assets\css\mobile.css" $mobileCss -Encoding UTF8 -NoNewline
```

- [ ] **Step 2: Verificar (si no hay ocurrencias el comando no falla)**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\public\assets\css\mobile.css" -Pattern "Prooriente|#1a56db"
```

Expected: Sin resultados.

---

## Task 11: Reemplazar logo — copiar logo_fenix.png

**Files:**
- Source: `C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP\logo_fenix.png`
- Destination: `C:\xampp\htdocs\WMS_FENIX\public\assets\images\logo.png`

- [ ] **Step 1: Copiar logo Fénix sobre el logo anterior**

```powershell
Copy-Item `
  "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP\logo_fenix.png" `
  "C:\xampp\htdocs\WMS_FENIX\public\assets\images\logo.png" -Force
```

- [ ] **Step 2: Verificar tamaño del archivo (debe ser > 1 KB)**

```powershell
(Get-Item "C:\xampp\htdocs\WMS_FENIX\public\assets\images\logo.png").Length
```

Expected: Número mayor a 1024 (bytes).

---

## Task 12: Rebrand global en seeds (empresa demo = Fénix)

**Files:**
- Modify: `C:\xampp\htdocs\WMS_FENIX\database\seeds\DatabaseSeeder.php`

- [ ] **Step 1: Actualizar razon_social y email en el seeder**

```powershell
$seeder = Get-Content "C:\xampp\htdocs\WMS_FENIX\database\seeds\DatabaseSeeder.php" -Raw -Encoding UTF8
$seeder = $seeder -replace "'razon_social' => 'Prooriente WMS'", "'razon_social' => 'WMS Fénix'"
$seeder = $seeder -replace "'razon_social' => 'WMS Prooriente'", "'razon_social' => 'WMS Fénix'"
$seeder = $seeder -replace "'email' => 'admin@prooriente.com'", "'email' => 'admin@wmsfenix.com'"
$seeder = $seeder -replace "'nombre' => 'Prooriente WMS'", "'nombre' => 'WMS Fénix'"
Set-Content "C:\xampp\htdocs\WMS_FENIX\database\seeds\DatabaseSeeder.php" $seeder -Encoding UTF8 -NoNewline
```

- [ ] **Step 2: Verificar**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\database\seeds\DatabaseSeeder.php" -Pattern "prooriente|Prooriente" -CaseSensitive
```

Expected: Sin resultados.

---

## Task 13: Replace-All global en JS, PHP src/ y docs

**Files:**
- Modify (bulk): `C:\xampp\htdocs\WMS_FENIX\public\assets\js\**\*.js`
- Modify (bulk): `C:\xampp\htdocs\WMS_FENIX\src\**\*.php`

- [ ] **Step 1: Reemplazar texto de marca en todos los JS**

```powershell
$jsFiles = Get-ChildItem "C:\xampp\htdocs\WMS_FENIX\public\assets\js" -Recurse -Include "*.js"
foreach ($file in $jsFiles) {
    $content = Get-Content $file.FullName -Raw -Encoding UTF8
    $updated = $content `
        -replace 'WMS Prooriente', 'WMS Fénix' `
        -replace 'WMS ProOriente', 'WMS Fénix' `
        -replace 'WMS Pro Oriente', 'WMS Fénix' `
        -replace 'Prooriente', 'Fénix' `
        -replace 'Pro Oriente', 'Fénix'
    if ($content -ne $updated) {
        Set-Content $file.FullName $updated -Encoding UTF8 -NoNewline
        Write-Host "Updated: $($file.Name)"
    }
}
```

- [ ] **Step 2: Reemplazar texto de marca en comentarios de src/ PHP**

```powershell
$phpFiles = Get-ChildItem "C:\xampp\htdocs\WMS_FENIX\src" -Recurse -Include "*.php"
foreach ($file in $phpFiles) {
    $content = Get-Content $file.FullName -Raw -Encoding UTF8
    $updated = $content `
        -replace 'WMS Prooriente', 'WMS Fénix' `
        -replace 'WMS ProOriente', 'WMS Fénix' `
        -replace 'WMS Pro Oriente', 'WMS Fénix' `
        -replace 'ProOriente', 'WMSFenix' `
        -replace 'Prooriente', 'Fénix' `
        -replace 'Pro Oriente', 'Fénix'
    if ($content -ne $updated) {
        Set-Content $file.FullName $updated -Encoding UTF8 -NoNewline
        Write-Host "Updated: $($file.Name)"
    }
}
```

- [ ] **Step 3: Verificar que no quedan referencias en JS**

```powershell
Select-String -Path "C:\xampp\htdocs\WMS_FENIX\public\assets\js\*" -Pattern "Prooriente|Pro Oriente" -Recurse
```

Expected: Sin resultados (o solo en vendor/ que no modificamos).

---

## Task 14: Crear base de datos MySQL WMS_FENIX

**Files:** (sin archivos, operación de BD)

- [ ] **Step 1: Drop y Create de la base de datos**

```powershell
$mysqlExe = "C:\xampp\mysql\bin\mysql.exe"
& $mysqlExe -u root -e "DROP DATABASE IF EXISTS WMS_FENIX; CREATE DATABASE WMS_FENIX CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- [ ] **Step 2: Verificar que la BD fue creada**

```powershell
$mysqlExe = "C:\xampp\mysql\bin\mysql.exe"
& $mysqlExe -u root -e "SHOW DATABASES LIKE 'WMS_FENIX';"
```

Expected: `WMS_FENIX` aparece en los resultados.

---

## Task 15: Ejecutar migraciones vía PHP CLI

**Files:**
- Execute: `C:\xampp\htdocs\WMS_FENIX\database\migrations\*.php` (59 archivos)

- [ ] **Step 1: Crear script de migración temporal**

```powershell
$migrateScript = @'
<?php
chdir('C:\\xampp\\htdocs\\WMS_FENIX');
require_once 'bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();

// Crear tabla de control de migraciones
if (!$schema->hasTable('migrations')) {
    $schema->create('migrations', function ($t) {
        $t->increments('id');
        $t->string('migration');
        $t->integer('batch');
        $t->timestamp('ran_at')->useCurrent();
    });
    echo "[OK] Tabla migrations creada\n";
}

$ran   = Capsule::table('migrations')->pluck('migration')->toArray();
$batch = (int)(Capsule::table('migrations')->max('batch') ?? 0) + 1;
$files = glob('C:\\xampp\\htdocs\\WMS_FENIX\\database\\migrations\\*.php');
sort($files);

$done = 0; $errors = 0;
foreach ($files as $file) {
    $name = basename($file, '.php');
    if (in_array($name, $ran)) { echo "[SKIP] $name\n"; continue; }
    try {
        $migration = require $file;
        if (is_array($migration) && isset($migration['up'])) {
            $migration['up']();
        }
        Capsule::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
        echo "[OK]   $name\n";
        $done++;
    } catch (\Throwable $e) {
        echo "[ERR]  $name: " . $e->getMessage() . "\n";
        $errors++;
    }
}
echo "\nTotal: $done ejecutadas, $errors errores\n";
'@
Set-Content "C:\xampp\htdocs\WMS_FENIX\migrate_tmp.php" $migrateScript -Encoding UTF8
```

- [ ] **Step 2: Ejecutar migraciones**

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\WMS_FENIX\migrate_tmp.php"
```

Expected: Se listan ~59 líneas `[OK]   001_create_empresas_table`, `[OK]   002_...` etc.  
Si aparecen `[ERR]` revisar el mensaje de error antes de continuar.

- [ ] **Step 3: Eliminar script temporal**

```powershell
Remove-Item "C:\xampp\htdocs\WMS_FENIX\migrate_tmp.php" -Force
```

---

## Task 16: Ejecutar seeds iniciales (empresa, sucursal, usuarios, permisos)

**Files:**
- Execute: `C:\xampp\htdocs\WMS_FENIX\database\seeds\DatabaseSeeder.php`

- [ ] **Step 1: Crear script de seed temporal**

```powershell
$seedScript = @'
<?php
chdir('C:\\xampp\\htdocs\\WMS_FENIX');
require_once 'bootstrap.php';
require_once 'C:\\xampp\\htdocs\\WMS_FENIX\\database\\seeds\\DatabaseSeeder.php';

$seeder = new DatabaseSeeder();
$seeder->run();
echo "\n[DONE] Seeds ejecutados correctamente\n";
'@
Set-Content "C:\xampp\htdocs\WMS_FENIX\seed_tmp.php" $seedScript -Encoding UTF8
```

- [ ] **Step 2: Ejecutar seed**

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\WMS_FENIX\seed_tmp.php"
```

Expected output:
```
  → Seeding empresa...
  → Seeding sucursal...
  → Seeding parametros...
  → Seeding virtual locations (PATIO, OBSOLETO, MUELLE-01)...
  → Seeding sample locations (P01-01-01 to P01-03-03)...
  → Seeding admin user (PIN: 1234)...
  → Seeding permissions catalog...
[DONE] Seeds ejecutados correctamente
```

- [ ] **Step 3: Eliminar script temporal**

```powershell
Remove-Item "C:\xampp\htdocs\WMS_FENIX\seed_tmp.php" -Force
```

---

## Task 17: Limpieza — eliminar WMS_PROORIENTE/ interno y backup

**Files:**
- Delete: `C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE\`
- Delete: `C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP\`

- [ ] **Step 1: Eliminar carpeta PROORIENTE interna (ya copiada)**

```powershell
Remove-Item "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE" -Recurse -Force
Remove-Item "C:\xampp\htdocs\WMS_FENIX\WMS_PROORIENTE_BACKUP" -Recurse -Force
```

- [ ] **Step 2: Verificar estructura final limpia**

```powershell
Get-ChildItem "C:\xampp\htdocs\WMS_FENIX" -Name | Sort-Object
```

Expected: `backups`, `bootstrap.php`, `composer.json`, `composer.lock`, `config`, `database`, `docs`, `logs`, `public`, `Skills`, `src`, `vendor`, `.env`, `.gitignore`, `.htaccess`, `.superpowers`, `WMS_FENIX_BRIEF.md`, `PROMPTS_SUPERPOWERS.md`. Sin `WMS_PROORIENTE` ni `frontend` ni `backend`.

---

## Task 18: Commit final y verificación en navegador

**Files:** (sin archivos — commit + test)

- [ ] **Step 1: Commit de todos los cambios de branding**

```powershell
cd "C:\xampp\htdocs\WMS_FENIX"
git add -A
git commit -m "feat: rebrand WMS_PROORIENTE to WMS_FENIX with Fenix color palette and logo"
```

- [ ] **Step 2: Verificar que Apache XAMPP sirve el proyecto**

Abre en el navegador: **http://localhost/WMS_FENIX/public/**

Expected:
- Se muestra pantalla de login con logo Fénix (azul #0F4C81)
- Título de la página: "WMS Fénix | Enterprise"
- Logo cargado correctamente (no imagen rota)
- Formulario de login con campo Documento y PIN

- [ ] **Step 3: Probar login con usuario demo**

En el formulario de login:
- **NIT empresa:** `900000001`
- **Documento:** `ADMIN001`  
- **PIN:** `1234`

Expected: Accede al dashboard principal. No debe aparecer "Prooriente" ni "Pro Oriente" en ningún lugar de la UI.

- [ ] **Step 4: Verificar mobile en http://localhost/WMS_FENIX/public/mobile/**

Expected: Login mobile con logo Fénix y colores azul #0F4C81.

---

## CHECKPOINT FINAL — Criterios de aceptación

Antes de cerrar esta tarea, verificar que se cumplen TODOS estos puntos:

- [ ] `http://localhost/WMS_FENIX/public/` carga sin errores 500
- [ ] El login muestra el logo `logo_fenix.png`
- [ ] Los colores son azul `#0F4C81` (no el azul PROORIENTE `#1a56db`)
- [ ] La DB `WMS_FENIX` existe en MySQL con 60+ tablas
- [ ] Login ADMIN001 / PIN 1234 funciona
- [ ] No aparece "Prooriente" ni "Pro Oriente" en la UI
- [ ] La URL es `/WMS_FENIX/public/` (no `/WMS_PROORIENTE/public/`)
- [ ] El PWA manifest dice "WMS Fénix"
