<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Auditoría WMS Fénix 2026-07-22/24 — llaves foráneas.
 *
 * Descubrimiento: se extrajeron las 140 relaciones belongsTo() reales de los 62
 * modelos Eloquent (vía reflexión, no adivinando por nombre de columna), y se
 * verificó cada una contra la base de datos real buscando registros huérfanos.
 *
 * Resultado: 100 relaciones están 100% limpias (0 huérfanos) y son seguras para
 * FK inmediata. Las otras ~37 NO se tocan aquí — tienen huérfanos reales que
 * requieren decisión de negocio, no de ingeniería (ver hallazgo abajo).
 *
 * HALLAZGO IMPORTANTE (no corregido, solo documentado): en algún momento la
 * tabla `empresas` pasó de tener un registro id=1 a tener solo id=2, y `productos`
 * fue recargada con IDs nuevos (5750-6856) abandonando el rango anterior
 * (<5750). Esto dejó huérfano un conjunto grande de datos HISTÓRICOS: casi todo
 * `producto_eans` (2928/2961 filas), varias filas de `movimiento_inventarios`
 * (kardex — 11 filas de abril 2026), `rol_permisos` (53/53 filas, todas
 * empresa_id=1), `ajustes_inventario`, `conteo_detalles/inventarios`,
 * `recepcion_detalles`, `sesiones_inventario`, `sesion_lineas`,
 * `orden_compra_detalles`, `ordenes_compra`, `proveedores`, `zonas`, y el mapeo
 * `ubicaciones.zona -> zonas.codigo`. Los datos operativos ACTUALES (inventarios,
 * picking_detalles, ajuste_ubicacion_detalles, alertas_stock, sesion_asignaciones)
 * están limpios — el problema es exclusivamente histórico/heredado. No se borra
 * ni se repara esa data aquí: el kardex es inmutable por regla de oro del
 * sistema, y decidir qué hacer con historia huérfana (archivar, remapear,
 * dejar así) es una decisión de negocio, no algo para resolver en silencio.
 *
 * ON DELETE parametrizado por tipo de relación (no un valor único para todas):
 *   - RESTRICT: empresa_id/sucursal_id siempre, y toda referencia a tablas
 *     maestras (productos, ubicaciones, clientes, proveedores, empresas,
 *     sucursales, rutas) — nunca se debe poder borrar un maestro que todavía
 *     tiene historia real apuntándole.
 *   - SET NULL: columnas "quién hizo esto" que referencian personal.id y son
 *     nullable (auxiliar_id, aprobado_por, procesado_por, etc.) — que un
 *     empleado deje de existir no debe borrar en cascada el historial
 *     operativo, solo desvincular el nombre.
 *   - CASCADE: solo relaciones detalle-de-encabezado genuinas dentro del mismo
 *     agregado (ej. picking_detalles.orden_picking_id -> orden_pickings) —
 *     borrar el encabezado borra sus líneas, que es exactamente lo que varios
 *     controladores ya hacen a mano hoy.
 */
