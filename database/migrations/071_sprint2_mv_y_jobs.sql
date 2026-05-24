-- ═══════════════════════════════════════════════════════════════════════════
-- MIGRACIÓN 003 — Sprint 2: Vista Materializada + Job de Población Inicial
-- Ejecutar DESPUÉS de 002_sprint2_ml_tables.sql
-- WMS ProOriente · PostgreSQL 16
-- ═══════════════════════════════════════════════════════════════════════════

\echo '>>> Iniciando migración 003 — Vista Materializada + Jobs...'

-- ═══════════════════════════════════════════════════════════════════════════
-- FUNCIÓN: poblar_ventas_ml
-- Agrega las ventas históricas de orden_detalles a ventas_agregadas_ml
-- Llamar una vez para datos históricos, luego mensualmente en cron
-- ═══════════════════════════════════════════════════════════════════════════
CREATE OR REPLACE FUNCTION poblar_ventas_ml(
    p_empresa_id   INTEGER,
    p_sucursal_id  INTEGER,
    p_desde        DATE DEFAULT (CURRENT_DATE - INTERVAL '24 months')::DATE,
    p_hasta        DATE DEFAULT CURRENT_DATE
) RETURNS INTEGER AS $$
DECLARE
    v_insertados INTEGER := 0;
    v_actualizados INTEGER := 0;
BEGIN
    -- INSERT OR UPDATE de ventas mensuales
    INSERT INTO ventas_agregadas_ml (
        empresa_id, sucursal_id, producto_id, periodo,
        unidades_vendidas, valor_vendido, num_transacciones
    )
    SELECT
        o.empresa_id,
        o.sucursal_id,
        od.producto_id,
        DATE_TRUNC('month', o.created_at)::DATE AS periodo,
        SUM(od.cantidad)                          AS unidades_vendidas,
        SUM(od.cantidad * COALESCE(od.precio_unitario, 0)) AS valor_vendido,
        COUNT(DISTINCT o.id)                      AS num_transacciones
    FROM orden_detalles od
    JOIN ordenes o ON od.orden_id = o.id
    WHERE o.empresa_id  = p_empresa_id
      AND o.sucursal_id = p_sucursal_id
      AND o.created_at  >= p_desde
      AND o.created_at  <  p_hasta + INTERVAL '1 month'
      AND o.estado NOT IN ('Anulada', 'Cancelada')
    GROUP BY o.empresa_id, o.sucursal_id, od.producto_id, DATE_TRUNC('month', o.created_at)
    ON CONFLICT (empresa_id, sucursal_id, producto_id, periodo)
    DO UPDATE SET
        unidades_vendidas  = EXCLUDED.unidades_vendidas,
        valor_vendido      = EXCLUDED.valor_vendido,
        num_transacciones  = EXCLUDED.num_transacciones,
        updated_at         = NOW();

    GET DIAGNOSTICS v_insertados = ROW_COUNT;

    -- Registrar la ejecución
    INSERT INTO ejecuciones_ml (empresa_id, sucursal_id, tipo, inicio_at, fin_at, estado,
                                 registros_creados, metricas_json)
    VALUES (p_empresa_id, p_sucursal_id, 'poblar_ventas', NOW(), NOW(), 'completado',
            v_insertados, jsonb_build_object('desde', p_desde, 'hasta', p_hasta));

    RETURN v_insertados;
END;
$$ LANGUAGE plpgsql;

\echo '  ✓ función poblar_ventas_ml() creada'

-- ═══════════════════════════════════════════════════════════════════════════
-- FUNCIÓN: ejecutar_abc_xyz
-- Corre el motor de clasificación ABC-XYZ para todos los productos activos
-- Parámetro p_meses: ventana de análisis (default 12)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE OR REPLACE FUNCTION ejecutar_abc_xyz(
    p_empresa_id   INTEGER,
    p_sucursal_id  INTEGER,
    p_meses        INTEGER DEFAULT 12
) RETURNS INTEGER AS $$
DECLARE
    v_ejecutado_id BIGINT;
    v_procesados   INTEGER := 0;
    v_inicio       TIMESTAMPTZ := NOW();
    v_periodo_ini  DATE := (DATE_TRUNC('month', CURRENT_DATE) - (p_meses || ' months')::INTERVAL)::DATE;
    v_periodo_fin  DATE := CURRENT_DATE;
