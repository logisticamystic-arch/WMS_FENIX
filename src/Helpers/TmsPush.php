<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * TmsPush — arma el payload de un pedido certificado y lo empuja al TMS
 * (sistema separado de entrega en punto de venta). Extraído de
 * scripts/tms_push_pedidos.php (el cron que barre TODO lo certificado
 * pendiente) para poder reusarlo también como push PUNTUAL e inmediato
 * cuando DespachoController::agregarPedidos() asigna el auxiliar de entrega
 * — sin esto, la asignación del auxiliar solo llegaría al TMS si el cron
 * corría DESPUÉS de crear la planilla de cargue, y un pedido ya certificado
 * (yms_sync_status='enviado') nunca se vuelve a tocar por el cron normal.
 *
 * Por eso enviarOrden() NO filtra por yms_sync_status ni estado_despacho —
 * a diferencia del cron (que solo barre lo AÚN no enviado), este método
 * siempre reenvía la orden pedida, para que el TMS reciba la actualización
 * del auxiliar aunque la orden ya se hubiera empujado antes sin él.
 */
class TmsPush
{
    public static function enviarOrden(int $ordenId): array
    {
        $endpoint = $_ENV['TMS_ENDPOINT_URL'] ?? getenv('TMS_ENDPOINT_URL') ?: null;
        $secret   = $_ENV['TMS_SHARED_SECRET'] ?? getenv('TMS_SHARED_SECRET') ?: null;
        if (!$endpoint || !$secret) {
            return ['ok' => false, 'message' => 'TMS_ENDPOINT_URL/TMS_SHARED_SECRET no configurados'];
        }

        $orden = Capsule::table('orden_pickings')->where('id', $ordenId)->first();
        if (!$orden) return ['ok' => false, 'message' => 'Orden no encontrada'];

        $lineas = Capsule::table('picking_detalles as pd')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->where('pd.orden_picking_id', $ordenId)
            ->where('pd.cantidad_certificada', '>', 0)
            ->select([
                'p.id as producto_id', 'p.codigo_interno as codigo', 'p.nombre',
                'p.unidades_caja', 'p.factor_udm',
                'pd.cantidad_certificada', 'pd.lote', 'pd.fecha_vencimiento',
            ])
            ->get()
            ->map(function ($d) {
                $upc = (isset($d->factor_udm) && (float)$d->factor_udm > 0)
                    ? (float)$d->factor_udm : max(1, (float)($d->unidades_caja ?? 1));
                $cant  = (float)$d->cantidad_certificada;
                $cajas = $upc > 1 ? (int)floor($cant / $upc) : $cant;
                $saldo = $upc > 1 ? round($cant - ($cajas * $upc), 3) : 0;
                return [
                    'producto_id'       => $d->producto_id,
                    'codigo'            => $d->codigo,
                    'nombre'            => $d->nombre,
                    'cajas'             => $cajas,
                    'saldo'             => $saldo,
                    'total_unidades'    => $cant,
                    'lote'              => $d->lote,
                    'fecha_vencimiento' => $d->fecha_vencimiento,
                ];
            });

        if ($lineas->isEmpty()) {
            return ['ok' => false, 'message' => 'Sin líneas certificadas todavía (cantidad_certificada = 0)'];
        }

        $agotados = Capsule::table('picking_faltantes as pf')
            ->join('productos as p', 'p.id', '=', 'pf.producto_id')
            ->where('pf.orden_picking_id', $ordenId)
            ->select(['p.codigo_interno as codigo', 'p.nombre', 'pf.cantidad_solicitada', 'pf.cantidad_faltante', 'pf.causa'])
            ->get()
            ->toArray();

        $motivos = Capsule::table('causales_devolucion')
            ->where('empresa_id', $orden->empresa_id)
            ->where('activo', true)
            ->select(['id as wms_causal_id', 'causal as nombre'])
            ->get()
            ->values()->toArray();

        // Auxiliar de entrega asignado en la Planilla de Cargue (Despacho) —
        // null si la orden todavía no está en ningún despacho, o el despacho
        // no tiene auxiliar asignado.
        $auxiliarPersonalId = null;
        if (!empty($orden->despacho_id)) {
            $auxiliarPersonalId = Capsule::table('despachos')->where('id', $orden->despacho_id)->value('auxiliar_id');
        }

        $payload = [
            'orden_picking_id'      => $orden->id,
            'numero_pedido'         => trim($orden->numero_factura ?: $orden->numero_orden ?: ('#' . $orden->id)),
            'planilla_numero'       => $orden->planilla_numero,
            'fecha_planilla'        => $orden->fecha_movimiento,
            'sucursal_entrega'      => $orden->sucursal_entrega,
            'empresa_id'            => $orden->empresa_id,
            'sucursal_id'           => $orden->sucursal_id,
            'auxiliar_personal_id'  => $auxiliarPersonalId ? (int)$auxiliarPersonalId : null,
            // Necesario para que el webhook de confirmación de entrega pueda
            // reportarlo de vuelta — sin esto entregas_ruta.despacho_id nunca
            // se llenaba y el mapa no podía cruzar la placa del vehículo (a
            // pedido explícito de Camilo, 2026-09-29: "no muestra el vehículo
            // en los filtros del mapa").
            'despacho_id'           => $orden->despacho_id ?: null,
            'lineas'                => $lineas->values()->toArray(),
            'agotados'              => $agotados,
            'motivos_devolucion'    => $motivos,
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-TMS-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $respBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $ok = $httpCode >= 200 && $httpCode < 300;
        Capsule::table('orden_pickings')->where('id', $ordenId)->update([
            'yms_sync_status' => $ok ? 'enviado' : 'error',
            'yms_sync_at'     => date('Y-m-d H:i:s'),
        ]);

        return [
            'ok'      => $ok,
            'numero_pedido' => $payload['numero_pedido'],
            'message' => $ok ? null : "HTTP {$httpCode} {$curlErr} {$respBody}",
        ];
    }
}
