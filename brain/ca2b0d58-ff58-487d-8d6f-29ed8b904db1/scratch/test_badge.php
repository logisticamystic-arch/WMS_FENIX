<?php
require_once 'C:/xampp/htdocs/WMS_PROORIENTE/bootstrap.php';
try {
    echo "Probando NotificacionesController::badge...\n";
    $nc = new \App\Controllers\NotificacionesController();
    
    $req = new \Slim\Psr7\Request('GET', new \Slim\Psr7\Uri('http', 'localhost', 80, '/api/notificaciones/badge'), new \Slim\Psr7\Headers(), [], [], new \Slim\Psr7\Stream(fopen('php://temp', 'r+')));
    // Mock user attribute - use a more complete mock
    $user = new stdClass();
    $user->id = 2;
    $user->empresa_id = 1;
    $user->sucursal_id = 1;
    $user->rol = 'Auxiliar';
    
    $req = $req->withAttribute('user', $user);
    $res = new \Slim\Psr7\Response();
    
    $nc->badge($req, $res);
    echo "Llamada a /api/notificaciones/badge OK.\n";

} catch (\Throwable $e) {
    echo "FALLO CRÍTICO: " . $e->getMessage() . "\n";
    echo "En archivo: " . $e->getFile() . " linea " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
