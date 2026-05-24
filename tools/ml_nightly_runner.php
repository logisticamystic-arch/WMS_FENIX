<?php
/**
 * ml_nightly_runner.php — Tarea nocturna de Inteligencia ML
 *
 * Ejecuta en secuencia:
 *   1. Predictor de vencimientos (ml_expiry_predictor.py)
 *   2. Detector de anomalías (ml_anomaly_detector.py)
 *   3. Genera notificaciones automáticas a supervisores
 *   4. Registra resumen en logs/app.log
 *
 * Configuración en XAMPP (Task Scheduler de Windows):
 *   Programa: C:\xampp\php\php.exe
 *   Argumentos: C:\xampp\htdocs\WMS_Fénix\tools\ml_nightly_runner.php
 *   Hora sugerida: 02:00 AM (baja actividad de usuarios)
 *
 * También se puede invocar manualmente:
 *   php tools/ml_nightly_runner.php
 *   php tools/ml_nightly_runner.php --empresa=1 --sucursal=1
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Helpers\NotificationService;

// ── Argumentos CLI ───────────────────────────────────────────────────────────
$opts = getopt('', ['empresa:', 'sucursal:', 'dry-run', 'verbose']);
$dryRun  = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);

// ── Helpers de log ───────────────────────────────────────────────────────────
$logFile = $root . '/logs/ml_nightly.log';
$logLine = function (string $level, string $msg) use ($logFile, $verbose) {
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] [{$level}] {$msg}" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($verbose) echo $line;
};

$logLine('INFO', '=== ML Nightly Runner iniciado ===');

// ── Obtener empresas y sucursales activas ────────────────────────────────────
try {
    $query = Capsule::table('empresas as e')
        ->join('sucursales as s', 's.empresa_id', '=', 'e.id')
        ->where('e.activo', 1)
        ->where('s.activo', 1)
        ->select('e.id as empresa_id', 'e.nombre as empresa_nombre', 's.id as sucursal_id', 's.nombre as sucursal_nombre');

    if (isset($opts['empresa'])) {
        $query->where('e.id', (int)$opts['empresa']);
    }
    if (isset($opts['sucursal'])) {
        $query->where('s.id', (int)$opts['sucursal']);
    }

    $targets = $query->get();
} catch (\Exception $e) {
    $logLine('ERROR', 'No se pudo conectar a la BD: ' . $e->getMessage());
    exit(1);
}

if ($targets->isEmpty()) {
    $logLine('WARN', 'Sin empresas/sucursales activas para procesar.');
    exit(0);
}

$logLine('INFO', "Procesando {$targets->count()} empresa(s)/sucursal(es).");

// ── Script paths ─────────────────────────────────────────────────────────────
$mlDir          = $root . '/tools/';
$predictorScript = $mlDir . 'ml_expiry_predictor.py';
$anomalyScript   = $mlDir . 'ml_anomaly_detector.py';

// ── Función auxiliar: ejecutar script Python ──────────────────────────────────
function runPython(string $script, string $jsonPayload, callable $log): ?array
{
    $escaped = escapeshellarg($jsonPayload);
    $cmd     = "echo {$escaped} | python3 " . escapeshellarg($script) . " 2>/dev/null";
    $output  = shell_exec($cmd);

    if (!$output) {
        $log('WARN', "Sin salida de: " . basename($script));
        return null;
    }

    $result = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $log('ERROR', "JSON inválido de: " . basename($script) . " — " . substr($output, 0, 200));
        return null;
    }

    return $result;
}

// ── Procesamiento por empresa/sucursal ────────────────────────────────────────
$totalVenc   = 0;
$totalAnom   = 0;
$totalNotif  = 0;
$errores     = 0;

foreach ($targets as $target) {
    $eId   = $target->empresa_id;
    $sId   = $target->sucursal_id;
    $label = "[E{$eId}/S{$sId}]";

    $logLine('INFO', "{$label} Iniciando empresa '{$target->empresa_nombre}' — sucursal '{$target->sucursal_nombre}'");

    // ──────────────────────────────────────────────────────────────────────────
    // 1. PREDICTOR DE VENCIMIENTOS
    // ──────────────────────────────────────────────────────────────────────────
    try {
        // Construir dataset de consumo por producto con fecha_vencimiento
        $productosConFecha = Capsule::table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.empresa_id',  $eId)
            ->where('i.sucursal_id', $sId)
            ->whereNotNull('i.fecha_vencimiento')
            ->where('i.cantidad', '>', 0)
            ->select([
                'i.producto_id',
                'p.nombre',
                'i.lote',
                'i.fecha_vencimiento',
                Capsule::raw('SUM(i.cantidad) as stock_actual'),
            ])
            ->groupBy('i.producto_id', 'p.nombre', 'i.lote', 'i.fecha_vencimiento')
            ->get();

        $hoy      = date('Y-m-d');
        $productos = [];
        foreach ($productosConFecha as $prod) {
            // Consumo diario: últimos 30 días por día
            $dias30 = [];
            for ($d = 29; $d >= 0; $d--) {
                $fecha = date('Y-m-d', strtotime("-{$d} days"));
                $c = Capsule::table('movimiento_inventarios')
                    ->where('empresa_id',  $eId)
                    ->where('sucursal_id', $sId)
                    ->where('producto_id', $prod->producto_id)
                    ->whereIn('tipo_movimiento', ['SalidaPicking', 'SalidaDespacho', 'Ajuste'])
                    ->where('fecha_movimiento', $fecha)
                    ->sum(Capsule::raw('ABS(cantidad)'));
                $dias30[] = (float)($c ?? 0);
            }

            $productos[] = [
                'producto_id'      => $prod->producto_id,
                'nombre'           => $prod->nombre,
                'lote'             => $prod->lote,
                'fecha_vencimiento'=> $prod->fecha_vencimiento,
                'stock_actual'     => (float)$prod->stock_actual,
                'consumo_historico'=> $dias30,
                'fecha_hoy'        => $hoy,
            ];
        }

        if (empty($productos)) {
            $logLine('INFO', "{$label} Sin productos con fecha de vencimiento.");
        } else {
            $payload = json_encode(['productos' => $productos, 'empresa_id' => $eId, 'sucursal_id' => $sId]);
            $result  = $dryRun ? null : runPython($predictorScript, $payload, $logLine);

            if ($result && !($result['error'] ?? false)) {
                $predictions = $result['predictions'] ?? [];
                $totalVenc  += count($predictions);

                // Guardar predicciones en BD (UPSERT)
                $now = date('Y-m-d H:i:s');
                foreach ($predictions as $p) {
                    $key = [
                        'empresa_id'        => $eId,
                        'sucursal_id'       => $sId,
                        'producto_id'       => $p['producto_id'],
                        'lote'              => $p['lote'] ?? null,
                        'fecha_vencimiento' => $p['fecha_vencimiento'],
                    ];
                    $vals = [
                        'dias_para_vencer'   => $p['dias_para_vencer']   ?? 0,
                        'stock_actual'       => $p['stock_actual']        ?? 0,
                        'consumo_diario'     => $p['consumo_diario']      ?? 0,
                        'dias_agotamiento'   => $p['dias_agotamiento']    ?? 0,
                        'unidades_en_riesgo' => $p['unidades_en_riesgo']  ?? 0,
                        'nivel_riesgo'       => $p['nivel_riesgo']        ?? 'bajo',
                        'confianza'          => $p['confianza']           ?? 0.5,
                        'recomendaciones'    => json_encode($p['recomendaciones'] ?? []),
                        'serie_consumo'      => json_encode($p['consumo_historico'] ?? []),
                        'calculado_at'       => $now,
                        'updated_at'         => $now,
                    ];

                    $existing = Capsule::table('expiry_predictions')->where($key)->first();
                    if ($existing) {
                        Capsule::table('expiry_predictions')->where($key)->update($vals);
                    } else {
                        Capsule::table('expiry_predictions')->insert(array_merge($key, $vals, ['created_at' => $now]));
                    }
                }

                // Notificaciones
                $enRiesgo = array_filter($predictions, fn($p) => in_array($p['nivel_riesgo'] ?? '', ['critico', 'alto']));
                if (!empty($enRiesgo)) {
                    $enRiesgo = array_values($enRiesgo);
                    $notif    = NotificationService::alertarVencimientos($eId, $sId, $enRiesgo);
                    $totalNotif += $notif;
                    $logLine('INFO', "{$label} Predictor: " . count($predictions) . " pred., " . count($enRiesgo) . " en riesgo, {$notif} notif.");
                } else {
                    $logLine('INFO', "{$label} Predictor: " . count($predictions) . " pred., sin riesgo alto/crítico.");
                }
            } elseif ($dryRun) {
                $logLine('INFO', "{$label} [DRY-RUN] Se habría ejecutado predictor sobre " . count($productos) . " productos.");
            } else {
                $logLine('WARN', "{$label} Predictor sin resultados.");
                $errores++;
            }
        }
    } catch (\Exception $e) {
        $logLine('ERROR', "{$label} Predictor falló: " . $e->getMessage());
        $errores++;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. DETECTOR DE ANOMALÍAS
    // ──────────────────────────────────────────────────────────────────────────
    try {
        $desde       = date('Y-m-d', strtotime('-30 days'));
        $movimientos = Capsule::table('movimiento_inventarios')
            ->where('empresa_id',  $eId)
            ->where('sucursal_id', $sId)
            ->where('fecha_movimiento', '>=', $desde)
            ->select(['id', 'producto_id', 'tipo_movimiento', 'cantidad', 'fecha_movimiento',
                      Capsule::raw('auxiliar_id as usuario_id'), 'referencia_tipo'])
            ->orderBy('fecha_movimiento')
            ->limit(5000)
            ->get()->toArray();

        $ajustes = Capsule::table('movimiento_inventarios')
            ->where('empresa_id',  $eId)
            ->where('sucursal_id', $sId)
            ->where('tipo_movimiento', 'Ajuste')
            ->where('fecha_movimiento', '>=', $desde)
            ->select(['id', 'producto_id', 'cantidad',
                      Capsule::raw('auxiliar_id as usuario_id'),
                      Capsule::raw('fecha_movimiento as fecha'),
                      'observaciones as motivo'])
            ->limit(2000)
            ->get()->toArray();

        $payload = json_encode([
            'empresa_id'  => $eId,
            'sucursal_id' => $sId,
            'movimientos' => array_map(fn($r) => (array)$r, $movimientos),
            'ajustes'     => array_map(fn($r) => (array)$r, $ajustes),
        ]);

        $result = $dryRun ? null : runPython($anomalyScript, $payload, $logLine);

        if ($result && !($result['error'] ?? false)) {
            $anomalias  = $result['anomalias'] ?? [];
            $totalAnom += count($anomalias);

            // Guardar anomalías en BD (evitando duplicados del día)
            $hoy     = date('Y-m-d');
            $guardadas = 0;
            foreach ($anomalias as $a) {
                $existe = Capsule::table('anomaly_flags')
                    ->where('empresa_id', $eId)
                    ->where('tipo', $a['tipo'] ?? 'inventario')
                    ->where('titulo', $a['titulo'] ?? '')
                    ->whereDate('created_at', $hoy)
                    ->exists();
                if (!$existe) {
                    Capsule::table('anomaly_flags')->insert([
                        'empresa_id'    => $eId,
                        'sucursal_id'   => $sId,
                        'tipo'          => $a['tipo']       ?? 'inventario',
                        'severidad'     => $a['severidad']  ?? 'media',
                        'titulo'        => $a['titulo']     ?? 'Anomalía detectada',
                        'descripcion'   => $a['descripcion'] ?? '',
                        'datos_anomalia'=> json_encode($a['datos'] ?? []),
                        'estado'        => 'pendiente',
                        'created_at'    => date('Y-m-d H:i:s'),
                        'updated_at'    => date('Y-m-d H:i:s'),
                    ]);
                    $guardadas++;
                }
            }

            // Notificaciones
            if ($guardadas > 0) {
                $notif = NotificationService::alertarAnomalias($eId, $sId, $anomalias);
                $totalNotif += $notif;
            }
            $logLine('INFO', "{$label} Anomalías: " . count($anomalias) . " detectadas, {$guardadas} nuevas.");
        } elseif ($dryRun) {
            $logLine('INFO', "{$label} [DRY-RUN] Se habría ejecutado detector de anomalías.");
        } else {
            $logLine('WARN', "{$label} Detector sin resultados.");
        }
    } catch (\Exception $e) {
        $logLine('ERROR', "{$label} Detector falló: " . $e->getMessage());
        $errores++;
    }
}

// ── Resumen final ─────────────────────────────────────────────────────────────
$logLine('INFO', "=== Resumen: {$totalVenc} predicciones | {$totalAnom} anomalías | {$totalNotif} notificaciones | {$errores} errores ===");

// También escribir al log principal de la app
$appLog = $root . '/logs/app.log';
file_put_contents($appLog,
    "[" . date('Y-m-d H:i:s') . "] [ML-NIGHTLY] " .
    "Pred:{$totalVenc} Anom:{$totalAnom} Notif:{$totalNotif} Err:{$errores}" . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

exit($errores > 0 ? 1 : 0);
