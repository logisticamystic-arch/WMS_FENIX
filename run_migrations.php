<?php
/**
 * Script standalone para ejecutar migraciones pendientes.
 * Uso: php run_migrations.php
 */
require_once __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

echo "=== Ejecutando migraciones pendientes ===\n\n";

// Crear tabla de migraciones si no existe
if (!DB::schema()->hasTable('migrations')) {
    DB::schema()->create('migrations', function ($t) {
        $t->increments('id');
        $t->string('migration');
        $t->integer('batch');
        $t->timestamp('ran_at')->useCurrent();
    });
    echo "[OK] Tabla 'migrations' creada.\n";
}

$migPath = __DIR__ . '/database/migrations/';
$files   = glob($migPath . '*.php');
sort($files);

// Auditoría 2026-07-22: este runner solo ejecutaba `$m['up']()` cuando el archivo
// devolvía un array (['up' => fn, 'down' => fn]). Las migraciones que devuelven
// `new class { public function up() {...} }` (075, 076, 077, 117...) llegaban
// aquí como OBJETO — is_array() daba false, así que up() nunca se llamaba, pero
// igual se insertaba en `migrations` marcándola como aplicada. En un ambiente
// nuevo esto dejaba tablas/columnas silenciosamente faltantes mientras el
// historial de migraciones decía que todo estaba al día. Ahora se soportan
// ambos estilos explícitamente.

// .sql sueltos de la era "ejecutar manualmente en pgAdmin/psql" (ver comentarios
// dentro de cada uno). YA están aplicados en esta base de datos (verificado
// 2026-07-24: inventory_guard_log, expiry_predictions, anomaly_flags,
// sesiones_inventario.fv_obligatorio, picking_detalles.unid_pedido_empaque, etc.
// todos existen). Algunos (050_integrity_ml_tables.sql) están escritos en
// sintaxis MySQL (backticks, AUTO_INCREMENT) y NO son ejecutables tal cual
// contra este Postgres — por eso no se auto-ejecutan aquí, solo se registran
// como aplicados para que este runner no los reporte como pendientes. En un
// ambiente nuevo, revisar y traducir cada uno manualmente antes de aplicarlo.
$sqlSueltosAplicados = [
    '050_integrity_ml_tables.sql',
    '055_devoluciones_fotos_odc.sql',
    '069_sprint1_indices.sql',
    '070_sprint2_ml_tables.sql',
    '071_sprint2_mv_y_jobs.sql',
    '2026_05_30_add_fecha_vencimiento_picking_detalles.sql',
    '2026_05_30_create_aprobaciones_vencimiento.sql',
    'add_fv_obligatorio_sesiones_inventario.sql',
    'picking_v2_nuevos_campos.sql',
];

$ran   = DB::table('migrations')->pluck('migration')->toArray();
$batch = (int)(DB::table('migrations')->max('batch') ?? 0) + 1;
$done  = [];
$errors = [];

foreach ($sqlSueltosAplicados as $sqlName) {
    if (in_array($sqlName, $ran) || !file_exists($migPath . $sqlName)) continue;
    DB::table('migrations')->insert(['migration' => $sqlName, 'batch' => $batch]);
    echo "[SQL]  {$sqlName} (registrado como ya aplicado manualmente — no auto-ejecutable, ver comentario arriba)\n";
}

foreach ($files as $file) {
    $name = basename($file, '.php');
    if (in_array($name, $ran)) {
        echo "[SKIP] {$name} (ya ejecutada)\n";
        continue;
    }
    try {
        $m = require $file;
        if (is_array($m) && isset($m['up'])) {
            $m['up']();
        } elseif (is_object($m) && method_exists($m, 'up')) {
            $m->up();
        }
        // Si $m no es ninguno de los dos (ej. script plano que ya ejecutó su DDL
        // como efecto secundario del require), no hay nada más que invocar.
        DB::table('migrations')->insert([
            'migration' => $name,
            'batch'     => $batch,
        ]);
        $done[] = $name;
        echo "[OK]   {$name}\n";
    } catch (\Exception $e) {
        $errors[] = "{$name}: " . $e->getMessage();
        echo "[ERR]  {$name}: " . $e->getMessage() . "\n";
    }
}

$sqlPendientes = array_filter($sqlSueltosAplicados, fn($n) => file_exists($migPath . $n) && !in_array($n, DB::table('migrations')->pluck('migration')->toArray()));
if (!empty($sqlPendientes)) {
    echo "\n[AVISO] .sql sueltos sin registrar aún (revisar y aplicar manualmente): " . implode(', ', $sqlPendientes) . "\n";
}

echo "\n=== Resumen ===\n";
echo "Ejecutadas: " . count($done) . "\n";
echo "Errores:    " . count($errors) . "\n";

if (!empty($done)) {
    echo "\nMigraciones aplicadas:\n";
    foreach ($done as $d) echo "  - {$d}\n";
}
if (!empty($errors)) {
    echo "\nErrores encontrados:\n";
    foreach ($errors as $e) echo "  - {$e}\n";
}
echo "\nListo.\n";
