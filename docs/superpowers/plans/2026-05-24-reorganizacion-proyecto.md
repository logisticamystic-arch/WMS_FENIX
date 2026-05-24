# WMS Fénix — Reorganización y Limpieza de Proyecto

> **Para agentes:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan sintaxis checkbox (`- [ ]`) para seguimiento.

**Goal:** Eliminar archivos temporales/debug, reorganizar la estructura de carpetas, asegurar que la conexión PostgreSQL quede lista para migración a producción, y dejar el proyecto limpio y funcional.

**Architecture:** El proyecto es una API REST en Slim 4 + Eloquent ORM. El web root es `public/`. El código fuente está en `src/` (Controllers, Models, Middleware, Helpers). La configuración de BD en `config/database.php` lee del `.env`. La estructura actual es correcta — solo hay que eliminar archivos temporales y asegurar la configuración.

**Tech Stack:** PHP 8.2, Slim 4, Eloquent ORM (illuminate/database ^11), firebase/php-jwt, vlucas/phpdotenv, PostgreSQL 15 (prod) / MySQL (dev local), Apache + XAMPP (dev)

---

## Estructura Objetivo (después de limpieza)

```
WMS_FENIX/
├── backups/                    ← Backups BD (vacío, .gitkeep)
├── config/
│   ├── app.php
│   └── database.php
├── database/
│   ├── migrations/             ← 71 archivos (68 + 3 movidos desde root/migrations/)
│   └── seeds/
├── docs/                       ← Documentación
├── logs/                       ← Logs app
├── public/                     ← Web root (Apache apunta aquí)
│   ├── .htaccess
│   ├── index.php               ← Entry point Slim 4
│   ├── index.html              ← SPA Frontend
│   ├── manifest.json
│   ├── sw.js
│   ├── tv-picking.html
│   ├── api/                    ← Endpoints dashboard + migrations-run ASEGURADO
│   ├── assets/
│   ├── icons/
│   ├── images/
│   ├── mobile/
│   └── uploads/
├── scripts/                    ← Solo mantenimiento
│   ├── backup.php
│   ├── clear_opcache.php
│   └── deploy_postgres.sh
├── src/
│   ├── Controllers/            ← 32 controladores
│   ├── Helpers/                ← 12 helpers
│   ├── Middleware/             ← 5 middlewares
│   └── Models/                 ← 48 modelos
├── tools/                      ← Solo ML tools
│   ├── ml_anomaly_detector.py
│   ├── ml_expiry_predictor.py
│   └── ml_nightly_runner.php
├── vendor/
├── .env
├── .env.example
├── .gitignore
├── bootstrap.php
├── composer.json
└── deploy_to_ubuntu.sh
```

## Archivos a ELIMINAR

```
CARPETAS COMPLETAS:
- scratch/           (40 scripts de debug/desarrollo)
- brain/             (8 archivos de memoria Claude AI)
- frontend/          (vacío)
- migrations/        (root — 3 SQL movidos a database/migrations/ primero)

RAÍZ:
- check_data.php     (debug)
- check_db.php       (debug)
- fix_all_compat.php (one-time, ya ejecutado)
- migrate_odc.php    (one-time migration, ya ejecutada)
- update_odc_sucursal.php (one-time, ya ejecutado)

PUBLIC/:
- public/debug_login.php  (debug file en web root — riesgo seguridad)

TOOLS/ (conservar solo ML):
- tools/apply_tenant_scopes.php
- tools/dashboardBI_patch.php
- tools/list_tables.php
- tools/patch_import.js
- tools/patch_import2.js
- tools/schema_dump.php
- tools/setup_task_scheduler.bat
- tools/show_gerencial_2.js
- tools/show_gerencial_3.js
- tools/show_gerencial_bi.js
- tools/tendencia_patch.php
- tools/validate_controllers.py
- tools/verify_syntax.php
- tools/__pycache__/

SCRIPTS/ (conservar solo mantenimiento):
- scripts/aplicar_config_20usuarios.bat
- scripts/create_muelle_1.php
- scripts/migrate.php
- scripts/migration_add_indexes.php
- scripts/migration_pallet_support.php
```

