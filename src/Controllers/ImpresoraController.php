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

        $query = Impresora::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $this->getEffectiveSucursalId($user, $r));

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
        $sucursalId = $this->getEffectiveSucursalId($user, $r) ?: ($data['sucursal_id'] ?? 1);

        $tiposTrabajo = $data['tipos_trabajo'] ?? [];
        if (is_string($tiposTrabajo)) {
            $tiposTrabajo = json_decode($tiposTrabajo, true) ?? [];
        }

        $impresora = Impresora::updateOrCreate(
            ['id' => $id],
            [
                'empresa_id'    => $this->getEffectiveEmpresaId($user, $r),
                'sucursal_id'   => $sucursalId,
                'nombre'        => $data['nombre'],
                'ip'            => $data['ip'],
                'puerto'        => $data['puerto'] ?? 9100,
                'tipo'          => $data['tipo']     ?? 'General',
                'lenguaje'      => $data['lenguaje'] ?? 'ZPL',
                'modulos'       => $data['modulos']  ?? '',
                'tipos_trabajo' => $tiposTrabajo,
                'activo'        => isset($data['activo']) ? (bool)$data['activo'] : true,
            ]
        );

        return $this->ok($res, $impresora, 'Impresora guardada');
    }

    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $impresora = Impresora::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $this->getEffectiveSucursalId($user, $r))->find($a['id']);
        if (!$impresora) return $this->notFound($res);

        $impresora->delete();
        return $this->ok($res, null, 'Impresora eliminada');
    }

    public function imprimirRotulo(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody();

        $impresora = Impresora::where('empresa_id', $this->getEffectiveEmpresaId($user, $r))
            ->where('sucursal_id', $this->getEffectiveSucursalId($user, $r))
            ->where('modulos', 'LIKE', '%rotulos%')
            ->where('activo', true)
            ->first();

        if (!$impresora) return $this->error($res, 'No hay impresora configurada para el módulo de rótulos en esta sucursal. Verifique la configuración de impresoras y que se haya asignado al módulo de rótulos.');

        if (empty($data['tipo'])) {
            return $this->error($res, 'El tipo de rótulo (producto/ubicacion) es requerido.');
        }

        $rawData = "";
        if ($data['tipo'] === 'producto') {
            if (empty($data['nombre']) || empty($data['codigo']) || empty($data['ean'])) {
                return $this->error($res, 'Datos incompletos para rótulo de producto: nombre, código y EAN requeridos.');
            }
            $rawData = \App\Helpers\PrintHelper::generateZPLProducto($data['nombre'], $data['codigo'], $data['ean']);
        } elseif ($data['tipo'] === 'ubicacion') {
            if (empty($data['codigo'])) {
                return $this->error($res, 'Datos incompletos para rótulo de ubicación: código requerido.');
            }
            $copias = max(1, intval($data['copias'] ?? 1));
            $ubis = [];
            for ($i = 0; $i < $copias; $i++) {
                $ubis[] = ['codigo' => $data['codigo'], 'zona' => $data['zona'] ?? ''];
            }
            $rawData = \App\Helpers\PrintHelper::generateTSPLUbicaciones($ubis);
        } elseif ($data['tipo'] === 'ubicacion_masivo') {
            $ubicaciones = $data['ubicaciones'] ?? [];
            if (empty($ubicaciones)) {
                return $this->error($res, 'No se recibieron ubicaciones para impresión masiva.');
            }
            $copias = max(1, intval($data['copias'] ?? 1));
            $expanded = [];
            foreach ($ubicaciones as $ubi) {
                for ($i = 0; $i < $copias; $i++) {
                    $expanded[] = $ubi;
                }
            }
            $rawData = \App\Helpers\PrintHelper::generateTSPLUbicaciones($expanded);
        } else {
            return $this->error($res, 'Tipo de rótulo no reconocido.');
        }

        $resPrint = \App\Helpers\PrintHelper::sendToPrinter($impresora->ip, $impresora->puerto, $rawData);

        if ($resPrint['error']) return $this->error($res, $resPrint['message']);
        $count = ($data['tipo'] === 'ubicacion_masivo') ? count($data['ubicaciones'] ?? []) : 1;
        return $this->ok($res, null, "{$count} rótulo(s) enviado(s) a la impresora " . $impresora->nombre);
    }

    public function testPrint(Request $r, Response $res, array $a): Response
    {
        $user      = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);

        $impresora = Impresora::withoutGlobalScope('tenant')
            ->where('empresa_id', $empresaId)
            ->find((int)$a['id']);

        if (!$impresora) return $this->notFound($res, 'Impresora no encontrada (id=' . $a['id'] . ')');

        $nombre = $impresora->nombre ?? 'TEST';
        $ip     = $impresora->ip     ?? '?';
        $puerto = $impresora->puerto ?? 9100;
        $fecha  = date('d/m/Y H:i');
        $tipo   = strtoupper($impresora->lenguaje ?? $impresora->tipo ?? 'ZPL');

        if ($tipo === 'TSC') {
            $payload  = "INITIALPRINTER\r\n";
            $payload .= "SIZE 100 mm, 50 mm\r\n";
            $payload .= "GAP 3 mm, 0 mm\r\n";
            $payload .= "DIRECTION 1\r\n";
            $payload .= "CLS\r\n";
            $payload .= "TEXT 20,20,\"4\",0,1,1,\"PRUEBA DE IMPRESION\"\r\n";
            $payload .= "TEXT 20,56,\"3\",0,1,1,\"Imp: {$nombre}\"\r\n";
            $payload .= "TEXT 20,82,\"3\",0,1,1,\"IP: {$ip}:{$puerto}\"\r\n";
            $payload .= "TEXT 20,108,\"2\",0,1,1,\"Fecha: {$fecha}\"\r\n";
            $payload .= "BAR 20,134,760,2\r\n";
            $payload .= "TEXT 20,140,\"2\",0,1,1,\"WMS Fenix - TSC OK\"\r\n";
            $payload .= "PRINT 1,1\r\n";
        } elseif ($tipo === 'PCL') {
            // PCL para impresoras láser Ricoh/HP
            $payload  = "\x1bE";
            $payload .= "\x1b&l1O\x1b(0U\x1b(s0p10h12v0s0b4099T\x1b&l0.5u6D";
            $payload .= "PRUEBA DE IMPRESION\r\n";
            $payload .= "Impresora : {$nombre}\r\n";
            $payload .= "IP        : {$ip}:{$puerto}\r\n";
            $payload .= "Fecha     : {$fecha}\r\n";
            $payload .= str_repeat('-', 40) . "\r\n";
            $payload .= "WMS Fenix - PCL OK\r\n\r\n";
            $payload .= "\x0C\x1bE";
        } elseif ($tipo === 'ZEBRA') {
            // ZPL para impresoras Zebra
            $payload  = "^XA\r\n";
            $payload .= "^FO50,50^A0N,50,50^FDPRUEBA DE IMPRESION^FS\r\n";
            $payload .= "^FO50,120^A0N,30,30^FDImpresora: {$nombre}^FS\r\n";
            $payload .= "^FO50,160^A0N,30,30^FDIP: {$ip}:{$puerto}^FS\r\n";
            $payload .= "^FO50,200^A0N,30,30^FDFecha: {$fecha}^FS\r\n";
            $payload .= "^FO50,250^GB700,3,3^FS\r\n";
            $payload .= "^XZ\r\n";
        } else {
            // ZPL genérico (default)
            $payload  = "^XA\r\n";
            $payload .= "^FO50,50^A0N,50,50^FDPRUEBA DE IMPRESION^FS\r\n";
            $payload .= "^FO50,120^A0N,30,30^FDImpresora: {$nombre}^FS\r\n";
            $payload .= "^FO50,160^A0N,30,30^FDIP: {$ip}:{$puerto}^FS\r\n";
            $payload .= "^FO50,200^A0N,30,30^FDFecha: {$fecha}^FS\r\n";
            $payload .= "^FO50,250^GB700,3,3^FS\r\n";
            $payload .= "^XZ\r\n";
        }

        $resPrint = \App\Helpers\PrintHelper::sendToPrinter($ip, $puerto, $payload);

        if ($resPrint['error']) {
            return $this->error($res, "No se pudo conectar a {$ip}:{$puerto} — " . $resPrint['message']);
        }
        return $this->ok($res, ['tipo' => $tipo, 'ip' => $ip, 'puerto' => $puerto],
            "Prueba enviada a {$nombre} ({$tipo}) en {$ip}:{$puerto}");
    }
}
