@echo off
REM ================================================================
REM  WMS PROORIENTE — Script de Tuning XAMPP para 20 usuarios
REM  Ejecutar como Administrador en el servidor XAMPP
REM ================================================================
echo.
echo === WMS ProOriente - Aplicando configuracion para 20 usuarios ===
echo.

REM ── Ruta de XAMPP (ajustar si es diferente) ──────────────────────
set XAMPP=C:\xampp

REM ── Backup de configuraciones originales ─────────────────────────
echo [1/3] Creando backups...
copy "%XAMPP%\php\php.ini"   "%XAMPP%\php\php.ini.bak_%date:~-4,4%%date:~-10,2%%date:~-7,2%" >nul
copy "%XAMPP%\mysql\bin\my.ini" "%XAMPP%\mysql\bin\my.ini.bak_%date:~-4,4%%date:~-10,2%%date:~-7,2%" >nul
echo    Backups creados OK.

REM ── PHP.INI — Tuning memoria y ejecucion ─────────────────────────
echo [2/3] Aplicando ajustes a php.ini...

REM memory_limit: 128M -> 256M
powershell -Command "(Get-Content '%XAMPP%\php\php.ini') -replace '^memory_limit\s*=.*', 'memory_limit = 256M' | Set-Content '%XAMPP%\php\php.ini'"

REM max_execution_time: 30 -> 60
powershell -Command "(Get-Content '%XAMPP%\php\php.ini') -replace '^max_execution_time\s*=.*', 'max_execution_time = 60' | Set-Content '%XAMPP%\php\php.ini'"

REM max_input_vars: 1000 -> 3000
powershell -Command "(Get-Content '%XAMPP%\php\php.ini') -replace '^;?max_input_vars\s*=.*', 'max_input_vars = 3000' | Set-Content '%XAMPP%\php\php.ini'"

echo    php.ini actualizado OK.

REM ── MY.INI — Tuning MySQL/MariaDB ────────────────────────────────
echo [3/3] Aplicando ajustes a my.ini...

REM max_connections: 151 -> 300
powershell -Command "(Get-Content '%XAMPP%\mysql\bin\my.ini') -replace '^max_connections\s*=.*', 'max_connections = 300' | Set-Content '%XAMPP%\mysql\bin\my.ini'"

REM innodb_buffer_pool_size -> 512M
powershell -Command "$c = Get-Content '%XAMPP%\mysql\bin\my.ini'; if ($c -match 'innodb_buffer_pool_size') { $c -replace '^innodb_buffer_pool_size\s*=.*', 'innodb_buffer_pool_size = 512M' | Set-Content '%XAMPP%\mysql\bin\my.ini' } else { Add-Content '%XAMPP%\mysql\bin\my.ini' 'innodb_buffer_pool_size = 512M' }"

REM query_cache_size -> 64M
powershell -Command "$c = Get-Content '%XAMPP%\mysql\bin\my.ini'; if ($c -match 'query_cache_size') { $c -replace '^;?query_cache_size\s*=.*', 'query_cache_size = 64M' | Set-Content '%XAMPP%\mysql\bin\my.ini' } else { Add-Content '%XAMPP%\mysql\bin\my.ini' 'query_cache_size = 64M' }"

REM slow_query_log -> ON con umbral 2 segundos
powershell -Command "$c = Get-Content '%XAMPP%\mysql\bin\my.ini'; if ($c -match 'slow_query_log') { $c -replace '^;?slow_query_log\s*=.*', 'slow_query_log = 1' | Set-Content '%XAMPP%\mysql\bin\my.ini' } else { Add-Content '%XAMPP%\mysql\bin\my.ini' 'slow_query_log = 1' }"
powershell -Command "$c = Get-Content '%XAMPP%\mysql\bin\my.ini'; if ($c -match 'long_query_time') { $c -replace '^;?long_query_time\s*=.*', 'long_query_time = 2' | Set-Content '%XAMPP%\mysql\bin\my.ini' } else { Add-Content '%XAMPP%\mysql\bin\my.ini' 'long_query_time = 2' }"

echo    my.ini actualizado OK.

echo.
echo ================================================================
echo  IMPORTANTE: Reiniciar Apache Y MySQL desde el panel XAMPP
echo  para que los cambios tomen efecto.
echo ================================================================
echo.
pause
