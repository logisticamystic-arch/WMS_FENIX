<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
try {
    echo "Probando InboundController::getOrdenesCompra...\n";
    $ic = new \App\Controllers\InboundController();
    
    $req = new \Slim\Psr7\Request('GET', new \Slim\Psr7\Uri('http', 'localhost', 80, '/api/odc'), new \Slim\Psr7\Headers(), [], [], new \Slim\Psr7\Stream(fopen('php://temp', 'r+')));
    // Mock user attribute
    $req = $req->withAttribute('user', (object)['empresa_id' => 1, 'sucursal_id' => 1]);
    $res = new \Slim\Psr7\Response();
    
    $ic->getOrdenesCompra($req, $res);
    echo "Llamada a /api/odc OK.\n";

} catch (\Throwable $e) {
    echo "FALLO CRÍTICO: " . $e->getMessage() . "\n";
    echo "En archivo: " . $e->getFile() . " linea " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
