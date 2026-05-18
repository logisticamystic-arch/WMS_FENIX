<?php

namespace App\Helpers;

class PrintHelper
{
    /**
     * Sends raw data to a network printer via TCP/IP
     */
    public static function sendToPrinter($ip, $port, $data)
    {
        try {
            $fp = @fsockopen($ip, $port, $errno, $errstr, 5);
            if (!$fp) {
                return ["error" => true, "message" => "No se pudo conectar a la impresora en $ip:$port ($errstr)"];
            }

            fwrite($fp, $data);
            fclose($fp);

            return ["error" => false, "message" => "Datos enviados a la impresora"];
        } catch (\Exception $e) {
            return ["error" => true, "message" => $e->getMessage()];
        }
    }

    /**
     * Generates a simple ZPL label for Picking Certification
     */
    public static function generateZPL($sucursal, $info)
    {
        $zpl = "^XA\n";
        $zpl .= "^CF0,60\n";
        $zpl .= "^FO50,50^FDWMS FENIX - CERTIFICADO^FS\n";
        $zpl .= "^CF0,30\n";
        $zpl .= "^FO50,130^FDSucursal: " . $sucursal . "^FS\n";
        $zpl .= "^FO50,170^FDPedidos: " . $info['pedidos'] . "^FS\n";
        $zpl .= "^FO50,210^FDLineas: " . $info['lineas'] . "^FS\n";
        $zpl .= "^FO50,250^FDFecha: " . date('Y-m-d H:i') . "^FS\n";
        $zpl .= "^FO50,300^GB700,3,3^FS\n";
        $zpl .= "^FO50,330^FDENVIAR A: " . $sucursal . "^FS\n";
        $zpl .= "^XZ";
        return $zpl;
    }

    public static function generateZPLProducto($nombre, $codigo, $ean)
    {
        $zpl = "^XA\n";
        $zpl .= "^CI28\n"; // UTF-8
        $zpl .= "^FO50,50^A0N,30,30^FD" . substr($nombre, 0, 40) . "^FS\n";
        $zpl .= "^FO50,90^A0N,25,25^FDCodigo: " . $codigo . "^FS\n";
        $zpl .= "^FO100,130^BY3\n";
        $zpl .= "^BCN,100,Y,N,N\n";
        $zpl .= "^FD" . $ean . "^FS\n";
        $zpl .= "^XZ";
        return $zpl;
    }

    public static function generateZPLUbicacion($codigo, $zona)
    {
        $zpl = "^XA\n";
        $zpl .= "^FO50,50^A0N,40,40^FDZONA: " . $zona . "^FS\n";
        $zpl .= "^FO100,110^BY4\n";
        $zpl .= "^BCN,150,Y,N,N\n";
        $zpl .= "^FD" . $codigo . "^FS\n";
        $zpl .= "^XZ";
        return $zpl;
    }
}
