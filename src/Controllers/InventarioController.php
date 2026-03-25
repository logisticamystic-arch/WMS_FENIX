<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\ConteoInventario;
use App\Models\ConteoDetalle;
use App\Models\Producto;
use App\Models\Ubicacion;
use App\Models\NivelReposicion;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * InventarioController — Kardex, traslados, ajustes, conteos físicos.
 * FEFO estricto: los lotes con menor fecha de vencimiento salen primero.
 * Todos los movimientos generan registro en movimiento_inventarios y audit_logs.
 */
class InventarioController extends BaseController
{
    // ── GET /api/inventario/stock ────────────────────────────────────────────
    public function getStock(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();

        $q = Inventario::where('inventarios.empresa_id', $user->empresa_id)
            ->where('inventarios.sucursal_id', $user->sucursal_id)
            ->join('productos', 'inventarios.producto_id', '=', 'productos.id')
            ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
            ->select(
                'inventarios.*',
                'productos.nombre as producto_nombre',
                'productos.codigo_interno',
                'ubicaciones.codigo as ubicacion_codigo'
            );

        if (!empty($params['producto_id'])) {
            $q->where('inventarios.producto_id', $params['producto_id']);
        }
        if (!empty($params['ubicacion_id'])) {
            $q->where('inventarios.ubicacion_id', $params['ubicacion_id']);
        }
        if (!empty($params['estado'])) {
            $q->where('inventarios.estado', $params['estado']);
        }
        // Alerta de próximos a vencer (días configurables, default 30)
        if (!empty($params['proximos_vencer'])) {
            $dias = (int)$params['proximos_vencer'];
            $q->whereNotNull('inventarios.fecha_vencimiento')
              ->where('inventarios.fecha_vencimiento', '<=', date('Y-m-d', strtotime("+{$dias} days")))
              ->where('inventarios.fecha_vencimiento', '>=', date('Y-m-d'));
        }

        $stock = $q->orderBy('inventarios.fecha_vencimiento')->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Producto', 'Código', 'Ubicación', 'Lote', 'F.Vencimiento', 'Cantidad', 'Estado', 'Días p/Vencer'];
            $rows = $stock->map(fn($i) => [
                $i->producto_nombre,
                $i->codigo_interno,
                $i->ubicacion_codigo,
                $i->lote ?? '—',
                $i->fecha_vencimiento ?? '—',
                $i->cantidad,
                $i->estado,
                $i->fecha_vencimiento
                    ? max(0, (int)((strtotime($i->fecha_vencimiento) - time()) / 86400))
                    : '—',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'stock_' . date('Y-m-d'));
        }

        return $this->ok($res, $stock);
    }

