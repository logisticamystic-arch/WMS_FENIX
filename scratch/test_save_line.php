<?php
/**
 * Test de registro de línea de conteo para InventarioV2.
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\InventarioV2Controller;
use App\Models\SesionAsignacion;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Factory\ServerRequestFactory;

try {
    // 1. Obtener una asignación activa (o crear una si no hay)
    $asig = SesionAsignacion::where('estado', '!=', 'Finalizado')->orderBy('id', 'desc')->first();
    if (!$asig) {
        echo "No hay asignaciones activas para probar. Por favor cree una en el UI.\n";
        exit;
    }

    echo "PROBANDO REGISTRO EN ASIGNACION #{$asig->id} (Sesión #{$asig->sesion_id})\n";

    $user = (object)[
        'id' => $asig->auxiliar_id,
        'empresa_id' => 1,
        'sucursal_id' => 1,
    ];

    $request = (new ServerRequestFactory())->createServerRequest('POST', "/api/v2/inventario/asignaciones/{$asig->id}/linea");
    $request = $request->withAttribute('user', $user);
    $request = $request->withParsedBody([
        'producto_id' => 1, // Asumiendo que producto 1 existe
        'ubicacion_id' => 1, // Asumiendo que ubicacion 1 existe
        'cantidad_contada' => 10,
        'lote' => 'TEST-001'
    ]);

    $response = new SlimResponse();
    $controller = new InventarioV2Controller();
    $result = $controller->registrarLinea($request, $response, ['id' => $asig->id]);

    $status = $result->getStatusCode();
    $body = (string)$result->getBody();

    echo "STATUS: $status\n";
    if ($status === 200) {
        echo "CONTEO REGISTRADO CORRECTAMENTE ✅\n";
    } else {
        echo "FALLO EL REGISTRO ❌\n";
        echo "BODY: $body\n";
    }

} catch (\Throwable $e) {
    echo "ERROR CRITICO: " . $e->getMessage() . "\n";
}
