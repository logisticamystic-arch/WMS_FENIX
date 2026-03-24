<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * AlertasController — Motor de alertas del WMS.
 *
 * Detecta y persiste automáticamente:
 *  - Productos próximos a vencer (≤ 30 días)
 *  - Productos vencidos
 *  - Stock bajo mínimo configurado
 *  - Agotados (stock = 0 con nivel de reposición activo)
 *
 * El frontend llama GET /api/alertas para mostrar el panel de alertas.
 * GET /api/alertas/generar re-escanea y actualiza la tabla alertas_stock.
 */
class AlertasController extends BaseController
{
    // ── GET /api/alertas ──────────────────────────────────────────────────────
    public function index(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $q = Capsule::table('alertas_stock as a')
            ->join('productos as p', 'a.producto_id', '=', 'p.id')
            ->where('a.empresa_id', $user->empresa_id)
            ->where('a.sucursal_id', $user->sucursal_id)
            ->where('a.estado', 'Activa')
            ->select(
                'a.*',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo'
            );

        if (!empty($params['tipo'])) {
            $q->where('a.tipo', $params['tipo']);
        }

        $alertas = $q->orderByRaw("FIELD(a.tipo, 'Vencido','ProximoVencer','Agotado','BajoMinimo','SobreMaximo')")
                     ->get();

        return $this->ok($res, [
            'alertas'  => $alertas,
            'resumen'  => [
                'vencidos'        => $alertas->where('tipo', 'Vencido')->count(),
                'proximos_vencer' => $alertas->where('tipo', 'ProximoVencer')->count(),
                'agotados'        => $alertas->where('tipo', 'Agotado')->count(),
                'bajo_minimo'     => $alertas->where('tipo', 'BajoMinimo')->count(),
            ],
        ]);
    }

    // ── POST /api/alertas/generar — escanea y actualiza alertas ───────────────
    public function generar(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $eId = $user->empresa_id;
        $sId = $user->sucursal_id;
        $hoy = date('Y-m-d');
        $en30 = date('Y-m-d', strtotime('+30 days'));

        $creadas = 0;

        // ── 1. Detectar productos vencidos ────────────────────────────────────
        $vencidos = Capsule::table('inventarios as i')
            ->join('productos as p', 'i.producto_id', '=', 'p.id')
            ->where('i.empresa_id', $eId)
            ->where('i.sucursal_id', $sId)
            ->where('i.cantidad', '>', 0)
            ->whereNotNull('i.fecha_vencimiento')
            ->where('i.fecha_vencimiento', '<', $hoy)
            ->select('i.producto_id', 'i.fecha_vencimiento', Capsule::raw('SUM(i.cantidad) as stock'))
            ->groupBy('i.producto_id', 'i.fecha_vencimiento')
            ->get();

        foreach ($vencidos as $v) {
            $this->upsertAlerta($eId, $sId, $v->producto_id, 'Vencido', $v->stock, null, $v->fecha_vencimiento);
            $creadas++;
        }

        // ── 2. Detectar próximos a vencer (1–30 días) ─────────────────────────
        $proximos = Capsule::table('inventarios as i')
            ->join('productos as p', 'i.producto_id', '=', 'p.id')
            ->where('i.empresa_id', $eId)
            ->where('i.sucursal_id', $sId)
            ->where('i.cantidad', '>', 0)
            ->whereNotNull('i.fecha_vencimiento')
            ->whereBetween('i.fecha_vencimiento', [$hoy, $en30])
            ->select('i.producto_id', 'i.fecha_vencimiento', Capsule::raw('SUM(i.cantidad) as stock'))
            ->groupBy('i.producto_id', 'i.fecha_vencimiento')
            ->get();

        foreach ($proximos as $p) {
            $dias = (int)((strtotime($p->fecha_vencimiento) - strtotime($hoy)) / 86400);
            $this->upsertAlerta($eId, $sId, $p->producto_id, 'ProximoVencer', $p->stock, null, $p->fecha_vencimiento, $dias);
            $creadas++;
        }

        // ── 3. Detectar agotados y bajo mínimo (con nivel de reposición) ──────
        $niveles = Capsule::table('niveles_reposicion as nr')
            ->leftJoin(Capsule::raw(
                "(SELECT producto_id, COALESCE(SUM(cantidad),0) as total
                  FROM inventarios
                  WHERE empresa_id = {$eId} AND sucursal_id = {$sId} AND estado = 'Disponible'
                  GROUP BY producto_id) as inv"
            ), 'nr.producto_id', '=', 'inv.producto_id')
            ->where('nr.empresa_id', $eId)
            ->where('nr.sucursal_id', $sId)
            ->where('nr.activo', true)
            ->select('nr.producto_id', 'nr.stock_minimo', Capsule::raw('COALESCE(inv.total,0) as stock_actual'))
            ->get();

