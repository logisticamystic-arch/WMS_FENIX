<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Models\Devolucion;
use App\Models\CausalDevolucion;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Personal;
use App\Models\Empresa;
use App\Models\Sucursal;

echo "=== TEST CREACIÓN DE DEVOLUCIÓN ===" . PHP_EOL;

$empresa  = Empresa::first();
$sucursal = Sucursal::where('empresa_id', $empresa->id)->first() ?? Sucursal::first();
$user     = Personal::first();
$causal   = CausalDevolucion::where('activo', true)->first();
$cliente  = Cliente::where('activo', true)->first();
$prod     = Producto::first();

echo "Empresa ID: {$empresa->id}" . PHP_EOL;
echo "Sucursal ID: {$sucursal->id}" . PHP_EOL;
echo "User ID: {$user->id}" . PHP_EOL;
echo "Causal ID: {$causal->id} ({$causal->causal})" . PHP_EOL;
echo "Cliente ID: {$cliente->id} ({$cliente->razon_social})" . PHP_EOL;
echo "Producto ID: {$prod->id} ({$prod->nombre})" . PHP_EOL;

// Simular creación
$numero      = Devolucion::generarNumero($empresa->id);
$consecutivo = Devolucion::generarConsecutivo($empresa->id);

echo "Nuevo Número Interno generado: {$numero}" . PHP_EOL;
echo "Nuevo Consecutivo numérico simple generado: {$consecutivo}" . PHP_EOL;

$dev = Devolucion::create([
    'empresa_id'             => $empresa->id,
    'sucursal_id'            => $sucursal->id,
    'numero_devolucion'      => $numero,
    'consecutivo_devolucion' => $consecutivo,
    'tipo'                   => 'cliente',
    'estado'                 => 'PendienteAprobacion',
    'motivo_general'         => 'Test de verificación sin responsable',
    'auxiliar_id'            => $user->id,
    'solicitado_por'         => $user->id,
    'fecha_movimiento'       => date('Y-m-d'),
    'hora_inicio'            => date('H:i:s'),
    'causal_devolucion_id'   => $causal->id,
    'cliente_origen_id'      => $cliente->id,
]);

echo "Devolución ID Creada: {$dev->id}" . PHP_EOL;
echo "Consecutivo grabado en BD: {$dev->consecutivo_devolucion}" . PHP_EOL;
echo "Cliente Origen asociado: " . ($dev->clienteOrigen ? $dev->clienteOrigen->razon_social : 'Ninguno') . PHP_EOL;
