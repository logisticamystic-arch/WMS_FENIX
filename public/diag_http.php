<?php
// Diagnostic: Find empresas and test API
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dsn = 'pgsql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'wms_fenix');
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'postgres', $_ENV['DB_PASS'] ?? '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Find user's empresa
echo "=== Finding user's empresa ===\n";
$stmt = $pdo->prepare("SELECT p.id, p.nombre, p.documento, p.empresa_id, p.activo, e.nit, e.razon_social as empresa_nombre FROM personal p JOIN empresas e ON e.id = p.empresa_id WHERE p.documento = ?");
$stmt->execute(['1017130145']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user) {
    echo "User: {$user['nombre']}, Empresa: {$user['empresa_nombre']}, NIT: {$user['nit']}, empresa_id: {$user['empresa_id']}\n\n";
    
    // Now login with empresa_id
    $loginUrl = 'http://localhost/WMS_FENIX/public/api/auth/login';
    $loginData = json_encode(['documento' => '1017130145', 'pin' => '0145', 'empresa_id' => $user['empresa_id']]);
    
    $ch = curl_init($loginUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    echo "=== Login with empresa_id ===\n";
    $start = microtime(true);
    $loginResponse = curl_exec($ch);
    $loginTime = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP $httpCode - {$loginTime}ms\n";
    
    $loginJson = json_decode($loginResponse, true);
    if (empty($loginJson['token'])) {
        echo "Login FAILED: " . substr($loginResponse, 0, 500) . "\n";
        exit;
    }
    
    $token = $loginJson['token'];
    echo "Token OK\n\n";
    
    // Test picking endpoint
    $pickingUrl = 'http://localhost/WMS_FENIX/public/api/picking?estado_certificacion=Certificada&sin_despacho=1&limit=500&incluir_finalizados=1';
    
    $ch = curl_init($pickingUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    echo "=== GET /picking (Planilla de Cargue query) ===\n";
    $start = microtime(true);
    $pickingResponse = curl_exec($ch);
    $pickingTime = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP $httpCode - {$pickingTime}ms\n";
    
    if ($curlError) {
        echo "CURL Error: $curlError\n";
    }
    
    $pickingJson = json_decode($pickingResponse, true);
    if ($pickingJson === null) {
        echo "JSON decode failed. Raw (first 2000 chars):\n" . substr($pickingResponse, 0, 2000) . "\n";
    } else {
        $dataCount = is_array($pickingJson['data'] ?? null) ? count($pickingJson['data']) : 'N/A';
        echo "error=" . json_encode($pickingJson['error'] ?? null) . ", data count=$dataCount\n";
        
        if (!empty($pickingJson['error'])) {
            echo "Error message: " . ($pickingJson['message'] ?? 'none') . "\n";
        }
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "Login: {$loginTime}ms\n";
    echo "Picking API: {$pickingTime}ms (HTTP $httpCode)\n";
    echo "Records: $dataCount\n";
} else {
    echo "User not found!\n";
}
