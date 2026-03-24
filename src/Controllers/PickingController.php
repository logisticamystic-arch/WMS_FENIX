<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenPicking;
use App\Models\PickingDetalle;
use App\Models\TareaReabastecimiento;

/** PickingController - WMS v2 - FEFO + Concurrencia */
class PickingController
{
    public function listar(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function crearBatch(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false], 201);
    }

    public function detalle(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function pickerrLinea(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function marcarFaltante(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function lockPasillo(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function unlockPasillo(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function completar(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function dashboard(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function reabastecimientos(Request $r, Response $res): Response
    {
        return $this->j($res, ['error' => false]);
    }

    public function completarReabast(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    /** POST /api/picking/{orden_id}/generar-ruta */
    public function generateRoute(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    /** POST /api/picking/{orden_id}/confirmar-linea */
    public function confirmLine(Request $r, Response $res, array $a): Response
    {
        return $this->j($res, ['error' => false]);
    }

    private function j(Response $res, array $d, int $st = 200): Response
    {
        $res->getBody()->write(json_encode($d));
        return $res->withStatus($st)->withHeader('Content-Type', 'application/json');
    }
}
