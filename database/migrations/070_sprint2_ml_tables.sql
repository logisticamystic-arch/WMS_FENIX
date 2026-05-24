-- ═══════════════════════════════════════════════════════════════════════════
-- MIGRACIÓN 002 — Sprint 2: Tablas del Sistema ML / Rotación de Productos
-- WMS ProOriente · PostgreSQL 16
-- Ejecutar DESPUÉS de 001_sprint1_indices.sql
-- ═══════════════════════════════════════════════════════════════════════════

\echo '>>> Iniciando migración 002 — Tablas ML...'

-- ── 1. VENTAS_AGREGADAS_ML ────────────────────────────────────────────────────
-- Historial mensual pre-calculado, particionado por año para max rendimiento
CREATE TABLE IF NOT EXISTS ventas_agregadas_ml (
    id                  BIGSERIAL,
    empresa_id          INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id         INTEGER NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    periodo             DATE    NOT NULL,  -- siempre el 1er día del mes
    unidades_vendidas   NUMERIC(12,3) NOT NULL DEFAULT 0,
    valor_vendido       NUMERIC(15,2) NOT NULL DEFAULT 0,
    num_transacciones   INTEGER NOT NULL DEFAULT 0,
    stock_medio         NUMERIC(12,3),
    dias_sin_stock      INTEGER DEFAULT 0,
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    updated_at          TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (id, periodo)
) PARTITION BY RANGE (periodo);

-- Particiones por año (agregar un año nuevo cada enero)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'ventas_agregadas_ml_2023') THEN
        EXECUTE $SQL$
            CREATE TABLE ventas_agregadas_ml_2023 PARTITION OF ventas_agregadas_ml
                FOR VALUES FROM ('2023-01-01') TO ('2024-01-01')
        $SQL$;
    END IF;
END $$;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'ventas_agregadas_ml_2024') THEN
        EXECUTE $SQL$
            CREATE TABLE ventas_agregadas_ml_2024 PARTITION OF ventas_agregadas_ml
                FOR VALUES FROM ('2024-01-01') TO ('2025-01-01')
        $SQL$;
    END IF;
END $$;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'ventas_agregadas_ml_2025') THEN
        EXECUTE $SQL$
            CREATE TABLE ventas_agregadas_ml_2025 PARTITION OF ventas_agregadas_ml
                FOR VALUES FROM ('2025-01-01') TO ('2026-01-01')
        $SQL$;
    END IF;
END $$;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'ventas_agregadas_ml_2026') THEN
        EXECUTE $SQL$
            CREATE TABLE ventas_agregadas_ml_2026 PARTITION OF ventas_agregadas_ml
                FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')
        $SQL$;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS idx_ventas_ml_unique
    ON ventas_agregadas_ml (empresa_id, sucursal_id, producto_id, periodo);

CREATE INDEX IF NOT EXISTS idx_ventas_ml_producto_periodo
    ON ventas_agregadas_ml (producto_id, periodo DESC);

\echo '  ✓ ventas_agregadas_ml creada con particiones 2023-2026'

