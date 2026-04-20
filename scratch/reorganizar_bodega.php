<?php
include __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    echo "--- INICIANDO REORGANIZACIÓN DE BODEGA ---\n";

    $empresaId = 1;

    // 1. Asegurar la columna secuencia_picking
    echo "Verificando columna secuencia_picking...\n";
    $hasColumn = false;
    $columns = DB::select("DESCRIBE ubicaciones");
    foreach($columns as $col) {
        if ($col->Field === 'secuencia_picking') {
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        DB::statement("ALTER TABLE ubicaciones ADD COLUMN secuencia_picking INT DEFAULT 0 AFTER activo");
        echo "Columna secuencia_picking añadida.\n";
    }

    // 2. Crear las 10 zonas (A-J)
    echo "Creando zonas A-J...\n";
    $pasillos = range('A', 'J');
    foreach ($pasillos as $p) {
        $exists = DB::table('zonas')->where('codigo', $p)->where('empresa_id', $empresaId)->exists();
        if (!$exists) {
            DB::table('zonas')->insert([
                'empresa_id' => $empresaId,
                'codigo'     => $p,
                'descripcion'=> "Zona Pasillo $p",
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            echo "Zona $p creada.\n";
        }
    }

    // 3. Reorganizar Ubicaciones (Serpentina)
    echo "Calculando secuencia de picking y tipos de ubicación...\n";
    $totalActualizados = 0;
    $secuencia = 1;

    // Patrón S:
    // A (Impar) -> Mod 01-20, Niv A-G
    // B (Par)   -> Mod 20-01, Niv G-A
    // C (Impar) -> Mod 01-20, Niv A-G
    // ...
    
    foreach ($pasillos as $idx => $p) {
        $esPar = ($idx % 2 !== 0); // B=1, D=3, F=5...
        
        $modulos = range(1, 20);
        if ($esPar) $modulos = array_reverse($modulos);
        
        foreach ($modulos as $m) {
            $mStr = str_pad($m, 2, '0', STR_PAD_LEFT);
            
            $niveles = range('A', 'G');
            if ($esPar) $niveles = array_reverse($niveles);
            
            foreach ($niveles as $n) {
                $codigo = "WP/EX/{$p}-{$mStr}-{$n}";
                
                // Tipo de ubicación: A y B son Picking
                $tipo = (in_array($n, ['A', 'B'])) ? 'Picking' : 'Almacenamiento';
                
                $affected = DB::table('ubicaciones')
                    ->where('empresa_id', $empresaId)
                    ->where('pasillo', $p)
                    ->where('modulo', $mStr)
                    ->where('nivel', $n)
                    ->update([
                        'codigo'           => $codigo, // Asegurar formato
                        'zona'             => $p,      // Zona = Pasillo
                        'tipo_ubicacion'   => $tipo,
                        'secuencia_picking'=> $secuencia
                    ]);
                
                if ($affected) $totalActualizados++;
                $secuencia++;
            }
        }
    }

    echo "--- RESUMEN ---\n";
    echo "Ubicaciones actualizadas: $totalActualizados\n";
    echo "--- FIN DEL PROCESO ---";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
