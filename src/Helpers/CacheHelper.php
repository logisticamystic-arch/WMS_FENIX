<?php

namespace App\Helpers;

/**
 * CacheHelper — Cache de archivo con TTL.
 *
 * Diseñado para datos que se leen en CADA request pero cambian poco:
 *  - Configuración de empresa/sucursal
 *  - Lista de parámetros del sistema
 *  - Ubicaciones activas (para validaciones de putaway)
 *  - Permisos de rol
 *
 * Funciona en cualquier entorno (XAMPP, servidor Linux) sin Redis ni APCu.
 * Los archivos de cache se guardan en sys_get_temp_dir()/wms_cache/ con nombre hash.
 *
 * Uso:
 *   $empresa = CacheHelper::remember("empresa_{$id}", 300, fn() => Empresa::find($id));
 *   CacheHelper::forget("empresa_{$id}");
 *   CacheHelper::flush('empresa_');
 */
class CacheHelper
{
    private static string $cacheDir = '';
    private static array  $memory   = [];  // L1: mismo proceso PHP

    private static function dir(): string
    {
        if (self::$cacheDir === '') {
            self::$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_cache' . DIRECTORY_SEPARATOR;
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }
        return self::$cacheDir;
    }

    private static function path(string $key): string
    {
        return self::dir() . md5($key) . '.cache';
    }

    // ── API pública ────────────────────────────────────────────────────────────

    /**
     * Lee del cache o ejecuta $callback para poblar y guardar.
     * $ttl en segundos (default 300 = 5 min).
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (isset(self::$memory[$key])) {
            return self::$memory[$key];
        }
        $path = self::path($key);
        if (file_exists($path)) {
            $raw  = @file_get_contents($path);
            $data = $raw ? @unserialize($raw) : false;
            if ($data && isset($data['exp']) && $data['exp'] > time()) {
                self::$memory[$key] = $data['val'];
                return $data['val'];
            }
        }
        $value = $callback();
        self::put($key, $value, $ttl);
        return $value;
    }

    public static function put(string $key, mixed $value, int $ttl = 300): void
    {
        self::$memory[$key] = $value;
        @file_put_contents(self::path($key), serialize(['exp' => time() + $ttl, 'val' => $value]), LOCK_EX);
    }

    public static function forget(string $key): void
    {
        unset(self::$memory[$key]);
        $path = self::path($key);
        if (file_exists($path)) @unlink($path);
    }

    public static function flush(string $prefix = ''): int
    {
        $count = 0;
        foreach (array_keys(self::$memory) as $k) {
            if ($prefix === '' || str_starts_with($k, $prefix)) unset(self::$memory[$k]);
        }
        $dir = self::dir();
        foreach (glob($dir . '*.cache') as $file) {
            @unlink($file);
            $count++;
        }
        return $count;
    }

    public static function get(string $key): mixed
    {
        if (isset(self::$memory[$key])) return self::$memory[$key];
        $path = self::path($key);
        if (!file_exists($path)) return null;
        $raw  = @file_get_contents($path);
        $data = $raw ? @unserialize($raw) : false;
        return ($data && $data['exp'] > time()) ? $data['val'] : null;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    // ── Helpers específicos del WMS ────────────────────────────────────────────

    public static function empresa(int $id, callable $loader): mixed
    {
        return self::remember("empresa_{$id}", 600, $loader);
    }

    public static function parametros(int $empresaId, callable $loader): mixed
    {
        return self::remember("parametros_{$empresaId}", 300, $loader);
    }

    public static function ubicaciones(int $sucursalId, callable $loader): mixed
    {
        return self::remember("ubicaciones_{$sucursalId}", 300, $loader);
    }

    public static function permisos(int $empresaId, string $rol, callable $loader): mixed
    {
        return self::remember("permisos_{$empresaId}_{$rol}", 600, $loader);
    }

    public static function flushEmpresa(int $id): void
    {
        self::forget("empresa_{$id}");
        self::forget("parametros_{$id}");
    }
}
