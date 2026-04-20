@echo off
:: ============================================================================
:: WMS Prooriente — Configurar tarea nocturna de Inteligencia ML
:: Ejecutar como Administrador una sola vez
:: ============================================================================

set TASK_NAME=WMS_ML_Nightly
set PHP_EXE=C:\xampp\php\php.exe
set SCRIPT=C:\xampp\htdocs\WMS_PROORIENTE\tools\ml_nightly_runner.php
set HORA=02:00

echo Registrando tarea programada "%TASK_NAME%"...

:: Eliminar si ya existe
schtasks /delete /tn "%TASK_NAME%" /f 2>nul

:: Crear tarea — todos los días a las 02:00 AM
schtasks /create ^
  /tn "%TASK_NAME%" ^
  /tr "\"%PHP_EXE%\" \"%SCRIPT%\"" ^
  /sc DAILY ^
  /st %HORA% ^
  /ru SYSTEM ^
  /rl HIGHEST ^
  /f

if %ERRORLEVEL% == 0 (
  echo.
  echo [OK] Tarea "%TASK_NAME%" creada. Se ejecutara todos los dias a las %HORA%.
  echo      Logs en: C:\xampp\htdocs\WMS_PROORIENTE\logs\ml_nightly.log
) else (
  echo.
  echo [ERROR] No se pudo crear la tarea. Ejecuta este archivo como Administrador.
)

echo.
pause
