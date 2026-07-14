@echo off
REM ============================================================================
REM  backup_daily.bat — Backup diario PostgreSQL para WMS Fenix
REM
REM  USO:
REM    1. Ejecutar manualmente:  scripts\backup_daily.bat
REM    2. Programar en Task Scheduler de Windows:
REM       - Programa: C:\xampp\htdocs\WMS_FENIX\scripts\backup_daily.bat
REM       - Iniciar en: C:\xampp\htdocs\WMS_FENIX
REM       - Disparador: Diario a las 04:00 AM
REM       - Ejecutar con privilegios mas elevados: Si
REM
REM  NOTA: Para configurar automaticamente en Task Scheduler, ejecutar:
REM    powershell -ExecutionPolicy Bypass -File scripts\setup-scheduler-windows.ps1
REM ============================================================================

REM ── Configuracion de rutas ──────────────────────────────────────────────────
set PHP_EXE=C:\xampp\php\php.exe
set PROJECT_DIR=C:\xampp\htdocs\WMS_FENIX
set SCRIPT=%PROJECT_DIR%\scripts\backup_db.php
set LOG_FILE=%PROJECT_DIR%\backups\backup.log

REM ── Verificar que PHP existe ────────────────────────────────────────────────
if not exist "%PHP_EXE%" (
    echo [%date% %time%] [ERROR] PHP no encontrado en: %PHP_EXE% >> "%LOG_FILE%"
    echo ERROR: PHP no encontrado en: %PHP_EXE%
    exit /b 1
)

REM ── Verificar que el script existe ──────────────────────────────────────────
if not exist "%SCRIPT%" (
    echo [%date% %time%] [ERROR] Script no encontrado: %SCRIPT% >> "%LOG_FILE%"
    echo ERROR: Script no encontrado: %SCRIPT%
    exit /b 1
)

REM ── Crear directorio de backups si no existe ────────────────────────────────
if not exist "%PROJECT_DIR%\backups" (
    mkdir "%PROJECT_DIR%\backups"
)

REM ── Ejecutar backup ─────────────────────────────────────────────────────────
cd /d "%PROJECT_DIR%"
echo [%date% %time%] Iniciando backup via BAT... >> "%LOG_FILE%"

"%PHP_EXE%" "%SCRIPT%"
set EXIT_CODE=%ERRORLEVEL%

if %EXIT_CODE% equ 0 (
    echo [%date% %time%] Backup BAT finalizado OK >> "%LOG_FILE%"
) else (
    echo [%date% %time%] [ERROR] Backup BAT fallo con codigo: %EXIT_CODE% >> "%LOG_FILE%"
)

exit /b %EXIT_CODE%
