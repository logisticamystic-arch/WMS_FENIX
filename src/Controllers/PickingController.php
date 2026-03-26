<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenPicking;
use App\Models\PickingDetalle;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\TareaReabastecimiento;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * PickingController — FEFO estricto + auditoría completa.
 *
 * Flujo:
 *  1. crearBatch → genera OrdenPicking con detalles
 *  2. generateRoute → asigna ubicaciones FEFO a cada línea
 *  3. confirmLine → auxiliar confirma que tomó la cantidad
 *  4. completar → cierra la orden y descuenta inventario
 */
class PickingController extends BaseController
{
    // ── GET /api/picking ──────────────────────────────────────────────────────
    // Parámetros de filtro: estado, auxiliar_id, pasillo, marca_id, ubicacion,
    //                       planilla, sin_auxiliar, fecha_inicio, fecha_fin, limit
    public function listar(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);
        $limit = min((int)($params['limit'] ?? 100), 500);

        $q = OrdenPicking::where('orden_pickings.empresa_id', $user->empresa_id)
            ->where('orden_pickings.sucursal_id', $user->sucursal_id)
            ->whereBetween('orden_pickings.created_at', [$ini, $fin])
            ->when($params['estado']       ?? null, fn($q, $e) => $q->where('estado', $e))
            ->when($params['auxiliar_id']  ?? null, fn($q, $v) => $q->where('auxiliar_id', (int)$v))
            ->when($params['sin_auxiliar'] ?? null, fn($q)     => $q->whereNull('auxiliar_id'))
            ->when($params['cliente']      ?? null, fn($q, $v) => $q->where('cliente', 'like', "%$v%"));

        // Filtro por pasillo: requiere JOIN a detalles → ubicaciones
        if (!empty($params['pasillo'])) {
            $pasillo = $params['pasillo'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
                ->where(fn($sq) => $sq
                    ->where('ubicaciones.pasillo', $pasillo)
                    ->orWhere('ubicaciones.codigo', 'like', "$pasillo%")
                )
            );
        }

        // Filtro por marca
        if (!empty($params['marca_id'])) {
            $marcaId = (int)$params['marca_id'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('productos', 'picking_detalles.producto_id', '=', 'productos.id')
                ->where('productos.marca_id', $marcaId)
            );
        }

        // Filtro por ubicación (código)
        if (!empty($params['ubicacion'])) {
            $ubic = $params['ubicacion'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones as ub2', 'picking_detalles.ubicacion_id', '=', 'ub2.id')
                ->where('ub2.codigo', 'like', "%$ubic%")
            );
        }

        // Filtro por número de planilla (cruzando con archivos de planilla)
        if (!empty($params['planilla'])) {
            $planilla = $params['planilla'];
            $q->where(fn($sq) => $sq
                ->where('orden_pickings.numero_planilla', $planilla)
                ->orWhere('orden_pickings.cliente', 'like', "%$planilla%")
            );
        }

        $ordenes = $q->with(['auxiliar:id,nombre', 'detalles'])
            ->orderBy('orden_pickings.prioridad')
            ->orderBy('orden_pickings.created_at', 'desc')
            ->limit($limit)
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# Orden', 'Cliente', 'Estado', 'Prioridad', 'Auxiliar', 'F.Requerida', 'Planilla'];
            $rows = $ordenes->map(fn($o) => [
                $o->numero_orden, $o->cliente ?? '—', $o->estado,
                $o->prioridad, $o->auxiliar->nombre ?? '—',
                $o->fecha_requerida ?? '—',
                $o->numero_planilla ?? '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'picking_' . date('Y-m-d'));
        }

        return $this->ok($res, $ordenes);
    }

