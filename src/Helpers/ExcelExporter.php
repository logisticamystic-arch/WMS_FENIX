<?php

namespace App\Helpers;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * ExcelExporter — Genera archivos CSV con BOM UTF-8 listos para Excel.
 * No requiere librerías externas (PHPSpreadsheet, etc.).
 */
class ExcelExporter
{
    /**
     * Envía una respuesta HTTP con el CSV para descarga.
     *
     * @param Response $response  Slim response
     * @param array    $headers   ['Col1', 'Col2', ...]
     * @param array    $rows      [ ['val1','val2',...], ... ]
     * @param string   $filename  sin extensión, ej: 'kardex_2025-01'
     */
    public static function download(
        Response $response,
        array    $headers,
        array    $rows,
        string   $filename = 'reporte'
    ): Response {
        $csv = self::buildCsv($headers, $rows);

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '.csv"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Construye el CSV como string (con BOM para Excel).
     */
    public static function buildCsv(array $headers, array $rows): string
    {
        $lines = [];

        // BOM UTF-8 para que Excel lo abra correctamente en Windows
        $bom = "\xEF\xBB\xBF";

        $lines[] = self::csvLine($headers);

        foreach ($rows as $row) {
            $lines[] = self::csvLine($row);
        }

        return $bom . implode("\r\n", $lines);
    }

    /**
     * Devuelve el CSV como JSON para que el frontend lo descargue.
     * Útil cuando el cliente quiere generar el archivo desde JS.
     */
    public static function asJson(array $headers, array $rows): array
    {
        return [
            'headers' => $headers,
            'rows'    => $rows,
            'csv_b64' => base64_encode(self::buildCsv($headers, $rows)),
        ];
    }

    // ── privado ───────────────────────────────────────────────────────────────

    private static function csvLine(array $fields): string
    {
        $escaped = array_map(function ($v) {
            $v = (string) ($v ?? '');
            // Si contiene coma, comillas o salto de línea → envolver en comillas
            if (strpbrk($v, '",\r\n') !== false) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            return $v;
        }, $fields);

        return implode(',', $escaped);
    }
}
