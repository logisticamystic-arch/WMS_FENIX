<?php
// Corrección 2026-10-08: líneas de la 2ª importación (16:06) en Planillas 1951-1958
// quedaron EnProceso sin auxiliar ni reserva. Hereda el auxiliar del ambiente en la
// planilla y reserva stock con la misma rutina del sistema. Uso: php ... [--aplicar]
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\TenantContext;

$aplicar = in_array('--aplicar', $argv, true);
TenantContext::setCurrentTenant(2, 2);
$user = (object)['id' => 0, 'empresa_id' => 2, 'sucursal_id' => 2, 'rol' => 'Administrador'];
$req  = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')->withAttribute('empresa_id', 2);

$lineas = Capsule::select("
  select pd.id, pd.orden_picking_id, pd.ambiente, op.planilla_numero,
    (select pd2.auxiliar_id from picking_detalles pd2 join orden_pickings op2 on op2.id=pd2.orden_picking_id
      where op2.empresa_id=op.empresa_id and op2.planilla_numero=op.planilla_numero
        and pd2.ambiente=pd.ambiente and pd2.auxiliar_id is not null order by pd2.id limit 1) aux
  from picking_detalles pd join orden_pickings op on op.id=pd.orden_picking_id
  where op.empresa_id=2 and op.estado='EnProceso' and pd.estado='EnProceso'
    and pd.auxiliar_id is null and pd.created_at::date = date '2026-10-08'");

Capsule::beginTransaction();
$porOrden = []; $sinAux = 0;
foreach ($lineas as $l) {
    if (!$l->aux) { $sinAux++; Capsule::table('picking_detalles')->where('id', $l->id)->update(['estado' => 'Pendiente']); continue; }
    Capsule::table('picking_detalles')->where('id', $l->id)->update(['auxiliar_id' => $l->aux, 'updated_at' => date('Y-m-d H:i:s')]);
    $porOrden[$l->orden_picking_id][] = $l->id;
}
$c = new \App\Controllers\PickingController();
$m = new ReflectionMethod($c, '_reservarInventarioBatch'); $m->setAccessible(true);
foreach ($porOrden as $oid => $ids) $m->invoke($c, [$oid], $user, $req, $ids);

$res = Capsule::select("select op.planilla_numero, pd.ambiente, pe.nombre, count(*) n, count(pd.ubicacion_id) con_ubic
  from picking_detalles pd join orden_pickings op on op.id=pd.orden_picking_id left join personal pe on pe.id=pd.auxiliar_id
  where pd.created_at >= '2026-10-08 16:00' and op.estado='EnProceso' group by 1,2,3 order by 1,2");
foreach ($res as $x) echo "$x->planilla_numero | $x->ambiente | $x->nombre | $x->n líneas | $x->con_ubic con ubicación\n";
echo count($lineas) . " líneas; sin auxiliar en su ambiente (quedan Pendiente para asignar): $sinAux\n";
if ($aplicar) { Capsule::commit(); echo "APLICADO\n"; } else { Capsule::rollBack(); echo "SIMULACIÓN (rollback)\n"; }
