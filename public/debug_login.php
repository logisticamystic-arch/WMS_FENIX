<?php
/**
 * DEBUG SCRIPT - Diagnóstico de Login
 * Acceder via: http://localhost/WMS_PROORIENTE/public/debug_login.php
 * ELIMINAR ESTE ARCHIVO EN PRODUCCIÓN
 */

// Solo acceso local
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])) {
    die('Acceso denegado');
}

require_once __DIR__ . '/../bootstrap.php';

use App\Models\{Empresa, Personal};

echo "<pre style='font-family:monospace;font-size:14px;background:#1e1e1e;color:#d4d4d4;padding:20px'>";
echo "=== DIAGNÓSTICO WMS PROORIENTE ===\n\n";

// 1. Conexión DB
echo "--- 1. CONEXIÓN BASE DE DATOS ---\n";
try {
    \Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $db = \Illuminate\Database\Capsule\Manager::connection()->getDatabaseName();
    echo "[OK] Conectado a: $db\n\n";
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
    die();
}

// 2. Empresa
echo "--- 2. EMPRESA (NIT 900000001) ---\n";
$empresa = Empresa::where('nit', '900000001')->first();
if ($empresa) {
    echo "[OK] Empresa: {$empresa->razon_social} (ID: {$empresa->id}, Activo: " . ($empresa->activo ? 'SI' : 'NO') . ")\n\n";
} else {
    echo "[ERROR] Empresa con NIT 900000001 NO ENCONTRADA\n";
    echo "Empresas existentes:\n";
    foreach (Empresa::all() as $e) {
        echo "  - NIT: {$e->nit} | {$e->razon_social} | Activo: " . ($e->activo ? 'SI' : 'NO') . "\n";
    }
    echo "\n";
}

// 3. Usuario ADMIN001
echo "--- 3. USUARIO ADMIN001 ---\n";
$user = Personal::where('documento', 'ADMIN001')->first();
if ($user) {
    echo "[OK] Usuario encontrado:\n";
    echo "  Nombre: {$user->nombre}\n";
    echo "  Rol: {$user->rol}\n";
    echo "  Empresa ID: {$user->empresa_id}\n";
    echo "  Activo: " . ($user->activo ? 'SI' : 'NO') . "\n";
    echo "  PIN hash guardado: " . substr($user->pin, 0, 20) . "...\n";

    // Verificar PINs
    $pines = ['1234', '0000', '1111', '4321'];
    echo "\nVerificando PINs:\n";
    foreach ($pines as $pin) {
        $ok = password_verify($pin, $user->pin);
        echo "  PIN $pin: " . ($ok ? "[CORRECTO]" : "incorrecto") . "\n";
    }
    echo "\n";
} else {
    echo "[ERROR] Usuario ADMIN001 NO encontrado\n";
    echo "Usuarios en la BD:\n";
    foreach (Personal::all() as $p) {
        echo "  - Documento: {$p->documento} | Nombre: {$p->nombre} | Rol: {$p->rol} | Empresa ID: {$p->empresa_id}\n";
    }
    echo "\n";
}

// 4. Simular login
echo "--- 4. SIMULACIÓN DE LOGIN (ADMIN001 + PIN 1234) ---\n";
if ($empresa && $user) {
    if ($user->empresa_id !== $empresa->id) {
        echo "[ERROR] empresa_id del usuario ({$user->empresa_id}) != empresa encontrada ({$empresa->id})\n";
    } elseif (!$user->activo) {
        echo "[ERROR] Usuario inactivo\n";
    } elseif (!password_verify('1234', $user->pin)) {
        echo "[ERROR] PIN 1234 NO coincide con hash guardado\n";
        echo "  Intenta re-ejecutar las migraciones: php migrate.php fresh\n";
    } else {
        echo "[OK] Login EXITOSO - El sistema debería funcionar\n";
    }
}

// 5. Variables de entorno
echo "\n--- 5. VARIABLES DE ENTORNO ---\n";
$vars = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'APP_URL'];
foreach ($vars as $v) {
    $val = $_ENV[$v] ?? $_SERVER[$v] ?? getenv($v) ?: '(no definida)';
    if ($v === 'DB_USER') $val = '***'; // Ocultar por seguridad
    echo "  $v = $val\n";
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
echo "</pre>";
