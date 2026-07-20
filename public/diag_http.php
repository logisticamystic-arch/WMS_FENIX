<?php
// Diagnostic: Test the full API endpoint via HTTP to see timing
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>Testing Planilla de Cargue API</h2>";

// First, login to get a token
$loginUrl = 'http://localhost/WMS_FENIX/public/api/auth/login';
$loginData = json_encode(['username' => '1017130145', 'pin' => '0145']);

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

echo "<h3>1. Login</h3>";
$start = microtime(true);
$loginResponse = curl_exec($ch);
$loginTime = round((microtime(true) - $start) * 1000);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $httpCode - {$loginTime}ms<br>";

$loginJson = json_decode($loginResponse, true);
if (empty($loginJson['token'])) {
    echo "<pre>Login FAILED: " . htmlspecialchars(substr($loginResponse, 0, 500)) . "</pre>";
    exit;
}

$token = $loginJson['token'];
echo "Token OK: " . substr($token, 0, 30) . "...<br><br>";

// Now test the picking endpoint
$pickingUrl = 'http://localhost/WMS_FENIX/public/api/picking?estado_certificacion=Certificada&sin_despacho=1&limit=500&incluir_finalizados=1';

$ch = curl_init($pickingUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

echo "<h3>2. GET /picking (Planilla de Cargue query)</h3>";
echo "URL: " . htmlspecialchars($pickingUrl) . "<br>";

$start = microtime(true);
$pickingResponse = curl_exec($ch);
$pickingTime = round((microtime(true) - $start) * 1000);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP $httpCode - {$pickingTime}ms<br>";

if ($curlError) {
    echo "CURL Error: " . htmlspecialchars($curlError) . "<br>";
}

$pickingJson = json_decode($pickingResponse, true);
if ($pickingJson === null) {
    echo "<pre>JSON decode failed. Raw response (first 2000 chars):\n" . htmlspecialchars(substr($pickingResponse, 0, 2000)) . "</pre>";
} else {
    $dataCount = is_array($pickingJson['data'] ?? null) ? count($pickingJson['data']) : 'N/A';
    echo "Response: error=" . ($pickingJson['error'] ?? 'null') . ", data count=" . $dataCount . "<br>";
    
    if (!empty($pickingJson['error'])) {
        echo "<pre>Error message: " . htmlspecialchars($pickingJson['message'] ?? 'none') . "</pre>";
    }
    
    if ($dataCount !== 'N/A' && $dataCount > 0) {
        echo "<br>First record:<br>";
        echo "<pre>" . htmlspecialchars(json_encode($pickingJson['data'][0] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    }
}

echo "<br><h3>Summary</h3>";
echo "Login: {$loginTime}ms<br>";
echo "Picking API: {$pickingTime}ms<br>";
echo "Status: HTTP $httpCode<br>";
