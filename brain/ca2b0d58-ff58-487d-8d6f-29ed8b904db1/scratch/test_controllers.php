<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
try {
    echo "Instanciando RecepcionController...\n";
    $rc = new \App\Controllers\RecepcionController();
    echo "Instanciado OK.\n";
    
    echo "Instanciando InventarioController...\n";
    $ic = new \App\Controllers\InventarioController();
    echo "Instanciado OK.\n";
    
    echo "Probando getProximoPallet...\n";
    $req = new \Slim\Psr7\Request('GET', new \Slim\Psr7\Uri('http', 'localhost', 80, '/api/recepciones/proximo-pallet'), new \Slim\Psr7\Headers(), [], [], new \Slim\Psr7\Stream(fopen('php://temp', 'r+')));
    // Mock user attribute
    $req = $req->withAttribute('user', (object)['empresa_id' => 1, 'sucursal_id' => 1]);
    $res = new \Slim\Psr7\Response();
    
    $rc->getProximoPallet($req, $res);
    echo "Llamada OK.\n";

} catch (\Throwable $e) {
    echo "FALLO CRÍTICO: " . $e->getMessage() . "\n";
    echo "En archivo: " . $e->getFile() . " linea " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
