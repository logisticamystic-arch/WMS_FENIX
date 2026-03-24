<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Cliente;
use App\Models\Ruta;
use Illuminate\Database\Capsule\Manager as Capsule;

class ParametrosController
{
    /**
     * GET /api/param/empresas
     */
    public function getEmpresas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        
        // 1. Obtener datos (sin validación estricta para testing)
        $empresas = \App\Models\Empresa::where('activo', 1)->get();

        return $this->json($response, ['data' => $empresas]);
    }

    /**
     * POST /api/param/empresas
     */
    public function createEmpresa(Request $request, Response $response): Response
    {
         $user = $request->getAttribute('user');
         $data = $request->getParsedBody();
         
         $empresa = new Empresa();
         $empresa->nit = $data['nit'] ?? '';
         $empresa->razon_social = $data['razon_social'] ?? '';
         $empresa->direccion = $data['direccion'] ?? '';
         $empresa->telefono = $data['telefono'] ?? '';
         $empresa->activo = 1;
         $empresa->save();

         return $this->json($response, ['error' => false, 'message' => 'Empresa creada con éxito', 'data' => $empresa]);
    }

     /**
      * GET /api/param/sucursales
      */
     public function getSucursales(Request $request, Response $response): Response
     {
         $user = $request->getAttribute('user');
         try {
             $sucursales = \App\Models\Sucursal::where('empresa_id', $user->empresa_id)->get();
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
         
         try {
             $suc = new \App\Models\Sucursal();
             $suc->empresa_id = $user->empresa_id;
             $suc->codigo = $data['codigo'];
             $suc->nombre = $data['nombre'];
             $suc->direccion = $data['direccion'] ?? null;
             $suc->ciudad = $data['ciudad'] ?? null;
             $suc->telefono = $data['telefono'] ?? null;
             $suc->tipo = $data['tipo'] ?? 'Bodega';
             $suc->activo = 1;
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
             $suc = \App\Models\Sucursal::where('empresa_id', $user->empresa_id)->find($id);
             if (!$suc) return $this->json($response, ['error' => true, 'message' => 'Sucursal no encontrada'], 404);

             if (isset($data['codigo'])) $suc->codigo = $data['codigo'];
             if (isset($data['nombre'])) $suc->nombre = $data['nombre'];
             if (isset($data['direccion'])) $suc->direccion = $data['direccion'];
             if (isset($data['ciudad'])) $suc->ciudad = $data['ciudad'];
             if (isset($data['telefono'])) $suc->telefono = $data['telefono'];
             if (isset($data['tipo'])) $suc->tipo = $data['tipo'];
             if (isset($data['activo'])) $suc->activo = $data['activo'] ? 1 : 0;
             $suc->save();

             return $this->json($response, ['error' => false, 'message' => 'Sucursal actualizada', 'data' => $suc]);
         } catch (\Exception $e) {
             return $this->json($response, ['error' => true, 'message' => 'Error al actualizar'], 400);
         }
     }

    /**
     * GET /api/param/marcas
     */
    public function getMarcas(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $marcas = \App\Models\Marca::where('empresa_id', $user->empresa_id)
                    ->where('activo', 1)->get();

        return $this->json($response, ['error' => false, 'data' => $marcas]);
    }

    /**
     * POST /api/param/marcas
     */
    public function createMarca(Request $request, Response $response): Response
    {
         $user = $request->getAttribute('user');
         $data = $request->getParsedBody();
         
         $marca = new \App\Models\Marca();
         $marca->empresa_id = $user->empresa_id;
         $marca->nombre = $data['nombre'] ?? '';
         $marca->activo = 1;
         $marca->save();

         return $this->json($response, ['error' => false, 'message' => 'Marca creada con éxito', 'data' => $marca]);
    }

    /**
     * GET /api/param/productos
     */
    public function getProductos(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $productos = \App\Models\Producto::where('empresa_id', $user->empresa_id)
            ->with(['marca', 'categoria', 'eans' => fn($q) => $q->where('activo', 1)])
            ->where('activo', 1)->get();

        return $this->json($response, ['error' => false, 'data' => $productos]);
    }

    /**
     * GET /api/param/productos/buscar?q=
     */
    public function buscarProductos(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $q = trim($request->getQueryParams()['q'] ?? '');

        if (strlen($q) < 2) {
            return $this->json($response, ['error' => false, 'data' => []]);
        }

        $productos = \App\Models\Producto::where('empresa_id', $user->empresa_id)
            ->where('activo', 1)
            ->where(function ($query) use ($q) {
                $query->where('nombre', 'LIKE', "%{$q}%")
                    ->orWhere('codigo_interno', 'LIKE', "%{$q}%")
                    ->orWhere('descripcion', 'LIKE', "%{$q}%")
                    ->orWhereHas('eans', fn($eq) => $eq->where('codigo_ean', 'LIKE', "%{$q}%")->where('activo', 1));
            })
            ->with(['eans' => fn($q) => $q->where('activo', 1), 'marca', 'categoria'])
            ->limit(20)
            ->get();

        return $this->json($response, ['error' => false, 'data' => $productos]);
    }

    /**
     * GET /api/param/categorias
     */
    public function getCategorias(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $cats = \App\Models\CategoriaProducto::where('empresa_id', $user->empresa_id)
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
            'empresa_id'               => $user->empresa_id,
            'nombre'                   => $data['nombre'],
            'descripcion'              => $data['descripcion'] ?? null,
            'requiere_foto_vencimiento'=> isset($data['requiere_foto_vencimiento']) && $data['requiere_foto_vencimiento'] ? 1 : 0,
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
        $cat = \App\Models\CategoriaProducto::where('empresa_id', $user->empresa_id)->find($args['id']);
        if (!$cat) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
        $data = $request->getParsedBody();
        if (!empty($data['nombre'])) $cat->nombre = $data['nombre'];
        if (array_key_exists('descripcion', $data)) $cat->descripcion = $data['descripcion'];
        if (array_key_exists('requiere_foto_vencimiento', $data)) {
            $cat->requiere_foto_vencimiento = $data['requiere_foto_vencimiento'] ? 1 : 0;
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
        $cat = \App\Models\CategoriaProducto::where('empresa_id', $user->empresa_id)->find($args['id']);
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
         $data = $request->getParsedBody();
         
         // Mapeamos codigo_ean a su propia tabla, no a codigo_interno
         // Si la UI no envía codigo_interno, usamos el EAN como interno provisionalmente
         $ean = $data['codigo_ean'] ?? '';
         $interno = $data['codigo_interno'] ?? $ean;

         Capsule::beginTransaction();
         try {
             $prod = new \App\Models\Producto();
             $prod->empresa_id = $user->empresa_id;
             $prod->codigo_interno = $interno;
             $prod->nombre = $data['nombre'] ?? '';
             $prod->descripcion = $data['descripcion'] ?? null;
             $prod->unidad_medida = $data['unidad_medida'] ?? 'UN';
             $prod->peso_unitario = isset($data['peso_unitario']) ? (float)$data['peso_unitario'] : 0;
             $prod->volumen_unitario = isset($data['volumen_unitario']) ? (float)$data['volumen_unitario'] : 0;
             $prod->vida_util_dias = isset($data['vida_util_dias']) && $data['vida_util_dias'] !== '' ? (int)$data['vida_util_dias'] : null;
             $prod->temperatura_almacen = $data['temperatura_almacen'] ?? null;
             $prod->marca_id = $data['marca_id'] ?? null;
             $prod->categoria_id = $data['categoria_id'] ?? null;
             $prod->controla_lote = isset($data['maneja_lotes']) && $data['maneja_lotes'] ? 1 : 0;
             $prod->controla_vencimiento = isset($data['controla_vencimiento']) && $data['controla_vencimiento'] ? 1 : 0;
             $prod->imagen_url = $data['imagen_url'] ?? null;
             $prod->activo = 1;
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
      * PUT /api/param/productos/{id}
      */
     public function editProducto(Request $request, Response $response, array $args): Response
     {
         $user = $request->getAttribute('user');
         $productId = $args['id'];
         $data = $request->getParsedBody();
         
         try {
             $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)->find($productId);
             if (!$prod) {
                 return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
             }

             if (isset($data['codigo_interno'])) $prod->codigo_interno = $data['codigo_interno'];
             if (isset($data['nombre'])) $prod->nombre = $data['nombre'];
             if (isset($data['descripcion'])) $prod->descripcion = $data['descripcion'];
             if (isset($data['unidad_medida'])) $prod->unidad_medida = $data['unidad_medida'];
             if (isset($data['peso_unitario'])) $prod->peso_unitario = (float)$data['peso_unitario'];
             if (isset($data['volumen_unitario'])) $prod->volumen_unitario = (float)$data['volumen_unitario'];
             if (isset($data['vida_util_dias'])) $prod->vida_util_dias = $data['vida_util_dias'] !== '' ? (int)$data['vida_util_dias'] : null;
             if (isset($data['temperatura_almacen'])) $prod->temperatura_almacen = $data['temperatura_almacen'];
             if (array_key_exists('marca_id', $data)) $prod->marca_id = $data['marca_id'] ?: null;
             if (array_key_exists('categoria_id', $data)) $prod->categoria_id = $data['categoria_id'] ?: null;
             if (isset($data['maneja_lotes'])) $prod->controla_lote = $data['maneja_lotes'] ? 1 : 0;
             if (isset($data['controla_vencimiento'])) $prod->controla_vencimiento = $data['controla_vencimiento'] ? 1 : 0;
             if (isset($data['imagen_url'])) $prod->imagen_url = $data['imagen_url'];
             
             $prod->save();

             // Update Main EAN (if provided during edit)
             if (!empty($data['codigo_ean'])) {
                 $principalEan = \App\Models\ProductoEan::where('producto_id', $prod->id)->where('es_principal', 1)->first();
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
     * DELETE /api/param/productos/{id}
     */
    public function deleteProducto(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->json($response, ['error' => true, 'message' => 'Acceso denegado. Solo administradores.'], 403);
        }

        $prod = \App\Models\Producto::where('empresa_id', $user->empresa_id)->find($args['id']);
        if (!$prod) {
            return $this->json($response, ['error' => true, 'message' => 'Producto no encontrado'], 404);
        }

        $prod->activo = 0;
        $prod->save();

        return $this->json($response, ['error' => false, 'message' => 'Producto desactivado correctamente']);
    }

    /**
     * GET /api/param/productos/{id}/eans
     */
    public function getProductoEans(Request $request, Response $response, array $args): Response
    {
        $productId = $args['id'];
        $eans = \App\Models\ProductoEan::where('producto_id', $productId)
            ->where('activo', 1)
            ->orderBy('es_principal', 'desc')
            ->get();
            
        return $this->json($response, ['error' => false, 'data' => $eans]);
    }

    /**
     * POST /api/param/productos/{id}/eans
     */
    public function addProductoEan(Request $request, Response $response, array $args): Response
    {
        $productId = $args['id'];
        $data = $request->getParsedBody();
        $eanCode = $data['codigo_ean'] ?? '';
        
        if (empty($eanCode)) {
            return $this->json($response, ['error' => true, 'message' => 'Código EAN es requerido'], 400);
        }

        try {
            $ean = new \App\Models\ProductoEan();
            $ean->producto_id = $productId;
            $ean->codigo_ean = $eanCode;
            $ean->tipo = $data['tipo'] ?? 'EAN13';
            $ean->es_principal = false;
            $ean->activo = 1;
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
        $eanId = $args['ean_id'];
        $data = $request->getParsedBody();
        $eanCode = $data['codigo_ean'] ?? '';

        if (empty($eanCode)) {
            return $this->json($response, ['error' => true, 'message' => 'Código EAN es requerido'], 400);
        }

        try {
            $ean = \App\Models\ProductoEan::find($eanId);
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
        $eanId = $args['ean_id'];
        
        $ean = \App\Models\ProductoEan::find($eanId);
        if ($ean) {
            if ($ean->es_principal) {
                return $this->json($response, ['error' => true, 'message' => 'No se puede eliminar el EAN principal'], 400);
            }
            $ean->activo = 0;
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
        $personal = \App\Models\Personal::where('empresa_id', $user->empresa_id)->get();
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
            $p->empresa_id = $user->empresa_id;
            $p->sucursal_id = $data['sucursal_id'] ?? null;
            $p->nombre = $data['nombre'];
            $p->documento = $data['documento'];
            $p->pin = password_hash($data['pin'], PASSWORD_BCRYPT);
            $p->rol = $data['rol'] ?? 'Auxiliar';
            $p->activo = 1;
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
            $p = \App\Models\Personal::where('empresa_id', $user->empresa_id)->find($id);
            if (!$p) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            if (isset($data['nombre'])) $p->nombre = $data['nombre'];
            if (isset($data['sucursal_id'])) $p->sucursal_id = $data['sucursal_id'] ?: null;
            if (isset($data['rol'])) $p->rol = $data['rol'];
            if (isset($data['pin']) && !empty($data['pin'])) $p->pin = password_hash($data['pin'], PASSWORD_BCRYPT);
            if (isset($data['activo'])) $p->activo = $data['activo'] ? 1 : 0;
            $p->save();
            return $this->json($response, ['error' => false, 'message' => 'Actualizado']);
        } catch (\Exception $e) {
            return $this->json($response, ['error' => true, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/param/ubicaciones
     */
    public function getUbicaciones(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $ubicaciones = \App\Models\Ubicacion::where('empresa_id', $user->empresa_id)->get();
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
            $u = new \App\Models\Ubicacion();
            $u->empresa_id = $user->empresa_id;
            $u->sucursal_id = $data['sucursal_id'];
            $u->zona = $data['zona'];
            $u->pasillo = $data['pasillo'];
            $u->modulo = $data['modulo'] ?? '00';
            $u->nivel = $data['nivel'];
            
            // Auto-generate code: PASILLO-MODULO-NIVEL
            $u->codigo = strtoupper($u->pasillo) . '-' . str_pad($u->modulo, 2, '0', STR_PAD_LEFT) . '-' . str_pad($u->nivel, 2, '0', STR_PAD_LEFT);
            
            $u->posicion = $data['posicion'] ?? null;
            $u->tipo_ubicacion = $data['tipo_ubicacion'] ?? 'Almacenamiento';
            $u->capacidad_maxima = $data['capacidad_maxima'] ?? 0;
            $u->activo = 1;
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
            $u = \App\Models\Ubicacion::where('empresa_id', $user->empresa_id)->find($id);
            if (!$u) return $this->json($response, ['error' => true, 'message' => 'No encontrado'], 404);
            
            if (isset($data['zona'])) $u->zona = $data['zona'];
            if (isset($data['pasillo'])) $u->pasillo = $data['pasillo'];
            if (isset($data['modulo'])) $u->modulo = $data['modulo'];
            if (isset($data['nivel'])) $u->nivel = $data['nivel'];

            // Update code if fragments changed
            $u->codigo = strtoupper($u->pasillo) . '-' . str_pad($u->modulo, 2, '0', STR_PAD_LEFT) . '-' . str_pad($u->nivel, 2, '0', STR_PAD_LEFT);

            if (isset($data['posicion'])) $u->posicion = $data['posicion'];
            if (isset($data['tipo_ubicacion'])) $u->tipo_ubicacion = $data['tipo_ubicacion'];
            if (isset($data['capacidad_maxima'])) $u->capacidad_maxima = $data['capacidad_maxima'];
            if (isset($data['activo'])) $u->activo = $data['activo'] ? 1 : 0;
            $u->save();
            return $this->json($response, ['error' => false, 'message' => 'Actualizada: ' . $u->codigo]);
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
        $provs = \App\Models\Proveedor::where('empresa_id', $user->empresa_id)->get();
        return $this->json($response, ['error' => false, 'data' => $provs]);
    }

    /**
     * POST /api/param/proveedores
     */
    public function createProveedor(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        try {
            $p = new \App\Models\Proveedor();
            $p->empresa_id = $user->empresa_id;
            $p->nit = $data['nit'];
            $p->razon_social = $data['razon_social'];
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
            $p = \App\Models\Proveedor::where('empresa_id', $user->empresa_id)->find($id);
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
        $grantedIds = \App\Models\RolPermiso::where('empresa_id', $user->empresa_id)
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
            $rp = \App\Models\RolPermiso::where('empresa_id', $user->empresa_id)
                ->where('rol', $rol)
                ->where('permiso_id', $permisoId)
                ->first();

            if (!$rp) {
                $rp = new \App\Models\RolPermiso();
                $rp->empresa_id = $user->empresa_id;
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
        $rutas = Ruta::where('empresa_id', $user->empresa_id)->orderBy('nombre')->get();
        return $this->json($response, ['error' => false, 'data' => $rutas]);
    }

    /**
     * POST /api/param/rutas
     */
    public function createRuta(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        
        $ruta = new Ruta();
        $ruta->empresa_id = $user->empresa_id;
        $ruta->nombre = $data['nombre'] ?? '';
        $ruta->comercial = $data['comercial'] ?? '';
        $ruta->frecuencia = $data['frecuencia'] ?? '';
        $ruta->activo = 1;
        $ruta->save();

        return $this->json($response, ['error' => false, 'message' => 'Ruta creada', 'data' => $ruta]);
    }

    /**
     * PUT /api/param/rutas/{id}
     */
    public function updateRuta(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        $ruta = Ruta::where('id', $id)->where('empresa_id', $user->empresa_id)->first();
        if (!$ruta) return $this->json($response, ['error' => true, 'message' => 'Ruta no encontrada'], 404);

        $data = $request->getParsedBody();
        if (isset($data['nombre'])) $ruta->nombre = $data['nombre'];
        if (isset($data['comercial'])) $ruta->comercial = $data['comercial'];
        if (isset($data['frecuencia'])) $ruta->frecuencia = $data['frecuencia'];
        if (isset($data['activo'])) $ruta->activo = $data['activo'] ? 1 : 0;
        $ruta->save();

        return $this->json($response, ['error' => false, 'message' => 'Ruta actualizada']);
    }

    /* ========================================================= */
    /* ==================== CLIENTES ========================= */
    /* ========================================================= */


    /**
     * GET /api/param/clientes
     */
    public function getClientes(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $clientes = Cliente::where('empresa_id', $user->empresa_id)->orderBy('razon_social')->get();
        return $this->json($response, ['error' => false, 'data' => $clientes]);
    }

    /**
     * POST /api/param/clientes
     */
    public function createCliente(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $data['empresa_id'] = $user->empresa_id;
        
        $cliente = Cliente::create($data);
        return $this->json($response, ['error' => false, 'id' => $cliente->id]);
    }

    /**
     * PUT /api/param/clientes/{id}
     */
    public function updateCliente(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = $args['id'];
        $cliente = Cliente::where('id', $id)->where('empresa_id', $user->empresa_id)->first();
        if (!$cliente) return $this->json($response, ['error' => true], 404);

        $data = $request->getParsedBody();
        $cliente->update($data);
        return $this->json($response, ['error' => false]);
    }

    private function isAdmin($user): bool
    {
        return isset($user->rol) && $user->rol === 'Admin';
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