---

### Tarea 1: Mover migraciones sprint de root/migrations/ a database/migrations/

**Archivos:**
- Mover: `migrations/001_sprint1_indices.sql` → `database/migrations/069_sprint1_indices.sql`
- Mover: `migrations/002_sprint2_ml_tables.sql` → `database/migrations/070_sprint2_ml_tables.sql`
- Mover: `migrations/003_sprint2_mv_y_jobs.sql` → `database/migrations/071_sprint2_mv_y_jobs.sql`
- Eliminar: `migrations/` (carpeta raíz)

- [ ] **Paso 1: Verificar que los archivos no son duplicados**

```bash
ls database/migrations/ | grep -i sprint
```
Esperado: Sin resultados (no son duplicados)

- [ ] **Paso 2: Mover los 3 archivos SQL**

```bash
cp migrations/001_sprint1_indices.sql database/migrations/069_sprint1_indices.sql
cp migrations/002_sprint2_ml_tables.sql database/migrations/070_sprint2_ml_tables.sql
cp migrations/003_sprint2_mv_y_jobs.sql database/migrations/071_sprint2_mv_y_jobs.sql
```

- [ ] **Paso 3: Verificar que los archivos se copiaron**

```bash
ls database/migrations/069_sprint1_indices.sql database/migrations/070_sprint2_ml_tables.sql database/migrations/071_sprint2_mv_y_jobs.sql
```
Esperado: Los 3 archivos listados

- [ ] **Paso 4: Eliminar carpeta migrations/ de la raíz**

```bash
rm -rf migrations/
```

- [ ] **Paso 5: Verificar eliminación**

```bash
ls migrations/ 2>/dev/null || echo "OK: carpeta eliminada"
```
Esperado: `OK: carpeta eliminada`

---

### Tarea 2: Eliminar carpetas temporales completas

**Archivos:**
- Eliminar: `scratch/` (40 archivos debug)
- Eliminar: `brain/` (8 archivos Claude AI)
- Eliminar: `frontend/` (vacío)

- [ ] **Paso 1: Contar archivos en scratch/ antes de eliminar**

```bash
find scratch/ -type f | wc -l
```
Esperado: ~40 archivos

