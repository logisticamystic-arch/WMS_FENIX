<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\Producto;
use App\Models\ProductoEan;
use App\Models\Proveedor;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * InboundController — Órdenes de Compra (ODC)
 * Gestión completa del ciclo: Borrador → Confirmada → Cerrada
 * Registro de auditoría en cada transición.
 */
class InboundController extends BaseController
{
    // ── GET /api/odc ─────────────────────────────────────────────────────────
    public function getOrdenesCompra(Request $req, Response $res): Response
    {
        $user   = $req->getAttribute('user');
        $params = $req->getQueryParams();
        [$ini, $fin] = $this->getDateRange($params);

        $q = OrdenCompra::where('empresa_id', $user->empresa_id)
            ->whereBetween('created_at', [$ini, $fin])
            ->with(['proveedor']);

        if (!empty($params['estado'])) {
            $q->where('estado', $params['estado']);
        }
        if (!empty($params['proveedor_id'])) {
            $q->where('proveedor_id', $params['proveedor_id']);
        }

        $ordenes = $q->orderBy('created_at', 'desc')->get();

        // Export Excel
        if (($params['export'] ?? '') === 'excel') {
            $headers = ['# ODC', 'Proveedor', 'Fecha', 'Estado', 'Observaciones'];
            $rows = $ordenes->map(fn($o) => [
                $o->numero_odc,
                $o->proveedor->razon_social ?? '—',
                $o->fecha,
                $o->estado,
                $o->observaciones ?? '',
            ])->toArray();
            return $this->exportCsv($res, $headers, $rows,
                'odc_' . date('Y-m-d'));
        }

        return $this->ok($res, $ordenes);
    }

    // ── GET /api/odc/{id} ────────────────────────────────────────────────────
    public function getODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc  = OrdenCompra::where('empresa_id', $user->empresa_id)
            ->with(['proveedor', 'detalles.producto'])
            ->find($a['id']);