    // ── POST /api/inventario/traslado ────────────────────────────────────────
    public function traslado(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $data = $req->getParsedBody() ?? [];

        $required = ['producto_id', 'ubicacion_origen_id', 'ubicacion_destino_id', 'cantidad'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        $cantidad = (int)$data['cantidad'];
        if ($cantidad <= 0) return $this->error($res, 'La cantidad debe ser mayor a 0');

        try {
            Capsule::transaction(function () use ($data, $user, $cantidad) {
                $lote  = $data['lote']              ?? null;
                $fvenc = $data['fecha_vencimiento'] ?? null;

                // Verificar stock origen
                $origen = Inventario::where('empresa_id',    $user->empresa_id)
                    ->where('sucursal_id',   $user->sucursal_id)
                    ->where('producto_id',   $data['producto_id'])
                    ->where('ubicacion_id',  $data['ubicacion_origen_id'])
                    ->where('estado',        'Disponible')
                    ->when($lote, fn($q) => $q->where('lote', $lote))
                    ->first();

                if (!$origen || $origen->cantidad < $cantidad) {
                    throw new \Exception('Stock insuficiente en ubicación origen');
                }

                // Descontar origen
                $origen->cantidad -= $cantidad;
                if ($origen->cantidad === 0) $origen->delete();
                else $origen->save();

                // Acumular en destino
                $destino = Inventario::firstOrCreate(
                    [
                        'empresa_id'   => $user->empresa_id,
                        'sucursal_id'  => $user->sucursal_id,
                        'producto_id'  => $data['producto_id'],
                        'ubicacion_id' => $data['ubicacion_destino_id'],
                        'lote'         => $lote,
                        'estado'       => 'Disponible',
                    ],
                    [
                        'cantidad'          => 0,
                        'fecha_vencimiento' => $fvenc,
                    ]
                );
                $destino->cantidad += $cantidad;
                $destino->save();

                // Movimiento trazable
                MovimientoInventario::create([
                    'empresa_id'          => $user->empresa_id,
                    'sucursal_id'         => $user->sucursal_id,
                    'producto_id'         => $data['producto_id'],
                    'tipo_movimiento'     => 'Traslado',
                    'cantidad'            => $cantidad,
                    'ubicacion_origen_id' => $data['ubicacion_origen_id'],
                    'ubicacion_destino_id'=> $data['ubicacion_destino_id'],
                    'lote'                => $lote,
                    'fecha_vencimiento'   => $fvenc,
                    'usuario_id'          => $user->id,
                    'referencia'          => $data['referencia'] ?? null,
                    'observaciones'       => $data['observaciones'] ?? null,
                    'fecha_movimiento'    => date('Y-m-d'),
                    'hora_movimiento'     => date('H:i:s'),
                ]);
            });

            $this->audit($user, 'inventario', 'traslado', 'inventarios', $data['producto_id'],
                null, $data, "Traslado de {$cantidad} unidades del producto {$data['producto_id']}");

            return $this->ok($res, null, 'Traslado registrado exitosamente');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/ajuste ──────────────────────────────────────────
    public function ajuste(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];
        $required = ['producto_id', 'ubicacion_id', 'cantidad_nueva', 'motivo'];
        foreach ($required as $f) {
            if (!isset($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        try {
            Capsule::transaction(function () use ($data, $user) {
                $inv = Inventario::where('empresa_id',   $user->empresa_id)
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $data['producto_id'])
                    ->where('ubicacion_id', $data['ubicacion_id'])
                    ->where('estado', 'Disponible')
                    ->when($data['lote'] ?? null, fn($q) => $q->where('lote', $data['lote']))
                    ->first();

                $cantidadAnterior = $inv ? $inv->cantidad : 0;
                $cantidadNueva    = (int)$data['cantidad_nueva'];
                $diferencia       = $cantidadNueva - $cantidadAnterior;

                if (!$inv) {
                    if ($cantidadNueva > 0) {
                        Inventario::create([
                            'empresa_id'       => $user->empresa_id,
                            'sucursal_id'      => $user->sucursal_id,
                            'producto_id'      => $data['producto_id'],
                            'ubicacion_id'     => $data['ubicacion_id'],
                            'lote'             => $data['lote'] ?? null,
                            'fecha_vencimiento'=> $data['fecha_vencimiento'] ?? null,
                            'cantidad'         => $cantidadNueva,
                            'estado'           => 'Disponible',
                        ]);
                    }
                } else {
                    $inv->cantidad = $cantidadNueva;
                    if ($cantidadNueva === 0) $inv->delete();
                    else $inv->save();
                }

                $tipo = $diferencia >= 0 ? 'AjusteEntrada' : 'AjusteSalida';
                MovimientoInventario::create([
                    'empresa_id'       => $user->empresa_id,
                    'sucursal_id'      => $user->sucursal_id,
                    'producto_id'      => $data['producto_id'],
                    'tipo_movimiento'  => $tipo,
                    'cantidad'         => abs($diferencia),
                    'ubicacion_origen_id'  => $data['ubicacion_id'],
                    'ubicacion_destino_id' => $data['ubicacion_id'],
                    'lote'             => $data['lote'] ?? null,
                    'usuario_id'       => $user->id,
                    'referencia'       => $data['numero_nota'] ?? null,
                    'observaciones'    => $data['motivo'],
                    'fecha_movimiento' => date('Y-m-d'),
                    'hora_movimiento'  => date('H:i:s'),
                ]);
            });

            $this->audit($user, 'inventario', 'ajuste', 'inventarios', $data['producto_id'],
                null, $data, "Ajuste de inventario: {$data['motivo']}");

            return $this->ok($res, null, 'Ajuste registrado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── GET /api/inventario/kardex ────────────────────────────────────────────
    public function getKardex(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $q = MovimientoInventario::where('movimiento_inventarios.empresa_id', $user->empresa_id)
            ->where('movimiento_inventarios.sucursal_id', $user->sucursal_id)
            ->join('productos', 'movimiento_inventarios.producto_id', '=', 'productos.id')
            ->leftJoin('personal', 'movimiento_inventarios.auxiliar_id', '=', 'personal.id')
            ->leftJoin('ubicaciones as uo', 'movimiento_inventarios.ubicacion_origen_id', '=', 'uo.id')
            ->leftJoin('ubicaciones as ud', 'movimiento_inventarios.ubicacion_destino_id', '=', 'ud.id')
            ->whereBetween('movimiento_inventarios.fecha_movimiento', [
                substr($ini, 0, 10), substr($fin, 0, 10)
            ])
            ->select(
                'movimiento_inventarios.*',
                'productos.nombre as producto',
                'productos.codigo_interno as codigo',
                'personal.nombre as usuario',
                'uo.codigo as ubicacion_origen',
                'ud.codigo as ubicacion_destino'
            );

        if (!empty($params['producto_id'])) {
            $q->where('movimiento_inventarios.producto_id', $params['producto_id']);
        }
        if (!empty($params['tipo'])) {
            $q->where('movimiento_inventarios.tipo_movimiento', $params['tipo']);
        }

        $movimientos = $q->orderBy('movimiento_inventarios.fecha_movimiento', 'desc')
                         ->orderBy('movimiento_inventarios.hora_inicio', 'desc')
                         ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Fecha', 'Hora', 'Producto', 'Código', 'Tipo', 'Cantidad',
                        'Lote', 'F.Vencimiento', 'Origen', 'Destino', 'Usuario', 'Ref.', 'Obs.'];
            $rows = $movimientos->map(fn($m) => [
                $m->fecha_movimiento,
                $m->hora_inicio,
                $m->producto,
                $m->codigo,
                $m->tipo_movimiento,
                $m->cantidad,
                $m->lote ?? '—',
                $m->fecha_vencimiento ?? '—',
                $m->ubicacion_origen  ?? '—',
                $m->ubicacion_destino ?? '—',
                $m->usuario ?? '—',
                $m->referencia ?? '—',
                $m->observaciones ?? '',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'kardex_' . date('Y-m-d'));
        }

        return $this->ok($res, $movimientos);
    }

    // ── POST /api/inventario/conteo/nuevo ────────────────────────────────────
    public function crearConteo(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];

        try {
            // Map tipo values from frontend to DB enum
            $tipoMap = [
                'General'       => 'General',
                'PorUbicacion'  => 'PorUbicacion',
                'PorReferencia' => 'PorReferencia',
                'Total'         => 'General',   // legacy alias
            ];
            $tipo = $tipoMap[$data['tipo'] ?? 'General'] ?? 'General';

            // Guardar observaciones: filtro, producto_id y ubicacion_ids para PorUbicacion
            $obs = [];
            if (!empty($data['filtro']))       $obs['filtro']        = $data['filtro'];
            if (!empty($data['producto_id']))  $obs['producto_id']   = (int)$data['producto_id'];
            if (!empty($data['ubicacion_ids']) && is_array($data['ubicacion_ids'])) {
                $obs['ubicacion_ids'] = array_map('intval', $data['ubicacion_ids']);
            }

            $conteo = ConteoInventario::create([
                'empresa_id'      => $user->empresa_id,
                'sucursal_id'     => $user->sucursal_id,
                'tipo_conteo'     => $tipo,
                'estado'          => 'EnConteo',
                'auxiliar_id'     => $user->id,
                'fecha_movimiento'=> date('Y-m-d'),
                'hora_inicio'     => date('H:i:s'),
                'observaciones'   => $obs ? json_encode($obs) : null,
            ]);

            $this->audit($user, 'inventario', 'crear_conteo', 'conteo_inventarios', $conteo->id,
                null, $conteo->toArray(), "Conteo #{$conteo->id} tipo {$conteo->tipo_conteo} iniciado");

            return $this->created($res, $conteo);
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/conteo/{id}/linea ───────────────────────────────
    public function addLineaConteo(Request $req, Response $res, array $a): Response
    {
        $user   = $req->getAttribute('user');
        $data   = $req->getParsedBody() ?? [];
        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)->find($a['id']);

        if (!$conteo) return $this->notFound($res);
        if ($conteo->estado !== 'EnConteo') {
            return $this->error($res, 'El conteo no está activo (estado: ' . $conteo->estado . ')');
        }

        // Validaciones básicas
        $productoId = (int)($data['producto_id'] ?? 0);
        if ($productoId <= 0) {
            return $this->error($res, 'producto_id requerido');
        }
        $cantidadContada = (float)($data['cantidad_contada'] ?? 0);
        if ($cantidadContada < 0) {
            return $this->error($res, 'cantidad_contada no puede ser negativa');
        }

        // Resolver ubicacion_id: acepta ID numérico directo o código de texto
        $ubicacionId = null;
        if (!empty($data['ubicacion_id']) && is_numeric($data['ubicacion_id'])) {
            $ubicacionId = (int)$data['ubicacion_id'];
        } elseif (!empty($data['ubicacion_codigo'])) {
            $ubic = \App\Models\Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('codigo', trim($data['ubicacion_codigo']))
                ->first();
            if (!$ubic) {
                return $this->error($res, "Ubicación '{$data['ubicacion_codigo']}' no encontrada");
            }
            $ubicacionId = $ubic->id;
        }

        // Cantidad actual en sistema para esa combinación
        $cantSistema = (float)Inventario::where('empresa_id',  $user->empresa_id)
            ->where('sucursal_id',  $user->sucursal_id)
            ->where('producto_id',  $productoId)
            ->when($ubicacionId, fn($q) => $q->where('ubicacion_id', $ubicacionId))
            ->where('estado', 'Disponible')
            ->sum('cantidad');

        // If ubicacion_id still null, resolve the first matching ubicacion from inventory
        if (!$ubicacionId) {
            $invRow = Inventario::where('empresa_id',  $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $productoId)
                ->where('estado', 'Disponible')
                ->orderBy('fecha_vencimiento')
                ->first();
            $ubicacionId = $invRow?->ubicacion_id;
        }
        // Still null — use the first available ubicacion
        if (!$ubicacionId) {
            $ubicacionId = \App\Models\Ubicacion::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->value('id');
        }
        if (!$ubicacionId) {
            return $this->error($res, 'No se encontró ubicación para registrar el conteo');
        }

        try {
            $linea = ConteoDetalle::updateOrCreate(
                [
                    'conteo_id'   => $conteo->id,
                    'producto_id' => $productoId,
                    'ubicacion_id'=> $ubicacionId,
                    'lote'        => $data['lote'] ?? null,
                ],
                [
                    'cantidad_fisica'  => (int)$cantidadContada,
                    'cantidad_sistema' => (int)$cantSistema,
                    'diferencia'       => (int)($cantidadContada - $cantSistema),
                    'estado'           => 'Contado',
                ]
            );

            return $this->ok($res, $linea);
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── POST /api/inventario/conteo/{id}/finalizar ───────────────────────────
    public function finalizarConteo(Request $req, Response $res, array $a): Response
    {
        $user   = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $conteo = ConteoInventario::where('empresa_id', $user->empresa_id)
            ->with('detalles')
            ->find($a['id']);

        if (!$conteo)                      return $this->notFound($res);
        if ($conteo->estado !== 'EnConteo') return $this->error($res, 'Conteo no está activo');

        $data = $req->getParsedBody() ?? [];

        try {
            Capsule::transaction(function () use ($conteo, $user, $data) {
                $ajustar = (bool)($data['aplicar_ajustes'] ?? false);

                foreach ($conteo->detalles as $linea) {
                    $diferencia = $linea->cantidad_contada - $linea->cantidad_sistema;

                    if ($ajustar && $diferencia !== 0) {
                        // Aplicar ajuste automático en inventario
                        $inv = Inventario::where([
                            'empresa_id'  => $user->empresa_id,
                            'sucursal_id' => $user->sucursal_id,
                            'producto_id' => $linea->producto_id,
                            'ubicacion_id'=> $linea->ubicacion_id,
                            'lote'        => $linea->lote,
                        ])->first();

                        if ($inv) {
                            $inv->cantidad = $linea->cantidad_fisica;
                            if ($inv->cantidad <= 0) $inv->delete();
                            else $inv->save();
                        } elseif ($linea->cantidad_fisica > 0) {
                            Inventario::create([
                                'empresa_id'   => $user->empresa_id,
                                'sucursal_id'  => $user->sucursal_id,
                                'producto_id'  => $linea->producto_id,
                                'ubicacion_id' => $linea->ubicacion_id,
                                'lote'         => $linea->lote,
                                'cantidad'     => $linea->cantidad_fisica,
                                'estado'       => 'Disponible',
                            ]);
                        }

                        // Movimiento de ajuste — usa enum correcto AjustePositivo/AjusteNegativo
                        $tipoMov = $diferencia > 0 ? 'AjustePositivo' : 'AjusteNegativo';
                        MovimientoInventario::create([
                            'empresa_id'           => $user->empresa_id,
                            'sucursal_id'          => $user->sucursal_id,
                            'producto_id'          => $linea->producto_id,
                            'tipo_movimiento'      => $tipoMov,
                            'cantidad'             => abs($diferencia),
                            'ubicacion_origen_id'  => $linea->ubicacion_id,
                            'ubicacion_destino_id' => $linea->ubicacion_id,
                            'lote'                 => $linea->lote,
                            'auxiliar_id'          => $user->id,
                            'referencia_tipo'      => 'conteo',
                            'referencia_id'        => $conteo->id,
                            'observaciones'        => "Ajuste por conteo #{$conteo->id}",
                            'fecha_movimiento'     => date('Y-m-d'),
                            'hora_inicio'          => date('H:i:s'),
                        ]);
                    }

                    $linea->diferencia = $diferencia;
                    $linea->estado = 'Aprobado';
                    $linea->save();
                }

                // Cerrar el conteo
                $conteo->estado   = 'PendienteAprobacion';
                $conteo->hora_fin = date('H:i:s');
                $conteo->save();
            });

            $this->audit($user, 'inventario', 'finalizar_conteo', 'conteo_inventarios', $conteo->id,
                ['estado' => 'EnConteo'], ['estado' => 'PendienteAprobacion'],
                "Conteo #{$conteo->id} finalizado, pendiente aprobacion");

            return $this->ok($res, $conteo->load('detalles'), 'Conteo finalizado');
        } catch (\Exception $e) {
            return $this->error($res, $e->getMessage());
        }
    }

    // ── GET /api/inventario/conteos ──────────────────────────────────────────
    public function getConteos(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $conteos = ConteoInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->orderBy('created_at', 'desc')
            ->get();

        if (($params['export'] ?? '') === 'excel') {
            $headers = ['Número', 'Tipo', 'Estado', 'F.Inicio', 'F.Fin', 'Ajustes Aplicados', 'Observaciones'];
            $rows = $conteos->map(fn($c) => [
                $c->numero, $c->tipo, $c->estado,
                $c->fecha_inicio, $c->fecha_fin ?? '—',
                $c->ajustes_aplicados ? 'Sí' : 'No',
                $c->observaciones ?? '',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows, 'conteos_' . date('Y-m-d'));
        }

        return $this->ok($res, $conteos);
    }

    // ── GET /api/inventario/niveles-reposicion ───────────────────────────────
    public function getNivelesReposicion(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $niveles = \App\Models\NivelReposicion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->with('producto')
            ->get();
        return $this->ok($res, $niveles);
    }

    // ── POST /api/inventario/niveles-reposicion ──────────────────────────────
    public function saveNivelReposicion(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;
        $data = $req->getParsedBody() ?? [];

        $nivel = \App\Models\NivelReposicion::updateOrCreate(
            [
                'empresa_id'  => $user->empresa_id,
                'sucursal_id' => $user->sucursal_id,
                'producto_id' => $data['producto_id'],
            ],
            [
                'stock_minimo'    => $data['stock_minimo']    ?? 0,
                'stock_maximo'    => $data['stock_maximo']    ?? 9999,
                'punto_reorden'   => $data['punto_reorden']   ?? 0,
                'cantidad_reorden'=> $data['cantidad_reorden']?? 0,
                'activo'          => true,
            ]
        );

        $this->audit($user, 'inventario', 'nivel_reposicion', 'niveles_reposicion', $nivel->id,
            null, $nivel->toArray());

        return $this->ok($res, $nivel, 'Nivel de reposición guardado');
    }
}
