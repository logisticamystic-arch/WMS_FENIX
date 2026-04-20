<?php
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Eloquent
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dbConfig = require __DIR__ . '/../config/database.php';
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection($dbConfig);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use Illuminate\Database\Capsule\Manager as Capsule;

try {
    $pasillos = ['L', 'M', 'N', 'O'];
    $maxMod = 20;
    $niveles = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    
    $empresaId = 1;
    $sucursalId = 1;
    
    // Obtener la secuencia inicial
    $currentMaxSeq = Capsule::table('ubicaciones')->max('secuencia_picking') ?? 0;
    $secuencia = $currentMaxSeq + 1;

    echo "Iniciando creación masiva de ubicaciones (L, M, N, O)...\n";
    echo "Secuencia inicial: $secuencia\n";
    $count = 0;

    foreach ($pasillos as $p) {
        for ($m = 1; $m <= $maxMod; $m++) {
            $moduloStr = str_pad($m, 2, '0', STR_PAD_LEFT);
            
            foreach ($niveles as $n) {
                $codigo = "WP/EX/$p-$moduloStr-$n";
                
                // Lógica de Tipo
                $tipo = in_array($n, ['A', 'B']) ? 'Picking' : 'Almacenamiento';
                
                // Verificar existencia
                $exists = Capsule::table('ubicaciones')->where('codigo', $codigo)->exists();
                
                if (!$exists) {
                    Capsule::table('ubicaciones')->insert([
                        'empresa_id'       => $empresaId,
                        'sucursal_id'      => $sucursalId,
                        'codigo'           => $codigo,
                        'zona'             => $p,
                        'pasillo'          => $p,
                        'modulo'           => $moduloStr,
                        'nivel'            => $n,
                        'posicion'         => '',
                        'tipo_ubicacion'   => $tipo,
                        'capacidad_maxima' => 0,
                        'm3'               => 0,
                        'clase'            => 'Normal',
                        'volumen_maximo'   => 0,
                        'estado'           => 'Libre',
                        'activo'           => 1,
                        'secuencia_picking'=> $secuencia++,
                        'created_at'       => date('Y-m-d H:i:s'),
                        'updated_at'       => date('Y-m-d H:i:s')
                    ]);
                    $count++;
                }
            }
        }
        echo "Pasillo $p completado.\n";
    }

    echo "\nPROCESO FINALIZADO.\n";
    echo "Registros creados con éxito: $count\n";

} catch (\Exception $e) {
    echo "ERROR CRÍTICO: " . $e->getMessage() . "\n";
}
