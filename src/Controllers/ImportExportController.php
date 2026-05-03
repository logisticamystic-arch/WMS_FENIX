<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class ImportExportController extends BaseController
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
        $user = $request->getAttribute('user');
        $tipo = $args['tipo'];
        if (!isset($this->models[$tipo])) {
            return $this->json($response, ['error' => true, 'message' => 'Tipo no válido'], 400);
        }

        $modelClass = $this->models[$tipo];
        $model = new $modelClass();
        $fillable = $model->getFillable();
        
        // Remove internal fields + include virtual for template
        $fieldsToHide = ['empresa_id', 'sucursal_id', 'activo', 'imagen_url', 'created_at', 'updated_at'];
        $headers = array_values(array_filter($fillable, fn($f) => !in_array($f, $fieldsToHide)));
        
        if ($tipo === 'productos') {
            $headers[] = 'codigo_ean'; // Virtual field for import
        }

        $help = [];
        $help[] = "INSTRUCCIONES: Diligencie desde la fila 8. No modifique los encabezados de la fila 7. Use punto y coma (;) como separador.";
        $help[] = "Campos obligatorios (*): Código Interno, Nombre, Unidad Medida, Categoría ID, Marca ID.";
        $help[] = "Lógica de Importación: " . ($tipo === 'productos' ? "Se OMITEN códigos ya existentes." : "Se ACTUALIZAN NITs ya existentes.");
        $help[] = ""; // Row 4 empty

        // Row 5: Reference Data (Table)
        $refTable = "";
        if ($tipo === 'productos') {
            $cats   = \App\Models\CategoriaProducto::where('empresa_id', $user->empresa_id)->get(['id', 'nombre']);
            $marcas = \App\Models\Marca::where('empresa_id', $user->empresa_id)->get(['id', 'nombre']);
            $refTable = "CATÁLOGOS — [CATEGORÍAS] : " . $cats->map(fn($c) => "{$c->id}={$c->nombre}")->implode(', ') . " | [MARCAS] : " . $marcas->map(fn($m) => "{$m->id}={$m->nombre}")->implode(', ');
        } elseif ($tipo === 'clientes') {
            $rutas = \App\Models\Ruta::where('empresa_id', $user->empresa_id)->get(['id', 'nombre']);
            $refTable = "CATÁLOGOS — [RUTAS] : " . $rutas->map(fn($r) => "{$r->id}={$r->nombre}")->implode(', ');
        }
        $help[] = $refTable; // This is row 5
        $help[] = ""; // Row 6 empty

        // CSV Generation
        $content = "";
        foreach ($help as $h) { $content .= '"' . str_replace('"', '""', $h) . '"' . "\r\n"; }
        $content .= implode(';', $headers) . "\r\n"; // Headers in Row 7
        
        // Row 8: Sample row
        if ($tipo === 'productos') {
            $content .= "1;1;P001;Producto Fénix;Ejemplo;UN;0.100;0.0010;1;1;365;Temp Ambiente;10.0;7701234567890\r\n";
        } elseif ($tipo === 'clientes') {
            $content .= "1;900000001;Fénix SAS;CALLE 123;BOGOTA;3000000;ventas@Fénix.com;Contacto Ventas\r\n";
        }

        if (ob_get_length()) ob_clean();
        $response->getBody()->write("\xEF\xBB\xBF" . $content); // UTF-8 BOM
        return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                        ->withHeader('Content-Disposition', 'attachment; filename="plantilla_' . $tipo . '.csv"');
    }

    /**
     * POST /api/import-export/upload/{tipo}
     */
    public function uploadCSV(Request $request, Response $response, array $args): Response
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        $user = $request->getAttribute('user');
        $tipo = $args['tipo'];
        
        if (!isset($this->models[$tipo])) {
            return $this->json($response, ['error' => true, 'message' => 'Tipo no válido'], 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => true, 'message' => 'Archivo no válido'], 400);
        }

        $contents = $file->getStream()->getContents();
        if (!mb_detect_encoding($contents, 'UTF-8', true)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
        $lines = array_filter($lines, fn($l) => !empty(trim($l)));

        if (count($lines) < 2) {
            return $this->json($response, ['error' => true, 'message' => 'El archivo no tiene datos'], 400);
        }

        // Logic to find the header row (skip instructions)
        $headerRowIndex = 0;
        $headers = [];
        $modelClass = $this->models[$tipo];
        $model = new $modelClass();
        $fillable = $model->getFillable();

        // Alias mapping for flexibility (lowercase always)
        $aliases = [
            'codigo_interno' => ['codigo', 'ref', 'cod_interno', 'codigo_producto', 'identificador'],
            'nombre'         => ['nombre', 'producto', 'descripcion', 'detalle'],
            'codigo_ean'     => ['ean', 'barcode', 'codigo_ean', 'codigo_barras'],
            'unidades_caja'  => ['unidades_caja', 'unidades x caja', 'uxc', 'caja', 'packaging', 'unidades xcaja'],
            'stock_minimo'   => ['stock_minimo', 'minimo', 'alerta_stock'],
            'unidad_medida'  => ['unidad_medida', 'um', 'u.m', 'unidad']
        ];

        foreach (array_slice($lines, 0, 15) as $idx => $line) {
            $sep = str_contains($line, ';') ? ';' : ',';
            $cols = str_getcsv($line, $sep);
            $validCount = 0;
            $mappedHeaders = [];
            
            foreach ($cols as $c) {
                $raw = strtolower(trim($c, " \t\n\r\0\x0B\xEF\xBB\xBF")); // Clean BOM too
                $found = false;
                if (in_array($raw, $fillable) || $raw === 'codigo_ean') {
                    $mappedHeaders[] = $raw;
                    $validCount++;
                    $found = true;
                } else {
                    foreach ($aliases as $key => $list) {
                        if (in_array($raw, $list)) {
                            $mappedHeaders[] = $key;
                            $validCount++;
                            $found = true;
                            break;
                        }
                    }
                }
                if (!$found) $mappedHeaders[] = null;
            }
            
            if ($validCount >= 2) {
                $headers = $mappedHeaders;
                $headerRowIndex = $idx;
                $delimiter = $sep;
                break;
            }
        }

        if (empty($headers)) {
            return $this->json($response, ['error' => true, 'message' => 'No se encontraron encabezados válidos en las primeras 10 líneas.'], 400);
        }

        $dataRows = array_slice($lines, $headerRowIndex + 1);
        $summary = ['total' => count($dataRows), 'creados' => 0, 'actualizados' => 0, 'omitiendo' => 0, 'errors' => []];

        // OPTIMIZACIÓN: Cargar catálogo a memoria para evitar N sentencias SELECT
        $cacheProds = [];
        $cacheClis  = [];
        $cacheEans  = []; // [codigo_ean => producto_id]

        if ($tipo === 'productos') {
            foreach (\App\Models\Producto::where('empresa_id', $user->empresa_id)->get() as $p) {
                $cacheProds[$p->codigo_interno] = $p;
            }
        } elseif ($tipo === 'clientes') {
            foreach (\App\Models\Cliente::where('empresa_id', $user->empresa_id)->get() as $c) {
                $cacheClis[$c->nit] = $c;
            }
        }

        foreach ($dataRows as $i => $line) {
            $cols = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $idx => $key) {
                if ($key !== null && isset($cols[$idx])) {
                    $row[$key] = strip_tags(trim($cols[$idx], " \t\n\r\0\x0B\xEF\xBB\xBF"));
                }
            }

            if (empty($row)) continue;
            $row['empresa_id'] = $user->empresa_id;
            try {
                // Limpiar valores (incluyendo errores de Excel como #N/D o #N/A)
                foreach($row as $fld => $val) {
                    if ($val === null) continue;
                    $valStr = (string)$val;
                    if ($valStr === '' || $valStr === 'null' || str_starts_with($valStr, '#')) {
                        $row[$fld] = null;
                    }
                }

                // Asegurar que campos ID sean numéricos o nulos
                foreach(['categoria_id','marca_id','empresa_id','sucursal_id'] as $fld) {
                    if (isset($row[$fld]) && !is_numeric($row[$fld])) $row[$fld] = null;
                }

                if ($tipo === 'productos') {
                    $codigo = $row['codigo_interno'] ?? null;
                    if (!$codigo) { 
                        $summary['errors'][] = "Línea " . ($i + 2 + $headerRowIndex) . ": Código Interno vacío."; 
                        $summary['omitiendo']++; continue; 
                    }
                    
                    $exists = $cacheProds[$codigo] ?? null;
                    $ean = $row['codigo_ean'] ?? null;
                    unset($row['codigo_ean']);

                    if ($exists) {
                        $exists->update($row);
                        $prod = $exists;
                    } else {
                        $prod = \App\Models\Producto::create($row);
                        $cacheProds[$codigo] = $prod; 
                    }
                    
                    // Registro de EAN: Permitir duplicidad entre diferentes productos
                    if ($ean) {
                        try {
                            \App\Models\ProductoEan::updateOrCreate(
                                ['producto_id' => $prod->id, 'es_principal' => true],
                                ['codigo_ean' => $ean, 'tipo' => 'EAN13', 'activo' => true]
                            );
                        } catch (\Exception $eEan) {
                            $summary['errors'][] = "Línea " . ($i + 2 + $headerRowIndex) . ": Error al guardar EAN: " . $eEan->getMessage();
                        }
                    }

                    if ($exists) $summary['actualizados']++;
                    else $summary['creados']++;
                    
                } elseif ($tipo === 'clientes') {
                    $nit = $row['nit'] ?? null;
                    if (!$nit) { 
                        $summary['errors'][] = "Línea " . ($i + 2 + $headerRowIndex) . ": NIT vacío."; 
                        $summary['omitiendo']++; continue; 
                    }
                    
                    $cliente = $cacheClis[$nit] ?? null;
                    if ($cliente) {
                        $cliente->update($row);
                        $summary['actualizados']++;
                    } else {
                        $row['activo'] = 1;
                        $nc = \App\Models\Cliente::create($row);
                        $cacheClis[$nit] = $nc;
                        $summary['creados']++;
                    }
                }
            } catch (\Exception $e) {
                $summary['errors'][] = "Línea " . ($i + 2 + $headerRowIndex) . ": " . $e->getMessage();
                $summary['omitiendo']++;
            }
        }

        return $this->json($response, ['error' => false, 'data' => $summary]);
    }

    /**
     * GET /api/param/import-export/export/productos
     * Exports all products for the authenticated empresa as CSV
     */
    public function exportProductos(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        $productos = \App\Models\Producto::with(['marca', 'categoria', 'eans' => function ($q) {
            $q->where('es_principal', true)->where('activo', true);
        }])
        ->where('empresa_id', $user->empresa_id)
        ->orderBy('codigo_interno')
        ->get();

        $headers = ['codigo_interno', 'nombre', 'descripcion', 'unidad_medida', 'peso_kg',
                    'unidades_caja', 'stock_minimo', 'categoria', 'marca', 'codigo_ean'];

        $content = "\xEF\xBB\xBF"; // UTF-8 BOM
        $content .= implode(';', $headers) . "\r\n";

        foreach ($productos as $p) {
            $ean = $p->eans->first();
            $row = [
                $p->codigo_interno ?? '',
                $p->nombre ?? '',
                $p->descripcion ?? '',
                $p->unidad_medida ?? '',
                $p->peso_kg ?? '',
                $p->unidades_caja ?? '',
                $p->stock_minimo ?? '',
                $p->categoria ? $p->categoria->nombre : '',
                $p->marca ? $p->marca->nombre : '',
                $ean ? $ean->codigo_ean : '',
            ];
            $content .= implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\r\n";
        }

        if (ob_get_length()) ob_clean();
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="productos_export_' . date('Ymd_His') . '.csv"');
    }

}