return new class {
    private const FKS = [
        // ── RESTRICT ──────────────────────────────────────────────────────────
        ['ajustes_inventario', 'sesion_id', 'sesiones_inventario', 'id', 'RESTRICT'],
        ['ajustes_inventario', 'linea_id', 'sesion_lineas', 'id', 'RESTRICT'],
        ['ajustes_inventario', 'movimiento_id', 'movimiento_inventarios', 'id', 'RESTRICT'],
        ['ajuste_ubicacion', 'ubicacion_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['ajuste_ubicacion', 'auxiliar_id', 'personal', 'id', 'RESTRICT'],
        ['ajuste_ubicacion_detalles', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['alertas_stock', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['aprobaciones_vencimiento', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['aprobaciones_vencimiento', 'solicitado_por', 'personal', 'id', 'RESTRICT'],
        ['bloqueo_lotes', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['certificaciones', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['certificaciones', 'usuario_id', 'personal', 'id', 'RESTRICT'],
        ['certificacion_despachos', 'despacho_id', 'despachos', 'id', 'RESTRICT'],
        ['certificacion_despachos', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['certificacion_despachos', 'escaneado_por', 'personal', 'id', 'RESTRICT'],
        ['certificacion_detalles', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['certificacion_detalles', 'cliente_id', 'clientes', 'id', 'RESTRICT'],
        ['citas', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['citas', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['clientes', 'ruta_id', 'rutas', 'id', 'RESTRICT'],
        ['despachos', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['despachos', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['despachos', 'muelle_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['despachos', 'ruta_id', 'rutas', 'id', 'RESTRICT'],
        ['devoluciones', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['devoluciones', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['devoluciones', 'recepcion_id', 'recepciones', 'id', 'RESTRICT'],
        ['devoluciones', 'auxiliar_id', 'personal', 'id', 'RESTRICT'],
        ['devoluciones', 'causal_devolucion_id', 'causales_devolucion', 'id', 'RESTRICT'],
        ['devoluciones', 'ubicacion_patio_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['devolucion_detalles', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['inv_general_asignaciones', 'personal_id', 'personal', 'id', 'RESTRICT'],
        ['inv_general_asignaciones', 'asignado_por', 'personal', 'id', 'RESTRICT'],
        ['inv_general_conteos', 'ubicacion_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['inv_general_conteos', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['inv_general_conteos', 'personal_id', 'personal', 'id', 'RESTRICT'],
        ['inv_general_diferencias', 'ubicacion_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['inv_general_diferencias', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['inventarios', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['inventarios', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['inventarios', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['inventarios', 'ubicacion_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['marcas', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['miscelaneos', 'cliente_id', 'clientes', 'id', 'RESTRICT'],
        ['niveles_reposicion', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['ordenes_compra', 'proveedor_id', 'proveedores', 'id', 'RESTRICT'],
        ['orden_pickings', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['orden_pickings', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['parametros', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['personal', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['personal', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['personal_permisos', 'personal_id', 'personal', 'id', 'RESTRICT'],
        ['personal_permisos', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['picking_detalles', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['picking_detalles', 'ubicacion_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['productos', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['productos', 'marca_id', 'marcas', 'id', 'RESTRICT'],
        ['productos', 'categoria_id', 'categoria_productos', 'id', 'RESTRICT'],
        ['productos', 'ambiente_id', 'ambientes', 'id', 'RESTRICT'],
        ['recepciones', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['recepciones', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],
        ['recepciones', 'cita_id', 'citas', 'id', 'RESTRICT'],
        ['recepciones', 'auxiliar_id', 'personal', 'id', 'RESTRICT'],
        ['recepciones', 'odc_id', 'ordenes_compra', 'id', 'RESTRICT'],
        ['rol_permisos', 'permiso_id', 'permisos', 'id', 'RESTRICT'],
        ['sesion_asignaciones', 'sesion_id', 'sesiones_inventario', 'id', 'RESTRICT'],
        ['sesion_asignaciones', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['sucursales', 'empresa_id', 'empresas', 'id', 'RESTRICT'],
        ['tarea_reabastecimientos', 'orden_picking_id', 'orden_pickings', 'id', 'RESTRICT'],
        ['tarea_reabastecimientos', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['tarea_reabastecimientos', 'ubicacion_origen_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['tarea_reabastecimientos', 'ubicacion_destino_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['traspasos', 'producto_id', 'productos', 'id', 'RESTRICT'],
        ['traspasos', 'ubicacion_id', 'ubicaciones', 'id', 'RESTRICT'],
        ['traspasos', 'cliente_id', 'clientes', 'id', 'RESTRICT'],
        ['ubicaciones', 'sucursal_id', 'sucursales', 'id', 'RESTRICT'],

        // ── SET NULL (columnas "quién hizo esto", todas nullable) ──────────────
        ['ajuste_ubicacion', 'aprobado_por', 'personal', 'id', 'SET NULL'],
        ['alertas_stock', 'resuelta_por', 'personal', 'id', 'SET NULL'],
        ['aprobaciones_vencimiento', 'aprobado_por', 'personal', 'id', 'SET NULL'],
        ['audit_logs', 'usuario_id', 'personal', 'id', 'SET NULL'],
        ['conteo_inventarios', 'aprobado_por', 'personal', 'id', 'SET NULL'],
        ['despachos', 'auxiliar_id', 'personal', 'id', 'SET NULL'],
        ['devoluciones', 'solicitado_por', 'personal', 'id', 'SET NULL'],
        ['devoluciones', 'aprobado_por', 'personal', 'id', 'SET NULL'],
        ['devoluciones', 'procesado_por', 'personal', 'id', 'SET NULL'],
        ['miscelaneos', 'recibido_por', 'personal', 'id', 'SET NULL'],
        ['orden_pickings', 'auxiliar_id', 'personal', 'id', 'SET NULL'],
        ['picking_detalles', 'auxiliar_id', 'personal', 'id', 'SET NULL'],
        ['sesiones_inventario', 'ajustado_por', 'personal', 'id', 'SET NULL'],
        ['sesion_lineas', 'editado_por', 'personal', 'id', 'SET NULL'],
        ['sesion_lineas', 'eliminado_por', 'personal', 'id', 'SET NULL'],
        ['tarea_reabastecimientos', 'asignado_a', 'personal', 'id', 'SET NULL'],
        ['traspasos', 'auxiliar_id', 'personal', 'id', 'SET NULL'],

        // ── CASCADE (detalle-de-encabezado genuino) ─────────────────────────────
        ['certificacion_detalles', 'certificacion_id', 'certificaciones', 'id', 'CASCADE'],
        ['conteo_detalles', 'conteo_id', 'conteo_inventarios', 'id', 'CASCADE'],
        ['devolucion_detalles', 'devolucion_id', 'devoluciones', 'id', 'CASCADE'],
        ['orden_compra_detalles', 'orden_compra_id', 'ordenes_compra', 'id', 'CASCADE'],
        ['picking_detalles', 'orden_picking_id', 'orden_pickings', 'id', 'CASCADE'],
        ['sesion_lineas', 'sesion_id', 'sesiones_inventario', 'id', 'CASCADE'],
        ['sesion_lineas', 'asignacion_id', 'sesion_asignaciones', 'id', 'CASCADE'],
    ];

    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (self::FKS as [$child, $fk, $parent, $owner, $onDelete]) {
            $constraint = "fk_{$child}_{$fk}";
            $exists = DB::select(
                "SELECT 1 FROM information_schema.table_constraints WHERE table_name = ? AND constraint_name = ?",
                [$child, $constraint]
            );
            if (!empty($exists)) continue;

            // Verificación defensiva final antes de crear: si algo cambió entre el
            // análisis y la ejecución (carrera con otra transacción), no reventar
            // toda la migración por una sola FK — se salta y se reporta al final.
            $huerfanos = DB::select(
                "SELECT COUNT(*) as n FROM \"{$child}\" c WHERE c.\"{$fk}\" IS NOT NULL "
                . "AND NOT EXISTS (SELECT 1 FROM \"{$parent}\" p WHERE p.\"{$owner}\" = c.\"{$fk}\")"
            )[0]->n;
            if ($huerfanos > 0) {
                error_log("[FK-MIGRATION] Saltada {$child}.{$fk} -> {$parent}.{$owner}: {$huerfanos} huérfanos encontrados justo antes de crear la FK.");
                continue;
            }

            $pdo->exec(
                "ALTER TABLE \"{$child}\" ADD CONSTRAINT \"{$constraint}\" "
                . "FOREIGN KEY (\"{$fk}\") REFERENCES \"{$parent}\" (\"{$owner}\") ON DELETE {$onDelete}"
            );
        }
    }

    public function down(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (self::FKS as [$child, $fk, $parent, $owner, $onDelete]) {
            $constraint = "fk_{$child}_{$fk}";
            try {
                $pdo->exec("ALTER TABLE \"{$child}\" DROP CONSTRAINT IF EXISTS \"{$constraint}\"");
            } catch (\Throwable $e) {}
        }
    }
};
