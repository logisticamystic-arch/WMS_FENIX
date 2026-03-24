<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** ReportesController - WMS v2 - 9 Reportes */
class ReportesController
{
    public function kardex(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function stockActual(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function recepciones(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function despachos(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function devoluciones(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function picking(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function conteos(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function odcReporte(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function dashboardGerencial(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    private function j(Response $res, array $d, int $st = 200): Response
    {
        $res->getBody()->write(json_encode($d));
        return $res->withStatus($st)->withHeader('Content-Type', 'application/json');
    }
}
