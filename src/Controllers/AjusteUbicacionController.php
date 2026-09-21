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
use App\Helpers\InventoryGuard;
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

    // ── GET /api/ajuste-ubicacion/mis-pendientes ──────────────────────────────
    // Ajustes Pendientes del auxiliar autenticado — a pedido explícito de
    // Camilo (2026-09-16): una vez enviado para aprobación, el auxiliar debe
    // poder ver, editar o eliminar sus propias referencias mientras siga
    // Pendiente (una vez Aprobado, ya no se puede tocar — hay que iniciar el
    // proceso de nuevo).
    public function misPendientes(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        $ajustes = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('auxiliar_id', $user->id)
            ->where('estado', AjusteUbicacion::ESTADO_PENDIENTE)
            ->with(['ubicacion:id,codigo', 'detalles.producto:id,nombre,codigo_interno,unidades_caja'])
            ->orderBy('created_at', 'desc')
            ->get();

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
        $tiposValidos  = [
            AjusteUbicacion::TIPO_AJUSTE_COMPLETO,
            AjusteUbicacion::TIPO_AGREGAR_INVENTARIO,
            AjusteUbicacion::TIPO_AJUSTAR_CANTIDAD,
            AjusteUbicacion::TIPO_AJUSTE_CERO,
        ];
        $tipo          = in_array($data['tipo'] ?? '', $tiposValidos, true)
                         ? $data['tipo']
                         : AjusteUbicacion::TIPO_AJUSTE_COMPLETO;
        $esCero        = $tipo === AjusteUbicacion::TIPO_AJUSTE_CERO;

        if (!$ubicacionId)                 return $this->error($res, 'ubicacion_id es requerido');
        if (!$esCero && empty($detalles))  return $this->error($res, 'Debe ingresar al menos un producto');

        // Validar que la ubicación existe en el tenant
        $ubicacion = Ubicacion::where('empresa_id', $empresaId)->find($ubicacionId);
        if (!$ubicacion) return $this->notFound($res, 'Ubicación no encontrada');

        // "Ajustar a Cero" — no hay físico en la ubicación: no se piden
        // referencias (no hay nada que contar), se vacía lo que el sistema
        // tenga registrado ahí. A pedido explícito de Camilo (2026-09-16):
        // solo afecta ESTA ubicación — el resto del inventario de esas
        // referencias en otras ubicaciones no se toca (el borrado/negativo
        // en aprobar() ya está acotado por ubicacion_id, igual que en
        // Ajuste Completo).
        if ($esCero) {
            $detalles = [];
            $hayInventario = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('ubicacion_id', $ubicacionId)
                ->where('cantidad', '>', 0)
                ->exists();
            if (!$hayInventario) {
                return $this->error($res, 'Esta ubicación ya está en cero — no hay inventario registrado para ajustar.', 422);
            }
        } else {
            // Validar cada línea: producto, cantidad, y — a pedido explícito de
            // Camilo (2026-09-16) — lote/fecha de vencimiento obligatorios cuando
            // el producto los controla (antes solo se exigía en el JS del móvil;
            // una llamada directa al API se saltaba la regla).
            $guard = new InventoryGuard($empresaId, $sucursalId, $user->id);
            foreach ($detalles as $idx => $det) {
                if (empty($det['producto_id'])) {
                    return $this->error($res, "Línea " . ($idx + 1) . ": producto_id es requerido");
                }
                $cantidad = (float)($det['cantidad'] ?? 0);
                if ($cantidad < 0) {
                    return $this->error($res, "Línea " . ($idx + 1) . ": la cantidad no puede ser negativa");
                }

                $producto = Producto::where('empresa_id', $empresaId)->find((int)$det['producto_id']);
                if (!$producto) {
                    return $this->error($res, "Línea " . ($idx + 1) . ": producto no encontrado");
                }

                $fvenc = trim((string)($det['fecha_vencimiento'] ?? ''));
                $checkFv = $guard->checkExpirationMandatory((int)$det['producto_id'], $fvenc ?: null);
                if (!$checkFv['ok']) {
                    return $this->error($res, "Línea " . ($idx + 1) . ": " . $checkFv['message'], 422);
                }

                $lote = trim((string)($det['lote'] ?? ''));
                if ($producto->controla_lote && ($lote === '' || $lote === 'N/A' || $lote === '-')) {
                    return $this->error($res, "Línea " . ($idx + 1) . ": el lote es obligatorio para el producto {$producto->nombre}.", 422);
                }
            }

            // "Ajustar Cantidad" solo edita partidas que YA existen en la
            // ubicación (producto+lote+fecha_vencimiento) — si alguna línea no
            // matchea nada, se rechaza con un mensaje claro en vez de crearla
            // silenciosamente, para no confundir este modo con "Agregar Inventario".
            if ($tipo === AjusteUbicacion::TIPO_AJUSTAR_CANTIDAD) {
                foreach ($detalles as $idx => $det) {
                    $lote = trim((string)($det['lote'] ?? '')) ?: null;
                    $fvenc = trim((string)($det['fecha_vencimiento'] ?? '')) ?: null;
                    $existe = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('ubicacion_id', $ubicacionId)
                        ->where('producto_id', (int)$det['producto_id'])
                        ->where('lote', $lote)
                        ->when($fvenc, fn($q) => $q->where('fecha_vencimiento', $fvenc))
                        ->when(!$fvenc, fn($q) => $q->whereNull('fecha_vencimiento'))
                        ->exists();
                    if (!$existe) {
                        return $this->error($res, "Línea " . ($idx + 1) . ": esa referencia (con ese lote/vencimiento) no está actualmente en la ubicación. Use \"Agregar Inventario\" para referencias nuevas.", 422);
                    }
                }
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
        // BUG DE SEGURIDAD CORREGIDO 2026-08-20: este endpoint no tenía NINGUNA
        // validación de rol — cualquier usuario autenticado (incluido un Auxiliar)
        // podía aprobar un ajuste y modificar el inventario real directamente. A
        // pedido explícito: solo el Administrador puede aprobar.
        if ($deny = $this->requireAdmin($user, $res)) return $deny;
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

        // ── Validación: stock reservado — AjusteCompleto y AjusteCero borran
        // TODO el inventario de la ubicación a ciegas, así que son los únicos
        // modos que necesitan el bloqueo global. "Ajustar Cantidad" no borra
        // nada; se valida línea por línea más abajo (no se puede contar menos
        // de lo ya reservado en esa partida).
        $tipoAjuste = $ajuste->tipo ?? AjusteUbicacion::TIPO_AJUSTE_COMPLETO;
        if ($tipoAjuste === AjusteUbicacion::TIPO_AJUSTE_COMPLETO || $tipoAjuste === AjusteUbicacion::TIPO_AJUSTE_CERO) {
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
            Capsule::transaction(function () use ($ajuste, $user, $empresaId, $sucursalId, $tipoAjuste) {
                $ubicacionId = $ajuste->ubicacion_id;
                $hoy         = date('Y-m-d');
                $ahora       = date('H:i:s');
                $referencia  = 'AjusteUbicacion#' . $ajuste->id;
                $esAgregar   = $tipoAjuste === AjusteUbicacion::TIPO_AGREGAR_INVENTARIO;
                $esAjustarCantidad = $tipoAjuste === AjusteUbicacion::TIPO_AJUSTAR_CANTIDAD;
                $esCero      = $tipoAjuste === AjusteUbicacion::TIPO_AJUSTE_CERO;

                if ($esCero) {
                    // ══ AJUSTAR A CERO: no hay físico en la ubicación — se vacía
                    // TODO lo que el sistema tenga registrado ahí (y solo ahí; el
                    // resto del inventario de esas referencias en otras
                    // ubicaciones no se toca), con AjusteNegativo en Kardex por
                    // cada partida. No se crea nada nuevo. ═══════════════════════
                    $invActual = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('ubicacion_id', $ubicacionId)
                        ->lockForUpdate()
                        ->get();

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
                            'observaciones'       => 'Ajuste x ubicación — sin inventario físico, se pone en cero',
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
                            'motivo'           => $referencia . ' (Ajuste a Cero)',
                            'auxiliar_id'      => $ajuste->auxiliar_id,
                            'ajustado_por'     => $user->id,
                            'fecha'            => $hoy,
                            'hora'             => $ahora,
                        ]);

                        $inv->delete();
                    }

                    $ajuste->estado           = AjusteUbicacion::ESTADO_APROBADO;
                    $ajuste->aprobado_por     = $user->id;
                    $ajuste->fecha_aprobacion = date('Y-m-d H:i:s');
                    $ajuste->save();
                    return; // ── fin del flujo AjusteCero, no sigue al bloque de abajo ──
                }

                if ($esAjustarCantidad) {
                    // ══ AJUSTAR CANTIDAD: corrige cajas/saldos de partidas que YA
                    // existen en la ubicación (validado al crear el ajuste) — un
                    // solo movimiento de Kardex por línea, del tamaño del delta
                    // (positivo si sube, negativo si baja) ═══════════════════════
                    foreach ($ajuste->detalles as $det) {
                        $lote  = $det->lote;
                        $fvenc = $det->fecha_vencimiento;

                        $inv = Inventario::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('ubicacion_id', $ubicacionId)
                            ->where('producto_id', $det->producto_id)
                            ->where('lote', $lote)
                            ->when($fvenc, fn($q) => $q->where('fecha_vencimiento', $fvenc))
                            ->when(!$fvenc, fn($q) => $q->whereNull('fecha_vencimiento'))
                            ->lockForUpdate()
                            ->first();

                        $cantidadAnterior = $inv ? (float)$inv->cantidad : 0.0;
                        $nuevaCantidad    = (float)$det->cantidad;
                        $delta            = round($nuevaCantidad - $cantidadAnterior, 4);

                        if ($inv && $nuevaCantidad < (float)$inv->cantidad_reservada) {
                            throw new \RuntimeException("No se puede ajustar el producto #{$det->producto_id} a {$nuevaCantidad}: hay {$inv->cantidad_reservada} reservado (picking activo) en esa partida.");
                        }

                        if ($delta == 0.0) continue; // sin cambio real, no se toca Kardex

                        $upc    = max(1, (int)(Producto::find($det->producto_id)?->unidades_caja ?? 1));
                        $cajas  = (int)$det->cantidad_cajas;
                        $saldos = (float)$det->saldos;
                        if ($cajas === 0 && $saldos == 0.0 && $nuevaCantidad > 0) {
                            $cajas  = (int)floor($nuevaCantidad / $upc);
                            $saldos = fmod($nuevaCantidad, (float)$upc);
                        }

                        if ($inv) {
                            $inv->cantidad       = $nuevaCantidad;
                            $inv->cantidad_cajas = $cajas;
                            $inv->saldos         = $saldos;
                            if ($fvenc) $inv->fecha_vencimiento = $fvenc;
                            $inv->save();
                        } else {
                            // Defensivo — crear() ya exige que la partida exista;
                            // si de todos modos no aparece (borrada entretanto), se
                            // crea como entrada nueva para no perder el conteo.
                            Inventario::create([
                                'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId,
                                'producto_id' => $det->producto_id, 'ubicacion_id' => $ubicacionId,
                                'lote' => $lote, 'fecha_vencimiento' => $fvenc,
                                'cantidad' => $nuevaCantidad, 'cantidad_cajas' => $cajas, 'saldos' => $saldos,
                                'cantidad_reservada' => 0, 'estado' => Inventario::ESTADO_DISPONIBLE,
                            ]);
                        }

                        AjusteInventario::create([
                            'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId,
                            'origen' => 'AjusteUbicacion', 'producto_id' => $det->producto_id,
                            'ubicacion_id' => $ubicacionId, 'lote' => $lote, 'fecha_vencimiento' => $fvenc,
                            'cantidad_fisica' => $nuevaCantidad, 'cantidad_sistema' => $cantidadAnterior,
                            'diferencia' => $delta,
                            'tipo_ajuste' => $delta >= 0 ? AjusteInventario::TIPO_ENTRADA : AjusteInventario::TIPO_SALIDA,
                            'motivo' => $referencia . ' (Ajustar Cantidad)',
                            'auxiliar_id' => $ajuste->auxiliar_id, 'ajustado_por' => $user->id,
                            'fecha' => $hoy, 'hora' => $ahora,
                        ]);

                        MovimientoInventario::create([
                            'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId,
                            'producto_id' => $det->producto_id,
                            'ubicacion_origen_id'  => $delta < 0 ? $ubicacionId : null,
                            'ubicacion_destino_id' => $delta >= 0 ? $ubicacionId : null,
                            'tipo_movimiento' => $delta >= 0 ? 'AjustePositivo' : 'AjusteNegativo',
                            'cantidad' => abs($delta), 'lote' => $lote, 'fecha_vencimiento' => $fvenc,
                            'referencia_tipo' => $referencia, 'auxiliar_id' => $user->id,
                            'fecha_movimiento' => $hoy, 'hora_inicio' => $ahora, 'hora_fin' => $ahora,
                            'observaciones' => 'Ajuste x ubicación — corrección de cantidad contada',
                        ]);
                    }

                    $ajuste->estado           = AjusteUbicacion::ESTADO_APROBADO;
                    $ajuste->aprobado_por     = $user->id;
                    $ajuste->fecha_aprobacion = date('Y-m-d H:i:s');
                    $ajuste->save();
                    return; // ── fin del flujo AjustarCantidad, no sigue al bloque de abajo ──
                }

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
                        // ── AGREGAR: buscar fila existente con mismo producto+lote+fecha_vencimiento y SUMAR ──
                        // fecha_vencimiento entra en la clave: es el diferenciador real entre
                        // partidas, no el lote. Sin esto, dos partidas del mismo lote con
                        // vencimiento distinto se fusionaban y la fecha nueva nunca se
                        // aplicaba a la fila viva (solo quedaba en el registro de auditoría).
                        $inv = Inventario::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->where('producto_id', $det->producto_id)
                            ->where('ubicacion_id', $ubicacionId)
                            ->where('lote', $det->lote)
                            ->when($det->fecha_vencimiento, fn($q) => $q->where('fecha_vencimiento', $det->fecha_vencimiento))
                            ->when(!$det->fecha_vencimiento, fn($q) => $q->whereNull('fecha_vencimiento'))
                            ->lockForUpdate()
                            ->first();

                        if ($inv) {
                            // Acumular sobre el registro existente
                            $cantAnterior        = (float)$inv->cantidad;
                            $inv->cantidad      += (float)$det->cantidad;
                            $inv->cantidad_cajas = (int)floor($inv->cantidad / $upc);
                            $inv->saldos         = fmod($inv->cantidad, (float)$upc);
                            if ($det->fecha_vencimiento) {
                                $inv->fecha_vencimiento = $det->fecha_vencimiento;
                            }
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

            $mensajesPorTipo = [
                AjusteUbicacion::TIPO_AGREGAR_INVENTARIO => 'Inventario agregado correctamente. Registrado en Kardex.',
                AjusteUbicacion::TIPO_AJUSTAR_CANTIDAD   => 'Cantidades ajustadas correctamente. Diferencias registradas en Kardex.',
                AjusteUbicacion::TIPO_AJUSTE_COMPLETO    => 'Ajuste completo aprobado. Inventario reemplazado y registrado en Kardex.',
                AjusteUbicacion::TIPO_AJUSTE_CERO        => 'Ubicación puesta en cero. Salida registrada en Kardex.',
            ];
            $msg = $mensajesPorTipo[$ajuste->tipo] ?? $mensajesPorTipo[AjusteUbicacion::TIPO_AJUSTE_COMPLETO];
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
        // Mismo blindaje que aprobar() — decidir el destino de un ajuste pendiente
        // (aceptarlo o descartarlo) es una decisión exclusiva del Administrador.
        if ($deny = $this->requireAdmin($user, $res)) return $deny;
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

    /**
     * Valida que $ajuste pueda ser editado por $user en este momento:
     * debe ser quien lo envió, y debe seguir Pendiente (una vez Aprobado no
     * se toca más — hay que iniciar el proceso de nuevo). Devuelve un
     * mensaje de error o null si todo está OK.
     */
    private function _validarEdicionPendiente(?AjusteUbicacion $ajuste, $user): ?string
    {
        if (!$ajuste) return 'Ajuste no encontrado';
        if ((int)$ajuste->auxiliar_id !== (int)$user->id) return 'Solo quien envió el ajuste puede editarlo o eliminarlo';
        if ($ajuste->estado !== AjusteUbicacion::ESTADO_PENDIENTE) {
            return 'Este ajuste ya fue ' . strtolower($ajuste->estado) . ' — si requiere un cambio, inicie el proceso de nuevo';
        }
        return null;
    }

    // ── PUT /api/ajuste-ubicacion/{id}/detalles/{detalleId} ──────────────────
    // Edita una línea de un ajuste PROPIO mientras siga Pendiente — a pedido
    // explícito de Camilo (2026-09-16).
    public function actualizarDetalle(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);
        $data = (array)($r->getParsedBody() ?? []);

        $ajuste = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)$a['id']);

        if ($err = $this->_validarEdicionPendiente($ajuste, $user)) {
            return $this->error($res, $err, 422);
        }

        $detalle = AjusteUbicacionDetalle::where('ajuste_id', $ajuste->id)->find((int)$a['detalleId']);
        if (!$detalle) return $this->notFound($res, 'Línea no encontrada');

        $producto = Producto::where('empresa_id', $empresaId)->find($detalle->producto_id);
        if (!$producto) return $this->error($res, 'Producto no encontrado');

        $upc    = max(1, (int)($producto->unidades_caja ?? 1));
        $cajas  = (int)($data['cantidad_cajas'] ?? $detalle->cantidad_cajas);
        $saldos = (float)($data['saldos'] ?? $detalle->saldos);
        $cantidad = isset($data['cantidad']) && (float)$data['cantidad'] > 0
            ? (float)$data['cantidad']
            : ($cajas * $upc + $saldos);
        if ($cantidad < 0) return $this->error($res, 'La cantidad no puede ser negativa');

        $lote  = array_key_exists('lote', $data) ? trim((string)($data['lote'] ?? '')) : (string)($detalle->lote ?? '');
        $fvenc = array_key_exists('fecha_vencimiento', $data) ? trim((string)($data['fecha_vencimiento'] ?? '')) : (string)($detalle->fecha_vencimiento ?? '');

        $guard   = new InventoryGuard($empresaId, $sucursalId, $user->id);
        $checkFv = $guard->checkExpirationMandatory($detalle->producto_id, $fvenc ?: null);
        if (!$checkFv['ok']) return $this->error($res, $checkFv['message'], 422);
        if ($producto->controla_lote && ($lote === '' || $lote === 'N/A' || $lote === '-')) {
            return $this->error($res, "El lote es obligatorio para el producto {$producto->nombre}.", 422);
        }

        $detalle->cantidad_cajas    = $cajas;
        $detalle->saldos            = $saldos;
        $detalle->cantidad          = $cantidad;
        $detalle->lote              = $lote ?: null;
        $detalle->fecha_vencimiento = $fvenc ?: null;
        $detalle->save();

        return $this->ok($res, $detalle, 'Línea actualizada.');
    }

    // ── DELETE /api/ajuste-ubicacion/{id}/detalles/{detalleId} ───────────────
    public function eliminarDetalle(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        $ajuste = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)$a['id']);

        if ($err = $this->_validarEdicionPendiente($ajuste, $user)) {
            return $this->error($res, $err, 422);
        }

        $detalle = AjusteUbicacionDetalle::where('ajuste_id', $ajuste->id)->find((int)$a['detalleId']);
        if (!$detalle) return $this->notFound($res, 'Línea no encontrada');
        $detalle->delete();

        // Un ajuste sin líneas no tiene nada que procesar — se cancela solo.
        $restantes = AjusteUbicacionDetalle::where('ajuste_id', $ajuste->id)->count();
        if ($restantes === 0) {
            $ajuste->delete();
            return $this->ok($res, null, 'Línea eliminada. Como era la última, el ajuste completo fue cancelado.');
        }

        return $this->ok($res, null, 'Línea eliminada.');
    }

    // ── DELETE /api/ajuste-ubicacion/{id} ─────────────────────────────────────
    // Cancela un ajuste PROPIO completo mientras siga Pendiente — nada se
    // había aplicado todavía a Inventario/Kardex, así que se puede borrar
    // directamente sin dejar rastro contable que revertir.
    public function cancelar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        [$empresaId, $sucursalId] = $this->getEffectiveTenantIds($user, $r);

        $ajuste = AjusteUbicacion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)$a['id']);

        if ($err = $this->_validarEdicionPendiente($ajuste, $user)) {
            return $this->error($res, $err, 422);
        }

        AjusteUbicacionDetalle::where('ajuste_id', $ajuste->id)->delete();
        $ajuste->delete();

        return $this->ok($res, null, 'Ajuste cancelado.');
    }
}
