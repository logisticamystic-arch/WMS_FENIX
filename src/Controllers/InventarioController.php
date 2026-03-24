<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\ConteoInventario;
use App\Models\ConteoDetalle;
use Illuminate\Database\Capsule\Manager as DB;
/** InventarioController - WMS v2 */
class InventarioController {
    public function traslado($r,$res):Response{ return $this->j($res,['error'=>false]); }
    public function ajuste($r,$res):Response{ return $this->j($res,['error'=>false]); }
    public function getStock($r,$res):Response{ return $this->j($res,['error'=>false]); }
    public function crearConteo($r,$res):Response{ return $this->j($res,['error'=>false],201); }
    public function addLineaConteo($r,$res,$a):Response{ return $this->j($res,['error'=>false]); }
    public function finalizarConteo($r,$res,$a):Response{ return $this->j($res,['error'=>false]); }
    public function aprobarConteo($r,$res,$a):Response{ return $this->j($res,['error'=>false]); }
    public function getConteos($r,$res):Response{ return $this->j($res,['error'=>false]); }
    private function j($res,$d,$st=200):Response{ $res->getBody()->write(json_encode($d)); return $res->withStatus($st)->withHeader('Content-Type','application/json'); }
}