- [ ] **Paso 2: Eliminar scratch/**

```bash
rm -rf scratch/
```

- [ ] **Paso 3: Eliminar brain/**

```bash
rm -rf brain/
```

- [ ] **Paso 4: Eliminar frontend/**

```bash
rm -rf frontend/
```

- [ ] **Paso 5: Verificar eliminaciones**

```bash
ls scratch/ brain/ frontend/ 2>/dev/null || echo "OK: carpetas eliminadas"
```
Esperado: `OK: carpetas eliminadas`

---

### Tarea 3: Eliminar archivos debug/one-time de la raíz

**Archivos:**
- Eliminar: `check_data.php`, `check_db.php`, `fix_all_compat.php`, `migrate_odc.php`, `update_odc_sucursal.php`

- [ ] **Paso 1: Listar archivos objetivo**

```bash
ls check_data.php check_db.php fix_all_compat.php migrate_odc.php update_odc_sucursal.php 2>/dev/null
```
Esperado: Los archivos listados

- [ ] **Paso 2: Eliminar archivos debug de raíz**

```bash
rm -f check_data.php check_db.php fix_all_compat.php migrate_odc.php update_odc_sucursal.php
```

- [ ] **Paso 3: Verificar que la raíz quedó limpia**

```bash
ls *.php 2>/dev/null
```
Esperado: Solo `bootstrap.php` (y posiblemente otros archivos del sistema)

---

### Tarea 4: Limpiar tools/ — conservar solo ML tools

**Archivos:**
- CONSERVAR: `tools/ml_anomaly_detector.py`, `tools/ml_expiry_predictor.py`, `tools/ml_nightly_runner.php`
- ELIMINAR: todos los demás

- [ ] **Paso 1: Eliminar archivos patch/dev de tools/**

```bash
rm -f tools/apply_tenant_scopes.php \
      tools/dashboardBI_patch.php \
      tools/list_tables.php \
      tools/patch_import.js \
      tools/patch_import2.js \
      tools/schema_dump.php \
      tools/setup_task_scheduler.bat \
      tools/show_gerencial_2.js \
      tools/show_gerencial_3.js \
      tools/show_gerencial_bi.js \
      tools/tendencia_patch.php \
      tools/validate_controllers.py \
      tools/verify_syntax.php
```

- [ ] **Paso 2: Eliminar __pycache__/**

```bash
rm -rf tools/__pycache__/
```

- [ ] **Paso 3: Verificar que solo quedan ML tools**

```bash
ls tools/
```
Esperado: `ml_anomaly_detector.py  ml_expiry_predictor.py  ml_nightly_runner.php`

---

### Tarea 5: Limpiar scripts/ — conservar solo mantenimiento

**Archivos:**
- CONSERVAR: `scripts/backup.php`, `scripts/clear_opcache.php`, `scripts/deploy_postgres.sh`
- ELIMINAR: `scripts/aplicar_config_20usuarios.bat`, `scripts/create_muelle_1.php`, `scripts/migrate.php`, `scripts/migration_add_indexes.php`, `scripts/migration_pallet_support.php`

- [ ] **Paso 1: Eliminar scripts one-time/obsoletos**

```bash
rm -f scripts/aplicar_config_20usuarios.bat \
      scripts/create_muelle_1.php \
      scripts/migrate.php \
      scripts/migration_add_indexes.php \
      scripts/migration_pallet_support.php
```

- [ ] **Paso 2: Verificar que solo quedan scripts de mantenimiento**

```bash
ls scripts/
```
Esperado: `backup.php  clear_opcache.php  deploy_postgres.sh`

---

### Tarea 6: Limpiar public/ y asegurar migrations-run.php

**Archivos:**
- Eliminar: `public/debug_login.php`
- Asegurar: `public/api/migrations-run.php` (agregar restricción IP)

- [ ] **Paso 1: Eliminar debug_login.php de web root**

```bash
rm -f public/debug_login.php
```

- [ ] **Paso 2: Verificar eliminación**

```bash
ls public/debug_login.php 2>/dev/null || echo "OK: eliminado"
```
Esperado: `OK: eliminado`

- [ ] **Paso 3: Agregar restricción IP a migrations-run.php**

Leer el archivo actual y agregar al inicio (después del `<?php`):

```php
<?php
// Solo ejecutable desde localhost — bloquea acceso externo
$allowed = ['127.0.0.1', '::1', '192.168.1.0/24'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$isAllowed = in_array($ip, ['127.0.0.1', '::1']);
if (!$isAllowed) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso no autorizado']));
}
```

- [ ] **Paso 4: Verificar sintaxis de migrations-run.php**

```bash
php -l public/api/migrations-run.php
```
Esperado: `No syntax errors detected`

---

### Tarea 7: Actualizar .gitignore

**Archivos:**
- Modificar: `.gitignore`

- [ ] **Paso 1: Verificar contenido actual del .gitignore**

```bash
cat .gitignore
```

- [ ] **Paso 2: Asegurar que .gitignore incluye entradas críticas**

El `.gitignore` debe incluir:
```
# Entorno
.env
.env.local

# Dependencias
/vendor/

# Logs y backups
logs/*.log
backups/*.sql
backups/*.gz

# Herramientas dev
.claude/
.superpowers/
Skills/
brain/
scratch/

# Python
tools/__pycache__/
*.pyc
*.pyo

# OS
.DS_Store
Thumbs.db

# IDE
.idea/
.vscode/
*.swp
```

---

### Tarea 8: Verificar y consolidar configuración PostgreSQL

**Archivos:**
- Verificar: `.env`, `config/database.php`, `bootstrap.php`

- [ ] **Paso 1: Verificar variables PostgreSQL en .env**

```bash
grep -E "^DB_" .env
```
Esperado:
```
DB_DRIVER=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=wms_fenix
DB_USER=postgres
DB_PASS=...
DB_CHARSET=utf8
DB_SSLMODE=disable
```

- [ ] **Paso 2: Verificar que .env.example tiene los campos correctos para producción**

```bash
grep -E "^DB_" .env.example
```
Debe incluir todos los campos de DB_

- [ ] **Paso 3: Verificar que bootstrap.php carga correctamente el .env**

```bash
php -r "
require_once 'vendor/autoload.php';
\$dotenv = Dotenv\Dotenv::createMutable(__DIR__);
\$dotenv->safeLoad();
echo 'DB_DRIVER=' . (\$_ENV['DB_DRIVER'] ?? 'NO CARGADO') . PHP_EOL;
echo 'DB_HOST=' . (\$_ENV['DB_HOST'] ?? 'NO CARGADO') . PHP_EOL;
echo 'DB_PORT=' . (\$_ENV['DB_PORT'] ?? 'NO CARGADO') . PHP_EOL;
echo 'DB_NAME=' . (\$_ENV['DB_NAME'] ?? 'NO CARGADO') . PHP_EOL;
"
```
Esperado: Muestra los valores de PostgreSQL

- [ ] **Paso 4: Verificar sintaxis de todos los archivos críticos**

```bash
php -l bootstrap.php && php -l config/database.php && php -l config/app.php && echo "OK: sintaxis correcta"
```
Esperado: `OK: sintaxis correcta`

---

### Tarea 9: Verificar estructura final

- [ ] **Paso 1: Contar archivos en src/ (debe ser ~97)**

```bash
find src/ -name "*.php" | wc -l
```
Esperado: ~97 archivos

- [ ] **Paso 2: Verificar estructura completa**

```bash
find . -maxdepth 2 -not -path './vendor/*' -not -path './.git/*' -not -path './node_modules/*' | sort
```

- [ ] **Paso 3: Verificar que public/index.php tiene sintaxis correcta**

```bash
php -l public/index.php
```
Esperado: `No syntax errors detected`

- [ ] **Paso 4: Verificar que todos los controladores tienen sintaxis correcta**

```bash
find src/Controllers -name "*.php" -exec php -l {} \; | grep -v "No syntax" | head -5
```
Esperado: Sin salida (todos correctos)

- [ ] **Paso 5: Contar archivos totales en database/migrations/**

```bash
ls database/migrations/ | wc -l
```
Esperado: 71 archivos (68 originales + 3 movidos)

---

### Tarea 10: Commit final

- [ ] **Paso 1: Ver resumen de cambios**

```bash
git status
git diff --stat HEAD
```

- [ ] **Paso 2: Crear commit de reorganización**

```bash
git add -A
git commit -m "chore: limpieza profesional — elimina 60+ archivos debug/temp, consolida migraciones, asegura migrations-run.php

- Eliminados: scratch/ (40 archivos), brain/ (8 archivos), frontend/ (vacío)
- Eliminados: root debug PHP (check_data, check_db, fix_all_compat, migrate_odc, update_odc_sucursal)
- Eliminados: tools/ dev-only (patches, BI scripts, utilities) — conservados ML tools
- Eliminados: scripts/ one-time — conservados backup, clear_opcache, deploy_postgres
- Eliminado: public/debug_login.php (seguridad)
- Movidos: migrations/001-003 SQL → database/migrations/069-071
- Asegurado: public/api/migrations-run.php con restricción IP
- PostgreSQL: DB_DRIVER=pgsql, listo para migración a producción"
```
