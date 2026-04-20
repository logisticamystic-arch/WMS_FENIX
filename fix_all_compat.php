<?php
/**
 * fix_all_compat.php — Corrige TODA sintaxis PostgreSQL en todos los Controllers
 * para compatibilidad MySQL/XAMPP.
 * Ejecutar UNA sola vez: C:\xampp\php\php.exe fix_all_compat.php
 */

$dir   = __DIR__ . '/src/Controllers';
$files = glob($dir . '/*.php');
$total = 0;

foreach ($files as $path) {
    $orig = file_get_contents($path);
    $txt  = $orig;

    // ── 1. col::date cast  →  col (MySQL acepta DATE(col) o directo) ──────────
    // (sesion_lineas.fecha_vencimiento::date - CURRENT_DATE)
    $txt = preg_replace(
        '/\((\w+\.\w+)::date\s*-\s*CURRENT_DATE\)/',
        '" . (\\App\\Helpers\\DbCompat::isPg() ? \'($1::date - CURRENT_DATE)\' : \'DATEDIFF($1, CURDATE())\') . "',
        $txt
    );

    // (CURRENT_DATE - col::date)  ← orden invertido
    $txt = preg_replace(
        '/\(CURRENT_DATE\s*-\s*(\w+\.\w+)::date\)/',
        '" . (\\App\\Helpers\\DbCompat::isPg() ? \'(CURRENT_DATE - $1::date)\' : \'DATEDIFF(CURDATE(), $1)\') . "',
        $txt
    );

    // any remaining standalone ::date cast on a column
    $txt = preg_replace('/(\w+\.\w+)::date(?!\s*[+-])/', 'DATE($1)', $txt);

    // ── 2. CURRENT_DATE + INTERVAL 'N days' ────────────────────────────────────
    $txt = preg_replace_callback(
        "/\(CURRENT_DATE\s*\+\s*INTERVAL\s*'(\d+)\s*days?'\)/i",
        fn($m) => '" . (\\App\\Helpers\\DbCompat::isPg() ? \'(CURRENT_DATE + INTERVAL \'' . $m[1] . ' days\')\' : \'DATE_ADD(CURDATE(), INTERVAL ' . $m[1] . ' DAY)\') . "',
        $txt
    );

    // ── 3. CURRENT_DATE - INTERVAL 'N days' ────────────────────────────────────
    $txt = preg_replace_callback(
        "/\(CURRENT_DATE\s*-\s*INTERVAL\s*'(\d+)\s*days?'\)/i",
        fn($m) => '" . (\\App\\Helpers\\DbCompat::isPg() ? \'(CURRENT_DATE - INTERVAL \'' . $m[1] . ' days\')\' : \'DATE_SUB(CURDATE(), INTERVAL ' . $m[1] . ' DAY)\') . "',
        $txt
    );

    // ── 4. NOW() - INTERVAL 'N days' ───────────────────────────────────────────
    $txt = preg_replace_callback(
        "/\(NOW\(\)\s*-\s*INTERVAL\s*'(\d+)\s*days?'\)/i",
        fn($m) => '" . (\\App\\Helpers\\DbCompat::isPg() ? \'(NOW() - INTERVAL \'' . $m[1] . ' days\')\' : \'DATE_SUB(NOW(), INTERVAL ' . $m[1] . ' DAY)\') . "',
        $txt
    );

    // ── 5. ::INTEGER / ::FLOAT / ::TEXT / ::BIGINT casts ──────────────────────
    $txt = preg_replace('/(\w+)::INTEGER\b/',   'CAST($1 AS SIGNED)',   $txt);
    $txt = preg_replace('/(\w+)::BIGINT\b/',    'CAST($1 AS SIGNED)',   $txt);
    $txt = preg_replace('/(\w+)::FLOAT\b/',     'CAST($1 AS DECIMAL)',  $txt);
    $txt = preg_replace('/(\w+)::TEXT\b/',      'CAST($1 AS CHAR)',     $txt);
    // Leave ::date handled above

    if ($txt !== $orig) {
        $n = substr_count($txt, 'DbCompat::isPg') + substr_count($txt, 'DATE(') + substr_count($txt, 'CAST(');
        file_put_contents($path, $txt);
        echo "  FIXED  " . basename($path) . "\n";
        $total++;
    } else {
        echo "  OK     " . basename($path) . "\n";
    }
}

echo "\n==> {$total} archivo(s) corregido(s).\n";
echo "Recargue el navegador con Ctrl+Shift+R\n";
