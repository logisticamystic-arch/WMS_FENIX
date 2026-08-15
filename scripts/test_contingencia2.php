<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\OrdenPicking;
$c = new Capsule;
$c->addConnection([
    'driver'=>$_ENV['DB_DRIVER']??'pgsql','host'=>$_ENV['DB_HOST']??'127.0.0.1','port'=>$_ENV['DB_PORT']??'5432',
    'database'=>$_ENV['DB_NAME'],'username'=>$_ENV['DB_USER'],'password'=>$_ENV['DB_PASS'],
    'charset'=>'utf8','prefix'=>'','schema'=>'public',
]);
$c->setAsGlobal(); $c->bootEloquent();

$row = Capsule::table('orden_pickings')->where('empresa_id',2)->where('sucursal_id',2)
    ->whereIn('estado',['Pendiente','EnProceso'])
    ->select('fecha_movimiento')
    ->orderByDesc('fecha_movimiento')->first();
echo "Fecha cruda encontrada: " . var_export($row->fecha_movimiento ?? null, true) . "\n";
$fecha = $row ? substr($row->fecha_movimiento, 0, 10) : date('Y-m-d');
echo "fecha usada: $fecha\n";

$ordenes = OrdenPicking::where('empresa_id', 2)->where('sucursal_id', 2)
    ->whereIn('estado', ['Pendiente', 'EnProceso'])
    ->whereDate('fecha_movimiento', $fecha)
    ->with([
        'detalles' => fn($q) => $q->whereIn('estado', ['Pendiente','EnProceso'])->orderBy('ambiente')->orderBy('id'),
        'detalles.producto:id,nombre,codigo_interno',
        'detalles.ubicacion:id,codigo',
        'auxiliar:id,nombre',
    ])
    ->orderBy('cliente')->orderBy('id')
    ->get();

echo "Ordenes: " . $ordenes->count() . "\n";
$totalLineas = 0;
foreach ($ordenes as $o) { $totalLineas += $o->detalles->count(); }
echo "Lineas totales: $totalLineas\n";

$ctrl = new \App\Controllers\ReportesController();
$ref = new ReflectionMethod($ctrl, 'buildHtmlSeparacion');
$ref->setAccessible(true);
try {
    $html = $ref->invoke($ctrl, $ordenes, $fecha);
    echo "HTML generado OK, longitud=" . strlen($html) . "\n";
    echo "Contiene 'SIN UBICACIÓN': " . (str_contains($html,'SIN UBICACIÓN') ? 'SI' : 'NO') . "\n";
    echo "Contiene 'Firma de quien separó': " . (str_contains($html,'Firma de quien separó') ? 'SI' : 'NO') . "\n";
    $hojas = substr_count($html, 'class="hoja"');
    echo "Hojas generadas: $hojas\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
