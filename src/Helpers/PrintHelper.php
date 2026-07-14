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

    /**
     * TSC TE200 — Etiqueta de packing (canasta/caja) en 100mm x 150mm
     * Incluye empresa, cliente, tipo+consecutivo, tabla de productos y certificador.
     *
     * @param array $data  [
     *   'empresa'       => string,
     *   'cliente'       => string,
     *   'tipo'          => 'CANASTA'|'CAJA'|...,
     *   'consecutivo'   => int,
     *   'fecha'         => string (d/m/Y H:i),
     *   'certificador'  => string,
     *   'items'         => [['codigo'=>..., 'nombre'=>..., 'cert'=>..., 'separador'=>...], ...],
     * ]
     */
    public static function generateTSPLPacking(array $data): string
    {
        $dpmm   = 8;              // 203 DPI → 8 dots/mm
        $w      = 100 * $dpmm;   // 800 dots
        $h      = 150 * $dpmm;   // 1200 dots
        $margin = 20;

        $ascii = static function (string $s, int $max = 40): string {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            return substr(preg_replace('/[^\x20-\x7E]/', ' ', $s), 0, $max);
        };

        $empresa     = $ascii($data['empresa']     ?? 'WMS Fenix',   35);
        $cliente     = $ascii($data['cliente']     ?? '',            38);
        $tipo        = strtoupper($ascii($data['tipo']        ?? 'CANASTA', 10));
        $consec      = (int)($data['consecutivo']  ?? 1);
        $fecha       = $ascii($data['fecha']       ?? date('d/m/Y H:i'), 20);
        $cert        = $ascii($data['certificador'] ?? '---',        30);
        $items       = $data['items'] ?? [];

        $tspl  = "INITIALPRINTER\r\n";
        $tspl .= "SIZE 100 mm, 150 mm\r\n";
        $tspl .= "GAP 3 mm, 0 mm\r\n";
        $tspl .= "DIRECTION 1\r\n";
        $tspl .= "CLS\r\n";

        $y = 20;
        // Empresa
        $tspl .= "TEXT {$margin},{$y},\"4\",0,1,1,\"{$empresa}\"\r\n";
        $y += 32;
        // Cliente
        $tspl .= "TEXT {$margin},{$y},\"3\",0,1,1,\"{$cliente}\"\r\n";
        $y += 26;
        // Fecha
        $tspl .= "TEXT {$margin},{$y},\"2\",0,1,1,\"{$fecha}\"\r\n";
        $y += 22;
        // Separador
        $tspl .= "BAR {$margin},{$y}," . ($w - $margin * 2) . ",3\r\n";
        $y += 8;
        // Tipo + Consecutivo (grande)
        $tspl .= "TEXT {$margin},{$y},\"5\",0,2,2,\"{$tipo} # {$consec}\"\r\n";
        $y += 60;
        // Separador
        $tspl .= "BAR {$margin},{$y}," . ($w - $margin * 2) . ",3\r\n";
        $y += 10;

        // Encabezado tabla
        $tspl .= "TEXT {$margin},{$y},\"2\",0,1,1,\"COD\"\r\n";
        $tspl .= "TEXT " . ($margin + 80) . ",{$y},\"2\",0,1,1,\"DESCRIPCION\"\r\n";
        $tspl .= "TEXT " . ($w - 120) . ",{$y},\"2\",0,1,1,\"CERT\"\r\n";
        $y += 24;

        // Items
        foreach ($items as $it) {
            $cod  = $ascii($it['codigo']    ?? '', 8);
            $desc = $ascii($it['nombre']    ?? '', 22);
            $cert_val = $ascii($it['cert']  ?? '', 10);
            $sep  = $ascii($it['separador'] ?? '', 15);

            $tspl .= "TEXT {$margin},{$y},\"2\",0,1,1,\"{$cod}\"\r\n";
            $tspl .= "TEXT " . ($margin + 80) . ",{$y},\"2\",0,1,1,\"{$desc}\"\r\n";
            $tspl .= "TEXT " . ($w - 120) . ",{$y},\"2\",0,1,1,\"{$cert_val}\"\r\n";
            $y += 22;
            $tspl .= "TEXT " . ($margin + 80) . ",{$y},\"2\",0,1,1,\"Sep: {$sep}\"\r\n";
            $y += 24;

            if ($y > $h - 60) break;  // evitar overflow
        }

        // Certificador
        $tspl .= "BAR {$margin},{$y}," . ($w - $margin * 2) . ",2\r\n";
        $y += 8;
        $tspl .= "TEXT {$margin},{$y},\"2\",0,1,1,\"Certificador: {$cert}\"\r\n";

        $tspl .= "PRINT 1,1\r\n";
        return $tspl;
    }

    /**
     * PCL — Etiqueta de packing para impresoras láser Ricoh/HP (puerto 9100)
     * Genera texto formateado con escape sequences PCL básicas.
     */
    public static function generatePCLPacking(array $data): string
    {
        $ascii = static function (string $s, int $max = 40): string {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            return substr(preg_replace('/[^\x20-\x7E]/', ' ', $s), 0, $max);
        };

        $empresa = $ascii($data['empresa']      ?? 'WMS Fenix', 40);
        $cliente = $ascii($data['cliente']      ?? '',           40);
        $tipo    = strtoupper($ascii($data['tipo'] ?? 'CANASTA', 10));
        $consec  = (int)($data['consecutivo']   ?? 1);
        $fecha   = $ascii($data['fecha']        ?? date('d/m/Y H:i'), 20);
        $cert    = $ascii($data['certificador'] ?? '---',        30);
        $items   = $data['items'] ?? [];

        $SEP = str_repeat('-', 62);

        // PCL básico: reset, portrait, Courier 10pt, 6lpi
        $pcl  = "\x1bE";        // reset
        $pcl .= "\x1b&l1O";     // portrait
        $pcl .= "\x1b(0U";      // símbolo PC-8
        $pcl .= "\x1b(s0p10h12v0s0b4099T"; // Courier 12pt fixed
        $pcl .= "\x1b&l0.5u6D"; // margen sup 0.5in, 6 líneas/pulgada

        $lines   = [];
        $lines[] = $empresa;
        $lines[] = 'ETIQUETA PACKING - ' . $ascii($cliente, 30);
        $lines[] = 'Fecha: ' . $fecha;
        $lines[] = $SEP;
        $lines[] = '   >>> ' . $tipo . ' # ' . $consec . ' <<<';
        $lines[] = $SEP;
        $lines[] = sprintf('%-10s  %-32s  %10s', 'CODIGO', 'DESCRIPCION', 'CERT');
        $lines[] = $SEP;

        foreach ($items as $it) {
            $cod  = $ascii($it['codigo']    ?? '', 10);
            $desc = $ascii($it['nombre']    ?? '', 32);
            $cv   = $ascii($it['cert']      ?? '',  10);
            $sep  = $ascii($it['separador'] ?? '', 25);
            $lines[] = sprintf('%-10s  %-32s  %10s', $cod, $desc, $cv);
            $lines[] = '  Separo: ' . $sep;
        }

        $lines[] = $SEP;
        $lines[] = 'Certificador: ' . $cert;
        $lines[] = '';

        foreach ($lines as $line) {
            $pcl .= $line . "\r\n";
        }

        $pcl .= "\x0C";  // form feed — expulsa la hoja
        $pcl .= "\x1bE"; // reset final

        return $pcl;
    }

    /**
     * TSC TE200 — Rótulos de ubicación en rollo de 2 columnas (50mm x 70mm c/u)
     * Genera TSPL con dos etiquetas por fila. Si el número es impar, la última
     * fila solo tiene la etiqueta izquierda.
     *
     * @param array $ubicaciones  [['codigo'=>'A-01-01','zona'=>'SECO'], ...]
     * @return string  Comandos TSPL listos para enviar por socket
     */
    public static function generateTSPLUbicaciones(array $ubicaciones): string
    {
        if (empty($ubicaciones)) return '';

        $tspl = '';
        $chunks = array_chunk($ubicaciones, 2);

        foreach ($chunks as $pair) {
            $tspl .= self::buildTSPLUbicacionPage($pair);
        }

        return $tspl;
    }

    /**
     * Genera una página TSPL con 1 o 2 etiquetas de ubicación lado a lado.
     * Medidas por etiqueta: 50 mm ancho × 70 mm alto.
     * Resolución TSC TE200: 203 DPI → 8 dots/mm.
     */
    private static function buildTSPLUbicacionPage(array $pair): string
    {
        $dpmm    = 8;
        $labelW  = 50 * $dpmm;   // 400 dots por etiqueta
        $labelH  = 70 * $dpmm;   // 560 dots de alto
        $totalW  = 100;           // mm — ancho total del rollo (2 × 50)

        $tspl  = "SIZE {$totalW} mm, 70 mm\n";
        $tspl .= "GAP 3 mm, 0 mm\n";
        $tspl .= "DIRECTION 1\n";
        $tspl .= "CLS\n";

        foreach ($pair as $col => $ubi) {
            $offsetX = $col * $labelW;
            $codigo  = strtoupper(trim($ubi['codigo'] ?? ''));

            $bcY     = 80;
            $bcH     = 300;
            $bcX     = $offsetX + 30;
            $tspl   .= "BARCODE {$bcX},{$bcY},\"128\",{$bcH},0,0,2,2,\"{$codigo}\"\n";

            $textY   = $bcY + $bcH;
            $charW   = 24;
            $textLen = strlen($codigo) * $charW * 2;
            $textX   = $offsetX + max(10, intval(($labelW - $textLen) / 2));
            $tspl   .= "TEXT {$textX},{$textY},\"4\",0,2,2,\"{$codigo}\"\n";
        }

        $tspl .= "PRINT 1,1\n";
        return $tspl;
    }

    /**
     * TSC TE200 — Rótulo individual de ubicación (una sola etiqueta, columna izquierda)
     */
    public static function generateTSPLUbicacion(string $codigo, string $zona): string
    {
        return self::generateTSPLUbicaciones([
            ['codigo' => $codigo, 'zona' => $zona]
        ]);
    }
}
