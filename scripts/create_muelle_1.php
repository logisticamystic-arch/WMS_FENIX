<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    $exists = Capsule::table('ubicaciones')->where('codigo', 'MUELLE 1')->exists();
    if ($exists) {
        echo "La ubicación MUELLE 1 ya existe.\n";
    } else {
        Capsule::table('ubicaciones')->insert([
            'empresa_id' => 1,
            'sucursal_id' => 1,
            'codigo' => 'MUELLE 1',
            'zona' => 'P',
            'pasillo' => 'P',
            'modulo' => '01',
            'nivel' => '1',
            'tipo_ubicacion' => 'Patio',
            'capacidad_maxima' => 9999,
            'estado' => 'Libre',
            'clase' => 'Normal',
            'activo' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        echo "Ubicación MUELLE 1 creada exitosamente como tipo Patio.\n";
    }
} catch (\Exception $e) {
    echo "Error al crear la ubicación: " . $e->getMessage() . "\n";
}
