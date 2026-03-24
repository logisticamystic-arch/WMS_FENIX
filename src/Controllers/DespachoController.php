<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Despacho;
use App\Models\CertificacionDespacho;

/** DespachoController - WMS v2 - TMS Prep */
class DespachoController
{
    public function listar(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    /** POST /api/despachos */
    public function store(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false], 201);
    }

    /** POST /api/despachos/{id}/certificar */
    public function certify(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    /** POST /api/despachos/{id}/cerrar */
    public function close(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function crear(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false], 201);
    }

    public function certificarItem(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function estadoCertificacion(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function certificar(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function tmsExport(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function tmsPush(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    private function j(Response $res, array $d, int $st = 200): Response
    {
        $res->getBody()->write(json_encode($d));
        return $res->withStatus($st)->withHeader('Content-Type', 'application/json');
    }
}
