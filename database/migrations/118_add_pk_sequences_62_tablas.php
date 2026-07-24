<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Auditoría WMS Fénix 2026-07-22 (Base de datos, hallazgo CRÍTICO #1):
 * ninguna tabla de negocio tenía PRIMARY KEY ni secuencia — el dump
 * produccion_real.sql mostraba 0 FK, 0 SEQUENCE y 1 solo PK en toda la base.
 *
 * Verificado el 2026-07-24 antes de escribir esta migración: de las 97 tablas
 * reales, 35 YA tenían PK (el sistema mejoró desde la auditoría original) y
 * las 62 listadas abajo NO tenían — pero las 62 tienen columna `id` única y
 * no nula en el 100% de sus filas (0 duplicados, 0 nulos, verificado fila por
 * fila), y ninguna supera ~10.200 filas — seguras para agregar PK+secuencia
 * sin depurar datos primero y sin riesgo de bloqueo prolongado.
 *
 * Las llaves foráneas quedan FUERA de esta migración a propósito — requieren
 * un chequeo de registros huérfanos por separado antes de poder aplicarse.
 */
return new class {
    private const TABLAS = [
        'ajustes_inventario', 'alertas_stock', 'anomaly_flags', 'api_keys',
        'archivos_planilla', 'audit_logs', 'categoria_productos', 'cert_planilla_det',
        'cert_planillas', 'certificacion_despachos', 'certificacion_detalles',
        'certificaciones', 'citas', 'clientes', 'conteo_detalles', 'conteo_inventarios',
        'conteo_personal', 'despachos', 'devolucion_detalles', 'devoluciones',
        'empresas', 'expiry_predictions', 'inv_general_asignaciones',
        'inv_general_conteos', 'inv_general_diferencias', 'inv_general_eventos',
        'inventarios', 'inventory_guard_log', 'lineas_planilla', 'marcas',
        'migrations', 'movimiento_inventarios', 'niveles_reposicion',
        'nota_ajuste_detalles', 'notas_ajuste', 'notificaciones', 'odc_auxiliares',
        'odc_personal', 'orden_compra_detalles', 'orden_pickings', 'ordenes_compra',
        'parametros', 'performance_metrics', 'permisos', 'personal',
        'personal_permisos', 'picking_faltantes', 'picking_novedades_stock',
        'producto_eans', 'proveedores', 'recepcion_detalles', 'recepciones',
        'rol_permisos', 'rutas', 'sesion_asignaciones', 'sesion_lineas',
        'sesiones_inventario', 'sucursales', 'tarea_reabastecimientos',
        'tms_webhooks', 'ubicaciones', 'zonas',
    ];

    public function up(): void
    {
        $pdo = DB::connection()->getPdo();

        // Columnas ya IDENTITY (Postgres): traen su propio autoincremento — no se
        // les puede agregar además un DEFAULT nextval() (Postgres lo rechaza:
        // "es una columna de identidad"). Solo les falta la restricción PK.
        $identityCols = DB::select(
            "SELECT table_name FROM information_schema.columns WHERE column_name = 'id' AND is_identity = 'YES' AND table_schema = 'public'"
        );
        $tablasIdentity = array_column($identityCols, 'table_name');

        foreach (self::TABLAS as $t) {
            $hasPk = DB::select(
                "SELECT 1 FROM information_schema.table_constraints WHERE table_name = ? AND constraint_type = 'PRIMARY KEY'",
                [$t]
            );
            if (!empty($hasPk)) continue;

            if (!in_array($t, $tablasIdentity, true)) {
                $seq = "{$t}_id_seq";
                $pdo->exec("CREATE SEQUENCE IF NOT EXISTS \"{$seq}\"");
                $pdo->exec("ALTER TABLE \"{$t}\" ALTER COLUMN id SET DEFAULT nextval('{$seq}')");
                $pdo->exec("ALTER SEQUENCE \"{$seq}\" OWNED BY \"{$t}\".id");

                $max = (int) (DB::table($t)->max('id') ?? 0);
                $pdo->exec("SELECT setval('{$seq}', " . ($max > 0 ? $max : 1) . ", " . ($max > 0 ? 'true' : 'false') . ")");
            }

            $pdo->exec("ALTER TABLE \"{$t}\" ADD CONSTRAINT \"{$t}_pkey\" PRIMARY KEY (id)");
        }
    }

    public function down(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (self::TABLAS as $t) {
            try { $pdo->exec("ALTER TABLE \"{$t}\" DROP CONSTRAINT IF EXISTS \"{$t}_pkey\""); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE \"{$t}\" ALTER COLUMN id DROP DEFAULT"); } catch (\Throwable $e) {}
            try { $pdo->exec("DROP SEQUENCE IF EXISTS \"{$t}_id_seq\""); } catch (\Throwable $e) {}
        }
    }
};