        if (!$odc) return $this->notFound($res);
        return $this->ok($res, $odc);
    }

    // ── POST /api/odc ────────────────────────────────────────────────────────
    public function createOrdenCompra(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $data = $req->getParsedBody() ?? [];

        if (empty($data['proveedor_id'])) {
            return $this->error($res, 'El proveedor es requerido');
        }

        try {
            $odc = Capsule::transaction(function () use ($data, $user) {
                $odc = OrdenCompra::create([
                    'empresa_id'    => $user->empresa_id,
                    'proveedor_id'  => $data['proveedor_id'],
                    'numero_odc'    => 'ODC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'fecha'         => $data['fecha'] ?? date('Y-m-d'),
                    'estado'        => 'Borrador',
                    'observaciones' => $data['observaciones'] ?? null,
                ]);

                // Líneas de detalle
                if (!empty($data['detalles']) && is_array($data['detalles'])) {
                    foreach ($data['detalles'] as $det) {
                        OrdenCompraDetalle::create([
                            'orden_compra_id'    => $odc->id,
                            'producto_id'        => $det['producto_id'],
                            'cantidad_solicitada'=> $det['cantidad'] ?? 0,
                            'cantidad_recibida'  => 0,
                        ]);
                    }
                }

                return $odc;
            });

            $this->audit($user, 'odc', 'crear', 'ordenes_compra', $odc->id,
                null, $odc->toArray(), "ODC {$odc->numero_odc} creada");

            return $this->created($res, $odc->load('detalles.producto'));
        } catch (\Exception $e) {
            return $this->error($res, 'Error al crear ODC: ' . $e->getMessage());
        }
    }

    // ── PUT /api/odc/{id} ────────────────────────────────────────────────────
    public function updateOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc  = OrdenCompra::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$odc) return $this->notFound($res);
        if ($odc->estado === 'Cerrada') {
            return $this->error($res, 'No se puede modificar una ODC cerrada');
        }

        $data     = $req->getParsedBody() ?? [];
        $anterior = $odc->toArray();

        try {
            Capsule::transaction(function () use ($odc, $data) {
                if (isset($data['observaciones'])) $odc->observaciones = $data['observaciones'];
                if (isset($data['fecha']))         $odc->fecha         = $data['fecha'];
                if (isset($data['estado']) && in_array($data['estado'], ['Borrador', 'Confirmada', 'Cancelada'])) {
                    $odc->estado = $data['estado'];
                }
                $odc->save();

                // Reemplazar detalles si se envían
                if (isset($data['detalles']) && is_array($data['detalles'])) {
                    OrdenCompraDetalle::where('orden_compra_id', $odc->id)->delete();
                    foreach ($data['detalles'] as $det) {
                        OrdenCompraDetalle::create([
                            'orden_compra_id'    => $odc->id,
                            'producto_id'        => $det['producto_id'],
                            'cantidad_solicitada'=> $det['cantidad'] ?? 0,
                            'cantidad_recibida'  => $det['cantidad_recibida'] ?? 0,
                        ]);
                    }
                }
            });

            $this->audit($user, 'odc', 'editar', 'ordenes_compra', $odc->id,
                $anterior, $odc->fresh()->toArray(), "ODC {$odc->numero_odc} actualizada");

            return $this->ok($res, $odc->load('detalles.producto'), 'ODC actualizada');
        } catch (\Exception $e) {
            return $this->error($res, 'Error: ' . $e->getMessage());
        }
    }

    // ── DELETE /api/odc/{id} — solo Admin ────────────────────────────────────
    public function deleteOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$odc) return $this->notFound($res);

        $snapshot = $odc->toArray();
        $odc->detalles()->delete();
        $odc->delete();

        $this->audit($user, 'odc', 'eliminar', 'ordenes_compra', $a['id'],
            $snapshot, null, "ODC {$snapshot['numero_odc']} eliminada por Admin");

        return $this->ok($res, null, 'ODC eliminada');
    }

    // ── POST /api/odc/{id}/confirmar ─────────────────────────────────────────
    public function confirmarOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$odc) return $this->notFound($res);
        if ($odc->estado !== 'Borrador') {
            return $this->error($res, "La ODC ya está en estado {$odc->estado}");
        }
        if ($odc->detalles()->count() === 0) {
            return $this->error($res, 'La ODC no tiene líneas de detalle');
        }

        $odc->estado = 'Confirmada';
        $odc->save();

        $this->audit($user, 'odc', 'confirmar', 'ordenes_compra', $odc->id,
            ['estado' => 'Borrador'], ['estado' => 'Confirmada'],
            "ODC {$odc->numero_odc} confirmada");

        return $this->ok($res, $odc, 'ODC confirmada exitosamente');
    }

    // ── POST /api/odc/{id}/cerrar ────────────────────────────────────────────
    public function cerrarOrdenCompra(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $odc = OrdenCompra::where('empresa_id', $user->empresa_id)->find($a['id']);
        if (!$odc) return $this->notFound($res);

        $odc->estado = 'Cerrada';
        $odc->save();

        $this->audit($user, 'odc', 'cerrar', 'ordenes_compra', $odc->id,
            null, ['estado' => 'Cerrada'], "ODC {$odc->numero_odc} cerrada");

        return $this->ok($res, $odc, 'ODC cerrada');
    }

    // ── GET /api/odc/buscar-producto?q= ──────────────────────────────────────
    public function buscarProducto(Request $req, Response $res): Response
    {
        $user  = $req->getAttribute('user');
        $q     = trim($req->getQueryParams()['q'] ?? '');

        if (strlen($q) < 2) {
            return $this->error($res, 'Ingrese al menos 2 caracteres');
        }

        $productos = Producto::where('empresa_id', $user->empresa_id)
            ->where(function ($query) use ($q) {
                $query->where('nombre', 'LIKE', "%{$q}%")
                    ->orWhere('codigo_interno', 'LIKE', "%{$q}%")
                    ->orWhereHas('eans', fn($eq) => $eq->where('codigo_ean', $q));
            })
            ->limit(15)
            ->get(['id', 'nombre', 'codigo_interno', 'unidad_medida', 'peso_unitario']);

        return $this->ok($res, $productos);
    }

    // ── POST /api/odc/importar — importar ODCs desde CSV ─────────────────────
    public function importarODC(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $uploadedFiles = $req->getUploadedFiles();

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
            return $this->error($res, 'El archivo está vacío');
        }

        $sep     = str_contains($lines[0], ';') ? ';' : ',';
        $headers = array_map('trim', str_getcsv(array_shift($lines), $sep));
        $groups  = [];
        $errors  = [];

        foreach ($lines as $i => $line) {
            $cols = array_map('trim', str_getcsv($line, $sep));
            $row  = array_combine($headers, array_pad($cols, count($headers), ''));

            $odcKey    = $row['numero_odc'] ?? $row['numero'] ?? ('IMPORT-' . date('Ymd'));
            $provId    = (int)($row['proveedor_id'] ?? 0);
            $provCod   = $row['proveedor'] ?? '';
            $codigo    = $row['codigo_interno'] ?? $row['codigo'] ?? $row['producto'] ?? '';
            $ean       = $row['ean'] ?? '';
            $cantidad  = max(1, (int)($row['cantidad'] ?? 1));
            $fecha     = $row['fecha'] ?? date('Y-m-d');

            // Buscar proveedor
            if (!$provId && $provCod) {
                $prov = Proveedor::where('empresa_id', $user->empresa_id)
                    ->where(fn($q) => $q->where('razon_social', 'LIKE', "%$provCod%")
                        ->orWhere('nit', $provCod))->first();
                $provId = $prov?->id ?? 0;
            }

            // Buscar producto
            $producto = null;
            if ($codigo) {
                $producto = Producto::where('empresa_id', $user->empresa_id)
                    ->where('codigo_interno', $codigo)->first();
            }
            if (!$producto && $ean) {
                $producto = Producto::findByEan($ean);
            }

            if (!$producto) {
                $errors[] = "Fila " . ($i + 2) . ": producto no encontrado (código=$codigo)";
                continue;
            }

            if (!isset($groups[$odcKey])) {
                $groups[$odcKey] = [
                    'proveedor_id' => $provId,
                    'fecha'        => $fecha,
                    'detalles'     => [],
                ];
            }
            $groups[$odcKey]['detalles'][] = [
                'producto_id'        => $producto->id,
                'cantidad_solicitada'=> $cantidad,
            ];
        }

        if (empty($groups)) {
            return $this->error($res, 'No se encontraron datos válidos. ' . implode(' | ', $errors));
        }

        $creadas = [];
        try {
            Capsule::transaction(function () use ($groups, $user, &$creadas) {
                foreach ($groups as $numOdc => $g) {
                    $odc = OrdenCompra::create([
                        'empresa_id'   => $user->empresa_id,
                        'proveedor_id' => $g['proveedor_id'] ?: null,
                        'numero_odc'   => $numOdc,
                        'fecha'        => $g['fecha'],
                        'estado'       => 'Borrador',
                    ]);
                    foreach ($g['detalles'] as $det) {
                        OrdenCompraDetalle::create([
                            'orden_compra_id'    => $odc->id,
                            'producto_id'        => $det['producto_id'],
                            'cantidad_solicitada'=> $det['cantidad_solicitada'],
                            'cantidad_recibida'  => 0,
                        ]);
                    }
                    $creadas[] = $numOdc;
                }
            });
        } catch (\Exception $e) {
            return $this->error($res, 'Error al crear ODCs: ' . $e->getMessage());
        }

        return $this->ok($res, [
            'odcs_creadas'  => $creadas,
            'advertencias'  => $errors,
        ], count($creadas) . ' ODC(s) creada(s) desde importación');
    }

    // ── GET /api/odc/export ──────────────────────────────────────────────────
    public function exportarODC(Request $req, Response $res, array $a): Response
    {
        $user = $req->getAttribute('user');
        $odc  = OrdenCompra::where('empresa_id', $user->empresa_id)
            ->with(['proveedor', 'detalles.producto'])
            ->find($a['id']);

        if (!$odc) return $this->notFound($res);

        $headers = ['Producto', 'Código', 'Cant. Solicitada', 'Cant. Recibida', 'Pendiente'];
        $rows = $odc->detalles->map(fn($d) => [
            $d->producto->nombre          ?? '—',
            $d->producto->codigo_interno  ?? '—',
            $d->cantidad_solicitada,
            $d->cantidad_recibida,
            $d->cantidad_solicitada - $d->cantidad_recibida,
        ])->toArray();

        return $this->exportCsv($res, $headers, $rows,
            'odc_' . $odc->numero_odc);
    }
}
