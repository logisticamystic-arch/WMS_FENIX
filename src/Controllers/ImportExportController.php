<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class ImportExportController
{
    private $models = [
        'empresas' => '\App\Models\Empresa',
        'sucursales' => '\App\Models\Sucursal',
        'marcas' => '\App\Models\Marca',
        'productos' => '\App\Models\Producto',
        'personal' => '\App\Models\Personal',
        'ubicaciones' => '\App\Models\Ubicacion',
        'proveedores' => '\App\Models\Proveedor',
        'clientes' => '\App\Models\Cliente'
    ];

    /**
     * GET /api/import-export/template/{tipo}
     */
    public function getTemplate(Request $request, Response $response, array $args): Response
    {
        $tipo = $args['tipo'];
        if (!isset($this->models[$tipo])) {
            return $this->json($response, ['error' => true, 'message' => 'Tipo no válido'], 400);
        }

        $modelClass = $this->models[$tipo];
        $model = new $modelClass();
        $fillable = $model->getFillable();
        
        // Remove 'empresa_id' from the template, as it's determined by the logged-in user context
        if (($key = array_search('empresa_id', $fillable)) !== false) {
            unset($fillable[$key]);
        }
        
        // Return CSV content
        $csvHeader = implode(',', $fillable) . "\r\n";
        
        // Clean any previous output buffer to avoid corruption
        if (ob_get_length()) ob_clean();
        
        $response->getBody()->write($csvHeader);
        return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                        ->withHeader('Content-Disposition', 'attachment; filename="plantilla_' . $tipo . '.csv"')
                        ->withHeader('Pragma', 'no-cache')
                        ->withHeader('Expires', '0');
    }

    /**
     * POST /api/import-export/upload/{tipo}
     */
    public function uploadCSV(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $tipo = $args['tipo'];
        
        if (!isset($this->models[$tipo])) {
            return $this->json($response, ['error' => true, 'message' => 'Tipo no válido'], 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $this->json($response, ['error' => true, 'message' => 'No se recibió ningún archivo'], 400);
        }

        $file = $uploadedFiles['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => true, 'message' => 'Error al subir el archivo'], 400);
        }

        // Limit file size to 5 MB
        $maxBytes = 5 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return $this->json($response, ['error' => true, 'message' => 'El archivo supera el límite de 5 MB.'], 400);
        }

        // Validate MIME type
        $clientMime = $file->getClientMediaType();
        $allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        if ($clientMime && !in_array($clientMime, $allowedMimes, true)) {
            return $this->json($response, ['error' => true, 'message' => 'Solo se permiten archivos CSV.'], 400);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $contents = $stream->getContents();

        // Validate content is valid UTF-8
        if (!mb_detect_encoding($contents, 'UTF-8', true)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        $lines = explode("\n", $contents);
        if (count($lines) < 2) {
            return $this->json($response, ['error' => true, 'message' => 'El archivo está vacío o no tiene datos válidos'], 400);
        }

        // Limit to 1000 data rows
        $maxRows = 1000;
        if (count($lines) - 1 > $maxRows) {
            return $this->json($response, ['error' => true, 'message' => "El archivo supera el límite de {$maxRows} filas."], 400);
        }

        $headers = str_getcsv(array_shift($lines), ';');
        $headers = array_map('trim', $headers);
        
        $modelClass = $this->models[$tipo];
        $successCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($lines as $i => $line) {
                if (empty(trim($line))) continue;
                $data = str_getcsv($line, ';');
                
                if (count($data) !== count($headers)) {
                    $errors[] = "Línea " . ($i + 2) . ": El número de columnas no coincide.";
                    continue;
                }

                $mappedData = array_combine($headers, $data);
                // Sanitize all string values
                foreach ($mappedData as $k => $v) {
                    $mappedData[$k] = is_string($v) ? strip_tags(trim($v)) : $v;
                }
                $mappedData['empresa_id'] = $user->empresa_id;
                // Siempre activar registros importados a menos que el CSV lo indique explícitamente
                if (!isset($mappedData['activo']) || $mappedData['activo'] === '') {
                    $mappedData['activo'] = 1;
                }

                try {
                    $modelClass::create($mappedData);
                    $successCount++;
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, '1062') || str_contains($msg, 'Duplicate')) {
                        $errors[] = "Línea " . ($i + 2) . ": Registro duplicado.";
                    } else {
                        $errors[] = "Línea " . ($i + 2) . ": Error al guardar registro.";
                    }
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('ImportExportController::uploadCSV critical error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error crítico al procesar el archivo. Verifique el formato e intente nuevamente.'], 500);
        }

        return $this->json($response, [
            'error' => false, 
            'message' => "Importación completada. $successCount registros añadidos.",
            'errores' => $errors
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
