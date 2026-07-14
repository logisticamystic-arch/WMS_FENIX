<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Cliente;
use App\Models\Ruta;
use Illuminate\Database\Capsule\Manager as Capsule;

class ParametrosController extends BaseController
{
    /**
     * GET /api/param/empresas
     * Also used as public route: GET /api/auth/empresas (no JWT)
     */
    public function getEmpresas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        try {
            $query = \App\Models\Empresa::query();

            if (!$user) {
                // Public route (login page) — return only active empresas
                $query->where('activo', true);
            } else {
                $isSuperAdmin = strcasecmp($user->rol ?? '', 'SuperAdmin') === 0;
                if (!$isSuperAdmin) {
                    // Regular users only see their own empresa
                    $query->where('activo', true)
                          ->where('id', $user->empresa_id);
                }
                // SuperAdmin sees all empresas
            }

            $empresas = $query->orderBy('razon_social')->get();
            return $this->json($response, ['error' => false, 'data' => $empresas]);
        } catch (\Exception $e) {
            error_log('getEmpresas error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al obtener empresas.'], 500);
        }
    }

    /**
     * POST /api/param/empresas
     */
    public function createEmpresa(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo administradores.'], 403);
        }

        $data = $request->getParsedBody();
        $nit = trim($data['nit'] ?? '');
        $razonSocial = trim($data['razon_social'] ?? '');

        if (empty($nit)) {
            return $this->json($response, ['error' => true, 'message' => 'El NIT es requerido.'], 400);
        }
        if (empty($razonSocial)) {
            return $this->json($response, ['error' => true, 'message' => 'La razón social es requerida.'], 400);
        }
        if (\App\Models\Empresa::where('nit', $nit)->exists()) {
            return $this->json($response, ['error' => true, 'message' => 'Ya existe una empresa con ese NIT.'], 409);
        }

        try {
            $empresa = new Empresa();
            $empresa->nit = $nit;
            $empresa->razon_social = $razonSocial;
            $empresa->direccion = trim($data['direccion'] ?? '');
            $empresa->telefono = trim($data['telefono'] ?? '');
            $empresa->activo = true;
            $empresa->save();

            return $this->json($response, ['error' => false, 'message' => 'Empresa creada con éxito', 'data' => $empresa], 201);
        } catch (\Exception $e) {
            error_log('createEmpresa error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al crear empresa.'], 500);
        }
    }

    /**
     * PUT /api/param/empresas/{id}
     */
    public function editEmpresa(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isSuperAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo superadministradores.'], 403);
        }

        $id = $args['id'];
        $data = $request->getParsedBody();

        try {
            $empresa = Empresa::find($id);
            if (!$empresa) return $this->json($response, ['error' => true, 'message' => 'Empresa no encontrada'], 404);

            if (isset($data['nit'])) $empresa->nit = $data['nit'];
            if (isset($data['razon_social'])) $empresa->razon_social = $data['razon_social'];
            if (isset($data['direccion'])) $empresa->direccion = $data['direccion'];
            if (isset($data['telefono'])) $empresa->telefono = $data['telefono'];
            if (isset($data['activo'])) $empresa->activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN);

            $empresa->save();

            return $this->json($response, ['error' => false, 'message' => 'Empresa actualizada', 'data' => $empresa]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/empresas/{id}
     */
    public function deleteEmpresa(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isSuperAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo superadministradores.'], 403);
        }

        try {
            $e = Empresa::find($args['id']);
            if (!$e) return $this->json($response, ['error' => true, 'message' => 'Empresa no encontrada'], 404);

            $e->activo = false;
            $e->save();

            return $this->json($response, ['error' => false, 'message' => 'Empresa inactivada']);
        } catch (\Exception $ex) {
            return $this->json($response, ['error' => true, 'message' => 'Error al inactivar: ' . $ex->getMessage()], 500);
        }
    }

     /**
      * GET /api/param/sucursales
      */
     public function getSucursales(Request $request, Response $response): Response
     {
         $user = $request->getAttribute('user');
         $params = $request->getQueryParams();
         try {
             $query = \App\Models\Sucursal::query();

             // SuperAdmin can filter by empresa_id, otherwise filter by user's empresa
             if ($this->isSuperAdmin($user) && !empty($params['empresa_id'])) {
                 $query->where('empresa_id', $params['empresa_id']);
             } elseif (!$this->isSuperAdmin($user)) {
                 // Regular users only see sucursales from their empresa
                 $query->where('empresa_id', $this->getEffectiveEmpresaId($user, $request));
             }
             // SuperAdmin without empresa_id filter sees ALL sucursales
             
             if (isset($params['activo'])) {
                 $query->where('activo', filter_var($params['activo'], FILTER_VALIDATE_BOOLEAN));
             }
             
             $sucursales = $query->get();
             return $this->json($response, ['error' => false, 'data' => $sucursales]);
         } catch (\Exception $e) {
             return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
         }
     }

     /**
      * POST /api/param/sucursales
      */
     public function createSucursal(Request $request, Response $response): Response
     {
         $user = $request->getAttribute('user');
         $data = $request->getParsedBody();
         
         $codigo = trim($data['codigo'] ?? '');
         $nombre = trim($data['nombre'] ?? '');
         if (empty($codigo)) {
             return $this->json($response, ['error' => true, 'message' => 'El código de sucursal es requerido.'], 400);
         }
         if (empty($nombre)) {
             return $this->json($response, ['error' => true, 'message' => 'El nombre de sucursal es requerido.'], 400);
         }

         try {
             $suc = new \App\Models\Sucursal();
             $suc->empresa_id = $this->getEffectiveEmpresaId($user, $request);
             $suc->codigo = $codigo;
             $suc->nombre = $nombre;
             $suc->direccion = $data['direccion'] ?? null;
             $suc->ciudad = $data['ciudad'] ?? null;
             $suc->telefono = $data['telefono'] ?? null;
             $suc->tipo = $data['tipo'] ?? 'Bodega';
             $suc->activo = true;
             $suc->save();

             return $this->json($response, ['error' => false, 'message' => 'Sucursal creada con éxito', 'data' => $suc]);
         } catch (\Exception $e) {
             return $this->json($response, ['error' => true, 'message' => 'Error al crear sucursal: ' . $e->getMessage()], 400);
         }
     }

     /**
      * PUT /api/param/sucursales/{id}
      */
     public function editSucursal(Request $request, Response $response, array $args): Response
     {
         $user = $request->getAttribute('user');
         $id = $args['id'];
         $data = $request->getParsedBody();

         try {
             $suc = \App\Models\Sucursal::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
             if (!$suc) return $this->json($response, ['error' => true, 'message' => 'Sucursal no encontrada'], 404);

             if (isset($data['codigo'])) $suc->codigo = $data['codigo'];
             if (isset($data['nombre'])) $suc->nombre = $data['nombre'];
             if (isset($data['direccion'])) $suc->direccion = $data['direccion'];
             if (isset($data['ciudad'])) $suc->ciudad = $data['ciudad'];
             if (isset($data['telefono'])) $suc->telefono = $data['telefono'];
             if (isset($data['tipo'])) $suc->tipo = $data['tipo'];
             if (isset($data['activo'])) $suc->activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN);
             $suc->save();

            return $this->json($response, ['error' => false, 'message' => 'Sucursal actualizada', 'data' => $suc]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al actualizar'], 400);
        }
    }

    /**
     * GET /api/param/productos/buscar?q=...
     * Búsqueda transversal por EAN o Nombre
     */
    public function buscarProductos(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $q      = trim($params['q'] ?? '');
        $catId  = $params['categoria_id'] ?? null;
        $marId  = $params['marca_id'] ?? null;
        $ambId  = $params['ambiente_id'] ?? null;
        $udm    = trim($params['unidad_medida'] ?? '');
        $activo = isset($params['activo']) && $params['activo'] !== ''
                  ? filter_var($params['activo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                  : null;

        try {
            // Buscamos productos que coincidan por nombre, descripción, código interno o eans asociados
            $query = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request));

            // Si no hay búsqueda ni filtros, ordenamos por los más recientes por defecto para "Ver Todos"
            if (empty($q) && empty($catId) && empty($marId) && empty($ambId) && empty($udm) && $activo === null) {
                $query->orderBy('created_at', 'desc');
            }

            // Filtro por texto (EAN, Nombre, Referencia)
            if (!empty($q)) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('nombre', 'ilike', "%$q%")
                        ->orWhere('descripcion', 'ilike', "%$q%")
                        ->orWhere('codigo_interno', 'ilike', "%$q%")
                        ->orWhereHas('eans', function ($e) use ($q) {
                            $e->where('codigo_ean', 'ilike', "%$q%")
                              ->where('activo', true);
                        });
                });
            }

            // Filtros dinámicos adicionales
            if ($catId)       $query->where('categoria_id', $catId);
            if ($marId)       $query->where('marca_id', $marId);
            if ($ambId)       $query->where('ambiente_id', $ambId);
            if ($udm !== '')  $query->where('unidad_medida', 'ilike', $udm);
            if ($activo !== null) $query->where('activo', $activo);

            $productos = $query->with(['eanPrincipal', 'categoria', 'marca', 'ambiente', 'fotos'])
                ->limit($params['limit'] ?? 1000)
                ->get();

            $data = $productos->map(function($p) {
                $stock = \App\Models\Inventario::where('producto_id', $p->id)->sum('cantidad');
                return [
                    'id'                  => $p->id,
                    'nombre'              => $p->nombre,
                    'descripcion'         => $p->descripcion ?: $p->nombre,
                    'codigo_ean'          => $p->eanPrincipal ? $p->eanPrincipal->codigo_ean : $p->codigo_interno,
                    'codigo_interno'      => $p->codigo_interno,
                    'unidades_caja'       => (int)($p->unidades_caja ?: 1),
                    'factor_udm'          => $p->factor_udm ? (float)$p->factor_udm : null,
                    'unidad_contenido'    => $p->unidad_contenido,
                    'stock'               => (float)$stock,
                    'activo'              => (bool)$p->activo,
                    'controla_vencimiento'=> (bool)$p->controla_vencimiento,
                    'controla_lote'       => (bool)$p->controla_lote,
                    'categoria_nombre'    => $p->categoria->nombre ?? '-',
                    'marca_nombre'        => $p->marca->nombre ?? '-',
                    'ambiente_nombre'     => $p->ambiente->codigo ?? '-',
                    'ambiente_color'      => $p->ambiente->color ?? null,
                    'ambiente_id'         => $p->ambiente_id,
                    'unidad_medida'       => $p->unidad_medida ?: 'UN',
                    'fotos'               => $p->fotos
                ];
            });

            return $this->json($response, ['error' => false, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/param/marcas
     */
    public function getMarcas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        try {
            // Mostrar todas las marcas en maestros (activas e inactivas)
            $marcas = \App\Models\Marca::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get();
            return $this->json($response, ['error' => false, 'data' => $marcas]);
        } catch (\Exception $e) {
            error_log('getMarcas error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al obtener marcas.'], 500);
        }
    }

    /**
     * POST /api/param/marcas
     */
    public function createMarca(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $nombre = trim($data['nombre'] ?? '');

        if (empty($nombre)) {
            return $this->json($response, ['error' => true, 'message' => 'El nombre de la marca es requerido.'], 400);
        }
        if (strlen($nombre) > 100) {
            return $this->json($response, ['error' => true, 'message' => 'El nombre no puede superar 100 caracteres.'], 400);
        }

        // Verificar duplicado (case-insensitive)
        $existe = \App\Models\Marca::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->whereRaw('LOWER(nombre) = ?', [strtolower($nombre)])
            ->exists();
        if ($existe) {
            return $this->json($response, ['error' => true, 'message' => "Ya existe una marca con el nombre '{$nombre}'."], 409);
        }

        try {
            $marca = new \App\Models\Marca();
            $marca->empresa_id = $this->getEffectiveEmpresaId($user, $request);
            $marca->nombre = $nombre;
            $marca->activo = true;
            $marca->save();

            return $this->json($response, ['error' => false, 'message' => 'Marca creada con éxito', 'data' => $marca], 201);
        } catch (\Exception $e) {
            error_log('createMarca error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al crear marca: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/param/marcas/{id}
     */
    public function editMarca(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];
        try {
            $marca = \App\Models\Marca::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
            if (!$marca) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            if (isset($data['nombre']) && !empty(trim($data['nombre']))) {
                $nombre = trim($data['nombre']);
                $existe = \App\Models\Marca::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                    ->whereRaw('LOWER(nombre) = ?', [strtolower($nombre)])
                    ->where('id', '!=', $marca->id)
                    ->exists();
                if ($existe) return $this->json($response, ['error' => true, 'message' => "Ya existe una marca con el nombre '{$nombre}'."], 409);
                $marca->nombre = $nombre;
            }
            if (array_key_exists('activo', $data)) $marca->activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN);
            if (array_key_exists('proveedor', $data)) $marca->proveedor = $data['proveedor'] ?: null;
            $marca->save();
            return $this->json($response, ['error' => false, 'message' => 'Marca actualizada', 'data' => $marca]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/marcas/{id}
     */
    public function deleteMarca(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        try {
            $marca = \App\Models\Marca::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
            if (!$marca) return $this->json($response, ['error' => true, 'message' => 'Marca no encontrada'], 404);
            
            // Verificar si hay productos asociados antes de eliminar
            $count = \App\Models\Producto::where('marca_id', $marca->id)->count();
            if ($count > 0) {
                return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar la marca porque tiene productos asociados.'], 400);
            }
            
            $marca->delete();
            return $this->json($response, ['error' => false, 'message' => 'Marca eliminada con éxito']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/param/productos
     */
    public function getProductos(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        try {
            $productos = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->with(['marca', 'categoria', 'eans' => fn($q) => $q->where('activo', true), 'fotos'])
                ->get();
            return $this->json($response, ['error' => false, 'data' => $productos]);
        } catch (\Exception $e) {
            error_log('getProductos error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al obtener productos.'], 500);
        }
    }

    /**
     * POST /api/param/productos/{id}/toggle
     */
    public function toggleProductoEstado(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];

        try {
            $producto = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
            if (!$producto) {
                return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado.'], 404);
            }

            $producto->activo = !(bool)$producto->activo;
            $producto->save();

            return $this->json($response, [
                'error' => false, 
                'message' => 'Estado del producto actualizado: ' . ($producto->activo ? 'Activo' : 'Inactivo'),
                'data' => $producto
            ]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/param/categorias
     */
    public function getCategorias(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $cats = \App\Models\CategoriaProducto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->orderBy('nombre')->get();
        return $this->json($response, ['error' => false, 'data' => $cats]);
    }

    /**
     * POST /api/param/categorias
     */
    public function createCategoria(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Solo administradores'], 403);
        }
        $data = $request->getParsedBody();
        if (empty($data['nombre'])) {
            return $this->json($response, ['error' => true, 'message' => 'El nombre es requerido'], 400);
        }
        $cat = \App\Models\CategoriaProducto::create([
            'empresa_id'               => $this->getEffectiveEmpresaId($user, $request),
            'nombre'                   => $data['nombre'],
            'descripcion'              => $data['descripcion'] ?? null,
            'requiere_foto_vencimiento'=> filter_var($data['requiere_foto_vencimiento'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);
        return $this->json($response, ['error' => false, 'data' => $cat]);
    }

    /**
     * PUT /api/param/categorias/{id}
     */
    public function editCategoria(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Solo administradores'], 403);
        }
        $cat = \App\Models\CategoriaProducto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$cat) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
        $data = $request->getParsedBody();
        if (!empty($data['nombre'])) $cat->nombre = $data['nombre'];
        if (array_key_exists('descripcion', $data)) $cat->descripcion = $data['descripcion'];
        if (array_key_exists('requiere_foto_vencimiento', $data)) {
            $cat->requiere_foto_vencimiento = filter_var($data['requiere_foto_vencimiento'], FILTER_VALIDATE_BOOLEAN);
        }
        $cat->save();
        return $this->json($response, ['error' => false, 'data' => $cat]);
    }

    /**
     * DELETE /api/param/categorias/{id}
     */
    public function deleteCategoria(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Solo administradores'], 403);
        }
        $cat = \App\Models\CategoriaProducto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$cat) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
        // Desasignar productos de esta categoría antes de eliminar
        \App\Models\Producto::where('categoria_id', $cat->id)->update(['categoria_id' => null]);
        $cat->delete();
        return $this->json($response, ['error' => false, 'message' => 'Categoría eliminada']);
    }

    /**
     * POST /api/param/productos
     */
    public function createProducto(Request $request, Response $response): Response
    {
         $user = $request->getAttribute('user');
         
         // Handle both JSON and form-encoded bodies
         $contentType = $request->getHeader('Content-Type')[0] ?? '';
         if (strpos($contentType, 'application/json') !== false) {
             $data = json_decode((string)$request->getBody(), true) ?? [];
         } else {
             $data = $request->getParsedBody();
         }
         
         // Mapeamos codigo_ean a su propia tabla, no a codigo_interno
         // Si la UI no envía codigo_interno, usamos el EAN como interno provisionalmente
         $ean = $data['codigo_ean'] ?? '';
         $interno = $data['codigo_interno'] ?? $ean;

         Capsule::beginTransaction();
         try {
             $prod = new \App\Models\Producto();
             $prod->empresa_id = $this->getEffectiveEmpresaId($user, $request);
             $prod->codigo_interno = $interno;
             $prod->nombre = $data['nombre'] ?? '';
             $prod->descripcion = $data['descripcion'] ?? null;
             $prod->unidad_medida = $data['unidad_medida'] ?? 'UN';
             $prod->peso_unitario = $data['peso_unitario'] ?? 0;
             $prod->volumen_unitario = $data['volumen_unitario'] ?? 0;
             $prod->vida_util_dias = isset($data['vida_util_dias']) && $data['vida_util_dias'] !== '' ? (int)$data['vida_util_dias'] : null;
             $prod->temperatura_almacen = $data['temperatura_almacen'] ?? null;
             $prod->marca_id = $data['marca_id'] ?? null;
             $prod->categoria_id = $data['categoria_id'] ?? null;
             $prod->ambiente_id = !empty($data['ambiente_id']) ? (int)$data['ambiente_id'] : null;
             $prod->controla_lote = filter_var($data['maneja_lotes'] ?? false, FILTER_VALIDATE_BOOLEAN);
             $prod->controla_vencimiento = filter_var($data['controla_vencimiento'] ?? false, FILTER_VALIDATE_BOOLEAN);
             $prod->imagen_url = $data['imagen_url'] ?? null;
             $prod->stock_minimo = $data['stock_minimo'] ?? 0;
             $prod->unidades_caja = isset($data['unidades_caja']) ? (int)$data['unidades_caja'] : 1;
             $prod->factor_udm = isset($data['factor_udm']) && $data['factor_udm'] !== '' && $data['factor_udm'] !== null
                 ? (float)$data['factor_udm'] : null;
             $prod->unidad_contenido = !empty($data['unidad_contenido']) ? $data['unidad_contenido'] : null;
             $prod->activo = true;
             $prod->save();

             // Crear EAN Principal si se proporcionó
             if (!empty($ean)) {
                 $eanModel = new \App\Models\ProductoEan();
                 $eanModel->producto_id = $prod->id;
                 $eanModel->codigo_ean = $ean;
                 $eanModel->tipo = 'EAN13'; // Default para principal
                 $eanModel->es_principal = true;
                 $eanModel->activo = true;
                 $eanModel->save();
             }
             
             Capsule::commit();
             return $this->json($response, ['error' => false, 'message' => 'Producto creado con éxito', 'data' => $prod]);
         } catch (\Exception $e) {
             Capsule::rollBack();
             $msg = $e->getMessage();
             if (str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry')) {
                 $msg = "El código interno '{$interno}' ya está en uso. Por favor use otro código.";
             }
             return $this->json($response, ['error' => true, 'message' => $msg], 400);
         }
    }

    /**
     * GET /api/param/productos/{id}
     */
    public function getProducto(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        try {
            $prod = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->with(['categoria', 'marca', 'ambiente', 'fotos'])->find($id);
            if (!$prod) return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
            
            // Adjuntar EANs
            $prod->eans = \App\Models\ProductoEan::where('producto_id', $prod->id)->get();
            $principal = $prod->eans->where('es_principal', true)->first();
            $prod->ean_principal = $principal ? $principal->codigo_ean : '';

            return $this->json($response, ['error' => false, 'data' => $prod]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al obtener producto'], 500);
        }
    }

     /**
      * PUT /api/param/productos/{id}
      */
     public function editProducto(Request $request, Response $response, array $args): Response
     {
         $user = $request->getAttribute('user');
         $productId = $args['id'];
         
         // Handle both JSON and form-encoded bodies
         $contentType = $request->getHeader('Content-Type')[0] ?? '';
         if (strpos($contentType, 'application/json') !== false) {
             $data = json_decode((string)$request->getBody(), true) ?? [];
         } else {
             $data = $request->getParsedBody();
         }
         
         try {
             $prod = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($productId);
             if (!$prod) {
                 return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
             }

             if (isset($data['codigo_interno'])) $prod->codigo_interno = $data['codigo_interno'];
             if (isset($data['nombre'])) $prod->nombre = $data['nombre'];
             elseif (isset($data['descripcion'])) $prod->nombre = $data['descripcion'];
             
             if (isset($data['descripcion'])) $prod->descripcion = $data['descripcion'];
             if (isset($data['unidad_medida'])) $prod->unidad_medida = $data['unidad_medida'];
             if (isset($data['peso_unitario'])) $prod->peso_unitario = (float)$data['peso_unitario'];
             if (isset($data['volumen_unitario'])) $prod->volumen_unitario = (float)$data['volumen_unitario'];
             if (isset($data['vida_util_dias'])) $prod->vida_util_dias = $data['vida_util_dias'] !== '' ? (int)$data['vida_util_dias'] : null;
             if (isset($data['temperatura_almacen'])) $prod->temperatura_almacen = $data['temperatura_almacen'];
             if (array_key_exists('marca_id', $data)) $prod->marca_id = $data['marca_id'] ?: null;
             if (array_key_exists('categoria_id', $data)) $prod->categoria_id = $data['categoria_id'] ?: null;
             if (array_key_exists('ambiente_id', $data)) $prod->ambiente_id = !empty($data['ambiente_id']) ? (int)$data['ambiente_id'] : null;
             if (isset($data['maneja_lotes'])) $prod->controla_lote = filter_var($data['maneja_lotes'], FILTER_VALIDATE_BOOLEAN);
             if (isset($data['controla_vencimiento'])) $prod->controla_vencimiento = filter_var($data['controla_vencimiento'], FILTER_VALIDATE_BOOLEAN);
             if (isset($data['imagen_url'])) $prod->imagen_url = $data['imagen_url'];
             if (isset($data['activo'])) $prod->activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN);
             if (isset($data['stock_minimo'])) $prod->stock_minimo = (float)$data['stock_minimo'];
             if (isset($data['unidades_caja'])) $prod->unidades_caja = (int)$data['unidades_caja'];
             if (array_key_exists('factor_udm', $data))
                 $prod->factor_udm = $data['factor_udm'] !== '' && $data['factor_udm'] !== null ? (float)$data['factor_udm'] : null;
             if (array_key_exists('unidad_contenido', $data))
                 $prod->unidad_contenido = !empty($data['unidad_contenido']) ? $data['unidad_contenido'] : null;

             $prod->save();

             // Update Main EAN (if provided during edit)
             if (!empty($data['codigo_ean'])) {
                 $principalEan = \App\Models\ProductoEan::where('producto_id', $prod->id)->where('es_principal', true)->first();
                 if ($principalEan) {
                     $principalEan->codigo_ean = $data['codigo_ean'];
                     $principalEan->save();
                 } else {
                     $eanModel = new \App\Models\ProductoEan();
                     $eanModel->producto_id = $prod->id;
                     $eanModel->codigo_ean = $data['codigo_ean'];
                     $eanModel->tipo = 'EAN13';
                     $eanModel->es_principal = true;
                     $eanModel->activo = 1;
                     $eanModel->save();
                 }
             }

             return $this->json($response, ['error' => false, 'message' => 'Producto actualizado con éxito', 'data' => $prod]);
         } catch (\Exception $e) {
             return $this->json($response, ['error' => true, 'message' => 'Error al actualizar producto: ' . $e->getMessage()], 400);
         }
     }

    /**
     * PUT /api/param/productos/{id}/toggle-status
     */
    public function toggleStatusProducto(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? 0;
        $user = $request->getAttribute('user');
        
        try {
            $prod = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
            if (!$prod) return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
            
            $prod->activo = $prod->activo ? 0 : 1;
            $prod->save();
            
            return $this->json($response, [
                'error' => false, 
                'message' => 'Estado actualizado: ' . ($prod->activo ? 'ACTIVO' : 'INACTIVO'),
                'activo' => (bool)$prod->activo
            ]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/productos/{id}
     */
    public function deleteProducto(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo administradores.'], 403);
        }

        $prod = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$prod) {
            return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
        }

        $prod->activo = false;
        $prod->save();

        return $this->json($response, ['error' => false, 'message' => 'Producto desactivado correctamente']);
    }

    /**
     * GET /api/param/productos/{id}/eans
     */
    public function getProductoEans(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        $productId = (int)$args['id'];

        // Verify the product belongs to the user's company
        $producto = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($productId);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
        }

        $eans = \App\Models\ProductoEan::where('producto_id', $productId)
            ->where('activo', true)
            ->orderBy('es_principal', 'desc')
            ->get();

        return $this->json($response, ['error' => false, 'data' => $eans]);
    }

    /**
     * POST /api/param/productos/{id}/eans
     */
    public function addProductoEan(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        $productId = (int)$args['id'];
        $data      = $request->getParsedBody();
        $eanCode   = $data['codigo_ean'] ?? '';

        $producto = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($productId);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
        }

        if (empty($eanCode)) {
            return $this->json($response, ['error' => true, 'message' => 'Código EAN es requerido'], 400);
        }

        try {
            $ean = new \App\Models\ProductoEan();
            $ean->producto_id = $productId;
            $ean->codigo_ean = $eanCode;
            $ean->tipo = $data['tipo'] ?? 'EAN13';
            $ean->es_principal = false;
            $ean->activo = true;
            $ean->save();

            return $this->json($response, ['error' => false, 'message' => 'EAN asociado correctamente', 'data' => $ean]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al guardar EAN (posible duplicado)'], 400);
        }
    }

    /**
     * PUT /api/param/productos/{id}/eans/{ean_id}
     */
    public function updateProductoEan(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        $productId = (int)$args['id'];
        $eanId     = (int)$args['ean_id'];
        $data      = $request->getParsedBody();
        $eanCode   = $data['codigo_ean'] ?? '';

        // Verify ownership via producto → empresa_id
        $producto = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($productId);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
        }

        if (empty($eanCode)) {
            return $this->json($response, ['error' => true, 'message' => 'Código EAN es requerido'], 400);
        }

        try {
            $ean = \App\Models\ProductoEan::where('producto_id', $productId)->find($eanId);
            if (!$ean) {
                return $this->json($response, ['error' => true, 'message' => 'EAN no encontrado'], 404);
            }

            $ean->codigo_ean = $eanCode;
            if (isset($data['tipo'])) $ean->tipo = $data['tipo'];
            $ean->save();

            return $this->json($response, ['error' => false, 'message' => 'EAN actualizado correctamente']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al actualizar EAN'], 400);
        }
    }

    /**
     * DELETE /api/param/productos/{id}/eans/{ean_id}
     */
    public function deleteProductoEan(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        $productId = (int)$args['id'];
        $eanId     = (int)$args['ean_id'];

        // Verify ownership via producto → empresa_id
        $producto = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($productId);
        if (!$producto) {
            return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
        }

        $ean = \App\Models\ProductoEan::where('producto_id', $productId)->find($eanId);
        if ($ean) {
            if ($ean->es_principal) {
                return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar el EAN principal'], 400);
            }
            $ean->activo = false;
            $ean->save();
        }

        return $this->json($response, ['error' => false, 'message' => 'EAN desactivado correctamente']);
    }

    /**
     * GET /api/param/personal
     */
    public function getPersonal(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $rol = $request->getQueryParams()['rol'] ?? null;
        
        // Bypass TenantScoped so Admins/SuperAdmins can see global users (sucursal_id = null)
        // and users from all sucursales within the company.
        $query = \App\Models\Personal::withoutTenantScope()
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request));
        
        if ($rol) {
            $query->where('rol', $rol);
        }
        
        $personal = $query->get();
        return $this->json($response, ['error' => false, 'data' => $personal]);
    }

    /**
     * POST /api/param/personal
     */
    public function createPersonal(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo administradores.'], 403);
        }

        $data = $request->getParsedBody();
        try {
            $p = new \App\Models\Personal();
            $p->empresa_id = $this->getEffectiveEmpresaId($user, $request);
            $p->sucursal_id = $data['sucursal_id'] ?? null;
            $p->nombre = $data['nombre'];
            $p->documento = $data['documento'];
            $p->pin = password_hash($data['pin'], PASSWORD_BCRYPT);
            $p->rol = $data['rol'] ?? 'Auxiliar';
            $p->activo = true;
            $p->save();
            return $this->json($response, ['error' => false, 'message' => 'Personal creado', 'data' => $p]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * PUT /api/param/personal/{id}
     */
    public function editPersonal(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo administradores.'], 403);
        }

        $id = $args['id'];
        $data = $request->getParsedBody();
        try {
            $p = \App\Models\Personal::withoutTenantScope()
                ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->find($id);
            if (!$p) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            if (isset($data['documento'])) $p->documento = $data['documento'];
            if (isset($data['nombre'])) $p->nombre = $data['nombre'];
            if (isset($data['sucursal_id'])) $p->sucursal_id = $data['sucursal_id'] ?: null;
            if (isset($data['rol'])) $p->rol = $data['rol'];
            if (isset($data['pin']) && !empty($data['pin'])) $p->pin = password_hash($data['pin'], PASSWORD_BCRYPT);
            if (isset($data['activo'])) $p->activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN);
            $p->save();
            return $this->json($response, ['error' => false, 'message' => 'Actualizado']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/param/ubicaciones
     * Optional query params: codigo (exact), q (partial search), tipo_ubicacion, activo
     */
    public function getUbicaciones(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $query = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('sucursal_id', $this->getEffectiveSucursalId($user, $request));

        // Exact code match (used by mobile app to resolve scanned código)
        // Normalize: remove dashes for flexible scanning
        if (!empty($params['codigo'])) {
            $codNorm = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($params['codigo']));
            // Búsqueda flexible: normaliza quitando guiones/barras y el ambiente (ej: "010201" encuentra "SECO/01-02-01")
            $query->whereRaw("REPLACE(REPLACE(UPPER(codigo), '-', ''), '/', '') LIKE ?", ["%{$codNorm}%"])
                  ->orderByRaw("CASE WHEN REPLACE(REPLACE(UPPER(codigo),'-',''),'/','') = ? THEN 0 ELSE 1 END", [$codNorm]);
        }
        // Partial search
        if (!empty($params['q'])) {
            $q = strtoupper($params['q']);
            $qNorm = preg_replace('/[^A-Za-z0-9]/', '', $q);
            $query->where(function ($w) use ($q, $qNorm) {
                $w->where('codigo', 'like', "%{$q}%")
                  ->orWhereRaw("REPLACE(REPLACE(UPPER(codigo), '-', ''), '/', '') LIKE ?", ["%{$qNorm}%"])
                  ->orWhere('zona', 'like', "%{$q}%")
                  ->orWhere('pasillo', 'like', "%{$q}%");
            });
        }
        // Type filter
        if (!empty($params['tipo_ubicacion'])) {
            $query->where('tipo_ubicacion', $params['tipo_ubicacion']);
        }
        // Active filter (default: only active)
        if (!isset($params['activo']) || $params['activo'] !== 'all') {
            $query->where('activo', true);
        }

        $ubicaciones = $query->orderBy('codigo')->get();
        return $this->json($response, ['error' => false, 'data' => $ubicaciones]);
    }

    /**
     * POST /api/param/ubicaciones
     */
    public function createUbicacion(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        try {
            $zona    = strtoupper(trim($data['zona'] ?? ''));
            $pasillo = strtoupper(trim($data['pasillo'] ?? ''));
            $modulo  = str_pad($data['modulo'] ?? '01', 2, '0', STR_PAD_LEFT);
            $nivel   = strtoupper(trim($data['nivel'] ?? ''));

            // Código = zona/pasillo-modulo-nivel (si el cliente ya lo envía, usarlo directamente)
            if (!empty($data['codigo'])) {
                $codigo = trim($data['codigo']);
            } else {
                $parts = array_filter([$pasillo, $modulo, $nivel]);
                $codigo = $zona . '/' . implode('-', $parts);
            }

            $exists = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->where('sucursal_id', $data['sucursal_id'])
                ->where('codigo', $codigo)
                ->exists();

            if ($exists) {
                return $this->json($response, ['error' => true, 'message' => "La ubicación {$codigo} ya existe en esta sucursal."], 400);
            }

            $u = new \App\Models\Ubicacion();
            $u->empresa_id      = $this->getEffectiveEmpresaId($user, $request);
            $u->sucursal_id     = $data['sucursal_id'];
            $u->zona            = $data['zona'];
            $u->pasillo         = $data['pasillo'];
            $u->modulo          = $data['modulo'] ?? '01';
            $u->nivel           = $data['nivel'];
            $u->codigo          = $codigo;
            $u->posicion        = $data['posicion'] ?? null;
            $u->tipo_ubicacion  = $data['tipo_ubicacion'] ?? 'Almacenamiento';
            $u->capacidad_maxima = (int)($data['capacidad_maxima'] ?? 0);
            $u->activo          = 1;
            $u->save();

            return $this->json($response, ['error' => false, 'message' => 'Ubicación creada: ' . $u->codigo, 'data' => $u]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * PUT /api/param/ubicaciones/{id}
     */
    public function editUbicacion(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        $data = $request->getParsedBody();
        try {
            $u = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
            if (!$u) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            
            if (isset($data['zona'])) $u->zona = $data['zona'];
            if (isset($data['pasillo'])) $u->pasillo = $data['pasillo'];
            if (isset($data['modulo'])) $u->modulo = $data['modulo'];
            if (isset($data['nivel'])) $u->nivel = $data['nivel'];

            // Update code if fragments changed: WP/EX/PASILLO-MODULO-NIVEL
            $mStr = str_pad($u->modulo, 2, '0', STR_PAD_LEFT);
            $u->codigo = "WP/EX/" . strtoupper($u->pasillo) . "-" . $mStr . "-" . strtoupper($u->nivel);

            if (isset($data['posicion'])) $u->posicion = $data['posicion'];
            if (isset($data['tipo_ubicacion'])) $u->tipo_ubicacion = $data['tipo_ubicacion'];
            if (isset($data['capacidad_maxima'])) $u->capacidad_maxima = $data['capacidad_maxima'];
            if (isset($data['activo'])) $u->activo = (int)$data['activo'] === 1 ? 1 : 0;
            $u->save();
            return $this->json($response, ['error' => false, 'message' => 'Actualizada: ' . $u->codigo]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * PATCH /api/param/ubicaciones/{id}/toggle
     */
    public function toggleStatusUbicacion(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        try {
            $u = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
            if (!$u) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            
            $u->activo = $u->activo ? 0 : 1;
            $u->save();
            
            $estado = $u->activo ? 'Activada' : 'Bloqueada';
            return $this->json($response, ['error' => false, 'message' => "Ubicación {$u->codigo} {$estado}", 'activo' => $u->activo]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/param/proveedores
     */
    public function getProveedores(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $provs = \App\Models\Proveedor::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->get();
        return $this->json($response, ['error' => false, 'data' => $provs]);
    }

    /**
     * POST /api/param/proveedores
     */
    public function createProveedor(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $nit = trim($data['nit'] ?? '');
        $razonSocial = trim($data['razon_social'] ?? '');
        if (empty($nit)) {
            return $this->json($response, ['error' => true, 'message' => 'El NIT del proveedor es requerido.'], 400);
        }
        if (empty($razonSocial)) {
            return $this->json($response, ['error' => true, 'message' => 'La razón social del proveedor es requerida.'], 400);
        }
        try {
            $p = new \App\Models\Proveedor();
            $p->empresa_id = $this->getEffectiveEmpresaId($user, $request);
            $p->nit = $nit;
            $p->razon_social = $razonSocial;
            $p->telefono = $data['telefono'] ?? null;
            $p->email = $data['email'] ?? null;
            $p->contacto_nombre = $data['contacto_nombre'] ?? null;
            $p->activo = 1;
            $p->save();
            return $this->json($response, ['error' => false, 'message' => 'Proveedor creado', 'data' => $p]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * PUT /api/param/proveedores/{id}
     */
    public function editProveedor(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        $data = $request->getParsedBody();
        try {
            $p = \App\Models\Proveedor::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
            if (!$p) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            if (isset($data['nit'])) $p->nit = $data['nit'];
            if (isset($data['razon_social'])) $p->razon_social = $data['razon_social'];
            if (isset($data['telefono'])) $p->telefono = $data['telefono'];
            if (isset($data['email'])) $p->email = $data['email'];
            if (isset($data['contacto_nombre'])) $p->contacto_nombre = $data['contacto_nombre'];
            if (isset($data['activo'])) $p->activo = $data['activo'] ? 1 : 0;
            $p->save();
            return $this->json($response, ['error' => false, 'message' => 'Actualizado']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/param/roles
     */
    public function getRoles(Request $request, Response $response): Response
    {
        $roles = [
            ['id' => 'Admin', 'nombre' => 'Administrador'],
            ['id' => 'Supervisor', 'nombre' => 'Supervisor / Jefe'],
            ['id' => 'Auxiliar', 'nombre' => 'Auxiliar de Bodega'],
            ['id' => 'Montacarguista', 'nombre' => 'Operador Montacargas'],
            ['id' => 'Analista', 'nombre' => 'Analista de Inventario'],
            ['id' => 'Conductor', 'nombre' => 'Conductor / Distribuidor']
        ];
        return $this->json($response, ['error' => false, 'data' => $roles]);
    }

    /**
     * GET /api/param/permisos-matriz/{rol}
     */
    public function getPermissionsMatrix(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $rol = $args['rol'];

        // 1. Obtener todos los permisos definidos en el sistema
        $allPermisos = \App\Models\Permiso::orderBy('modulo')->orderBy('accion')->get();

        // 2. Obtener los permisos concedidos para este rol en esta empresa
        $grantedIds = \App\Models\RolPermiso::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('rol', $rol)
            ->where('concedido', true)
            ->pluck('permiso_id')
            ->toArray();

        // 3. Cruzar los datos para la matriz
        $matrix = $allPermisos->map(function($p) use ($grantedIds) {
            return [
                'id' => $p->id,
                'modulo' => $p->modulo,
                'accion' => $p->accion,
                'descripcion' => $p->descripcion,
                'concedido' => in_array($p->id, $grantedIds)
            ];
        });

        return $this->json($response, ['error' => false, 'data' => $matrix]);
    }

    /**
     * POST /api/param/permisos-toggle
     */
    public function togglePermission(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Solo administradores pueden cambiar permisos'], 403);
        }

        $data = $request->getParsedBody();
        $rol = $data['rol'] ?? '';
        $permisoId = $data['permiso_id'] ?? 0;
        $concedido = $data['concedido'] ?? false;

        try {
            $rp = \App\Models\RolPermiso::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->where('rol', $rol)
                ->where('permiso_id', $permisoId)
                ->first();

            if (!$rp) {
                $rp = new \App\Models\RolPermiso();
                $rp->empresa_id = $this->getEffectiveEmpresaId($user, $request);
                $rp->rol = $rol;
                $rp->permiso_id = $permisoId;
            }

            $rp->concedido = $concedido ? 1 : 0;
            $rp->save();

            return $this->json($response, ['error' => false, 'message' => 'Permiso actualizado']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/param/rutas
     */
    public function getRutas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $rutas = Ruta::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->orderBy('nombre')->get();
        return $this->json($response, ['error' => false, 'data' => $rutas]);
    }

    /**
     * POST /api/param/rutas
     */
    public function createRuta(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $data   = $request->getParsedBody();
        $nombre = trim($data['nombre'] ?? '');

        if (empty($nombre)) {
            return $this->json($response, ['error' => true, 'message' => 'El nombre de la ruta es requerido.'], 400);
        }
        try {
            $ruta            = new Ruta();
            $ruta->empresa_id = $this->getEffectiveEmpresaId($user, $request);
            $ruta->nombre    = $nombre;
            $ruta->comercial = trim($data['comercial'] ?? '');
            $ruta->activo    = 1;
            $ruta->save();
            return $this->json($response, ['error' => false, 'message' => 'Ruta creada', 'data' => $ruta], 201);
        } catch (\Exception $e) {
            error_log('createRuta error: ' . $e->getMessage());
            return $this->json($response, ['error' => true, 'message' => 'Error al crear ruta.'], 500);
        }
    }

    private function _buildFrecuenciaLabel(array $data): string
    {
        $tipo   = $data['frecuencia_tipo'] ?? 'Diario';
        $config = json_decode($data['frecuencia_config'] ?? '{}', true) ?: [];

        if ($tipo === 'Diario') {
            $dias  = $config['dias'] ?? [];
            $abrev = ['Lunes'=>'L','Martes'=>'M','Miércoles'=>'Mié','Jueves'=>'J','Viernes'=>'V','Sábado'=>'S','Domingo'=>'D'];
            $label = implode(', ', array_map(fn($d) => $abrev[$d] ?? $d, $dias));
            return $label ? "Diario: {$label}" : 'Diario';
        }
        $sub = $config['subtipo'] ?? '';
        return match($sub) {
            'Diario'    => 'Parcial - Diario',
            'Semanal'   => 'Semanal - ' . ($config['dia'] ?? ''),
            'Quincenal' => 'Quincenal',
            'Mensual'   => 'Mensual - día ' . ($config['dia_mes'] ?? ''),
            default     => 'Parcial',
        };
    }

    /**
     * PUT /api/param/rutas/{id}
     */
    public function updateRuta(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = $args['id'];
        $ruta = Ruta::where('id', $id)->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->first();
        if (!$ruta) return $this->json($response, ['error' => true, 'message' => 'Ruta no encontrada'], 404);

        $data = $request->getParsedBody();
        if (isset($data['nombre']))    $ruta->nombre    = $data['nombre'];
        if (isset($data['comercial'])) $ruta->comercial = $data['comercial'];
        if (isset($data['activo']))    $ruta->activo    = $data['activo'] ? 1 : 0;
        $ruta->save();

        return $this->json($response, ['error' => false, 'message' => 'Ruta actualizada', 'data' => $ruta]);
    }


    // ── DELETE /api/param/sucursales/{id} ────────────────────────────────────
    public function deleteSucursal(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        $s = \App\Models\Sucursal::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$s) return $this->json($response, ['error' => true, 'message' => 'No encontrada'], 404);
        $s->activo = 0; $s->save();
        return $this->json($response, ['error' => false, 'message' => 'Sucursal desactivada']);
    }

    // ── DELETE /api/param/personal/{id} ─────────────────────────────────────
    public function deletePersonal(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        }

        $p = \App\Models\Personal::withoutTenantScope()
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($args['id']);
        if (!$p) {
            return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
        }

        if ($p->isSuperAdmin()) {
            return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar al usuario SuperAdmin global.'], 403);
        }

        if ($user->id == $p->id) {
            return $this->json($response, ['error' => true, 'message' => 'No puede eliminarse a sí mismo.'], 400);
        }

        try {
            \App\Models\PersonalPermiso::where('personal_id', $p->id)->delete();
            \App\Models\Notificacion::where('personal_id', $p->id)->delete();
            $p->delete();
            return $this->json($response, ['error' => false, 'message' => 'Usuario eliminado permanentemente de la base de datos.']);
        } catch (\Exception $e) {
            return $this->json($response, [
                'error' => true,
                'message' => 'No se puede eliminar porque el usuario tiene registros asociados (recepciones, picking, inventarios, etc.). Puede desactivarlo en su lugar.'
            ], 400);
        }
    }

    // ── DELETE /api/param/ubicaciones/{id} ──────────────────────────────────
    public function deleteUbicacion(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        $u = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$u) return $this->json($response, ['error' => true, 'message' => 'No encontrada'], 404);
        $u->activo = false; $u->save();
        return $this->json($response, ['error' => false, 'message' => 'Ubicación desactivada']);
    }

    /**
     * GET /api/param/zonas
     */
    public function getZonas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $zonas = \App\Models\Zona::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                                 ->orderBy('codigo')
                                 ->get();
        return $this->json($response, ['error' => false, 'data' => $zonas]);
    }

    /**
     * POST /api/param/zonas
     */
    public function createZona(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];
        $codigo = strtoupper(trim($data['codigo'] ?? ''));
        if ($codigo === '') {
            return $this->json($response, ['error' => true, 'message' => 'El código de la zona es obligatorio'], 400);
        }
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        if (\App\Models\Zona::where('empresa_id', $empresaId)->where('codigo', $codigo)->exists()) {
            return $this->json($response, ['error' => true, 'message' => "Ya existe una zona con el código '{$codigo}'"], 409);
        }
        try {
            $z = new \App\Models\Zona();
            $z->empresa_id = $empresaId;
            $z->codigo     = $codigo;
            $z->descripcion = $data['descripcion'] ?? null;
            $z->save();
            return $this->json($response, ['error' => false, 'message' => 'Zona creada: ' . $z->codigo, 'data' => $z]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al guardar la zona: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/param/zonas/{id}
     */
    public function editZona(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = $args['id'];
        $data = $request->getParsedBody() ?? [];
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $z = \App\Models\Zona::where('empresa_id', $empresaId)->find($id);
        if (!$z) return $this->json($response, ['error' => true, 'message' => 'No encontrada'], 404);
        if (isset($data['codigo'])) {
            $nuevoCodigo = strtoupper(trim($data['codigo']));
            if ($nuevoCodigo === '') {
                return $this->json($response, ['error' => true, 'message' => 'El código no puede estar vacío'], 400);
            }
            if ($nuevoCodigo !== $z->codigo && \App\Models\Zona::where('empresa_id', $empresaId)->where('codigo', $nuevoCodigo)->exists()) {
                return $this->json($response, ['error' => true, 'message' => "Ya existe una zona con el código '{$nuevoCodigo}'"], 409);
            }
            $z->codigo = $nuevoCodigo;
        }
        if (array_key_exists('descripcion', $data)) $z->descripcion = $data['descripcion'];
        try {
            $z->save();
            return $this->json($response, ['error' => false, 'message' => 'Zona actualizada: ' . $z->codigo]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al actualizar la zona: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/zonas/{id}
     */
    public function deleteZona(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        $z = \App\Models\Zona::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$z) return $this->json($response, ['error' => true, 'message' => 'No encontrada'], 404);

        // Check if zone is being used by locations
        $ubicacionesCount = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                                                ->where('zona', $z->codigo)
                                                ->count();
        if ($ubicacionesCount > 0) {
            return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar: ' . $ubicacionesCount . ' ubicaciones usan esta zona'], 400);
        }

        $z->delete();
        return $this->json($response, ['error' => false, 'message' => 'Zona eliminada']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ── AMBIENTES (CRUD) ─────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════

    public function getAmbientes(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $ambientes = \App\Models\Ambiente::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                                         ->withCount('productos')
                                         ->orderBy('codigo')
                                         ->get();
        return $this->json($response, ['error' => false, 'data' => $ambientes]);
    }

    public function createAmbiente(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];
        $codigo = strtoupper(trim($data['codigo'] ?? ''));
        if ($codigo === '') {
            return $this->json($response, ['error' => true, 'message' => 'El código del ambiente es obligatorio'], 400);
        }
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        if (\App\Models\Ambiente::where('empresa_id', $empresaId)->where('codigo', $codigo)->exists()) {
            return $this->json($response, ['error' => true, 'message' => "Ya existe un ambiente con el código '{$codigo}'"], 409);
        }
        try {
            $a = new \App\Models\Ambiente();
            $a->empresa_id  = $empresaId;
            $a->codigo      = $codigo;
            $a->descripcion = $data['descripcion'] ?? null;
            $a->icono       = $data['icono'] ?? null;
            $a->color       = $data['color'] ?? null;
            $a->activo      = true;
            $a->save();
            return $this->json($response, ['error' => false, 'message' => 'Ambiente creado: ' . $a->codigo, 'data' => $a]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function editAmbiente(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $a = \App\Models\Ambiente::where('empresa_id', $empresaId)->find($args['id']);
        if (!$a) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);

        $data = $request->getParsedBody() ?? [];
        if (isset($data['codigo'])) {
            $nuevoCodigo = strtoupper(trim($data['codigo']));
            if ($nuevoCodigo === '') return $this->json($response, ['error' => true, 'message' => 'El código no puede estar vacío'], 400);
            if ($nuevoCodigo !== $a->codigo && \App\Models\Ambiente::where('empresa_id', $empresaId)->where('codigo', $nuevoCodigo)->exists()) {
                return $this->json($response, ['error' => true, 'message' => "Ya existe un ambiente con código '{$nuevoCodigo}'"], 409);
            }
            $a->codigo = $nuevoCodigo;
        }
        if (array_key_exists('descripcion', $data)) $a->descripcion = $data['descripcion'];
        if (array_key_exists('icono', $data)) $a->icono = $data['icono'];
        if (array_key_exists('color', $data)) $a->color = $data['color'];
        if (isset($data['activo'])) $a->activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN);

        try {
            $a->save();
            return $this->json($response, ['error' => false, 'message' => 'Ambiente actualizado', 'data' => $a]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function deleteAmbiente(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $a = \App\Models\Ambiente::where('empresa_id', $empresaId)->find($args['id']);
        if (!$a) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);

        $productosCount = \App\Models\Producto::where('empresa_id', $empresaId)
                                              ->where('ambiente_id', $a->id)
                                              ->count();
        if ($productosCount > 0) {
            return $this->json($response, ['error' => true, 'message' => "No se puede eliminar: {$productosCount} productos usan este ambiente"], 400);
        }

        $a->delete();
        return $this->json($response, ['error' => false, 'message' => 'Ambiente eliminado']);
    }

    // ── DELETE /api/param/proveedores/{id} ──────────────────────────────────
    public function deleteProveedor(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) return $this->json($response, ['error' => true, 'message' => 'Acceso denegado'], 403);
        $p = \App\Models\Proveedor::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($args['id']);
        if (!$p) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
        $p->activo = 0; $p->save();
        return $this->json($response, ['error' => false, 'message' => 'Proveedor desactivado']);
    }

    /**
     * GET /api/param/proveedores/{id}/performance
     * Obtiene KPIs y calificación de un proveedor basada en su historial de entregas y citas
     */
    public function getProveedorPerformance(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $proveedorId = $args['id'] ?? null;

        $proveedor = \App\Models\Proveedor::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->find($proveedorId);

        if (!$proveedor) {
            return $this->json($response, ['error' => true, 'message' => 'Proveedor no encontrado'], 404);
        }

        // 1. Cálculo de cumplimiento de ODCs (Órdenes de Compra)
        $totalOdc = \App\Models\OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('proveedor_id', $proveedor->id)
            ->count();
        $odcCompletadas = \App\Models\OrdenCompra::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('proveedor_id', $proveedor->id)
            ->where('estado', 'Cerrada')
            ->count();
        $pctCumplimientoOdc = $totalOdc > 0 ? round(($odcCompletadas / $totalOdc) * 100, 1) : 0;

        // 2. Cálculo de cumplimiento de Citas (YMS - Yard Management System)
        $totalCitas = \App\Models\Cita::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('proveedor', $proveedor->razon_social)
            ->count();
        $citasCompletadas = \App\Models\Cita::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('proveedor', $proveedor->razon_social)
            ->where('estado', 'Completada')
            ->count();
        $pctCumplimientoCitas = $totalCitas > 0 ? round(($citasCompletadas / $totalCitas) * 100, 1) : 0;

        // 3. Cálculo de calidad (receptions en buen estado vs problemas)
        $totalLineasRecepcion = Capsule::table('recepcion_detalles as rd')
            ->join('recepciones as r', 'rd.recepcion_id', '=', 'r.id')
            ->where('r.empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->whereHas('cita', function($q) use ($proveedor) {
                $q->where('proveedor', $proveedor->razon_social);
            })
            ->count() ?? 0;
        $lineasBuenEstado = Capsule::table('recepcion_detalles as rd')
            ->join('recepciones as r', 'rd.recepcion_id', '=', 'r.id')
            ->where('r.empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('rd.estado', 'BuenEstado')
            ->count() ?? 0;
        $pctCalidad = $totalLineasRecepcion > 0 ? round(($lineasBuenEstado / $totalLineasRecepcion) * 100, 1) : 0;

        // 4. Evaluaciones directas de citas (1-10 scale)
        $evaluaciones = \App\Models\Cita::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('proveedor', $proveedor->razon_social)
            ->where('estado', 'Completada')
            ->whereNotNull('evaluacion_proveedor')
            ->get();
        $promedioEvaluacion = $evaluaciones->count() > 0 ? round($evaluaciones->avg('evaluacion_proveedor'), 1) : 0;

        // 5. Cálculo de índice combinado (weighted scoring)
        $indiceDesempeno = round(
            ($pctCumplimientoOdc * 0.40) +  // 40% cumplimiento ODC
            ($pctCumplimientoCitas * 0.30) + // 30% cumplimiento citas
            ($pctCalidad * 0.20) +            // 20% calidad
            (($promedioEvaluacion / 10) * 100 * 0.10), // 10% evaluación YMS
            1
        );

        // 6. Clasificación por desempeño (A/B/C)
        $clasificacion = 'C'; // Riesgo
        if ($indiceDesempeno >= 95) $clasificacion = 'A'; // Excelente
        elseif ($indiceDesempeno >= 80) $clasificacion = 'B'; // Buen desempeño
        elseif ($indiceDesempeno >= 60) $clasificacion = 'C'; // Requiere seguimiento

        // 7. Recolectar tendencia últimos 30 días
        $hace30Dias = date('Y-m-d', strtotime('-30 days'));
        $tendencia30d = Capsule::table('citas')
            ->select(
                Capsule::raw('DATE(created_at) as fecha'),
                Capsule::raw('COUNT(*) as total_citas'),
                Capsule::raw('SUM(CASE WHEN estado = "Completada" THEN 1 ELSE 0 END) as citas_completadas'),
                Capsule::raw('AVG(evaluacion_proveedor) as eval_promedio')
            )
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('proveedor', $proveedor->razon_social)
            ->whereDate('created_at', '>=', $hace30Dias)
            ->groupBy('fecha')
            ->orderBy('fecha', 'asc')
            ->get();

        return $this->json($response, [
            'error' => false,
            'proveedor' => [
                'id' => $proveedor->id,
                'nit' => $proveedor->nit,
                'razon_social' => $proveedor->razon_social,
                'contacto' => $proveedor->contacto_nombre,
                'email' => $proveedor->email,
                'telefono' => $proveedor->telefono,
            ],
            'kpis' => [
                'cumplimiento_odc_pct' => $pctCumplimientoOdc,
                'cumplimiento_citas_pct' => $pctCumplimientoCitas,
                'calidad_aceptacion_pct' => $pctCalidad,
                'evaluacion_promedio_pts' => $promedioEvaluacion,
                'indice_desempeno_pct' => $indiceDesempeno,
                'clasificacion' => $clasificacion,
            ],
            'volumen' => [
                'odc_totales' => $totalOdc,
                'odc_completadas' => $odcCompletadas,
                'citas_totales' => $totalCitas,
                'citas_completadas' => $citasCompletadas,
                'lineas_recepcion_totales' => $totalLineasRecepcion,
                'lineas_buen_estado' => $lineasBuenEstado,
            ],
            'tendencia_30_dias' => $tendencia30d->map(function($item) {
                return [
                    'fecha' => $item->fecha,
                    'citas' => (int)$item->total_citas,
                    'completadas' => (int)$item->citas_completadas,
                    'evaluacion' => $item->eval_promedio ? round($item->eval_promedio, 1) : null,
                ];
            })->values(),
        ]);
    }

    // ── CLIENTES ────────────────────────────────────────────────────────────
    /**
     * GET /api/param/clientes
     */
    public function getClientes(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        try {
            $clientes = Cliente::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->where('activo', 1)
                ->with('ruta')
                ->get();
            return $this->json($response, ['error' => false, 'data' => $clientes]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/param/clientes
     */
    public function createCliente(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        try {
            if (empty($data['nit']) || empty($data['razon_social'])) {
                return $this->json($response, ['error' => true, 'message' => 'NIT y Razón Social requeridos'], 400);
            }
            $empId = $this->getEffectiveEmpresaId($user, $request);
            if (Cliente::where('empresa_id', $empId)->where('nit', $data['nit'])->exists()) {
                return $this->json($response, ['error' => true, 'message' => 'Ya existe un cliente con este NIT'], 409);
            }

            $c = new Cliente();
            $c->empresa_id      = $empId;
            $c->nit             = trim($data['nit']);
            $c->razon_social    = trim($data['razon_social']);
            $c->ciudad          = $data['ciudad'] ?? null;
            $c->direccion       = $data['direccion'] ?? null;
            $c->telefono        = $data['telefono'] ?? null;
            $c->email           = $data['email'] ?? null;
            $c->contacto_nombre = $data['contacto_nombre'] ?? null;
            $c->ruta_id          = !empty($data['ruta_id']) ? (int)$data['ruta_id'] : null;
            $c->horario          = $data['horario'] ?? null;
            $c->latitud          = isset($data['latitud'])  && $data['latitud']  !== '' ? (float)$data['latitud']  : null;
            $c->longitud         = isset($data['longitud']) && $data['longitud'] !== '' ? (float)$data['longitud'] : null;
            $c->frecuencia_tipo  = $data['frecuencia_tipo']   ?? 'Diario';
            $c->frecuencia_config= $data['frecuencia_config'] ?? null;
            $c->frecuencia       = $this->_buildFrecuenciaLabel($data);
            $c->activo = 1;
            $c->save();

            return $this->json($response, ['error' => false, 'message' => 'Cliente creado', 'data' => $c->load('ruta')], 201);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/param/clientes/{id}
     */
    public function updateCliente(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = $args['id'];
        $data = $request->getParsedBody();
        try {
            $cliente = \App\Models\Cliente::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->findOrFail($id);
            if (isset($data['nit']))             $cliente->nit             = trim($data['nit']);
            if (isset($data['razon_social']))    $cliente->razon_social    = trim($data['razon_social']);
            if (isset($data['ciudad']))          $cliente->ciudad          = $data['ciudad'];
            if (isset($data['direccion']))       $cliente->direccion       = $data['direccion'];
            if (isset($data['telefono']))        $cliente->telefono        = $data['telefono'];
            if (isset($data['email']))           $cliente->email           = $data['email'];
            if (isset($data['contacto_nombre'])) $cliente->contacto_nombre = $data['contacto_nombre'];
            if (isset($data['ruta_id']))          $cliente->ruta_id          = !empty($data['ruta_id']) ? (int)$data['ruta_id'] : $cliente->ruta_id;
            if (isset($data['horario']))          $cliente->horario          = $data['horario'];
            if (array_key_exists('latitud', $data))   $cliente->latitud  = $data['latitud']  !== '' ? (float)$data['latitud']  : null;
            if (array_key_exists('longitud', $data))  $cliente->longitud = $data['longitud'] !== '' ? (float)$data['longitud'] : null;
            if (isset($data['frecuencia_tipo']))  $cliente->frecuencia_tipo  = $data['frecuencia_tipo'];
            if (isset($data['frecuencia_config'])) $cliente->frecuencia_config = $data['frecuencia_config'];
            if (isset($data['frecuencia_tipo']))  $cliente->frecuencia = $this->_buildFrecuenciaLabel($data);
            if (isset($data['activo']))           $cliente->activo = (bool)$data['activo'];
            $cliente->save();
            return $this->json($response, ['error' => false, 'message' => 'Cliente actualizado', 'data' => $cliente->load('ruta')]);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/clientes/{id}
     */
    public function deleteCliente(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = $args['id'];
        try {
            $cliente = \App\Models\Cliente::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->findOrFail($id);
            $cliente->delete();
            return $this->json($response, ['error' => false, 'message' => 'Cliente eliminado']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/rutas/{id}
     */
    public function deleteRuta(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id   = $args['id'];
        try {
            $ruta = \App\Models\Ruta::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->findOrFail($id);
            $ruta->delete();
            return $this->json($response, ['error' => false, 'message' => 'Ruta eliminada']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/param/productos/{id}/fotos
     */
    public function uploadProductoFotos(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        try {
            $prod = \App\Models\Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->find($id);
            if (!$prod) return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);

            $files = $request->getUploadedFiles();
            $uploadedFotos = [];
            $uploadPath = dirname(__DIR__, 2) . '/public/uploads/productos/';

            // Iterate over all uploaded files (supports 'fotos[]' array or single 'foto')
            $fotosData = isset($files['fotos']) ? (is_array($files['fotos']) ? $files['fotos'] : [$files['fotos']]) : [];
            if (isset($files['foto'])) {
                $fotosData[] = $files['foto'];
            }

            foreach ($fotosData as $file) {
                if ($file && $file->getError() === UPLOAD_ERR_OK) {
                    $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
                    $filename = sprintf('%d_%s.%s', $prod->id, uniqid(), $ext);
                    $file->moveTo($uploadPath . $filename);

                    $foto = new \App\Models\ProductoFoto();
                    $foto->producto_id = $prod->id;
                    $foto->url = '/uploads/productos/' . $filename;
                    $foto->save();

                    $uploadedFotos[] = $foto;
                }
            }

            return $this->json($response, ['error' => false, 'message' => 'Fotos subidas con éxito', 'data' => $uploadedFotos], 201);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al subir fotos: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/param/productos/fotos/{foto_id}
     */
    public function deleteProductoFoto(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $fotoId = $args['foto_id'];
        try {
            $foto = \App\Models\ProductoFoto::with('producto')->find($fotoId);
            if (!$foto || $foto->producto->empresa_id !== $this->getEffectiveEmpresaId($user, $request)) {
                return $this->json($response, ['error' => true, 'message' => 'Foto no encontrada o acceso denegado'], 404);
            }

            $filePath = dirname(__DIR__, 2) . '/public' . $foto->url;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $foto->delete();
            return $this->json($response, ['error' => false, 'message' => 'Foto eliminada con éxito']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error al eliminar foto: ' . $e->getMessage()], 500);
        }
    }
}