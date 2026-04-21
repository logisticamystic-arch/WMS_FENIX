<?php
/**
 * Script para limpiar OPcache desde CLI
 * Ejecutar como: php scripts/clear_opcache.php
 */

if (!function_exists('opcache_reset')) {
    echo "ERROR: OPcache no está habilitado o la función opcache_reset() no está disponible.\n";
    exit(1);
}

if (opcache_reset()) {
    echo "SUCCESS: OPcache ha sido reiniciado exitosamente.\n";
    echo "Los cambios en los archivos PHP ahora deberían ser visibles para el servidor.\n";
} else {
    echo "ERROR: No se pudo reiniciar el OPcache.\n";
    exit(1);
}