        foreach ($niveles as $n) {
            if ($n->stock_actual == 0) {
                $this->upsertAlerta($eId, $sId, $n->producto_id, 'Agotado', 0, $n->stock_minimo);
                $creadas++;
            } elseif ($n->stock_actual < $n->stock_minimo) {
                $this->upsertAlerta($eId, $sId, $n->producto_id, 'BajoMinimo', $n->stock_actual, $n->stock_minimo);
                $creadas++;
            }
        }

        $this->audit($user, 'alertas', 'generar', 'alertas_stock', null,
            null, ['generadas' => $creadas], "Escaneo de alertas: {$creadas} alertas activas");

        return $this->ok($res, ['alertas_procesadas' => $creadas], 'Alertas actualizadas');
    }

    // ── POST /api/alertas/{id}/resolver ──────────────────────────────────────
    public function resolver(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $alerta = Capsule::table('alertas_stock')
            ->where('id', $a['id'])
            ->where('empresa_id', $user->empresa_id)
            ->first();

        if (!$alerta) return $this->notFound($res);

        Capsule::table('alertas_stock')->where('id', $a['id'])->update([
            'estado'       => 'Resuelta',
            'resuelta_por' => $user->id,
            'resuelta_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->audit($user, 'alertas', 'resolver', 'alertas_stock', $a['id']);

        return $this->ok($res, null, 'Alerta marcada como resuelta');
    }

    // ── POST /api/alertas/{id}/ignorar ────────────────────────────────────────
    public function ignorar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        Capsule::table('alertas_stock')
            ->where('id', $a['id'])
            ->where('empresa_id', $user->empresa_id)
            ->update(['estado' => 'Ignorada']);

        return $this->ok($res, null, 'Alerta ignorada');
    }

    // ── GET /api/alertas/export ───────────────────────────────────────────────
    public function export(Request $r, Response $res): Response
    {
        $user    = $r->getAttribute('user');
        $alertas = Capsule::table('alertas_stock as a')
            ->join('productos as p', 'a.producto_id', '=', 'p.id')
            ->where('a.empresa_id', $user->empresa_id)
            ->where('a.estado', 'Activa')
            ->select('a.*', 'p.nombre as producto', 'p.codigo_interno as codigo')
            ->orderBy('a.tipo')
            ->get();

        $headers = ['Tipo', 'Producto', 'Código', 'Stock Actual', 'Stock Mínimo',
                    'F.Vencimiento', 'Días p/Vencer', 'Estado'];
        $rows = $alertas->map(fn($a) => [
            $a->tipo, $a->producto, $a->codigo,
            $a->stock_actual, $a->stock_minimo ?? '—',
            $a->fecha_vencimiento ?? '—',
            $a->dias_para_vencer !== null ? $a->dias_para_vencer : '—',
            $a->estado,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows, 'alertas_' . date('Y-m-d'));
    }

    // ── Privado ───────────────────────────────────────────────────────────────

    private function upsertAlerta(
        int     $eId,
        int     $sId,
        int     $productoId,
        string  $tipo,
        int     $stockActual,
        ?int    $stockMinimo    = null,
        ?string $fechaVencimiento = null,
        ?int    $diasVencer     = null
    ): void {
        $existing = Capsule::table('alertas_stock')
            ->where([
                'empresa_id'  => $eId,
                'sucursal_id' => $sId,
                'producto_id' => $productoId,
                'tipo'        => $tipo,
                'estado'      => 'Activa',
            ])
            ->when($fechaVencimiento, fn($q) => $q->where('fecha_vencimiento', $fechaVencimiento))
            ->first();

        $datos = [
            'stock_actual'       => $stockActual,
            'stock_minimo'       => $stockMinimo,
            'fecha_vencimiento'  => $fechaVencimiento,
            'dias_para_vencer'   => $diasVencer,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            Capsule::table('alertas_stock')->where('id', $existing->id)->update($datos);
        } else {
            Capsule::table('alertas_stock')->insert(array_merge($datos, [
                'empresa_id'  => $eId,
                'sucursal_id' => $sId,
                'producto_id' => $productoId,
                'tipo'        => $tipo,
                'estado'      => 'Activa',
                'created_at'  => date('Y-m-d H:i:s'),
            ]));
        }
    }
}
