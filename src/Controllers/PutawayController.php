<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Inventario;
use App\Models\Ubicacion;

/** PutawayController - WMS v2 - Almacenamiento */
class PutawayController
{
    public function listarPatio(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function sugerirUbicacion(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function ubicar(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function trasladar(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function resolverEan(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    private function j(Response $res, array $d, int $st = 200): Response
    {
        $res->getBody()->write(json_encode($d));
        return $res->withStatus($st)->withHeader('Content-Type', 'application/json');
    }
}
