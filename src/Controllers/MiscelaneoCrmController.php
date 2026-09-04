<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class MiscelaneoCrmController extends BaseController
{
    // ==========================================
    // 1. ODC MISCELÁNEOS
    // ==========================================
    
    public function getOdcs(Request $r, Response $res): Response
    {
        try {
            $odcs = DB::table('misc_odcs')->orderBy('id', 'desc')->get();
            return $this->ok($res, $odcs);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener ODCs: ' . $e->getMessage());
        }
    }

    public function createOdc(Request $r, Response $res): Response
    {
        $body = $r->getParsedBody() ?? [];
        $user = $r->getAttribute('user');
        
        if (empty($body['proveedor']) || empty($body['detalles'])) {
            return $this->error($res, 'Datos incompletos para ODC');
        }

        try {
            DB::beginTransaction();

            $consecutivo = 'ODCM-' . date('Ymd-Hi') . '-' . rand(100, 999);
            
            $odcId = DB::table('misc_odcs')->insertGetId([
                'consecutivo' => $consecutivo,
                'fecha' => date('Y-m-d'),
                'proveedor_nombre' => $body['proveedor'],
                'observaciones' => $body['observaciones'] ?? '',
                'creado_por' => $user->id ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            foreach ($body['detalles'] as $det) {
                if (empty($det['articulo']) || empty($det['cantidad'])) continue;
                
                $detId = DB::table('misc_odc_detalles')->insertGetId([
                    'misc_odc_id' => $odcId,
                    'articulo' => $det['articulo'],
                    'unidad_medida' => $det['unidad_medida'] ?? 'UND',
                    'cantidad_total' => $det['cantidad']
                ]);

                if (!empty($det['distribuciones'])) {
                    foreach ($det['distribuciones'] as $dist) {
                        DB::table('misc_odc_distribucion')->insert([
                            'misc_odc_detalle_id' => $detId,
                            'cliente_nombre' => $dist['cliente'],
                            'sucursal_destino' => $dist['sucursal'],
                            'cantidad_asignada' => $dist['cantidad']
                        ]);
                    }
                }
            }

            DB::commit();
            return $this->ok($res, ['message' => 'ODC creada exitosamente', 'consecutivo' => $consecutivo]);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($res, 'Error al crear ODC: ' . $e->getMessage());
        }
    }

    public function getOdcDetalle(Request $r, Response $res, array $args): Response
    {
        $id = $args['id'];
        try {
            $odc = DB::table('misc_odcs')->where('id', $id)->first();
            if (!$odc) return $this->error($res, 'ODC no encontrada', 404);

            $detalles = DB::table('misc_odc_detalles')->where('misc_odc_id', $id)->get();
            foreach ($detalles as $det) {
                $det->distribuciones = DB::table('misc_odc_distribucion')
                                         ->where('misc_odc_detalle_id', $det->id)
                                         ->get();
            }
            $odc->detalles = $detalles;

            return $this->ok($res, $odc);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener detalle ODC: ' . $e->getMessage());
        }
    }

    public function procesarOdc(Request $r, Response $res, array $args): Response
    {
        $id = $args['id'];
        try {
            DB::table('misc_odcs')->where('id', $id)->update([
                'estado' => 'Confirmada',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return $this->ok($res, ['message' => 'ODC marcada como procesada']);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al procesar ODC: ' . $e->getMessage());
        }
    }

    public function anularOdc(Request $r, Response $res, array $args): Response
    {
        $id = $args['id'];
        try {
            $odc = DB::table('misc_odcs')->where('id', $id)->first();
            if (!$odc) return $this->error($res, 'ODC no encontrada', 404);
            if ($odc->estado !== 'Pendiente') return $this->error($res, 'Solo se pueden anular ODCs en estado Pendiente');

            DB::table('misc_odcs')->where('id', $id)->update([
                'estado' => 'Anulada',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return $this->ok($res, ['message' => 'ODC marcada como Anulada']);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al anular ODC: ' . $e->getMessage());
        }
    }

    // ==========================================
    // 2. CRM TRACKING (Misceláneos)
    // ==========================================

    public function getEstados(Request $r, Response $res): Response
    {
        try {
            $estados = DB::table('misc_estados')
                ->where('activo', true)
                ->orderBy('orden', 'asc')
                ->get();
            return $this->ok($res, $estados);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener estados: ' . $e->getMessage());
        }
    }

    public function getTracking(Request $r, Response $res, array $args): Response
    {
        $miscId = $args['id'];
        try {
            $trackings = DB::table('misc_tracking')
                ->leftJoin('usuarios', 'usuarios.id', '=', 'misc_tracking.usuario_id')
                ->select(
                    'misc_tracking.*',
                    'usuarios.nombre as usuario_nombre'
                )
                ->where('misc_tracking.miscelaneo_id', $miscId)
                ->orderBy('misc_tracking.created_at', 'desc')
                ->get();

            foreach ($trackings as $track) {
                $track->evidencias = DB::table('misc_evidencias')
                    ->where('tracking_id', $track->id)
                    ->get();
            }

            return $this->ok($res, $trackings);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al obtener tracking: ' . $e->getMessage());
        }
    }

    public function addTracking(Request $r, Response $res, array $args): Response
    {
        $miscId = $args['id'];
        $user = $r->getAttribute('user');
        
        $body = $r->getParsedBody() ?? [];
        $estadoNuevo = $body['estado_nuevo'] ?? null;
        $observacion = $body['observacion'] ?? '';
        $fotosBase64 = $body['fotos'] ?? [];
        $docsBase64 = $body['documentos'] ?? [];

        if (!$estadoNuevo && empty($observacion)) {
            return $this->error($res, 'Debe especificar un nuevo estado u observación');
        }

        try {
            DB::beginTransaction();

            $misc = DB::table('miscelaneos')->where('id', $miscId)->first();
            if (!$misc) {
                throw new \Exception('Misceláneo no encontrado');
            }

            $estadoAnterior = $misc->estado;

            if ($estadoNuevo && $estadoNuevo !== $estadoAnterior) {
                DB::table('miscelaneos')->where('id', $miscId)->update([
                    'estado' => $estadoNuevo,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            $trackingId = DB::table('misc_tracking')->insertGetId([
                'miscelaneo_id' => $miscId,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $estadoNuevo ?: $estadoAnterior,
                'observacion' => $observacion,
                'usuario_id' => $user->id ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $uploadDir = __DIR__ . '/../../public/uploads/miscelaneos_crm/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Fotos
            foreach ($fotosBase64 as $idx => $b64) {
                if (preg_match('/^data:image\/(\w+);base64,/', $b64, $type)) {
                    $b64Data = substr($b64, strpos($b64, ',') + 1);
                    $ext = strtolower($type[1]);
                    $b64Data = base64_decode($b64Data);
                    
                    if ($b64Data !== false) {
                        $filename = 'trk_img_' . $trackingId . '_' . time() . '_' . $idx . '.' . $ext;
                        $filepath = $uploadDir . $filename;
                        file_put_contents($filepath, $b64Data);
                        
                        DB::table('misc_evidencias')->insert([
                            'miscelaneo_id' => $miscId,
                            'tracking_id' => $trackingId,
                            'ruta_archivo' => '/uploads/miscelaneos_crm/' . $filename,
                            'tipo' => 'imagen',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
            
            // Documentos (PDF)
            foreach ($docsBase64 as $idx => $b64) {
                if (preg_match('/^data:application\/pdf;base64,/', $b64, $type)) {
                    $b64Data = substr($b64, strpos($b64, ',') + 1);
                    $b64Data = base64_decode($b64Data);
                    
                    if ($b64Data !== false) {
                        $filename = 'trk_doc_' . $trackingId . '_' . time() . '_' . $idx . '.pdf';
                        $filepath = $uploadDir . $filename;
                        file_put_contents($filepath, $b64Data);
                        
                        DB::table('misc_evidencias')->insert([
                            'miscelaneo_id' => $miscId,
                            'tracking_id' => $trackingId,
                            'ruta_archivo' => '/uploads/miscelaneos_crm/' . $filename,
                            'tipo' => 'documento',
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

    public function updateSucursal(Request $r, Response $res, array $args): Response
    {
        $miscId = $args['id'];
        $body = $r->getParsedBody() ?? [];
        
        if (empty($body['cliente_nombre'])) {
            return $this->error($res, 'El nombre del cliente/sucursal es obligatorio');
        }

        try {
            DB::table('miscelaneos')->where('id', $miscId)->update([
                'cliente_nombre' => $body['cliente_nombre'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return $this->ok($res, ['message' => 'Sucursal actualizada exitosamente']);
        } catch (\Exception $e) {
            return $this->error($res, 'Error al actualizar sucursal: ' . $e->getMessage());
        }
    }
}
