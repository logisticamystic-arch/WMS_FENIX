<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dbConfig = require __DIR__ . '/../config/database.php';
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection($dbConfig);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use Illuminate\Database\Capsule\Manager as Capsule;

try {
    // Ver ejemplo de pasillo A
    $ejemplos = Capsule::table('ubicaciones')
        ->where('codigo', 'like', 'A-%')
        ->orderBy('codigo', 'asc')
        ->get();

    if ($ejemplos->isEmpty()) {
        echo "No se encontraron ubicaciones en el Pasillo A. Buscando cualquier ubicación...\n";
        $ejemplos = Capsule::table('ubicaciones')->limit(10)->get();
    }

    $modulos = [];
    $niveles = [];

    foreach ($ejemplos as $u) {
        // Formato esperado: LETRA-MODULO-NIVEL (ej. A-01-01)
        $parts = explode('-', $u->codigo);
        if (count($parts) >= 3) {
            $modulos[] = $parts[1];
            $niveles[] = $parts[2];
        }
        echo "Ejemplo encontrado: {$u->codigo} (Estado: {$u->estado}, Tipo: {$u->tipo_ubicacion})\n";
    }

    $maxModulo = !empty($modulos) ? max($modulos) : 'Desconocido';
    $maxNivel = !empty($niveles) ? max($niveles) : 'Desconocido';

    echo "\n--- RESUMEN DE CONFIGURACIÓN ---\n";
    echo "Máximo Módulo detectado: $maxModulo\n";
    echo "Máximo Nivel detectado: $maxNivel\n";
    
    // Obtener otros campos para asegurar consistencia
    $first = $ejemplos->first();
    echo "Otros datos para réplica:\n";
    echo "- Empresa ID: " . ($first->empresa_id ?? '1') . "\n";
    echo "- Sucursal ID: " . ($first->sucursal_id ?? '1') . "\n";
    echo "- Estado: " . ($first->estado ?? 'Activo') . "\n";
    echo "- Tipo Ubicación: " . ($first->tipo_ubicacion ?? 'Rack') . "\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
