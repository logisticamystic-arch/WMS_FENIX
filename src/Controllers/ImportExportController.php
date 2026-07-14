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

        // Para productos los headers se fijan explícitamente para garantizar
        // que ambiente_id y todos los campos opcionales siempre aparezcan.
        if ($tipo === 'productos') {
            $headers = [
                'marca_id', 'categoria_id', 'ambiente_id',
                'codigo_interno', 'nombre', 'descripcion',
                'unidad_medida', 'peso_unitario', 'volumen_unitario',
                'controla_lote', 'controla_vencimiento', 'vida_util_dias',
                'temperatura_almacen', 'stock_minimo', 'unidades_caja',
                'codigo_ean',
            ];
        } else {
            // Remove internal fields
            $fieldsToHide = ['empresa_id', 'sucursal_id', 'activo', 'imagen_url', 'created_at', 'updated_at'];
            $headers = array_values(array_filter($fillable, fn($f) => !in_array($f, $fieldsToHide)));
        }

        $help = [];
        $help[] = "INSTRUCCIONES: Diligencie desde la fila 8. No modifique los encabezados de la fila 7. Use punto y coma (;) como separador.";
        if ($tipo === 'productos') {
            $help[] = "Campos obligatorios: codigo_interno (*), nombre (*), unidad_medida (*). | Booleanos: use 1=Sí / 0=No (controla_lote, controla_vencimiento). | ambiente_id: ID numérico o texto SECO/REFRIGERADO/CONGELADO/FRESCO.";
            $help[] = "Lógica de Importación: Si el codigo_interno YA EXISTE el registro se ACTUALIZA. Si NO EXISTE se CREA.";
        } else {
            $help[] = "Campos obligatorios (*): Código Interno, Nombre, Unidad Medida, Categoría ID, Marca ID.";
            $help[] = "Lógica de Importación: Se ACTUALIZAN NITs ya existentes.";
        }
        $help[] = ""; // Row 4 empty

        // Row 5: Reference Data (Table)
        $refTable = "";
        if ($tipo === 'productos') {
            $cats   = \App\Models\CategoriaProducto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get(['id', 'nombre']);
            $marcas = \App\Models\Marca::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get(['id', 'nombre']);
            $ambientes = \App\Models\Ambiente::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get(['id', 'codigo']);
            $refTable = "CATÁLOGOS — [CATEGORÍAS] : " . $cats->map(fn($c) => "{$c->id}={$c->nombre}")->implode(', ')
                . " | [MARCAS] : " . $marcas->map(fn($m) => "{$m->id}={$m->nombre}")->implode(', ')
                . " | [AMBIENTES] : " . $ambientes->map(fn($a) => "{$a->id}={$a->codigo}")->implode(', ') . " (use ID o código: SECO, REFRIGERADO, CONGELADO)";
        } elseif ($tipo === 'clientes') {
            $rutas = \App\Models\Ruta::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get(['id', 'nombre']);
            $refTable = "CATÁLOGOS — [RUTAS] : " . $rutas->map(fn($r) => "{$r->id}={$r->nombre}")->implode(', ');
        }
        $help[] = $refTable; // This is row 5
        $help[] = ""; // Row 6 empty

        // CSV Generation
        $content = "";
        foreach ($help as $h) { $content .= '"' . str_replace('"', '""', $h) . '"' . "\r\n"; }
        $content .= implode(';', $headers) . "\r\n"; // Headers in Row 7
        
        // Row 8: Sample row — orden exacto de los headers dinámicos
        if ($tipo === 'productos') {
            // Orden: marca_id;categoria_id;ambiente_id;codigo_interno;nombre;descripcion;
            //        unidad_medida;peso_unitario;volumen_unitario;controla_lote;controla_vencimiento;
            //        vida_util_dias;temperatura_almacen;stock_minimo;unidades_caja;codigo_ean
            $content .= "1;1;SECO;PROD-001;Producto de Ejemplo Fénix;Descripción opcional del producto;UN;0.500;0.0005;0;0;;AMBIENTE;10.00;12;7701234567890\r\n";
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
            'codigo_interno' => ['codigo', 'ref', 'referencia', 'cod_interno', 'codigo_producto', 'identificador'],
            'nombre'         => ['nombre', 'producto', 'descripcion', 'detalle'],
            'codigo_ean'     => ['ean', 'barcode', 'codigo_ean', 'codigo_barras'],
            'unidades_caja'  => ['unidades_caja', 'unidades x caja', 'uxc', 'caja', 'packaging', 'unidades xcaja'],
            'stock_minimo'   => ['stock_minimo', 'minimo', 'alerta_stock'],
            'unidad_medida'  => ['unidad_medida', 'um', 'u.m', 'unidad'],
            'peso_unitario'  => ['peso_unitario', 'peso', 'peso_kg', 'kg'],
            'volumen_unitario' => ['volumen_unitario', 'volumen', 'volumen_m3', 'm3'],
            'controla_lote'  => ['controla_lote', 'maneja_lotes', 'lote', 'controla lote'],
            'controla_vencimiento' => ['controla_vencimiento', 'control_vencimiento', 'vencimiento', 'maneja_vencimiento'],
            'ambiente_id'    => ['ambiente_id', 'ambiente', 'zona_temperatura', 'temperatura_zona', 'condicion_almacen'],
            'vida_util_dias' => ['vida_util_dias', 'vida_util', 'dias_vida_util', 'shelf_life'],
            'temperatura_almacen' => ['temperatura_almacen', 'temperatura', 'temp_almacen'],
            'volumen_unitario'    => ['volumen_unitario', 'volumen', 'volumen_m3', 'm3', 'volumen_caja']
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
            foreach (\App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get() as $p) {
                $cacheProds[$p->codigo_interno] = $p;
            }
        } elseif ($tipo === 'clientes') {
            foreach (\App\Models\Cliente::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get() as $c) {
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
            $row['empresa_id'] = $this->getEffectiveEmpresaId($user, $request);
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

                // Normalizar decimales: Excel español usa coma → convertir a punto
                foreach(['peso_unitario','volumen_unitario','stock_minimo'] as $fld) {
                    if (isset($row[$fld]) && $row[$fld] !== null) {
                        $row[$fld] = str_replace(',', '.', (string)$row[$fld]);
                        if (!is_numeric($row[$fld])) $row[$fld] = null;
                    }
                }
                // unidades_caja es entero — limpiar coma y truncar
                if (isset($row['unidades_caja']) && $row['unidades_caja'] !== null) {
                    $ucVal = str_replace(',', '.', (string)$row['unidades_caja']);
                    $row['unidades_caja'] = is_numeric($ucVal) ? (int) floor((float)$ucVal) : null;
                }

                // Resolver ambiente_id: acepta ID numérico o código texto (SECO, REFRIGERADO, etc.)
                if ($tipo === 'productos' && isset($row['ambiente_id'])) {
                    $ambVal = trim($row['ambiente_id']);
                    if ($ambVal !== '' && !is_numeric($ambVal)) {
                        $amb = \App\Models\Ambiente::where('empresa_id', $row['empresa_id'])
                            ->whereRaw('UPPER(codigo) = ?', [strtoupper($ambVal)])
                            ->first();
                        $row['ambiente_id'] = $amb ? $amb->id : null;
                    } elseif ($ambVal === '') {
                        $row['ambiente_id'] = null;
                    }
                }

                if ($tipo === 'productos') {
                    $codigo = $row['codigo_interno'] ?? null;
                    if (!$codigo) { 
                        $summary['errors'][] = "Línea " . ($i + 2 + $headerRowIndex) . ": Código Interno vacío."; 
                        $summary['omitiendo']++; continue; 
                    }
                    
                    // Si el usuario usa 'descripcion' como su nombre principal (y nombre está vacío)
                    if (empty($row['nombre']) && !empty($row['descripcion'])) {
                        $row['nombre'] = $row['descripcion'];
                    }
                    if (empty($row['nombre'])) {
                        $row['nombre'] = 'Prod. ' . $codigo;
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

                } elseif ($tipo === 'ubicaciones') {
                    $codigo = $row['codigo'] ?? null;
                    $zona   = $row['zona'] ?? null;

                    if (!$codigo && $zona) {
                        $pas = $row['pasillo'] ?? '';
                        $mod = $row['modulo'] ?? '';
                        $niv = $row['nivel'] ?? '';
                        $codigo = $zona . '/' . implode('-', array_filter([$pas, $mod, $niv]));
                        $row['codigo'] = $codigo;
                    }

                    if (!$codigo) {
                        $summary['errors'][] = "Línea " . ($i + 2 + $headerRowIndex) . ": Código o Zona vacíos.";
                        $summary['omitiendo']++;
                        continue;
                    }

                    $row['sucursal_id'] = $row['sucursal_id'] ?? $this->getEffectiveSucursalId($user, $request);
                    $row['activo'] = isset($row['activo']) ? filter_var($row['activo'], FILTER_VALIDATE_BOOLEAN) : true;
                    $row['estado'] = $row['estado'] ?? 'Libre';
                    $row['capacidad_maxima'] = isset($row['capacidad_maxima']) && is_numeric($row['capacidad_maxima']) ? (int)$row['capacidad_maxima'] : 0;

                    $exists = \App\Models\Ubicacion::where('empresa_id', $row['empresa_id'])
                        ->where('codigo', $codigo)
                        ->first();

                    $fillableUbi = (new \App\Models\Ubicacion())->getFillable();
                    $rowClean = array_intersect_key($row, array_flip($fillableUbi));

                    if ($exists) {
                        $exists->update($rowClean);
                        $summary['actualizados']++;
                    } else {
                        \App\Models\Ubicacion::create($rowClean);
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
     * POST /api/param/import-export/preview/{tipo}
     */
    public function previewCSV(Request $request, Response $response, array $args): Response
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

        $headerRowIndex = 0;
        $headers = [];
        $delimiter = ';';
        $modelClass = $this->models[$tipo];
        $model = new $modelClass();
        $fillable = $model->getFillable();

        $aliases = [
            'codigo'           => ['codigo', 'cod', 'code', 'ubicacion'],
            'zona'             => ['zona', 'area', 'ambiente'],
            'pasillo'          => ['pasillo', 'pas', 'aisle'],
            'modulo'           => ['modulo', 'mod', 'module', 'columna'],
            'nivel'            => ['nivel', 'niv', 'level', 'piso'],
            'posicion'         => ['posicion', 'pos', 'position'],
            'tipo_ubicacion'   => ['tipo_ubicacion', 'tipo', 'type'],
            'capacidad_maxima' => ['capacidad_maxima', 'capacidad', 'cap'],
            'estado'           => ['estado', 'state', 'status'],
            'clase'            => ['clase', 'class'],
            'codigo_interno'   => ['codigo_interno', 'codigo', 'ref', 'referencia', 'cod_interno'],
            'nombre'           => ['nombre', 'producto', 'descripcion', 'detalle'],
            'codigo_ean'       => ['ean', 'barcode', 'codigo_ean', 'codigo_barras'],
            'unidad_medida'    => ['unidad_medida', 'um', 'u.m', 'unidad'],
        ];

        foreach (array_slice($lines, 0, 15) as $idx => $line) {
            $sep = str_contains($line, ';') ? ';' : ',';
            $cols = str_getcsv($line, $sep);
            $validCount = 0;
            $mappedHeaders = [];

            foreach ($cols as $c) {
                $raw = strtolower(trim($c, " \t\n\r\0\x0B\xEF\xBB\xBF"));
                $found = false;
                if (in_array($raw, $fillable) || $raw === 'codigo_ean' || $raw === 'clase') {
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
            return $this->json($response, ['error' => true, 'message' => 'No se encontraron encabezados válidos.'], 400);
        }

        $dataRows = array_slice($lines, $headerRowIndex + 1);
        $preview = [];
        $stats = ['total' => 0, 'nuevos' => 0, 'existentes' => 0, 'errores' => 0];
        $errors = [];

        $existingCodes = [];
        if ($tipo === 'ubicaciones') {
            $existingCodes = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->pluck('codigo')->map(fn($c) => strtoupper($c))->toArray();
        }

        $headersClean = array_filter($headers, fn($h) => $h !== null);

        foreach ($dataRows as $i => $line) {
            $cols = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $idx => $key) {
                if ($key !== null && isset($cols[$idx])) {
                    $row[$key] = strip_tags(trim($cols[$idx], " \t\n\r\0\x0B\xEF\xBB\xBF"));
                }
            }
            if (empty($row)) continue;

            $stats['total']++;
            $lineNum = $i + 2 + $headerRowIndex;
            $rowStatus = 'nuevo';
            $rowErrors = [];

            if ($tipo === 'ubicaciones') {
                $codigo = $row['codigo'] ?? null;
                $zona   = $row['zona'] ?? null;
                if (!$codigo && $zona) {
                    $pas = $row['pasillo'] ?? '';
                    $mod = $row['modulo'] ?? '';
                    $niv = $row['nivel'] ?? '';
                    $codigo = $zona . '/' . implode('-', array_filter([$pas, $mod, $niv]));
                    $row['codigo'] = $codigo;
                }
                if (!$codigo) {
                    $rowErrors[] = 'Código o Zona vacíos';
                }
                if (!($row['tipo_ubicacion'] ?? '')) {
                    $rowErrors[] = 'Tipo ubicación vacío';
                }
                if ($codigo && in_array(strtoupper($codigo), $existingCodes)) {
                    $rowStatus = 'existente';
                    $stats['existentes']++;
                } else {
                    $stats['nuevos']++;
                }
            }

            if (!empty($rowErrors)) {
                $rowStatus = 'error';
                $stats['errores']++;
                $stats[isset($row['codigo']) && in_array(strtoupper($row['codigo'] ?? ''), $existingCodes) ? 'existentes' : 'nuevos']--;
                $errors[] = "Línea $lineNum: " . implode(', ', $rowErrors);
            }

            $preview[] = [
                'linea'  => $lineNum,
                'datos'  => $row,
                'estado' => $rowStatus,
                'errores' => $rowErrors,
            ];

            if (count($preview) >= 500) break;
        }

        return $this->json($response, [
            'error'    => false,
            'headers'  => array_values($headersClean),
            'preview'  => $preview,
            'stats'    => $stats,
            'errors'   => $errors,
        ]);
    }

    /**
     * GET /api/param/import-export/export/productos
     * Exports all products for the authenticated empresa as CSV
     */
    public function exportProductos(Request $request, Response $response): Response
    {
        $user      = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        $productos = \App\Models\Producto::with([
            'marca',
            'categoria',
            'ambiente',
            'eans' => fn($q) => $q->where('es_principal', true)->where('activo', true),
        ])
        ->where('empresa_id', $empresaId)
        ->orderBy('codigo_interno')
        ->get();

        // Columnas en el mismo orden que la plantilla de importación (+referencias al final)
        $headers = [
            // ── Campos importables ────────────────────────────────────────────
            'marca_id', 'categoria_id', 'ambiente_id',
            'codigo_interno', 'nombre', 'descripcion',
            'unidad_medida', 'peso_unitario', 'volumen_unitario',
            'controla_lote', 'controla_vencimiento', 'vida_util_dias',
            'temperatura_almacen', 'stock_minimo', 'unidades_caja',
            'codigo_ean', 'activo',
            // ── Columnas de referencia (solo lectura — no se reimportan) ──────
            'marca_nombre', 'categoria_nombre', 'ambiente_codigo',
        ];

        $q = fn(string $v): string => '"' . str_replace('"', '""', $v) . '"';

        $content  = "\xEF\xBB\xBF"; // UTF-8 BOM para Excel
        $content .= implode(';', $headers) . "\r\n";

        foreach ($productos as $p) {
            $ean = $p->eans->first();
            $row = [
                // Importables
                $p->marca_id     ?? '',
                $p->categoria_id ?? '',
                $p->ambiente_id  ?? '',
                $p->codigo_interno ?? '',
                $p->nombre ?? '',
                $p->descripcion ?? '',
                $p->unidad_medida ?? 'UN',
                $p->peso_unitario ?? '0.000',
                $p->volumen_unitario ?? '0.0000',
                $p->controla_lote        ? '1' : '0',
                $p->controla_vencimiento ? '1' : '0',
                $p->vida_util_dias ?? '',
                $p->temperatura_almacen ?? '',
                $p->stock_minimo ?? '0.00',
                $p->unidades_caja ?? '1',
                $ean ? $ean->codigo_ean : '',
                $p->activo ? '1' : '0',
                // Referencias (lectura)
                $p->marca     ? $p->marca->nombre     : '',
                $p->categoria ? $p->categoria->nombre : '',
                $p->ambiente  ? $p->ambiente->codigo  : '',
            ];
            $content .= implode(';', array_map(fn($v) => $q((string) $v), $row)) . "\r\n";
        }

        if (ob_get_length()) ob_clean();
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="productos_export_' . date('Ymd_His') . '.csv"');
    }

}
