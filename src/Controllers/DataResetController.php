<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Personal;
use App\Helpers\AuditLogger;

/**
 * DataResetController — Reinicio de datos por secciones para un tenant.
 *
 * Permite a un Admin/SuperAdmin vaciar módulos completos de su empresa (para
 * volver a un estado limpio antes de salir a producción, o tras un piloto de
 * pruebas) sin tener que hacerlo tabla por tabla a mano.
 *
 * Diseño de seguridad (todo es deliberado, no lo simplifiques):
 *  - Nunca borra 'empresas', 'migrations' ni '_migrations' — sin excepción.
 *  - Todo borrado se acota por empresa_id del tenant efectivo (getEffectiveEmpresaId).
 *  - Las tablas sin empresa_id propio (detalle de un encabezado) se acotan
 *    resolviendo recursivamente la cadena de FKs hasta llegar a una tabla que
 *    sí tenga empresa_id — nunca se hace un DELETE sin WHERE.
 *  - Si el admin selecciona una sección, se calcula el CIERRE de dependencias:
 *    cualquier otra tabla que tenga una FK apuntando a las tablas seleccionadas
 *    se incluye automáticamente (si no, el motor rechazaría el borrado por las
 *    restricciones RESTRICT que ya protegen la integridad del sistema).
 *  - Requiere confirmación explícita: razón social exacta de la empresa + PIN
 *    del admin que ejecuta, revalidados en el propio endpoint de ejecución
 *    (no solo en el frontend).
 *  - Se registra un audit log ANTES de ejecutar el borrado, con la lista de
 *    tablas y conteos de filas que se van a eliminar — para que quede rastro
 *    aunque el borrado en sí sea irreversible.
 */
class DataResetController extends BaseController
{
    private const TABLAS_PROTEGIDAS = ['empresas', 'migrations', '_migrations'];