    // ── GET /api/picking/consolidados ─────────────────────────────────────────
    // Agrupa órdenes Pendientes/EnProceso por cliente para picking consolidado
    public function consolidados(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');

        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->with(['detalles.producto', 'auxiliar:id,nombre'])
            ->orderBy('prioridad')
            ->orderBy('created_at', 'desc')
            ->get();

        // Agrupar por cliente
        $grupos = [];
        foreach ($ordenes as $o) {
            $key = $o->cliente ?? 'Sin Cliente';
            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'cliente'          => $key,
                    'total_ordenes'    => 0,
                    'ordenes_pendientes' => 0,
                    'ordenes_en_proceso' => 0,
                    'prioridad_max'    => 9,
                    'productos_unicos' => [],
                    'ordenes'          => [],
                ];
            }
            $grupos[$key]['total_ordenes']++;
            if ($o->estado === 'Pendiente')  $grupos[$key]['ordenes_pendientes']++;
            if ($o->estado === 'EnProceso')  $grupos[$key]['ordenes_en_proceso']++;
            $grupos[$key]['prioridad_max'] = min($grupos[$key]['prioridad_max'], $o->prioridad ?? 9);
            foreach ($o->detalles as $d) {
                $grupos[$key]['productos_unicos'][$d->producto_id] = true;
            }
            $grupos[$key]['ordenes'][] = [
                'id'            => $o->id,
                'numero_orden'  => $o->numero_orden,
                'estado'        => $o->estado,
                'prioridad'     => $o->prioridad,
                'auxiliar'      => $o->auxiliar->nombre ?? null,
                'auxiliar_id'   => $o->auxiliar_id,
                'fecha_requerida' => $o->fecha_requerida,
                'total_lineas'  => count($o->detalles),
            ];
        }

        // Transformar para respuesta
        $result = array_values(array_map(function ($g) {
            $g['total_productos_unicos'] = count($g['productos_unicos']);
            unset($g['productos_unicos']);
            return $g;
        }, $grupos));

        // Ordenar por prioridad_max
        usort($result, fn($a, $b) => $a['prioridad_max'] <=> $b['prioridad_max']);

        return $this->ok($res, $result);
    }

    // ── POST /api/picking/asignar-multiple ────────────────────────────────────
    // Asigna auxiliar y/o genera rutas para múltiples órdenes en un solo POST
    public function asignarMultiple(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        $ordenIds    = array_map('intval', $data['orden_ids']    ?? []);
        $auxiliarId  = isset($data['auxiliar_id']) ? (int)$data['auxiliar_id'] : null;
        $generarRuta = (bool)($data['generar_ruta'] ?? false);

        if (empty($ordenIds)) {
            return $this->error($res, 'Se requiere al menos una orden');
        }

        $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->whereIn('id', $ordenIds)
            ->get();

        $resultados = ['asignadas' => 0, 'rutas_generadas' => 0, 'errores' => []];

        foreach ($ordenes as $orden) {
            try {
                // Asignar auxiliar si se indicó
                if ($auxiliarId !== null) {
                    $orden->auxiliar_id = $auxiliarId;
                    $orden->save();
                    $resultados['asignadas']++;
                }

                // Generar ruta FEFO si se solicitó y la orden está Pendiente
                if ($generarRuta && $orden->estado === 'Pendiente') {
                    $this->_generarRutaFEFO($orden, $user);
                    $resultados['rutas_generadas']++;
                }
            } catch (\Exception $e) {
                $resultados['errores'][] = "Orden {$orden->numero_orden}: {$e->getMessage()}";
            }
        }

        $this->audit($user, 'picking', 'asignar_multiple', 'orden_pickings', null,
            null, $resultados, "Asignación masiva: {$resultados['asignadas']} órdenes");

        return $this->ok($res, $resultados,
            "{$resultados['asignadas']} asignadas, {$resultados['rutas_generadas']} rutas generadas");
    }

    // ── Método privado: lógica FEFO reutilizable ──────────────────────────────
    private function _generarRutaFEFO(OrdenPicking $orden, $user): array
    {
        $alertas = [];
        $orden->load(['detalles.producto']);

        // Obtener asesor comercial de la planilla para enriquecer el registro de novedades
        $asesor = null;
        if ($orden->archivo_id && $orden->planilla_numero) {
            $asesor = Capsule::table('lineas_planilla')
                ->where('archivo_id',       $orden->archivo_id)
                ->where('numero_planilla',  $orden->planilla_numero)
                ->whereNotNull('asesor')
                ->value('asesor');
        }

        Capsule::transaction(function () use ($orden, $user, $asesor, &$alertas) {
            $now = date('Y-m-d H:i:s');

            foreach ($orden->detalles as $linea) {
                $stocks = Inventario::where('empresa_id',  $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('estado',       'Disponible')
                    ->where('cantidad',     '>', 0)
                    ->orderByRaw('fecha_vencimiento IS NULL ASC')
                    ->orderBy('fecha_vencimiento')
                    ->orderBy('ubicacion_id')
                    ->get();

                $totalDisponible = $stocks->sum('cantidad');

                if ($totalDisponible < $linea->cantidad_solicitada) {
                    $faltante = $linea->cantidad_solicitada - $totalDisponible;

                    $alertas[] = [
                        'producto_id'    => $linea->producto_id,
                        'producto_nombre'=> $linea->producto->nombre ?? null,
                        'producto_codigo'=> $linea->producto->codigo_interno ?? null,
                        'numero_planilla'=> $orden->planilla_numero,
                        'cliente'        => $orden->cliente,
                        'asesor'         => $asesor,
                        'solicitado'     => $linea->cantidad_solicitada,
                        'disponible'     => $totalDisponible,
                        'faltante'       => $faltante,
                    ];

                    // ── Persistir novedad en tabla dedicada ───────────────────
                    Capsule::table('picking_novedades_stock')->insert([
                        'empresa_id'          => $user->empresa_id,
                        'sucursal_id'         => $user->sucursal_id,
                        'archivo_id'          => $orden->archivo_id,
                        'orden_picking_id'    => $orden->id,
                        'numero_planilla'     => $orden->planilla_numero,
                        'cliente'             => $orden->cliente,
                        'asesor'              => $asesor,
                        'producto_id'         => $linea->producto_id,
                        'producto_nombre'     => $linea->producto->nombre ?? null,
                        'producto_codigo'     => $linea->producto->codigo_interno ?? null,
                        'cantidad_solicitada' => $linea->cantidad_solicitada,
                        'stock_disponible'    => $totalDisponible,
                        'cantidad_faltante'   => $faltante,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);

                    $linea->estado = 'Faltante';
                    $linea->save();
                    continue;
                }

                $first = $stocks->first();
                $linea->ubicacion_id      = $first->ubicacion_id;
                $linea->lote              = $first->lote;
                $linea->fecha_vencimiento = $first->fecha_vencimiento;
                $linea->estado            = 'EnProceso';
                $linea->save();
            }

            $orden->estado = 'EnProceso';
            $orden->save();
        });

        return $alertas;
    }

    // ── GET /api/picking/novedades-stock ──────────────────────────────────────
    // Retorna faltantes de stock registrados durante la generación de rutas FEFO.
    // Filtros: fecha_inicio, fecha_fin, archivo_id, numero_planilla, export=excel
    public function novedadesStock(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $rows = Capsule::table('picking_novedades_stock')
            ->where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini . ' 00:00:00', $fin . ' 23:59:59'])
            ->when($params['archivo_id']      ?? null, fn($q, $v) => $q->where('archivo_id', (int)$v))
            ->when($params['numero_planilla'] ?? null, fn($q, $v) => $q->where('numero_planilla', $v))
            ->orderBy('created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Fecha', 'Planilla', 'Cliente', 'Asesor/Comercial',
                        'Código', 'Producto', 'Solicitado', 'Stock Disponible', 'Faltante'];
            $data = $rows->map(fn($row) => [
                substr($row->created_at, 0, 10),
                $row->numero_planilla   ?? '—',
                $row->cliente           ?? '—',
                $row->asesor            ?? '—',
                $row->producto_codigo   ?? '—',
                $row->producto_nombre   ?? '—',
                $row->cantidad_solicitada,
                $row->stock_disponible,
                $row->cantidad_faltante,
            ])->toArray();
            return $this->exportCsv($res, $headers, $data, 'faltantes_picking_' . date('Y-m-d'));
        }

        return $this->ok($res, $rows);
    }

    // ── GET /api/picking/{id} ─────────────────────────────────────────────────
    public function detalle(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->with(['detalles.producto', 'detalles.ubicacion'])
            ->find($a['id']);

        if (!$orden) return $this->notFound($res);
        return $this->ok($res, $orden);
    }

    // ── POST /api/picking ─────────────────────────────────────────────────────
    public function crearBatch(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if (empty($data['detalles']) || !is_array($data['detalles'])) {
            return $this->error($res, 'Se requiere al menos una línea de productos');
        }

        try {
            $orden = Capsule::transaction(function () use ($data, $user) {
                $orden = OrdenPicking::create([
                    'empresa_id'     => $user->empresa_id,
                    'sucursal_id'    => $user->sucursal_id,
                    'numero_orden'   => 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'cliente'        => $data['cliente'] ?? null,
                    'estado'         => 'Pendiente',
                    'prioridad'      => $data['prioridad'] ?? 5,
                    'auxiliar_id'    => $data['auxiliar_id'] ?? null,
                    'fecha_movimiento'=> date('Y-m-d'),
                    'hora_inicio'    => date('H:i:s'),
                    'fecha_requerida'=> $data['fecha_requerida'] ?? null,
                ]);

                foreach ($data['detalles'] as $det) {
                    PickingDetalle::create([
                        'orden_picking_id'  => $orden->id,
                        'producto_id'       => $det['producto_id'],
                        'ubicacion_id'      => null, // Se asignará en generateRoute (FEFO)
                        'cantidad_solicitada'=> $det['cantidad'],
                        'cantidad_pickeada' => 0,
                        'estado'            => 'Pendiente',
                    ]);
                }

                return $orden;
            });

            $this->audit($user, 'picking', 'crear', 'orden_pickings', $orden->id,
                null, $orden->toArray(), "Orden picking {$orden->numero_orden} creada");

            return $this->created($res, $orden->load('detalles'));
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/generar-ruta ─────────────────────────────
    public function generateRoute(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];

        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->with('detalles')
            ->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if ($orden->estado !== 'Pendiente') {
            return $this->error($res, "La orden ya está en estado {$orden->estado}");
        }

        // Asignar auxiliar si viene en el body
        if (!empty($data['auxiliar_id'])) {
            $orden->auxiliar_id = (int)$data['auxiliar_id'];
            $orden->save();
        }

        try {
            $alertas = $this->_generarRutaFEFO($orden, $user);

            $this->audit($user, 'picking', 'generar_ruta', 'orden_pickings', $orden->id,
                ['estado' => 'Pendiente'], ['estado' => 'EnProceso'],
                "Ruta FEFO generada para {$orden->numero_orden}");

            $lineasAsignadas = $orden->fresh()->detalles->where('estado', 'EnProceso')->count();

            return $this->ok($res, [
                'orden'            => $orden->load('detalles.ubicacion'),
                'lineas_asignadas' => $lineasAsignadas,
                'alertas_stock'    => $alertas,
            ], 'Ruta generada con FEFO');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{orden_id}/confirmar-linea ──────────────────────────
    public function confirmLine(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['orden_id']);

        if (!$orden) return $this->notFound($res);
        if ($orden->estado !== 'EnProceso') {
            return $this->error($res, 'La orden no está en proceso');
        }

        $linea = PickingDetalle::where('orden_picking_id', $orden->id)
            ->find($data['linea_id'] ?? 0);

        if (!$linea) return $this->notFound($res, 'Línea no encontrada');

        $cantidadTomada = (int)($data['cantidad_tomada'] ?? 0);
        if ($cantidadTomada <= 0) return $this->error($res, 'Cantidad inválida');

        try {
            Capsule::transaction(function () use ($linea, $orden, $user, $cantidadTomada) {
                // Descontar inventario
                $inv = Inventario::where('empresa_id',  $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $linea->producto_id)
                    ->where('ubicacion_id', $linea->ubicacion_id)
                    ->where('estado',       'Disponible')
                    ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                    ->first();

                if (!$inv || $inv->cantidad < $cantidadTomada) {
                    throw new \Exception('Stock insuficiente para confirmar el picking');
                }

                $inv->cantidad -= $cantidadTomada;
                if ($inv->cantidad === 0) $inv->delete();
                else $inv->save();

                // Registrar movimiento
                MovimientoInventario::create([
                    'empresa_id'           => $user->empresa_id,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $linea->producto_id,
                    'tipo_movimiento'      => 'SalidaPicking',
                    'cantidad'             => $cantidadTomada,
                    'ubicacion_origen_id'  => $linea->ubicacion_id,
                    'ubicacion_destino_id' => $linea->ubicacion_id,
                    'lote'                 => $linea->lote,
                    'fecha_vencimiento'    => $linea->fecha_vencimiento,
                    'auxiliar_id'          => $user->id,
                    'referencia_tipo'      => 'OrdenPicking',
                    'referencia_id'        => $orden->id,
                    'observaciones'        => "Picking orden {$orden->numero_orden}",
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                ]);

                $linea->cantidad_pickeada = $cantidadTomada;
                $linea->estado = $cantidadTomada >= $linea->cantidad_solicitada
                    ? 'Completado' : 'Faltante';
                $linea->save();

                // Verificar si todas las líneas están completas
                $pendientes = PickingDetalle::where('orden_picking_id', $orden->id)
                    ->whereIn('estado', ['Pendiente', 'EnProceso'])
                    ->count();

                if ($pendientes === 0) {
                    $orden->estado   = 'Completada';
                    $orden->hora_fin = date('H:i:s');
                    $orden->save();
                }
            });

            $this->audit($user, 'picking', 'confirmar_linea', 'picking_detalles', $linea->id,
                null, ['cantidad_tomada' => $cantidadTomada],
                "Línea picking confirmada para {$orden->numero_orden}");

            return $this->ok($res, $linea, 'Línea confirmada');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/picking/{id}/completar ─────────────────────────────────────
    public function completar(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);

        $orden->estado   = 'Completada';
        $orden->hora_fin = date('H:i:s');
        $orden->save();

        $this->audit($user, 'picking', 'completar', 'orden_pickings', $orden->id,
            null, ['estado' => 'Completada'], "Orden picking {$orden->numero_orden} completada");

        // If this order belongs to a planilla archivo, check if all orders are done
        if ($orden->archivo_id) {
            $totalOrdenes = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->where('archivo_id', $orden->archivo_id)->count();
            $completadas  = \Illuminate\Database\Capsule\Manager::table('orden_pickings')
                ->where('archivo_id', $orden->archivo_id)
                ->where('estado', 'Completada')->count();
            if ($totalOrdenes > 0 && $completadas >= $totalOrdenes) {
                \Illuminate\Database\Capsule\Manager::table('archivos_planilla')
                    ->where('id', $orden->archivo_id)
                    ->update(['estado' => 'Separado', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }

        return $this->ok($res, $orden, 'Orden de picking completada');
    }

    // ── GET /api/picking/{orden_id}/siguiente-linea ───────────────────────────
    // Flujo de separación guiado: devuelve la siguiente línea pendiente con toda
    // la información que el auxiliar necesita para separar (ubicación, lote, vencimiento).
    public function siguienteLinea(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['orden_id']);
        if (!$orden) return $this->notFound($res);

        // Siguiente línea no confirmada, ordenada por ubicacion_id (sigue la ruta FEFO)
        $linea = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereIn('estado', ['Pendiente', 'EnProceso'])
            ->with(['producto', 'ubicacion'])
            ->orderBy('ubicacion_id')
            ->first();

        // Progreso
        $total       = PickingDetalle::where('orden_picking_id', $orden->id)->count();
        $confirmadas = PickingDetalle::where('orden_picking_id', $orden->id)
            ->whereNotIn('estado', ['Pendiente', 'EnProceso'])
            ->count();

        if (!$linea) {
            return $this->ok($res, [
                'completada' => true,
                'mensaje'    => 'Todas las líneas han sido confirmadas.',
                'progreso'   => ['confirmadas' => $confirmadas, 'total' => $total, 'pct' => 100],
            ]);
        }

        // EAN principal del producto
        $ean = \App\Models\ProductoEan::where('producto_id', $linea->producto_id)
            ->orderBy('es_principal', 'desc')->value('ean');

        return $this->ok($res, [
            'completada' => false,
            'linea' => [
                'id'                  => $linea->id,
                'producto_id'         => $linea->producto_id,
                'producto_nombre'     => $linea->producto->nombre          ?? '—',
                'producto_codigo'     => $linea->producto->codigo_interno  ?? '—',
                'producto_ean'        => $ean,
                'imagen_url'          => $linea->producto->imagen_url      ?? null,
                'ubicacion_id'        => $linea->ubicacion_id,
                'ubicacion_codigo'    => $linea->ubicacion->codigo         ?? '—',
                'pasillo'             => $linea->ubicacion->pasillo        ?? null,
                'nivel'               => $linea->ubicacion->nivel          ?? null,
                'cantidad_solicitada' => $linea->cantidad_solicitada,
                'cantidad_pickeada'   => $linea->cantidad_pickeada         ?? 0,
                'lote'                => $linea->lote,
                'fecha_vencimiento'   => $linea->fecha_vencimiento,
            ],
            'progreso' => [
                'confirmadas' => $confirmadas,
                'total'        => $total,
                'pct'          => $total > 0 ? (int)round($confirmadas / $total * 100) : 0,
            ],
            'orden' => [
                'id'           => $orden->id,
                'numero_orden' => $orden->numero_orden,
                'cliente'      => $orden->cliente,
            ],
        ]);
    }

    // ── DELETE /api/picking/{id} — solo Admin ─────────────────────────────────
    public function eliminar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$orden) return $this->notFound($res);
        if ($orden->estado === 'Completada') {
            return $this->error($res, 'No se puede eliminar una orden completada');
        }

        $snapshot = $orden->toArray();
        $orden->detalles()->delete();
        $orden->delete();

        $this->audit($user, 'picking', 'eliminar', 'orden_pickings', $a['id'],
            $snapshot, null, "Orden {$snapshot['numero_orden']} eliminada por Admin");

        return $this->ok($res, null, 'Orden eliminada');
    }

    // ── GET /api/picking/dashboard ────────────────────────────────────────────
    public function dashboard(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $hoy  = date('Y-m-d');

        $stats = [
            'pendientes'  => OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('estado', 'Pendiente')->count(),
            'en_proceso'  => OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('estado', 'EnProceso')->count(),
            'completadas_hoy' => OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('estado', 'Completada')
                ->where('fecha_movimiento', $hoy)->count(),
            'con_faltantes' => PickingDetalle::whereHas('ordenPicking', fn($q) =>
                $q->where('empresa_id', $user->empresa_id)
                  ->where('fecha_movimiento', $hoy)
            )->where('estado', 'Faltante')->count(),
        ];

        return $this->ok($res, $stats);
    }

    // ── GET /api/picking/reabastecimientos ────────────────────────────────────
    public function reabastecimientos(Request $r, Response $res): Response
    {
        $user  = $r->getAttribute('user');
        $tareas = TareaReabastecimiento::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('estado', 'Pendiente')
            ->with(['producto', 'ubicacionOrigen', 'ubicacionDestino'])
            ->get();
        return $this->ok($res, $tareas);
    }

    // ── POST /api/picking/reabastecimientos/{id}/completar ────────────────────
    public function completarReabast(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $tarea = TareaReabastecimiento::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$tarea) return $this->notFound($res);

        try {
            Capsule::transaction(function () use ($tarea, $user) {
                // Descontar origen
                $origen = Inventario::where([
                    'empresa_id'  => $user->empresa_id,
                    'sucursal_id' => $user->sucursal_id,
                    'producto_id' => $tarea->producto_id,
                    'ubicacion_id'=> $tarea->ubicacion_origen_id,
                    'estado'      => 'Disponible',
                ])->first();

                if (!$origen || $origen->cantidad < $tarea->cantidad) {
                    throw new \Exception('Stock insuficiente en origen para reabastecimiento');
                }

                $origen->cantidad -= $tarea->cantidad;
                if ($origen->cantidad === 0) $origen->delete();
                else $origen->save();

                // Acumular destino
                $destino = Inventario::firstOrCreate([
                    'empresa_id'   => $user->empresa_id,
                    'sucursal_id'  => $user->sucursal_id,
                    'producto_id'  => $tarea->producto_id,
                    'ubicacion_id' => $tarea->ubicacion_destino_id,
                    'estado'       => 'Disponible',
                ], ['cantidad' => 0]);
                $destino->cantidad += $tarea->cantidad;
                $destino->save();

                MovimientoInventario::create([
                    'empresa_id'           => $user->empresa_id,
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $tarea->producto_id,
                    'tipo_movimiento'      => 'Reabastecimiento',
                    'cantidad'             => $tarea->cantidad,
                    'ubicacion_origen_id'  => $tarea->ubicacion_origen_id,
                    'ubicacion_destino_id' => $tarea->ubicacion_destino_id,
                    'auxiliar_id'          => $user->id,
                    'referencia_tipo'      => 'TareaReabastecimiento',
                    'referencia_id'        => $tarea->id,
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                ]);

                $tarea->estado   = 'Completada';
                $tarea->hora_fin = date('H:i:s');
                $tarea->save();
            });

            $this->audit($user, 'picking', 'reabastecimiento', 'tarea_reabastecimientos', $tarea->id,
                null, ['estado' => 'Completada'], "Reabastecimiento completado");

            return $this->ok($res, $tarea, 'Reabastecimiento completado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── Alias para compatibilidad de rutas ────────────────────────────────────
    public function crear(Request $r, Response $res): Response { return $this->crearBatch($r, $res); }
    public function ver(Request $r, Response $res, array $a): Response { return $this->detalle($r, $res, $a); }

    // ── POST /api/picking/importar — importar pedidos desde CSV ──────────────
    public function importarPedidos(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $uploadedFiles = $r->getUploadedFiles();

        if (empty($uploadedFiles['file'])) {
            return $this->error($res, 'No se recibió ningún archivo');
        }

        $file = $uploadedFiles['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'Error al subir el archivo');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $contents = $stream->getContents();

        $lines = array_values(array_filter(explode("\n", $contents), fn($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            return $this->error($res, 'El archivo está vacío o no tiene datos');
        }

        // Detectar separador (coma o punto y coma)
        $sep = str_contains($lines[0], ';') ? ';' : ',';
        $headers = array_map('trim', str_getcsv(array_shift($lines), $sep));

        // Agrupar líneas por numero_pedido (o crear uno único si no existe esa columna)
        $groups  = [];
        $errors  = [];
        $hasNumPedido = in_array('numero_pedido', $headers) || in_array('pedido', $headers);

        foreach ($lines as $i => $line) {
            $cols = array_map('trim', str_getcsv($line, $sep));
            $row  = array_combine($headers, array_pad($cols, count($headers), ''));

            $codigoRaw = $row['codigo'] ?? $row['codigo_interno'] ?? $row['producto'] ?? '';
            $ean       = $row['ean'] ?? $row['ean13'] ?? '';
            $cantidad  = max(1, (int)($row['cantidad'] ?? 1));
            $pedidoKey = $hasNumPedido
                ? ($row['numero_pedido'] ?? $row['pedido'] ?? 'IMPORT')
                : 'IMPORT';

            $producto = null;
            if ($codigoRaw) {
                $producto = \App\Models\Producto::where('empresa_id', $user->empresa_id)
                    ->where('codigo_interno', $codigoRaw)->first();
            }
            if (!$producto && $ean) {
                $producto = \App\Models\Producto::findByEan($ean);
            }

            if (!$producto) {
                $errors[] = "Fila " . ($i + 2) . ": producto no encontrado (código=$codigoRaw, ean=$ean)";
                continue;
            }

            if (!isset($groups[$pedidoKey])) {
                $groups[$pedidoKey] = [
                    'cliente'         => $row['cliente'] ?? null,
                    'fecha_requerida' => $row['fecha_requerida'] ?? null,
                    'prioridad'       => isset($row['prioridad']) ? max(1, (int)$row['prioridad']) : 5,
                    'detalles'        => [],
                ];
            }
            $groups[$pedidoKey]['detalles'][] = [
                'producto_id' => $producto->id,
                'cantidad'    => $cantidad,
            ];
        }

        if (empty($groups)) {
            return $this->error($res, 'No se encontraron productos válidos. ' . implode(' | ', $errors));
        }

        $ordenesCreadas = [];
        try {
            Capsule::transaction(function () use ($groups, $user, &$ordenesCreadas) {
                foreach ($groups as $numPedido => $g) {
                    $orden = OrdenPicking::create([
                        'empresa_id'      => $user->empresa_id,
                        'sucursal_id'     => $user->sucursal_id,
                        'numero_orden'    => 'PK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                        'cliente'         => $g['cliente'] ?? $numPedido,
                        'estado'          => 'Pendiente',
                        'prioridad'       => $g['prioridad'],
                        'fecha_movimiento'=> date('Y-m-d'),
                        'hora_inicio'     => date('H:i:s'),
                        'fecha_requerida' => $g['fecha_requerida'] ?? null,
                    ]);
                    foreach ($g['detalles'] as $det) {
                        PickingDetalle::create([
                            'orden_picking_id'   => $orden->id,
                            'producto_id'        => $det['producto_id'],
                            'ubicacion_id'       => null,
                            'cantidad_solicitada'=> $det['cantidad'],
                            'cantidad_pickeada'  => 0,
                            'estado'             => 'Pendiente',
                        ]);
                    }
                    $ordenesCreadas[] = $orden->numero_orden;
                }
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al crear órdenes: ' . $e->getMessage());
        }

        return $this->ok($res, [
            'ordenes_creadas' => $ordenesCreadas,
            'advertencias'    => $errors,
        ], count($ordenesCreadas) . ' orden(es) de picking creada(s)');
    }

    // ── Marcar línea como faltante ────────────────────────────────────────────
    public function marcarFaltante(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $linea = PickingDetalle::whereHas('ordenPicking',
            fn($q) => $q->where('empresa_id', $user->empresa_id)
        )->find($a['id']);

        if (!$linea) return $this->notFound($res);
        $linea->estado = 'Faltante';
        $linea->save();

        return $this->ok($res, $linea, 'Marcado como faltante');
    }
}