-- ── 2. CLASIFICACIONES_ABC_XYZ ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clasificaciones_abc_xyz (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id         INTEGER NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    periodo_inicio      DATE NOT NULL,
    periodo_fin         DATE NOT NULL,
    -- Métricas ABC (valor económico)
    total_valor         NUMERIC(15,2) NOT NULL DEFAULT 0,
    pct_valor_acum      NUMERIC(6,3)  NOT NULL DEFAULT 0,  -- 0.00–100.00
    clase_abc           CHAR(1) NOT NULL CHECK (clase_abc IN ('A','B','C')),
    -- Métricas XYZ (variabilidad de demanda)
    demanda_media       NUMERIC(12,3) NOT NULL DEFAULT 0,
    demanda_std         NUMERIC(12,3) NOT NULL DEFAULT 0,
    coef_variacion      NUMERIC(8,4)  NOT NULL DEFAULT 0,  -- CV = std/media
    clase_xyz           CHAR(1) NOT NULL CHECK (clase_xyz IN ('X','Y','Z')),
    -- Segmento combinado (columna generada automáticamente por PG 16)
    segmento            CHAR(2) GENERATED ALWAYS AS (clase_abc || clase_xyz) STORED,
    -- Métricas adicionales
    meses_activos       SMALLINT NOT NULL DEFAULT 0,
    dias_inventario     NUMERIC(8,2),   -- DOH: días de inventario
    rotacion_anual      NUMERIC(8,2),   -- veces/año
    total_unidades      NUMERIC(12,3)   DEFAULT 0,
    -- Recomendación automática del motor
    zona_recomendada    VARCHAR(20) CHECK (zona_recomendada IN ('oro','plata','bronce','obsoleto','frio','peligroso')),
    accion_sugerida     VARCHAR(200),
    -- Auditoría
    generado_at         TIMESTAMPTZ DEFAULT NOW(),
    generado_por        VARCHAR(50) DEFAULT 'sistema_ml',
    vigente             BOOLEAN DEFAULT TRUE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_abc_xyz_vigente
    ON clasificaciones_abc_xyz (empresa_id, sucursal_id, producto_id, periodo_inicio, periodo_fin)
    WHERE vigente = TRUE;

CREATE INDEX IF NOT EXISTS idx_abc_xyz_segmento
    ON clasificaciones_abc_xyz (empresa_id, sucursal_id, segmento)
    WHERE vigente = TRUE;

CREATE INDEX IF NOT EXISTS idx_abc_xyz_clase_abc
    ON clasificaciones_abc_xyz (empresa_id, sucursal_id, clase_abc)
    WHERE vigente = TRUE;

\echo '  ✓ clasificaciones_abc_xyz creada'

-- ── 3. FORECAST_DEMANDA ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS forecast_demanda (
    id                      BIGSERIAL PRIMARY KEY,
    empresa_id              INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id             INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id             INTEGER NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    fecha_prediccion        DATE NOT NULL,
    horizonte_dias          SMALLINT NOT NULL CHECK (horizonte_dias IN (7, 14, 30, 60, 90)),
    -- Predicción y bandas de confianza
    demanda_pred            NUMERIC(12,3) NOT NULL,
    banda_inf_80            NUMERIC(12,3),
    banda_sup_80            NUMERIC(12,3),
    banda_inf_95            NUMERIC(12,3),
    banda_sup_95            NUMERIC(12,3),
    -- Metadatos del modelo
    modelo_usado            VARCHAR(50) NOT NULL DEFAULT 'holt_winters',
    mape                    NUMERIC(6,3),          -- Mean Absolute Percentage Error
    rmse                    NUMERIC(10,3),         -- Root Mean Square Error
    score_confianza         NUMERIC(4,3),          -- 0.000–1.000
    -- Alertas predictivas
    alerta_quiebre          BOOLEAN DEFAULT FALSE,
    dias_hasta_quiebre      INTEGER,
    stock_seguridad_sug     NUMERIC(12,3),         -- stock de seguridad sugerido
    punto_reorden_sug       NUMERIC(12,3),         -- punto de reorden sugerido
    -- Auditoría
    generado_at             TIMESTAMPTZ DEFAULT NOW(),
    es_vigente              BOOLEAN DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_forecast_vigente
    ON forecast_demanda (empresa_id, sucursal_id, producto_id, fecha_prediccion)
    WHERE es_vigente = TRUE;

CREATE INDEX IF NOT EXISTS idx_forecast_alertas
    ON forecast_demanda (empresa_id, sucursal_id, alerta_quiebre)
    WHERE es_vigente = TRUE AND alerta_quiebre = TRUE;

CREATE INDEX IF NOT EXISTS idx_forecast_horizonte
    ON forecast_demanda (empresa_id, sucursal_id, horizonte_dias, generado_at DESC)
    WHERE es_vigente = TRUE;

\echo '  ✓ forecast_demanda creada'

-- ── 4. UBICACIONES (mapa físico del almacén) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS ubicaciones (
    id              SERIAL PRIMARY KEY,
    empresa_id      INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    codigo          VARCHAR(30) NOT NULL,    -- ej: A-01-02-03
    pasillo         VARCHAR(10) NOT NULL,
    estanteria      SMALLINT NOT NULL,
    nivel           SMALLINT NOT NULL,
    posicion        SMALLINT NOT NULL,
    -- Capacidad física
    capacidad_kg    NUMERIC(8,2),
    capacidad_m3    NUMERIC(8,4),
    capacidad_unid  INTEGER,
    -- Clasificación de zona de picking
    zona            VARCHAR(20) NOT NULL DEFAULT 'bronce'
                    CHECK (zona IN ('oro','plata','bronce','frio','peligroso','cuarentena')),
    distancia_muelle NUMERIC(8,2),          -- metros al muelle principal
    accesibilidad   SMALLINT DEFAULT 3 CHECK (accesibilidad BETWEEN 1 AND 5),
    -- Tipo de ubicación
    tipo_ubicacion  VARCHAR(30) DEFAULT 'Picking'
                    CHECK (tipo_ubicacion IN ('Picking','Reserva','Recepcion','Despacho','Cross-Dock','Cuarentena')),
    -- Contenido actual
    producto_id     INTEGER REFERENCES productos(id),
    ocupacion_pct   NUMERIC(5,2) DEFAULT 0.00,
    -- Estado
    estado          VARCHAR(20) NOT NULL DEFAULT 'Disponible'
                    CHECK (estado IN ('Disponible','Ocupado','Bloqueado','Mantenimiento','Reservado')),
    activa          BOOLEAN DEFAULT TRUE,
    -- Auditoría
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (empresa_id, sucursal_id, codigo)
);

CREATE INDEX IF NOT EXISTS idx_ubi_empresa_zona
    ON ubicaciones (empresa_id, sucursal_id, zona)
    WHERE activa = TRUE;

CREATE INDEX IF NOT EXISTS idx_ubi_empresa_estado
    ON ubicaciones (empresa_id, sucursal_id, estado)
    WHERE activa = TRUE;

\echo '  ✓ ubicaciones creada'

-- ── 5. UBICACIONES_OPTIMAS ────────────────────────────────────────────────────
-- Resultado del motor de slotting: asignación óptima producto ↔ ubicación
CREATE TABLE IF NOT EXISTS ubicaciones_optimas (
    id                      BIGSERIAL PRIMARY KEY,
    empresa_id              INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id             INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id             INTEGER NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    ubicacion_id            INTEGER NOT NULL REFERENCES ubicaciones(id) ON DELETE CASCADE,
    -- Clasificación que motivó la asignación
    segmento                CHAR(2),
    score_asignacion        NUMERIC(6,3) DEFAULT 0,  -- 0–10
    -- Justificación
    motivo                  VARCHAR(200),
    -- Vigencia
    vigente_desde           DATE NOT NULL DEFAULT CURRENT_DATE,
    vigente_hasta           DATE,
    vigente                 BOOLEAN DEFAULT TRUE,
    -- Métricas de rendimiento real vs estimado
    tiempo_pick_real_s      INTEGER,
    tiempo_pick_estimado_s  INTEGER,
    -- Auditoría
    creado_at               TIMESTAMPTZ DEFAULT NOW(),
    creado_por              VARCHAR(50) DEFAULT 'motor_slotting',
    aprobado_por            INTEGER REFERENCES personal(id),
    aprobado_at             TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_ubi_opt_vigente
    ON ubicaciones_optimas (empresa_id, sucursal_id, producto_id)
    WHERE vigente = TRUE;

CREATE INDEX IF NOT EXISTS idx_ubi_opt_ubicacion
    ON ubicaciones_optimas (ubicacion_id)
    WHERE vigente = TRUE;

\echo '  ✓ ubicaciones_optimas creada'

-- ── 6. EJECUCIONES_ML ────────────────────────────────────────────────────────
-- Log de cada ejecución del pipeline ML para monitoreo y debugging
CREATE TABLE IF NOT EXISTS ejecuciones_ml (
    id                      BIGSERIAL PRIMARY KEY,
    empresa_id              INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id             INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    tipo                    VARCHAR(50) NOT NULL
                            CHECK (tipo IN ('abc_xyz','forecast','slotting','anomaly',
                                           'poblar_ventas','refresh_mv','cross_dock')),
    inicio_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    fin_at                  TIMESTAMPTZ,
    -- Columna generada: duración calculada automáticamente al cerrar
    duracion_s              INTEGER GENERATED ALWAYS AS (
                                CASE WHEN fin_at IS NOT NULL
                                     THEN EXTRACT(EPOCH FROM (fin_at - inicio_at))::integer
                                     ELSE NULL END
                            ) STORED,
    productos_procesados    INTEGER DEFAULT 0,
    registros_creados       INTEGER DEFAULT 0,
    registros_actualizados  INTEGER DEFAULT 0,
    estado                  VARCHAR(20) DEFAULT 'en_proceso'
                            CHECK (estado IN ('en_proceso','completado','error','cancelado')),
    error_msg               TEXT,
    metricas_json           JSONB,  -- métricas libres en formato JSON
    -- Quién lo ejecutó
    ejecutado_por           VARCHAR(50) DEFAULT 'cron',
    usuario_id              INTEGER REFERENCES personal(id)
);

CREATE INDEX IF NOT EXISTS idx_ejecuciones_ml_tipo_fecha
    ON ejecuciones_ml (empresa_id, sucursal_id, tipo, inicio_at DESC);

CREATE INDEX IF NOT EXISTS idx_ejecuciones_ml_estado
    ON ejecuciones_ml (estado, inicio_at DESC)
    WHERE estado IN ('en_proceso','error');

\echo '  ✓ ejecuciones_ml creada'

-- ── 7. CROSS_DOCK_ORDENES ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cross_dock_ordenes (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero          VARCHAR(30) NOT NULL,
    -- Documento de entrada y salida vinculados
    recepcion_id    INTEGER REFERENCES recepciones(id),
    despacho_id     INTEGER REFERENCES despachos(id),
    -- Muelle asignado
    muelle_entrada  VARCHAR(20),
    muelle_salida   VARCHAR(20),
    -- Tiempos
    llegada_est     TIMESTAMPTZ,
    llegada_real    TIMESTAMPTZ,
    salida_est      TIMESTAMPTZ,
    salida_real     TIMESTAMPTZ,
    tiempo_suelo_min INTEGER GENERATED ALWAYS AS (
        CASE WHEN llegada_real IS NOT NULL AND salida_real IS NOT NULL
             THEN EXTRACT(EPOCH FROM (salida_real - llegada_real))::integer / 60
             ELSE NULL END
    ) STORED,
    -- Estado
    estado          VARCHAR(30) DEFAULT 'Programado'
                    CHECK (estado IN ('Programado','Recibiendo','Clasificando','Despachando','Completado','Cancelado')),
    notas           TEXT,
    -- Auditoría
    creado_por      INTEGER REFERENCES personal(id),
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (empresa_id, numero)
);

CREATE INDEX IF NOT EXISTS idx_cross_dock_empresa_estado
    ON cross_dock_ordenes (empresa_id, sucursal_id, estado, created_at DESC);

CREATE TABLE IF NOT EXISTS cross_dock_detalles (
    id              BIGSERIAL PRIMARY KEY,
    cross_dock_id   INTEGER NOT NULL REFERENCES cross_dock_ordenes(id) ON DELETE CASCADE,
    producto_id     INTEGER NOT NULL REFERENCES productos(id),
    cantidad_esp    NUMERIC(12,3) NOT NULL,
    cantidad_real   NUMERIC(12,3) DEFAULT 0,
    estado          VARCHAR(20) DEFAULT 'Pendiente'
                    CHECK (estado IN ('Pendiente','Recibido','Transferido','Diferencia')),
    notas           VARCHAR(300)
);

\echo '  ✓ cross_dock_ordenes + cross_dock_detalles creadas'

-- ── 8. YARD_APPOINTMENTS (Yard Management básico) ────────────────────────────
CREATE TABLE IF NOT EXISTS yard_appointments (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero          VARCHAR(30) NOT NULL,
    -- Transportista / vehículo
    transportista   VARCHAR(100),
    placa_vehiculo  VARCHAR(20),
    conductor       VARCHAR(100),
    telefono        VARCHAR(20),
    -- Cita
    fecha_cita      TIMESTAMPTZ NOT NULL,
    muelle          VARCHAR(20),
    tipo            VARCHAR(20) DEFAULT 'Recepcion'
                    CHECK (tipo IN ('Recepcion','Despacho','Cross-Dock')),
    -- Tiempos reales
    entrada_real    TIMESTAMPTZ,
    inicio_op_real  TIMESTAMPTZ,
    fin_op_real     TIMESTAMPTZ,
    salida_real     TIMESTAMPTZ,
    -- KPIs calculados
    turnaround_min  INTEGER GENERATED ALWAYS AS (
        CASE WHEN entrada_real IS NOT NULL AND salida_real IS NOT NULL
             THEN EXTRACT(EPOCH FROM (salida_real - entrada_real))::integer / 60
             ELSE NULL END
    ) STORED,
    estado          VARCHAR(20) DEFAULT 'Programado'
                    CHECK (estado IN ('Programado','En Patio','Operando','Completado','No Show','Cancelado')),
    notas           TEXT,
    creado_por      INTEGER REFERENCES personal(id),
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (empresa_id, numero)
);

CREATE INDEX IF NOT EXISTS idx_yard_empresa_fecha
    ON yard_appointments (empresa_id, sucursal_id, fecha_cita)
    WHERE estado NOT IN ('Completado','Cancelado');

CREATE INDEX IF NOT EXISTS idx_yard_muelle_fecha
    ON yard_appointments (muelle, fecha_cita)
    WHERE estado NOT IN ('Completado','Cancelado');

\echo '  ✓ yard_appointments creada'

-- ── 9. WAVE_PICKING ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wave_picking (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      INTEGER NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     INTEGER NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero          VARCHAR(30) NOT NULL,
    nombre          VARCHAR(100),
    -- Estrategia de agrupación
    criterio        VARCHAR(30) DEFAULT 'zona'
                    CHECK (criterio IN ('zona','auxiliar','horario','cliente','prioridad')),
    prioridad       SMALLINT DEFAULT 3 CHECK (prioridad BETWEEN 1 AND 5),
    -- Planillas incluidas en esta wave
    planillas_count INTEGER DEFAULT 0,
    lineas_count    INTEGER DEFAULT 0,
    -- Tiempos
    inicio_est      TIMESTAMPTZ,
    inicio_real     TIMESTAMPTZ,
    fin_real        TIMESTAMPTZ,
    duracion_min    INTEGER GENERATED ALWAYS AS (
        CASE WHEN inicio_real IS NOT NULL AND fin_real IS NOT NULL
             THEN EXTRACT(EPOCH FROM (fin_real - inicio_real))::integer / 60
             ELSE NULL END
    ) STORED,
    estado          VARCHAR(20) DEFAULT 'Preparando'
                    CHECK (estado IN ('Preparando','En Proceso','Completado','Cancelado')),
    creado_por      INTEGER REFERENCES personal(id),
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (empresa_id, numero)
);

-- Tabla de asociación: wave ↔ planillas
CREATE TABLE IF NOT EXISTS wave_planillas (
    wave_id         INTEGER NOT NULL REFERENCES wave_picking(id) ON DELETE CASCADE,
    planilla_id     INTEGER NOT NULL REFERENCES planillas_picking(id) ON DELETE CASCADE,
    orden_picking   SMALLINT,  -- orden optimizado dentro de la wave
    PRIMARY KEY (wave_id, planilla_id)
);

CREATE INDEX IF NOT EXISTS idx_wave_empresa_estado
    ON wave_picking (empresa_id, sucursal_id, estado, created_at DESC);

\echo '  ✓ wave_picking + wave_planillas creadas'

\echo '>>> Migración 002 completada ✓'
\echo '>>> Tablas creadas: ventas_agregadas_ml (particionada), clasificaciones_abc_xyz,'
\echo '    forecast_demanda, ubicaciones, ubicaciones_optimas, ejecuciones_ml,'
\echo '    cross_dock_ordenes, cross_dock_detalles, yard_appointments,'
\echo '    wave_picking, wave_planillas'