BEGIN
    -- Registrar inicio
    INSERT INTO ejecuciones_ml (empresa_id, sucursal_id, tipo, inicio_at, estado)
    VALUES (p_empresa_id, p_sucursal_id, 'abc_xyz', v_inicio, 'en_proceso')
    RETURNING id INTO v_ejecutado_id;

    -- Marcar clasificaciones anteriores como no vigentes
    UPDATE clasificaciones_abc_xyz
    SET vigente = FALSE
    WHERE empresa_id  = p_empresa_id
      AND sucursal_id = p_sucursal_id
      AND vigente     = TRUE;

    -- Insertar nueva clasificación
    WITH estadisticas AS (
        SELECT
            producto_id,
            SUM(valor_vendido)                                  AS total_valor,
            SUM(unidades_vendidas)                              AS total_unidades,
            AVG(unidades_vendidas)                              AS demanda_media,
            COALESCE(STDDEV(unidades_vendidas), 0)              AS demanda_std,
            CASE WHEN AVG(unidades_vendidas) > 0
                 THEN COALESCE(STDDEV(unidades_vendidas), 0) / AVG(unidades_vendidas)
                 ELSE 9999 END                                  AS cv,
            COUNT(DISTINCT periodo)                             AS meses_activos
        FROM ventas_agregadas_ml
        WHERE empresa_id  = p_empresa_id
          AND sucursal_id = p_sucursal_id
          AND periodo     >= v_periodo_ini
        GROUP BY producto_id
    ),
    ranking AS (
        SELECT *,
               SUM(total_valor) OVER (
                   ORDER BY total_valor DESC
                   ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
               ) AS valor_acum,
               SUM(total_valor) OVER ()  AS gran_total
        FROM estadisticas
    )
    INSERT INTO clasificaciones_abc_xyz (
        empresa_id, sucursal_id, producto_id,
        periodo_inicio, periodo_fin,
        total_valor, pct_valor_acum, clase_abc,
        demanda_media, demanda_std, coef_variacion, clase_xyz,
        meses_activos, total_unidades,
        zona_recomendada, accion_sugerida
    )
    SELECT
        p_empresa_id,
        p_sucursal_id,
        producto_id,
        v_periodo_ini,
        v_periodo_fin,
        ROUND(total_valor::NUMERIC, 2),
        ROUND((valor_acum / NULLIF(gran_total, 0) * 100)::NUMERIC, 3),
        CASE WHEN valor_acum / NULLIF(gran_total, 0) <= 0.80 THEN 'A'
             WHEN valor_acum / NULLIF(gran_total, 0) <= 0.95 THEN 'B'
             ELSE 'C' END,
        ROUND(demanda_media::NUMERIC, 3),
        ROUND(demanda_std::NUMERIC, 3),
        ROUND(cv::NUMERIC, 4),
        CASE WHEN cv < 0.5  THEN 'X'
             WHEN cv < 1.0  THEN 'Y'
             ELSE                'Z' END,
        meses_activos,
        ROUND(total_unidades::NUMERIC, 3),
        -- Zona recomendada por segmento
        CASE
            WHEN valor_acum / NULLIF(gran_total, 0) <= 0.80 THEN 'oro'
            WHEN valor_acum / NULLIF(gran_total, 0) <= 0.95 THEN 'plata'
            WHEN meses_activos < 2                           THEN 'obsoleto'
            ELSE 'bronce'
        END,
        -- Acción sugerida
        CASE
            WHEN valor_acum / NULLIF(gran_total,0) <= 0.80 AND cv < 0.5
                THEN 'Zona oro. Reposición automática. FEFO estricto.'
            WHEN valor_acum / NULLIF(gran_total,0) <= 0.80 AND cv >= 0.5 AND cv < 1.0
                THEN 'Zona oro. Buffer +20% sobre demanda media.'
            WHEN valor_acum / NULLIF(gran_total,0) <= 0.80 AND cv >= 1.0
                THEN 'Zona oro. Demanda errática — revisar manualmente cada semana.'
            WHEN valor_acum / NULLIF(gran_total,0) <= 0.95
                THEN 'Zona plata. Revisión quincenal de stock.'
            WHEN meses_activos < 2
                THEN 'Sin movimiento. Considerar liquidación o reclasificación.'
            ELSE 'Zona bronce. Lote económico grande. Revisión mensual.'
        END
    FROM ranking;

    GET DIAGNOSTICS v_procesados = ROW_COUNT;

    -- Actualizar registro de ejecución
    UPDATE ejecuciones_ml
    SET fin_at              = NOW(),
        estado              = 'completado',
        productos_procesados= v_procesados,
        registros_creados   = v_procesados,
        metricas_json       = jsonb_build_object(
                                'meses_analizados', p_meses,
                                'periodo_ini', v_periodo_ini,
                                'periodo_fin', v_periodo_fin
                              )
    WHERE id = v_ejecutado_id;

    RETURN v_procesados;
