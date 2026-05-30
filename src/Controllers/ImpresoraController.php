<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Impresora;

class ImpresoraController extends BaseController
{
    public function listar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $params     = $r->getQueryParams();
        $tipoFiltro = $params['tipo_trabajo'] ?? null;

        $query = Impresora::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id);

        if ($tipoFiltro) {
            $isPg = \Illuminate\Database\Capsule\Manager::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                $query->whereRaw("tipos_trabajo @> ?::jsonb", [json_encode([$tipoFiltro])]);
            } else {
                $query->whereRaw("JSON_CONTAINS(tipos_trabajo, ?)", [json_encode($tipoFiltro)]);
            }
        }

        $impresoras = $query->get();
        return $this->ok($res, $impresoras);
    }

    public function guardar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();

        $id = ($data['id'] === 'null' || !$data['id']) ? null : $data['id'];
        $sucursalId = $user->sucursal_id ?: ($data['sucursal_id'] ?? 1);

        $tiposTrabajo = $data['tipos_trabajo'] ?? [];
        if (is_string($tiposTrabajo)) {
            $tiposTrabajo = json_decode($tiposTrabajo, true) ?? [];
        }

        $impresora = Impresora::updateOrCreate(
            ['id' => $id],
            [
                'empresa_id'    => $user->empresa_id,
                'sucursal_id'   => $sucursalId,
                'nombre'        => $data['nombre'],
                'ip'            => $data['ip'],
                'puerto'        => $data['puerto'] ?? 9100,
                'tipo'          => $data['tipo'] ?? 'General',
                'modulos'       => $data['modulos'] ?? '',
                'tipos_trabajo' => $tiposTrabajo,
                'activo'        => isset($data['activo']) ? (bool)$data['activo'] : true,
            ]
        );

        return $this->ok($res, $impresora, 'Impresora guardada');
    }

    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $impresora = Impresora::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$impresora) return $this->notFound($res);

        $impresora->delete();
        return $this->ok($res, null, 'Impresora eliminada');
    }

    public function imprimirRotulo(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();
        
        $impresora = Impresora::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('modulos', 'LIKE', '%rotulos%')
            ->where('activo', true)
            ->first();

        if (!$impresora) return $this->error($res, 'No hay impresora configurada para el módulo de rótulos');

        $zpl = "";
        if ($data['tipo'] === 'producto') {
            $zpl = \App\Helpers\PrintHelper::generateZPLProducto($data['nombre'], $data['codigo'], $data['ean']);
        } else {
            $zpl = \App\Helpers\PrintHelper::generateZPLUbicacion($data['codigo'], $data['zona']);
        }

        $resPrint = \App\Helpers\PrintHelper::sendToPrinter($impresora->ip, $impresora->puerto, $zpl);
        
        if ($resPrint['error']) return $this->error($res, $resPrint['message']);
        return $this->ok($res, null, 'Rótulo enviado a la impresora ' . $impresora->nombre);
    }

    public function testPrint(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $impresora = Impresora::where('empresa_id', $user->empresa_id)->find($a['id']);
        
        if (!$impresora) return $this->notFound($res);

        $zpl = "^XA\n";
        $zpl .= "^FO50,50^A0N,50,50^FDPRUEBA DE IMPRESION^FS\n";
        $zpl .= "^FO50,120^A0N,30,30^FDImpresora: " . $impresora->nombre . "^FS\n";
        $zpl .= "^FO50,160^A0N,30,30^FDIP: " . $impresora->ip . ":" . $impresora->puerto . "^FS\n";
        $zpl .= "^FO50,200^A0N,30,30^FDFecha: " . date('Y-m-d H:i:s') . "^FS\n";
        $zpl .= "^FO50,250^GB700,3,3^FS\n";
        $zpl .= "^XZ";

        $resPrint = \App\Helpers\PrintHelper::sendToPrinter($impresora->ip, $impresora->puerto, $zpl);
        
        if ($resPrint['error']) return $this->error($res, $resPrint['message']);
        return $this->ok($res, null, 'Prueba enviada correctamente');
    }
}
