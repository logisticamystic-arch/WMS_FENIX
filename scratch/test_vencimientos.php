<?php
require __DIR__ . '/../bootstrap.php';
$c = new App\Controllers\ReportesController();
$req = (new \Slim\Psr7\Request('GET', new \Slim\Psr7\Uri('http', 'localhost', null, '/reportes/vencimientos', 'dias=30')))
    ->withAttribute('user', (object)['empresa_id' => 1, 'sucursal_id' => 1]);
$res = new \Slim\Psr7\Response();
$out = $c->vencimientos($req, $res);
echo $out->getBody();
