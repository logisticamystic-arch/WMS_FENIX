<?php
// Diagnostic: simulate the exact query that show_cargue makes
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== DIAGNOSTIC: Planilla de Cargue API ===\n\n";

// Load the app
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

echo "DB_DRIVER: " . ($_ENV['DB_DRIVER'] ?? 'not set') . "\n";
echo "DB_HOST: " . ($_ENV['DB_HOST'] ?? 'not set') . "\n";
echo "DB_NAME: " . ($_ENV['DB_NAME'] ?? 'not set') . "\n\n";

// Try direct PDO connection
try {
    $dsn = 'pgsql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'wms_fenix');
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'postgres', $_ENV['DB_PASS'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ PDO connection OK\n\n";
} catch (Exception $e) {
    echo "❌ PDO connection FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if estado_despacho column exists
echo "--- Checking estado_despacho column ---\n";
try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'orden_pickings' AND column_name = 'estado_despacho'");
    $col = $stmt->fetch();
    if ($col) {
        echo "✅ Column estado_despacho EXISTS\n\n";
    } else {
        echo "❌ Column estado_despacho DOES NOT EXIST\n\n";
    }
} catch (Exception $e) {
    echo "❌ Error checking column: " . $e->getMessage() . "\n\n";
}

// Check if estado_certificacion column exists
echo "--- Checking estado_certificacion column ---\n";
try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'orden_pickings' AND column_name = 'estado_certificacion'");
    $col = $stmt->fetch();
    if ($col) {
        echo "✅ Column estado_certificacion EXISTS\n\n";
    } else {
        echo "❌ Column estado_certificacion DOES NOT EXIST\n\n";
    }
} catch (Exception $e) {
    echo "❌ Error checking column: " . $e->getMessage() . "\n\n";
}

// Now simulate the actual query
echo "--- Simulating picking query (estado_certificacion=Certificada, estado_despacho IS NULL) ---\n";
$start = microtime(true);
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orden_pickings WHERE estado_certificacion = 'Certificada' AND estado_despacho IS NULL");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "✅ Query OK - Found {$row['total']} records in {$elapsed}ms\n\n";
} catch (Exception $e) {
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "❌ Query FAILED after {$elapsed}ms: " . $e->getMessage() . "\n\n";
}

// Also try fetching actual data with limit
echo "--- Fetching first 5 records ---\n";
$start = microtime(true);
try {
    $stmt = $pdo->query("SELECT id, numero_pedido, cliente, estado, estado_certificacion, estado_despacho FROM orden_pickings WHERE estado_certificacion = 'Certificada' AND estado_despacho IS NULL LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "✅ Fetched " . count($rows) . " records in {$elapsed}ms\n";
    foreach ($rows as $r) {
        echo "  - ID:{$r['id']} Pedido:{$r['numero_pedido']} Cliente:{$r['cliente']} Estado:{$r['estado']}\n";
    }
} catch (Exception $e) {
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "❌ Fetch FAILED after {$elapsed}ms: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
