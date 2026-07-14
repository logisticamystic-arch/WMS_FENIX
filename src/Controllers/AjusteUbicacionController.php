<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\AjusteUbicacion;
use App\Models\AjusteUbicacionDetalle;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\AjusteInventario;
use App\Models\Ubicacion;
use App\Models\Producto;
use Illuminate\Database\Capsule\Manager as Capsule;

class AjusteUbicacionController extends BaseController
{
    // ── GET /api/ajuste-ubicacion ─────────────────────────────────────────────
    public function listar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $params = $r->getQueryParams();

        $q = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with(['ubicacion:id,codigo', 'auxiliar:id,nombre'])
            ->orderBy('created_at', 'desc');

        if (!empty($params['estado'])) {
            $q->where('estado', $params['estado']);
        }

        $ajustes = $q->limit(200)->get();
        return $this->ok($res, $ajustes);
    }

    // ── GET /api/ajuste-ubicacion/{id} ────────────────────────────────────────
    public function detalle(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        $ajuste = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with([
                'ubicacion:id,codigo',
                'auxiliar:id,nombre',
                'aprobador:id,nombre',
                'detalles.producto:id,nombre,codigo_interno,unidades_caja',
            ])
            ->find((int)$a['id']);

        if (!$ajuste) return $this->notFound($res);

        // Adjuntar inventario actual de la ubicación para comparación
        $invActual = Inventario::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('ubicacion_id', $ajuste->ubicacion_id)
            ->with('producto:id,nombre,codigo_interno,unidades_caja')
            ->get();

        return $this->ok($res, [
            'ajuste'      => $ajuste,
            'inv_actual'  => $invActual,
        ]);
    }

    // ── POST /api/ajuste-ubicacion ────────────────────────────────────────────
    public function crear(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $data = (array)($r->getParsedBody() ?? []);

        $ubicacionId   = (int)($data['ubicacion_id']  ?? 0);
        $observaciones = trim($data['observaciones'] ?? '');
        $detalles      = $data['detalles'] ?? [];
        $tipo          = in_array($data['tipo'] ?? '', [AjusteUbicacion::TIPO_AJUSTE_COMPLETO, AjusteUbicacion::TIPO_AGREGAR_INVENTARIO])
                         ? $data['tipo']
                         : AjusteUbicacion::TIPO_AJUSTE_COMPLETO;

        if (!$ubicacionId)        return $this->error($res, 'ubicacion_id es requerido');
        if (empty($detalles))     return $this->error($res, 'Debe ingresar al menos un producto');

        // Validar que la ubicación existe en el tenant
        $ubicacion = Ubicacion::where('empresa_id', $empresaId)->find($ubicacionId);
        if (!$ubicacion) return $this->notFound($res, 'Ubicación no encontrada');

        // Validar cada línea
        foreach ($detalles as $idx => $det) {
            if (empty($det['producto_id'])) {
                return $this->error($res, "Línea " . ($idx + 1) . ": producto_id es requerido");
            }
            $cantidad = (float)($det['cantidad'] ?? 0);
            if ($cantidad < 0) {
                return $this->error($res, "Línea " . ($idx + 1) . ": la cantidad no puede ser negativa");
            }
        }

        try {
            $ajuste = AjusteUbicacion::create([
                'empresa_id'    => $empresaId,
                'sucursal_id'   => $sucursalId,
                'ubicacion_id'  => $ubicacionId,
                'auxiliar_id'   => $user->id,
                'tipo'          => $tipo,
                'estado'        => AjusteUbicacion::ESTADO_PENDIENTE,
                'observaciones' => $observaciones ?: null,
            ]);

            foreach ($detalles as $det) {
                $upc    = max(1, (int)(Producto::find((int)$det['producto_id'])?->unidades_caja ?? 1));
                $cajas  = (int)($det['cantidad_cajas'] ?? 0);
                $saldos = (float)($det['saldos'] ?? 0);
                $total  = isset($det['cantidad']) && (float)$det['cantidad'] > 0
                    ? (float)$det['cantidad']
                    : ($cajas * $upc + $saldos);

                AjusteUbicacionDetalle::create([
                    'ajuste_id'         => $ajuste->id,
                    'producto_id'       => (int)$det['producto_id'],
                    'cantidad_cajas'    => $cajas,
                    'saldos'            => $saldos,
                    'cantidad'          => $total,
                    'lote'              => trim($det['lote'] ?? '') ?: null,
                    'fecha_vencimiento' => $det['fecha_vencimiento'] ?? null,
                ]);
            }

            $ajuste->load(['ubicacion:id,codigo', 'detalles']);
            return $this->ok($res, $ajuste, 'Ajuste enviado. Pendiente de aprobación.');
        } catch (\Exception $e) {
            error_log('AjusteUbicacionController::crear — ' . $e->getMessage());
            return $this->error($res, 'Error al crear el ajuste: ' . $e->getMessage(), 500);
        }
    }

    // ── POST /api/ajuste-ubicacion/{id}/aprobar ───────────────────────────────
    public function aprobar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $data = (array)($r->getParsedBody() ?? []);

        $ajuste = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->with('detalles')
            ->find((int)$a['id']);

        if (!$ajuste)                                                   return $this->notFound($res);
        if ($ajuste->estado !== AjusteUbicacion::ESTADO_PENDIENTE)     return $this->error($res, 'El ajuste ya fue procesado (estado: ' . $ajuste->estado . ')');

        // ── Actualizar detalles editados antes de procesar ────────────────────
        if (!empty($data['detalles']) && is_array($data['detalles'])) {
            foreach ($data['detalles'] as $det) {
                $detId = (int)($det['id'] ?? 0);
                if (!$detId) continue;
                $detRow = AjusteUbicacionDetalle::where('ajuste_id', $ajuste->id)->find($detId);
                if (!$detRow) continue;
                $upc    = max(1, (int)(Producto::find($detRow->producto_id)?->unidades_caja ?? 1));
                $cajas  = (int)($det['cantidad_cajas'] ?? $detRow->cantidad_cajas);
                $saldos = (float)($det['saldos']       ?? $detRow->saldos);
                $cant   = isset($det['cantidad']) && (float)$det['cantidad'] > 0
                    ? (float)$det['cantidad']
                    : ($cajas * $upc + $saldos);
                $detRow->cantidad_cajas    = $cajas;
                $detRow->saldos            = $saldos;
                $detRow->cantidad          = $cant;
                $lote = trim($det['lote'] ?? '');
                $detRow->lote              = $lote !== '' ? $lote : $detRow->lote;
                $fv   = $det['fecha_vencimiento'] ?? '';
                $detRow->fecha_vencimiento = ($fv !== '' && $fv !== null) ? $fv : $detRow->fecha_vencimiento;
                $detRow->save();
            }
            $ajuste->load('detalles');
        }

        // ── Validación: ubicación no bloqueada por conteo activo ──────────────
        $bloqueado = \Illuminate\Database\Capsule\Manager::table('conteo_detalles')
            ->join('conteo_inventarios', 'conteo_detalles.conteo_id', '=', 'conteo_inventarios.id')
            ->where('conteo_detalles.ubicacion_id', $ajuste->ubicacion_id)
            ->where('conteo_inventarios.estado', 'EnConteo')
            ->where('conteo_inventarios.usa_bloqueo', 1)
            ->exists();
        if ($bloqueado) return $this->error($res, 'La ubicación está bloqueada por un conteo activo. Finalice el conteo antes de aprobar el ajuste.', 422);

        // ── Validación: stock reservado (solo AjusteCompleto borra inventario) ──
        $esAgregarInventario = ($ajuste->tipo ?? AjusteUbicacion::TIPO_AJUSTE_COMPLETO) === AjusteUbicacion::TIPO_AGREGAR_INVENTARIO;
        if (!$esAgregarInventario) {
            $reservado = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('ubicacion_id', $ajuste->ubicacion_id)
                ->where('cantidad_reservada', '>', 0)
                ->exists();
            if ($reservado) {
                return $this->error($res, 'Hay stock reservado en esta ubicación (picking activo). Espere a que se complete el despacho antes de aprobar el ajuste.', 422);
            }
        }

        try {
            Capsule::transaction(function () use ($ajuste, $user, $empresaId, $sucursalId) {
                $ubicacionId = $ajuste->ubicacion_id;
                $hoy         = date('Y-m-d');
                $ahora       = date('H:i:s');
                $referencia  = 'AjusteUbicacion#' . $ajuste->id;
                $esAgregar   = ($ajuste->tipo ?? AjusteUbicacion::TIPO_AJUSTE_COMPLETO) === AjusteUbicacion::TIPO_AGREGAR_INVENTARIO;

                if (!$esAgregar) {
                    // ══ AJUSTE COMPLETO: borrar todo lo existente ════════════════

                    // ── 1. Leer inventario actual en la ubicación ─────────────
                    $invActual = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('ubicacion_id', $ubicacionId)
                        ->lockForUpdate()
                        ->get();

                    // ── 2. Registrar AjusteSalida por cada ítem existente ─────
                    foreach ($invActual as $inv) {
                        if ((float)$inv->cantidad <= 0) continue;

                        MovimientoInventario::create([
                            'empresa_id'          => $empresaId,
                            'sucursal_id'         => $sucursalId,
                            'producto_id'         => $inv->producto_id,
                            'ubicacion_origen_id' => $ubicacionId,
                            'tipo_movimiento'     => 'AjusteNegativo',
                            'cantidad'            => $inv->cantidad,
                            'lote'                => $inv->lote,
                            'fecha_vencimiento'   => $inv->fecha_vencimiento,
                            'referencia_tipo'     => $referencia,
                            'auxiliar_id'         => $user->id,
                            'fecha_movimiento'    => $hoy,
                            'hora_inicio'         => $ahora,
                            'hora_fin'            => $ahora,
                            'observaciones'       => 'Ajuste x ubicación — eliminación previa a recontar',
                        ]);

                        AjusteInventario::create([
                            'empresa_id'       => $empresaId,
                            'sucursal_id'      => $sucursalId,
                            'origen'           => 'AjusteUbicacion',
                            'producto_id'      => $inv->producto_id,
                            'ubicacion_id'     => $ubicacionId,
                            'lote'             => $inv->lote,
                            'fecha_vencimiento'=> $inv->fecha_vencimiento,
                            'cantidad_fisica'  => 0,
                            'cantidad_sistema' => $inv->cantidad,
                            'diferencia'       => -$inv->cantidad,
                            'tipo_ajuste'      => AjusteInventario::TIPO_SALIDA,
                            'motivo'           => $referencia,
                            'auxiliar_id'      => $ajuste->auxiliar_id,
                            'ajustado_por'     => $user->id,
                            'fecha'            => $hoy,
                            'hora'             => $ahora,
                        ]);

                        $inv->delete();
                    }
                }

                // ══ PASO COMÚN (ambos tipos): crear/sumar inventario ════════════

                foreach ($ajuste->detalles as $det) {
                    if ((float)$det->cantidad <= 0) continue;

                    $upc    = max(1, (int)(Producto::find($det->producto_id)?->unidades_caja ?? 1));
                    $cajas  = (int)$det->cantidad_cajas;
                    $saldos = (float)$det->saldos;
                    if ($cajas === 0 && $saldos == 0.0 && $det->cantidad > 0) {
                        $cajas  = (int)floor((float)$det->cantidad / $upc);
                        $saldos = fmod((float)$det->cantidad, (float)$upc);
                    }

                    if ($esAgregar) {
                        // ── AGREGAR: buscar fila existente con mismo producto+lote y SUMAR ──
                        $inv = Inventario::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('producto_id', $det->producto_id)
                            ->where('ubicacion_id', $ubicacionId)
                            ->where('lote', $det->lote)
                            ->lockForUpdate()
                            ->first();

                        if ($inv) {
                            // Acumular sobre el registro existente
                            $cantAnterior        = (float)$inv->cantidad;
                            $inv->cantidad      += (float)$det->cantidad;
                            $inv->cantidad_cajas = (int)floor($inv->cantidad / $upc);
                            $inv->saldos         = fmod($inv->cantidad, (float)$upc);
                            $inv->save();

                            AjusteInventario::create([
                                'empresa_id'       => $empresaId,
                                'sucursal_id'      => $sucursalId,
                                'origen'           => 'AjusteUbicacion',
                                'producto_id'      => $det->producto_id,
                                'ubicacion_id'     => $ubicacionId,
                                'lote'             => $det->lote,
                                'fecha_vencimiento'=> $det->fecha_vencimiento,
                                'cantidad_fisica'  => $inv->cantidad,
                                'cantidad_sistema' => $cantAnterior,
                                'diferencia'       => (float)$det->cantidad,
                                'tipo_ajuste'      => AjusteInventario::TIPO_ENTRADA,
                                'motivo'           => $referencia . ' (Agregar)',
                                'auxiliar_id'      => $ajuste->auxiliar_id,
                                'ajustado_por'     => $user->id,
                                'fecha'            => $hoy,
                                'hora'             => $ahora,
                            ]);
                        } else {
                            // No existe → crear nueva fila
                            $inv = Inventario::create([
                                'empresa_id'         => $empresaId,
                                'sucursal_id'        => $sucursalId,
                                'producto_id'        => $det->producto_id,
                                'ubicacion_id'       => $ubicacionId,
                                'lote'               => $det->lote,
                                'fecha_vencimiento'  => $det->fecha_vencimiento,
                                'cantidad'           => $det->cantidad,
                                'cantidad_cajas'     => $cajas,
                                'saldos'             => $saldos,
                                'cantidad_reservada' => 0,
                                'estado'             => Inventario::ESTADO_DISPONIBLE,
                            ]);

                            AjusteInventario::create([
                                'empresa_id'       => $empresaId,
                                'sucursal_id'      => $sucursalId,
                                'origen'           => 'AjusteUbicacion',
                                'producto_id'      => $det->producto_id,
                                'ubicacion_id'     => $ubicacionId,
                                'lote'             => $det->lote,
                                'fecha_vencimiento'=> $det->fecha_vencimiento,
                                'cantidad_fisica'  => $det->cantidad,
                                'cantidad_sistema' => 0,
                                'diferencia'       => $det->cantidad,
                                'tipo_ajuste'      => AjusteInventario::TIPO_ENTRADA,
                                'motivo'           => $referencia . ' (Agregar — nueva ref)',
                                'auxiliar_id'      => $ajuste->auxiliar_id,
                                'ajustado_por'     => $user->id,
                                'fecha'            => $hoy,
                                'hora'             => $ahora,
                            ]);
                        }
                    } else {
                        // ── AJUSTE COMPLETO: crear fila nueva (la ubicación fue vaciada antes) ──
                        Inventario::create([
                            'empresa_id'         => $empresaId,
                            'sucursal_id'        => $sucursalId,
                            'producto_id'        => $det->producto_id,
                            'ubicacion_id'       => $ubicacionId,
                            'lote'               => $det->lote,
                            'fecha_vencimiento'  => $det->fecha_vencimiento,
                            'cantidad'           => $det->cantidad,
                            'cantidad_cajas'     => $cajas,
                            'saldos'             => $saldos,
                            'cantidad_reservada' => 0,
                            'estado'             => Inventario::ESTADO_DISPONIBLE,
                        ]);

                        AjusteInventario::create([
                            'empresa_id'       => $empresaId,
                            'sucursal_id'      => $sucursalId,
                            'origen'           => 'AjusteUbicacion',
                            'producto_id'      => $det->producto_id,
                            'ubicacion_id'     => $ubicacionId,
                            'lote'             => $det->lote,
                            'fecha_vencimiento'=> $det->fecha_vencimiento,
                            'cantidad_fisica'  => $det->cantidad,
                            'cantidad_sistema' => 0,
                            'diferencia'       => $det->cantidad,
                            'tipo_ajuste'      => AjusteInventario::TIPO_ENTRADA,
                            'motivo'           => $referencia,
                            'auxiliar_id'      => $ajuste->auxiliar_id,
                            'ajustado_por'     => $user->id,
                            'fecha'            => $hoy,
                            'hora'             => $ahora,
                        ]);
                    }

                    MovimientoInventario::create([
                        'empresa_id'           => $empresaId,
                        'sucursal_id'          => $sucursalId,
                        'producto_id'          => $det->producto_id,
                        'ubicacion_destino_id' => $ubicacionId,
                        'tipo_movimiento'      => 'AjustePositivo',
                        'cantidad'             => $det->cantidad,
                        'lote'                 => $det->lote,
                        'fecha_vencimiento'    => $det->fecha_vencimiento,
                        'referencia_tipo'      => $referencia,
                        'auxiliar_id'          => $user->id,
                        'fecha_movimiento'     => $hoy,
                        'hora_inicio'          => $ahora,
                        'hora_fin'             => $ahora,
                        'observaciones'        => $esAgregar
                            ? 'Agregar x ubicación — entrada adicional aprobada'
                            : 'Ajuste x ubicación — entrada desde conteo físico',
                    ]);
                }

                // ── Marcar ajuste como aprobado ───────────────────────────────
                $ajuste->estado           = AjusteUbicacion::ESTADO_APROBADO;
                $ajuste->aprobado_por     = $user->id;
                $ajuste->fecha_aprobacion = date('Y-m-d H:i:s');
                $ajuste->save();
            });

            $msg = ($ajuste->tipo === AjusteUbicacion::TIPO_AGREGAR_INVENTARIO)
                ? 'Inventario agregado correctamente. Registrado en Kardex.'
                : 'Ajuste completo aprobado. Inventario reemplazado y registrado en Kardex.';
            return $this->ok($res, null, $msg);
        } catch (\Exception $e) {
            error_log('AjusteUbicacionController::aprobar — ' . $e->getMessage());
            return $this->error($res, 'Error al aprobar el ajuste: ' . $e->getMessage(), 500);
        }
    }

    // ── POST /api/ajuste-ubicacion/{id}/rechazar ──────────────────────────────
    public function rechazar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $data = (array)($r->getParsedBody() ?? []);

        $ajuste = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)$a['id']);

        if (!$ajuste)                                               return $this->notFound($res);
        if ($ajuste->estado !== AjusteUbicacion::ESTADO_PENDIENTE) return $this->error($res, 'El ajuste ya fue procesado');

        $ajuste->estado           = AjusteUbicacion::ESTADO_RECHAZADO;
        $ajuste->aprobado_por     = $user->id;
        $ajuste->fecha_aprobacion = date('Y-m-d H:i:s');
        $ajuste->observaciones    = ($ajuste->observaciones ? $ajuste->observaciones . ' | ' : '') .
                                    'Rechazado: ' . trim($data['motivo'] ?? 'Sin motivo');
        $ajuste->save();

        return $this->ok($res, null, 'Ajuste rechazado.');
    }
}
