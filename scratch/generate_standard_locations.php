<?php
include __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    echo "--- INICIANDO GENERACIÓN DE UBICACIONES ESTÁNDAR ---\n";

    $empresaId = 1;
    $sucursalId = 1;
    $zonaCodigo = 'CEDI';
    
    // 1. Asegurar la Zona CEDI
    $zona = DB::table('zonas')->where('codigo', $zonaCodigo)->first();
    if (!$zona) {
        DB::table('zonas')->insert([
            'empresa_id' => $empresaId,
            'codigo'     => $zonaCodigo,
            'descripcion'=> 'Zona Central de Distribución',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        echo "Zona CEDI creada.\n";
    } else {
        echo "Zona CEDI ya existe.\n";
    }

    $pasillos = range('A', 'J'); // 10
    $modulos  = range(1, 20);   // 20
    $niveles  = range('A', 'G'); // 7
    $total    = 0;
    $creadas  = 0;
    $existentes = 0;

    echo "Generando combinaciones...\n";

    DB::beginTransaction();
    
    foreach ($pasillos as $p) {
        foreach ($modulos as $m) {
            $mStr = str_pad($m, 2, '0', STR_PAD_LEFT);
            foreach ($niveles as $n) {
                $total++;
                
                // Formato: WP/EX/PASILLO-MODULO-NIVEL
                $codigo = "WP/EX/{$p}-{$mStr}-{$n}";
                
                // Verificar si ya existe
                $exists = DB::table('ubicaciones')
                    ->where('empresa_id', $empresaId)
                    ->where('codigo', $codigo)
                    ->exists();
                
                if (!$exists) {
                    DB::table('ubicaciones')->insert([
                        'empresa_id'       => $empresaId,
                        'sucursal_id'      => $sucursalId,
                        'codigo'           => $codigo,
                        'zona'             => $zonaCodigo,
                        'pasillo'          => $p,
                        'modulo'           => $mStr,
                        'nivel'            => $n,
                        'tipo_ubicacion'   => 'Almacenamiento',
                        'capacidad_maxima' => 0,
                        'm3'               => 0,
                        'estado'           => 'Libre',
                        'activo'           => 1,
                        'created_at'       => date('Y-m-d H:i:s'),
                        'updated_at'       => date('Y-m-d H:i:s')
                    ]);
                    $creadas++;
                } else {
                    $existentes++;
                }
            }
        }
    }
    
    DB::commit();
    
    echo "--- RESUMEN ---\n";
    echo "Total procesadas: $total\n";
    echo "Nuevas creadas:   $creadas\n";
    echo "Ya existentes:    $existentes\n";
    echo "--- FIN DEL PROCESO ---";

} catch (\Exception $e) {
    if(DB::transactionLevel() > 0) DB::rollBack();
    echo "ERROR: " . $e->getMessage();
}