EXCEPTION WHEN OTHERS THEN
    UPDATE ejecuciones_ml
    SET fin_at = NOW(), estado = 'error', error_msg = SQLERRM
    WHERE id = v_ejecutado_id;
    RAISE;
END;
$$ LANGUAGE plpgsql;

\echo '  ✓ función ejecutar_abc_xyz() creada'

-- ═══════════════════════════════════════════════════════════════════════════
-- VISTA MATERIALIZADA: mv_rotacion_productos
-- Pre-calcula todo para el dashboard analítico — refrescar cada hora
-- ═══════════════════════════════════════════════════════════════════════════
DROP MATERIALIZED VIEW IF EXISTS mv_rotacion_productos;

CREATE MATERIALIZED VIEW mv_rotacion_productos AS
SELECT
    c.empresa_id,
    c.sucursal_id,
    c.producto_id,
    p.nombre                    AS producto_nombre,
    p.codigo_interno            AS codigo,
    c.segmento,
    c.clase_abc,
    c.clase_xyz,
    c.total_valor,
    c.total_unidades,
    c.rotacion_anual,
    c.dias_inventario,
    c.demanda_media,
    c.coef_variacion,
    c.zona_recomendada,
    c.accion_sugerida,
    c.meses_activos,
    -- Forecast a 30 días (más reciente)
    f30.demanda_pred            AS forecast_30d,
    f30.alerta_quiebre,
    f30.dias_hasta_quiebre,
    f30.stock_seguridad_sug,
    f30.punto_reorden_sug,
    -- Stock actual disponible
    COALESCE(inv.stock_disponible, 0) AS stock_actual,
    -- Ubicación óptima asignada
    ub.codigo                   AS ubicacion_optima,
    ub.zona                     AS zona_actual,
    -- Score de riesgo operativo (0–100)
    CASE
        WHEN f30.alerta_quiebre = TRUE AND c.clase_abc = 'A' THEN 95
        WHEN f30.alerta_quiebre = TRUE AND c.clase_abc = 'B' THEN 70
        WHEN f30.alerta_quiebre = TRUE                        THEN 50
        WHEN c.clase_abc = 'A' AND c.clase_xyz = 'Z'         THEN 45
        WHEN c.clase_abc = 'A' AND c.clase_xyz = 'Y'         THEN 30
        WHEN c.clase_abc = 'B' AND c.clase_xyz = 'Z'         THEN 25
        ELSE 10
    END                         AS score_riesgo,
    -- Cobertura de días (stock / demanda_media_dia)
    CASE WHEN c.demanda_media > 0
         THEN ROUND((COALESCE(inv.stock_disponible, 0) / (c.demanda_media / 30))::NUMERIC, 1)
         ELSE NULL END          AS dias_cobertura,
    NOW()                       AS calculado_at
