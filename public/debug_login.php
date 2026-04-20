<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: text/plain');

$doc = '1000000001';
$pin = '1234';
$nit = '900000001';

echo "--- DIAGNOSTICO DE LOGIN WEB ---\n";
echo "PHP Version: " . phpversion() . "\n";

try {
    $empresa = App\Models\Empresa::where('nit', $nit)->first();
    echo "Empresa: " . ($empresa ? "ENCONTRADA (ID: {$empresa->id})" : "NO ENCONTRADA") . "\n";

    $user = App\Models\Personal::where('documento', $doc)->where('empresa_id', $empresa->id)->first();
    if (!$user) {
        echo "Usuario: NO ENCONTRADO\n";
    } else {
        echo "Usuario: ENCONTRADO ({$user->nombre})\n";
        echo "Hash en BD: {$user->pin}\n";
        echo "Largo Hash: " . strlen($user->pin) . "\n";
        echo "Validando PIN '1234': " . ($user->verifyPin($pin) ? "EXITO" : "FALLO") . "\n";
    }
} catch (Exception $e) {
    echo "ERROR CRITICO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
