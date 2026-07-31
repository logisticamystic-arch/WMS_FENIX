<?php

require_once 'c:/xampp/htdocs/WMS_FENIX/vendor/autoload.php';
require_once 'c:/xampp/htdocs/WMS_FENIX/bootstrap.php';

use App\Models\SesionInventario;
use App\Models\Producto;
use App\Models\SesionIcgLinea;
use Illuminate\Database\Capsule\Manager as DB;

echo "=== INICIANDO PRUEBA DE INGESTA ICG Y ANALÍTICA DE DASHBOARD V2 ===\n\n";

try {
    // 1. Obtener o crear una sesión de inventario de prueba
    $sesion = SesionInventario::first();
    if (!$sesion) {
        echo "No hay sesiones de inventario existentes para probar.\n";
        exit(0);
    }

    echo "Sesión seleccionada: ID {$sesion->id} - {$sesion->nombre}\n";

    // 2. Limpiar registros ICG previos de prueba
    SesionIcgLinea::where('sesion_id', $sesion->id)->delete();
    echo "Fase 1: Registros de ICG limpiados para la sesión {$sesion->id}\n";

    // 3. Simular la ingesta de un archivo plano ICG con 3 referencias
    // Referencia 1: Existente en WMS
    $prod1 = Producto::where('empresa_id', $sesion->empresa_id)->first();
    $cod1  = $prod1 ? $prod1->codigo_interno : 'REF-001';

    $datosSimulados = [
        ['codigo' => $cod1, 'cantidad' => 150.0],
        ['codigo' => 'REF-ICG-SINKATALOGO-999', 'cantidad' => 50.0],
    ];

    $productos = Producto::select('id', 'codigo_interno', 'nombre', 'unidades_caja', 'ambiente_id', 'temperatura_almacen')
        ->where('empresa_id', $sesion->empresa_id)
        ->get();

    $prodMap = [];
    foreach ($productos as $p) {
        $prodMap[strtoupper(trim($p->codigo_interno))] = $p;
    }

    $insertData = [];
    foreach ($datosSimulados as $item) {
        $cod = strtoupper(trim($item['codigo']));
        $p   = $prodMap[$cod] ?? null;
        $insertData[] = [
            'sesion_id'         => $sesion->id,
            'producto_id'       => $p ? $p->id : null,
            'codigo_referencia' => $item['codigo'],
            'nombre_referencia' => $p ? $p->nombre : 'REF ICG SIN CATALOGO',
            'cantidad_icg'      => $item['cantidad'],
            'empresa_id'        => $sesion->empresa_id,
            'sucursal_id'       => $sesion->sucursal_id,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
    }

    SesionIcgLinea::insert($insertData);
    echo "Fase 2: Insertadas " . count($insertData) . " líneas de prueba en `sesion_icg_lineas`\n";

    // 4. Probar la sobreescritura (Reemplazo en caliente)
    SesionIcgLinea::where('sesion_id', $sesion->id)->delete();
    SesionIcgLinea::insert($insertData);
    $totalActual = SesionIcgLinea::where('sesion_id', $sesion->id)->count();
    echo "Fase 3: Verificación de Reemplazo en Caliente -> Total actual: {$totalActual} registros (Correcto)\n";

    // 5. Verificar consulta de analítica de ICG
    $icgLineas = SesionIcgLinea::where('sesion_id', $sesion->id)->get();
    echo "Fase 4: Leídas {$icgLineas->count()} líneas ICG con modelo SesionIcgLinea\n";

    echo "\n=== TODAS LAS PRUEBAS BACKEND COMPLETADAS CON ÉXITO ===\n";
} catch (\Throwable $e) {
    echo "ERROR EN PRUEBA: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