    private const SECCIONES = [
        'reset_operativo_total' => [
            'label'  => 'REINICIO OPERATIVO TOTAL (Vacía Picking, Packing, Recepciones, ODC, Inventario, Kardex, Conteos, Devoluciones, Alertas y Logs — Mantiene intactos Productos, Ubicaciones, Clientes, Proveedores, Usuarios)',
            'tablas' => [
                'inventarios', 'movimiento_inventarios', 'inventory_guard_log',
                'recepcion_detalles', 'recepciones', 'orden_compra_detalles', 'ordenes_compra', 'citas', 'odc_auxiliares', 'odc_personal', 'cargue_inicial_lineas',
                'orden_pickings', 'picking_detalles', 'picking_faltantes', 'novedades_picking', 'picking_asignaciones_log', 'picking_consolidados', 'picking_cert_ambiente', 'picking_productos_pendientes', 'picking_novedades_stock', 'tarea_reabastecimientos',
                'packing_sesiones', 'packing_unidades', 'packing_items', 'certificaciones', 'certificacion_detalles', 'certificacion_despachos', 'despachos', 'despacho_ordenes',
                'wave_picking', 'wave_planillas', 'planillas_picking', 'planilla_vrs', 'archivos_planilla', 'cert_planillas', 'cert_planilla_det', 'lineas_planilla',
                'devolucion_detalles', 'devoluciones', 'traspasos',
                'conteo_detalles', 'conteo_personal', 'conteo_inventarios', 'sesion_lineas', 'sesion_asignaciones', 'sesiones_inventario', 'ajustes_inventario', 'ajuste_ubicacion_detalles', 'ajuste_ubicacion', 'inv_general_diferencias', 'inv_general_conteos', 'inv_general_asignaciones', 'inv_general_eventos', 'nota_ajuste_detalles', 'notas_ajuste',
                'bloqueo_lotes', 'aprobaciones_vencimiento',
                'alertas_stock', 'anomaly_flags', 'expiry_predictions', 'notificaciones', 'forecast_demanda', 'ventas_agregadas_ml', 'ubicaciones_optimas', 'clasificaciones_abc_xyz',
                'cross_dock_ordenes', 'cross_dock_detalles', 'yard_appointments', 'tms_webhooks',
                'miscelaneos', 'miscelaneo_fotos', 'audit_logs', 'performance_metrics'
            ],
        ],
        'maestros' => [
            'label'  => 'Maestros (productos, clientes, proveedores)',
            'tablas' => ['producto_eans', 'producto_fotos', 'productos', 'clientes', 'proveedores',
                         'marcas', 'categoria_productos', 'causales_devolucion', 'causales_novedad',
                         'niveles_reposicion'],
        ],
        'inventario_kardex' => [
            'label'  => 'Inventario y Kardex',
            'tablas' => ['inventarios', 'movimiento_inventarios', 'inventory_guard_log'],
        ],
        'recepcion' => [
            'label'  => 'Recepción y Órdenes de Compra',
            'tablas' => ['recepcion_detalles', 'recepciones', 'orden_compra_detalles', 'ordenes_compra',
                         'citas', 'odc_auxiliares', 'odc_personal', 'cargue_inicial_lineas'],
        ],
        'picking_despacho' => [
            'label'  => 'Picking, Packing y Despacho',
            'tablas' => ['orden_pickings', 'picking_detalles', 'picking_faltantes', 'novedades_picking',
                         'picking_asignaciones_log', 'picking_consolidados', 'picking_cert_ambiente',
                         'picking_productos_pendientes', 'picking_novedades_stock', 'tarea_reabastecimientos',
                         'packing_sesiones', 'packing_unidades', 'packing_items', 'certificaciones',
                         'certificacion_detalles', 'certificacion_despachos', 'despachos', 'despacho_ordenes',
                         'wave_picking', 'wave_planillas', 'planillas_picking', 'planilla_vrs',
                         'archivos_planilla', 'cert_planillas', 'cert_planilla_det', 'lineas_planilla'],
        ],
        'devoluciones_traspasos' => [
            'label'  => 'Devoluciones y Traspasos',
            'tablas' => ['devolucion_detalles', 'devoluciones', 'traspasos'],
        ],
        'conteos_ajustes' => [
            'label'  => 'Conteos y Ajustes de Inventario',
            'tablas' => ['conteo_detalles', 'conteo_personal', 'conteo_inventarios', 'sesion_lineas',
                         'sesion_asignaciones', 'sesiones_inventario', 'ajustes_inventario',
                         'ajuste_ubicacion_detalles', 'ajuste_ubicacion', 'inv_general_diferencias',
                         'inv_general_conteos', 'inv_general_asignaciones', 'inv_general_eventos',
                         'nota_ajuste_detalles', 'notas_ajuste'],
        ],
        'bloqueos_aprobaciones' => [
            'label'  => 'Bloqueos y Aprobaciones de Vencimiento',
            'tablas' => ['bloqueo_lotes', 'aprobaciones_vencimiento'],
        ],
        'alertas_ml' => [
            'label'  => 'Alertas, Notificaciones y ML',
            'tablas' => ['alertas_stock', 'anomaly_flags', 'expiry_predictions', 'notificaciones',
                         'forecast_demanda', 'ventas_agregadas_ml', 'ubicaciones_optimas',
                         'clasificaciones_abc_xyz'],
        ],
        'crossdock_yard_tms' => [
            'label'  => 'CrossDock, Patio (Yard) y TMS',
            'tablas' => ['cross_dock_ordenes', 'cross_dock_detalles', 'yard_appointments', 'tms_webhooks'],
        ],
        'miscelaneos' => [
            'label'  => 'Misceláneos',
            'tablas' => ['miscelaneos', 'miscelaneo_fotos'],
        ],
        'auditoria_rendimiento' => [
            'label'  => 'Auditoría y Métricas de Rendimiento',
            'tablas' => ['audit_logs', 'performance_metrics'],
        ],
        // Peligrosa a propósito: puede dejar sin acceso si se borra 'personal'
        // del propio usuario que ejecuta el reinicio. Nunca se incluye en un
        // cierre automático de dependencias — solo se toca si el admin la marca.
        'configuracion' => [
            'label'      => 'Configuración (usuarios, ubicaciones, permisos) — puede dejarte sin acceso',
            'tablas'     => ['personal_permisos', 'rol_permisos', 'permisos', 'api_keys', 'parametros',
                             'impresoras', 'ubicaciones', 'zonas', 'ambientes', 'rutas', 'sucursales',
                             'personal'],
            'peligroso'  => true,
        ],
    ];

    // Helper de escape de identificadores para compatibilidad MySQL / PgSQL
    private function quoteIdent(string $ident): string
    {
        try {
            $driver = Capsule::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        }
        if ($driver === 'mysql') {
            return "`" . str_replace("`", "``", $ident) . "`";
        }
        return "\"" . str_replace("\"", "\"\"", $ident) . "\"";
    }