FROM clasificaciones_abc_xyz c
JOIN productos p ON c.producto_id = p.id
LEFT JOIN forecast_demanda f30
    ON  f30.producto_id    = c.producto_id
    AND f30.empresa_id     = c.empresa_id
    AND f30.sucursal_id    = c.sucursal_id
    AND f30.horizonte_dias = 30
    AND f30.es_vigente     = TRUE
LEFT JOIN (
    SELECT producto_id, empresa_id, sucursal_id,
           SUM(cantidad) FILTER (WHERE estado = 'Disponible') AS stock_disponible
    FROM inventarios
    GROUP BY producto_id, empresa_id, sucursal_id
) inv ON inv.producto_id   = c.producto_id
     AND inv.empresa_id    = c.empresa_id
     AND inv.sucursal_id   = c.sucursal_id
LEFT JOIN ubicaciones_optimas uo
    ON  uo.producto_id   = c.producto_id
    AND uo.empresa_id    = c.empresa_id
    AND uo.sucursal_id   = c.sucursal_id
    AND uo.vigente       = TRUE
LEFT JOIN ubicaciones ub ON ub.id = uo.ubicacion_id
WHERE c.vigente = TRUE;

-- Índices en la vista materializada
CREATE UNIQUE INDEX idx_mv_rotacion_pk
    ON mv_rotacion_productos (empresa_id, sucursal_id, producto_id);

CREATE INDEX idx_mv_rotacion_riesgo
    ON mv_rotacion_productos (empresa_id, sucursal_id, score_riesgo DESC);

CREATE INDEX idx_mv_rotacion_segmento
    ON mv_rotacion_productos (empresa_id, sucursal_id, segmento);

CREATE INDEX idx_mv_rotacion_alerta
    ON mv_rotacion_productos (empresa_id, sucursal_id, alerta_quiebre)
    WHERE alerta_quiebre = TRUE;

\echo '  ✓ mv_rotacion_productos creada con índices'

-- ═══════════════════════════════════════════════════════════════════════════
-- FUNCIÓN: refresh_mv_rotacion
-- Refresca la vista materializada de forma no bloqueante
-- Programar en pg_cron: SELECT cron.schedule('0 * * * *', 'SELECT refresh_mv_rotacion()');
-- ═══════════════════════════════════════════════════════════════════════════
CREATE OR REPLACE FUNCTION refresh_mv_rotacion() RETURNS VOID AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rotacion_productos;
    RAISE NOTICE 'mv_rotacion_productos refrescada a %', NOW();
END;
$$ LANGUAGE plpgsql;

\echo '  ✓ función refresh_mv_rotacion() creada'
\echo ''
\echo '  Para activar refresco automático (requiere pg_cron):'
\echo "  SELECT cron.schedule('refresh-mv-rotacion', '0 * * * *', 'SELECT refresh_mv_rotacion()');"
\echo ''

-- ═══════════════════════════════════════════════════════════════════════════
-- SEED: Ejecutar población inicial de datos
-- Descomentar y ajustar empresa_id/sucursal_id antes de ejecutar
-- ═══════════════════════════════════════════════════════════════════════════
-- SELECT poblar_ventas_ml(1, 1);      -- empresa 1, sucursal 1, últimos 24 meses
-- SELECT ejecutar_abc_xyz(1, 1, 12); -- clasificación con ventana 12 meses
-- SELECT refresh_mv_rotacion();       -- refrescar dashboard inmediatamente

\echo '>>> Migración 003 completada ✓'
\echo '>>> Recuerda descomentar las líneas SEED y ajustar empresa_id/sucursal_id'
