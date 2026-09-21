<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class DevolucionCrmController extends BaseController
{
    // GET /api/devoluciones/crm/estados
    public function getEstados(Request $r, Response $res): Response
    {
        try {
            $estados = DB::table('crm_estados_devolucion')
                ->where('activo', true)
                ->orderBy('orden', 'asc')
                ->get();
            return $this->ok($res, $estados);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener estados CRM: ' . $e->getMessage());
        }
    }

    // GET /api/devoluciones/crm/estados/all (Incluye inactivos para el mantenedor)
    public function getAllEstados(Request $r, Response $res): Response
    {
        try {
            $estados = DB::table('crm_estados_devolucion')
                ->orderBy('orden', 'asc')
                ->get();
            return $this->ok($res, $estados);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener estados CRM: ' . $e->getMessage());
        }
    }

    // POST /api/devoluciones/crm/estados
    public function createEstado(Request $r, Response $res): Response
    {
        $body = $r->getParsedBody() ?? [];
        try {
            if (empty($body['nombre'])) {
                return $this->error($res, 'El nombre del estado es obligatorio');
            }

            $id = DB::table('crm_estados_devolucion')->insertGetId([
                'nombre' => $body['nombre'],
                'color' => $body['color'] ?? '#94a3b8',
                'orden' => $body['orden'] ?? 0,
                'activo' => isset($body['activo']) ? (bool)$body['activo'] : true,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            return $this->ok($res, ['id' => $id, 'message' => 'Estado creado exitosamente']);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al crear estado: ' . $e->getMessage());
        }
    }

    // PUT /api/devoluciones/crm/estados/{id}
    public function updateEstado(Request $r, Response $res, array $args): Response
    {
        $id = $args['id'];
        $body = $r->getParsedBody() ?? [];
        try {
            if (empty($body['nombre'])) {
                return $this->error($res, 'El nombre del estado es obligatorio');
            }

            DB::table('crm_estados_devolucion')->where('id', $id)->update([
                'nombre' => $body['nombre'],
                'color' => $body['color'] ?? '#94a3b8',
                'orden' => $body['orden'] ?? 0,
                'activo' => isset($body['activo']) ? (bool)$body['activo'] : true,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            return $this->ok($res, ['message' => 'Estado actualizado exitosamente']);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al actualizar estado: ' . $e->getMessage());
        }
    }

    // DELETE /api/devoluciones/crm/estados/{id}
    public function deleteEstado(Request $r, Response $res, array $args): Response
    {
        $id = $args['id'];
        try {
            $isInUse = DB::table('devolucion_tracking')->where('estado_nuevo', function($q) use ($id) {
                $q->select('nombre')->from('crm_estados_devolucion')->where('id', $id)->limit(1);
            })->exists();

            if ($isInUse) {
                return $this->error($res, 'No se puede eliminar este estado porque ya ha sido utilizado en el tracking de alguna devolución.');
            }

            DB::table('crm_estados_devolucion')->where('id', $id)->delete();
            return $this->ok($res, ['message' => 'Estado eliminado exitosamente']);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al eliminar estado: ' . $e->getMessage());
        }
    }

    // ── AUXILIARES DE CALIDAD ────────────────────────────────────────────────
    // Catálogo del personal responsable de los movimientos/estados del CRM de
    // devoluciones — reemplaza el campo de texto libre "Responsable" del
    // formulario de tracking por una selección controlada (Camilo, 2026-09-17).

    // GET /api/devoluciones/auxiliares-calidad?activo=1
    public function getAuxiliaresCalidad(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $params = $r->getQueryParams();
        try {
            $q = DB::table('auxiliares_calidad')->where('empresa_id', $empresaId);
            if (isset($params['activo'])) $q->where('activo', (bool)$params['activo']);
            $rows = $q->orderBy('nombre')->get();
            return $this->ok($res, $rows);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener auxiliares de calidad: ' . $e->getMessage());
        }
    }

    // POST /api/devoluciones/auxiliares-calidad
    public function createAuxiliarCalidad(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $body = $r->getParsedBody() ?? [];
        if (empty($body['nombre'])) {
            return $this->error($res, 'El nombre es obligatorio');
        }
        try {
            $id = DB::table('auxiliares_calidad')->insertGetId([
                'empresa_id' => $empresaId,
                'nombre'     => trim($body['nombre']),
                'cargo'      => $body['cargo'] ?? null,
                'activo'     => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->ok($res, ['id' => $id], 'Auxiliar de calidad creado');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al crear auxiliar de calidad: ' . $e->getMessage());
        }
    }

    // PUT /api/devoluciones/auxiliares-calidad/{id}
    public function updateAuxiliarCalidad(Request $r, Response $res, array $args): Response
    {
        $user = $r->getAttribute('user');
        $empresaId = $this->getEffectiveEmpresaId($user, $r);
        $id = (int)($args['id'] ?? 0);
        $body = $r->getParsedBody() ?? [];
        try {
            $existe = DB::table('auxiliares_calidad')->where('id', $id)->where('empresa_id', $empresaId)->exists();
            if (!$existe) return $this->notFound($res);

            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            if (!empty($body['nombre'])) $upd['nombre'] = trim($body['nombre']);
            if (array_key_exists('cargo', $body)) $upd['cargo'] = $body['cargo'];
            if (array_key_exists('activo', $body)) $upd['activo'] = (bool)$body['activo'];

            DB::table('auxiliares_calidad')->where('id', $id)->update($upd);
            return $this->ok($res, null, 'Auxiliar de calidad actualizado');
        } catch (\Exception $e) {
            return $this->error($res, 'Error al actualizar auxiliar de calidad: ' . $e->getMessage());
        }
    }

    // GET /api/devoluciones/{id}/tracking
    public function getTracking(Request $r, Response $res, array $args): Response
    {
        $devolucionId = $args['id'];
        try {
            $trackings = DB::table('devolucion_tracking')
                ->leftJoin('personal', 'personal.id', '=', 'devolucion_tracking.usuario_id')
                ->select(
                    'devolucion_tracking.*',
                    'personal.nombre as usuario_nombre'
                )
                ->where('devolucion_tracking.devolucion_id', $devolucionId)
                ->orderBy('devolucion_tracking.created_at', 'desc')
                ->get();

            foreach ($trackings as $track) {
                $track->evidencias = DB::table('devolucion_evidencias')
                    ->where('tracking_id', $track->id)
                    ->get();
            }

            return $this->ok($res, $trackings);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener tracking: ' . $e->getMessage());
        }
    }

    // POST /api/devoluciones/{id}/tracking
    public function addTracking(Request $r, Response $res, array $args): Response
    {
        $devolucionId = $args['id'];
        $user = $r->getAttribute('user');
        
        $body = $r->getParsedBody() ?? [];
        $estadoNuevo = $body['estado_nuevo'] ?? null;
        $observacion = $body['observacion'] ?? '';
        $fotosBase64 = $body['fotos'] ?? [];
        $responsable = trim($body['responsable'] ?? '');

        if (!$estadoNuevo && empty($observacion)) {
            return $this->error($res, 'Debe especificar un nuevo estado u observación');
        }
        // A pedido explícito (2026-09-17): la tarjeta de trazabilidad debe mostrar
        // siempre quién realizó el movimiento — responsable pasa de opcional a
        // obligatorio, seleccionado del catálogo auxiliares_calidad.
        if (empty($responsable)) {
            return $this->error($res, 'Debe seleccionar el responsable del movimiento');
        }

        try {
            DB::beginTransaction();

            $devolucion = DB::table('devoluciones')->where('id', $devolucionId)->first();
            if (!$devolucion) {
                throw new \Exception('Devolución no encontrada');
            }

            $estadoAnterior = $devolucion->estado;

            if ($estadoNuevo && $estadoNuevo !== $estadoAnterior) {
                DB::table('devoluciones')->where('id', $devolucionId)->update([
                    'estado' => $estadoNuevo,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            $trackingId = DB::table('devolucion_tracking')->insertGetId([
                'devolucion_id' => $devolucionId,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $estadoNuevo ?: $estadoAnterior,
                'observacion' => $observacion,
                'responsable' => $responsable,
                'usuario_id' => $user->id ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $uploadDir = __DIR__ . '/../../public/uploads/devoluciones_crm/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            foreach ($fotosBase64 as $idx => $b64) {
                if (preg_match('/^data:image\/(\w+);base64,/', $b64, $type)) {
                    $b64Data = substr($b64, strpos($b64, ',') + 1);
                    $ext = strtolower($type[1]);
                    $b64Data = base64_decode($b64Data);
                    
                    if ($b64Data !== false) {
                        $filename = 'trk_' . $trackingId . '_' . time() . '_' . $idx . '.' . $ext;
                        $filepath = $uploadDir . $filename;
                        file_put_contents($filepath, $b64Data);
                        
                        DB::table('devolucion_evidencias')->insert([
                            'devolucion_id' => $devolucionId,
                            'tracking_id' => $trackingId,
                            'ruta_archivo' => '/uploads/devoluciones_crm/' . $filename,
                            'tipo' => 'imagen',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }

            DB::commit();
            return $this->ok($res, ['message' => 'Tracking registrado exitosamente']);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($res, 'Error al registrar tracking: ' . $e->getMessage());
        }
    }
}