    // ── GET /api/admin/reset/secciones ────────────────────────────────────────
    public function secciones(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $response)) return $deny;

        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $out = [];
        foreach (self::SECCIONES as $key => $sec) {
            $tablas = [];
            foreach ($sec['tablas'] as $t) {
                $tablas[] = ['tabla' => $t, 'filas' => $this->contarFilasTenant($t, $empresaId)];
            }
            $out[] = [
                'key'       => $key,
                'label'     => $sec['label'],
                'peligroso' => !empty($sec['peligroso']),
                'tablas'    => $tablas,
                'total'     => array_sum(array_column($tablas, 'filas')),
            ];
        }
        return $this->ok($response, $out);
    }

    // ── POST /api/admin/reset/preview  Body: { secciones: [key,...] } ─────────
    public function preview(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $response)) return $deny;

        $data      = $request->getParsedBody() ?? [];
        $seccionesSel = (array)($data['secciones'] ?? []);
        if (empty($seccionesSel)) return $this->error($response, 'Seleccione al menos una sección.', 400);

        $empresaId = $this->getEffectiveEmpresaId($user, $request);

        try {
            $resultado = $this->calcularCierre($seccionesSel, $empresaId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($response, $e->getMessage(), 400);
        }

        return $this->ok($response, $resultado);
    }

    // ── POST /api/admin/reset/execute ─────────────────────────────────────────
    // Body: { secciones: [key,...], razon_social: '...', pin: '...' }
    public function execute(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $response)) return $deny;

        $data         = $request->getParsedBody() ?? [];
        $seccionesSel = (array)($data['secciones'] ?? []);
        $razonSocial  = trim((string)($data['razon_social'] ?? ''));
        $pin          = trim((string)($data['pin'] ?? ''));

        if (empty($seccionesSel)) return $this->error($response, 'Seleccione al menos una sección.', 400);
        if ($razonSocial === '' || $pin === '') {
            return $this->error($response, 'Debe confirmar con la razón social de la empresa y su PIN.', 400);
        }

        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $empresa   = Capsule::table('empresas')->where('id', $empresaId)->first();
        if (!$empresa) return $this->error($response, 'Empresa no encontrada.', 404);

        if (trim($razonSocial) !== trim($empresa->razon_social)) {
            return $this->error($response, 'La razón social no coincide exactamente con la de la empresa. No se ejecutó ningún borrado.', 422);
        }

        $personal = Personal::find($user->id);
        if (!$personal || !$personal->verifyPin($pin)) {
            return $this->error($response, 'PIN incorrecto. No se ejecutó ningún borrado.', 401);
        }

        try {
            $cierre = $this->calcularCierre($seccionesSel, $empresaId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($response, $e->getMessage(), 400);
        }

        $tablasOrdenadas = $cierre['orden_borrado'];
        if (empty($tablasOrdenadas)) {
            return $this->error($response, 'No hay tablas para borrar en la selección indicada.', 400);
        }

        // Auditoría ANTES de ejecutar — el borrado es irreversible, esto es lo
        // único que va a quedar como rastro de qué se eliminó y quién lo hizo.
        $this->audit($user, 'sistema', 'reset_datos_iniciado', 'empresas', $empresaId,
            null, ['secciones' => $seccionesSel, 'plan' => $cierre['detalle']],
            "Reinicio de datos iniciado por {$user->id} — empresa {$empresa->razon_social} — "
                . count($tablasOrdenadas) . ' tablas, ' . $cierre['total_filas'] . ' filas estimadas.');

        $borradas = [];
        try {
            Capsule::transaction(function () use ($tablasOrdenadas, $empresaId, &$borradas) {
                // Desactivar temporalmente restricciones de FK en MySQL/PgSQL
                $driver = Capsule::connection()->getDriverName();
                if ($driver === 'mysql') {
                    Capsule::statement('SET FOREIGN_KEY_CHECKS=0');
                } elseif ($driver === 'pgsql') {
                    Capsule::statement('SET CONSTRAINTS ALL DEFERRED');
                }

                foreach ($tablasOrdenadas as $t) {
                    $n = $this->borrarTablaTenant($t, $empresaId);
                    $borradas[$t] = $n;
                }

                if ($driver === 'mysql') {
                    Capsule::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });
        } catch (\Throwable $e) {
            $driver = Capsule::connection()->getDriverName();
            if ($driver === 'mysql') {
                try { Capsule::statement('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $ex) {}
            }
            $this->audit($user, 'sistema', 'reset_datos_error', 'empresas', $empresaId,
                null, ['error' => $e->getMessage()], 'Reinicio de datos falló y se revirtió: ' . $e->getMessage());
            return $this->error($response, 'Error durante el borrado — se revirtió todo (nada quedó a medias): ' . $e->getMessage(), 500);
        }

        $this->audit($user, 'sistema', 'reset_datos_completado', 'empresas', $empresaId,
            null, ['borradas' => $borradas],
            "Reinicio de datos completado — " . array_sum($borradas) . ' filas eliminadas en ' . count($borradas) . ' tablas.');

        return $this->ok($response, ['borradas' => $borradas, 'total' => array_sum($borradas)], 'Reinicio completado correctamente.');
    }

    // ── Núcleo: cierre de dependencias + orden topológico ─────────────────────

    private function calcularCierre(array $seccionesSel, int $empresaId): array
    {
        $seleccionadas = [];
        foreach ($seccionesSel as $key) {
            if (!isset(self::SECCIONES[$key])) {
                throw new \InvalidArgumentException("Sección desconocida: {$key}");
            }
            foreach (self::SECCIONES[$key]['tablas'] as $t) $seleccionadas[$t] = true;
        }
        $raiz = array_keys($seleccionadas);

        $grafo = $this->cargarGrafoFk(); // [tabla_hija => [[fk_col, tabla_padre], ...]]
        $hijosDe = [];                    // [tabla_padre => [tabla_hija, ...]]  (inverso)
        foreach ($grafo as $hija => $fks) {
            foreach ($fks as [$col, $padre]) {
                $hijosDe[$padre][] = $hija;
            }
        }

        // Cierre: toda tabla que (transitivamente) tenga una FK apuntando a
        // alguna tabla de la selección debe borrarse primero, o el motor
        // rechazará el DELETE del padre por las restricciones RESTRICT.
        $cierre = [];
        $cola = $raiz;
        while (!empty($cola)) {
            $t = array_pop($cola);
            if (isset($cierre[$t])) continue;
            if (in_array($t, self::TABLAS_PROTEGIDAS, true)) continue;
            $cierre[$t] = true;
            foreach ($hijosDe[$t] ?? [] as $hija) {
                if (!isset($cierre[$hija])) $cola[] = $hija;
            }
        }

        // Bloquear que 'configuracion' o 'maestros' se cuelen por cierre automático sin que
        // el admin la haya marcado explícitamente — preservación de tablas maestras.
        $seccionConfig = self::SECCIONES['configuracion']['tablas'];
        $configSeleccionada = in_array('configuracion', $seccionesSel, true);
        if (!$configSeleccionada) {
            foreach ($seccionConfig as $t) unset($cierre[$t]);
        }

        $seccionMaestros = self::SECCIONES['maestros']['tablas'];
        $maestrosSeleccionados = in_array('maestros', $seccionesSel, true);
        if (!$maestrosSeleccionados) {
            foreach ($seccionMaestros as $t) unset($cierre[$t]);
        }

        $tablas = array_keys($cierre);

        // Orden topológico (hijos antes que padres) vía Kahn, usando SOLO
        // aristas dentro del propio conjunto a borrar.
        $orden = $this->ordenTopologico($tablas, $grafo);

        $detalle = [];
        $totalFilas = 0;
        foreach ($orden as $t) {
            $n = $this->contarFilasTenant($t, $empresaId);
            $detalle[] = [
                'tabla'          => $t,
                'filas'          => $n,
                'auto_incluida'  => !in_array($t, $raiz, true),
            ];
            $totalFilas += $n;
        }

        return ['orden_borrado' => $orden, 'detalle' => $detalle, 'total_filas' => $totalFilas];
    }

    /** [tabla_hija => [[columna_fk, tabla_padre], ...]] a partir de information_schema. */
    private function cargarGrafoFk(): array
    {
        $rows = Capsule::select("
            SELECT tc.table_name AS hija, kcu.column_name AS col, ccu.table_name AS padre
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
        ");
        $grafo = [];
        foreach ($rows as $r) {
            $grafo[$r->hija][] = [$r->col, $r->padre];
        }
        return $grafo;
    }

    /** Kahn: procesa primero las tablas que no tienen (dentro del set) quien dependa de ellas aún sin borrar. */
    private function ordenTopologico(array $tablas, array $grafo): array
    {
        $set = array_flip($tablas);
        // gradoSalida[t] = cuántas tablas DENTRO del set dependen de t (le apuntan)
        $dependientes = [];
        foreach ($tablas as $t) $dependientes[$t] = 0;
        foreach ($tablas as $hija) {
            foreach ($grafo[$hija] ?? [] as [$col, $padre]) {
                if (isset($set[$padre])) $dependientes[$padre]++;
            }
        }

        $orden = [];
        $pendientes = $tablas;
        $maxIter = count($tablas) + 1;
        while (!empty($pendientes) && $maxIter-- > 0) {
            $listos = array_filter($pendientes, fn($t) => $dependientes[$t] === 0);
            if (empty($listos)) {
                // Ciclo o dato inconsistente: no debería pasar con FKs bien formadas.
                // Se procesa el resto en el orden que quede para no bloquear el reset.
                $orden = array_merge($orden, $pendientes);
                break;
            }
            foreach ($listos as $t) {
                $orden[] = $t;
                unset($pendientes[array_search($t, $pendientes, true)]);
                foreach ($grafo[$t] ?? [] as [$col, $padre]) {
                    if (isset($dependientes[$padre])) $dependientes[$padre]--;
                }
            }
        }
        return array_values($orden);
    }

    // ── Scoping por empresa (recursivo) ───────────────────────────────────────

    private array $tieneEmpresaCache = [];
    private array $scopeCache = [];

    private function tieneEmpresaId(string $tabla): bool
    {
        if (isset($this->tieneEmpresaCache[$tabla])) return $this->tieneEmpresaCache[$tabla];
        $r = Capsule::select(
            "SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = 'empresa_id'",
            [$tabla]
        );
        return $this->tieneEmpresaCache[$tabla] = !empty($r);
    }

    /**
     * Devuelve ['where' => 'sql...', 'bindings' => [...]] que acota `tabla` al
     * tenant, resolviendo recursivamente vía FK si la tabla no tiene empresa_id
     * propio.
     */
    private function resolverScope(string $tabla, int $empresaId, array $grafo, array $visitando = []): array
    {
        $cacheKey = $tabla;
        if (isset($this->scopeCache[$cacheKey])) return $this->scopeCache[$cacheKey];

        if ($this->tieneEmpresaId($tabla)) {
            return $this->scopeCache[$cacheKey] = ['where' => 'empresa_id = ?', 'bindings' => [$empresaId]];
        }

        if (isset($visitando[$tabla])) {
            throw new \RuntimeException("Ciclo de FKs detectado resolviendo el alcance de la tabla '{$tabla}'.");
        }
        $visitando[$tabla] = true;

        foreach ($grafo[$tabla] ?? [] as [$col, $padre]) {
            if (in_array($padre, self::TABLAS_PROTEGIDAS, true)) continue;
            try {
                $padreScope = $this->resolverScope($padre, $empresaId, $grafo, $visitando);
            } catch (\Throwable $e) {
                continue; // intentar con otra FK de la misma tabla
            }
            $padreQ = $this->quoteIdent($padre);
            $colQ   = $this->quoteIdent($col);
            $sub    = "SELECT id FROM {$padreQ} WHERE {$padreScope['where']}";
            return $this->scopeCache[$cacheKey] = [
                'where'    => "{$colQ} IN ({$sub})",
                'bindings' => $padreScope['bindings'],
            ];
        }

        throw new \RuntimeException("No se pudo determinar el alcance por empresa para la tabla '{$tabla}' — no se borra sin acotar.");
    }

    private function contarFilasTenant(string $tabla, int $empresaId): int
    {
        try {
            $grafo  = $this->cargarGrafoFk();
            $scope  = $this->resolverScope($tabla, $empresaId, $grafo);
            $tablaQ = $this->quoteIdent($tabla);
            $sql    = "SELECT COUNT(*) as n FROM {$tablaQ} WHERE {$scope['where']}";
            return (int) (Capsule::select($sql, $scope['bindings'])[0]->n ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function borrarTablaTenant(string $tabla, int $empresaId): int
    {
        if (in_array($tabla, self::TABLAS_PROTEGIDAS, true)) {
            throw new \RuntimeException("Intento de borrar tabla protegida '{$tabla}' bloqueado.");
        }
        $grafo  = $this->cargarGrafoFk();
        $scope  = $this->resolverScope($tabla, $empresaId, $grafo);
        $tablaQ = $this->quoteIdent($tabla);
        $sql    = "DELETE FROM {$tablaQ} WHERE {$scope['where']}";
        return Capsule::delete($sql, $scope['bindings']);
    }
}
