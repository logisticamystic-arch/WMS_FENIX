-- ============================================================
--  WMS PROORIENTE — Schema completo para PostgreSQL 14+
--  Generado desde migraciones 001–055
--  Servidor objetivo: Ubuntu 22.04 + PostgreSQL 16
-- ============================================================
--  USO:
--    sudo -u postgres psql -d wms_prooriente -f postgresql_full_schema.sql
-- ============================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- ============================================================
-- SECCIÓN 1 — TABLAS MAESTRAS (001–008)
-- ============================================================

CREATE TABLE IF NOT EXISTS empresas (
    id              BIGSERIAL PRIMARY KEY,
    nit             VARCHAR(20)  NOT NULL,
    razon_social    VARCHAR(200) NOT NULL,
    direccion       VARCHAR(300),
    telefono        VARCHAR(30),
    email           VARCHAR(150),
    logo_url        VARCHAR(500),
    activo          BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_empresas_nit UNIQUE (nit)
);

CREATE TABLE IF NOT EXISTS sucursales (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nombre          VARCHAR(200) NOT NULL,
    codigo          VARCHAR(20)  NOT NULL,
    direccion       VARCHAR(300),
    telefono        VARCHAR(30),
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_sucursales_empresa_codigo UNIQUE (empresa_id, codigo)
);

CREATE TABLE IF NOT EXISTS parametros (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    clave           VARCHAR(60)  NOT NULL,
    valor           TEXT,
    descripcion     VARCHAR(300),
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_parametros_empresa_clave UNIQUE (empresa_id, clave)
);

CREATE TABLE IF NOT EXISTS modulos (
    id              BIGSERIAL PRIMARY KEY,
    nombre          VARCHAR(80)  NOT NULL,
    descripcion     VARCHAR(300),
    icono           VARCHAR(60),
    orden           INTEGER NOT NULL DEFAULT 0,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permisos (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    modulo_id       BIGINT REFERENCES modulos(id) ON DELETE CASCADE,
    rol             VARCHAR(30) NOT NULL CHECK (rol IN ('Admin','Supervisor','Auxiliar','Montacarguista','Analista')),
    puede_ver       BOOLEAN NOT NULL DEFAULT FALSE,
    puede_crear     BOOLEAN NOT NULL DEFAULT FALSE,
    puede_editar    BOOLEAN NOT NULL DEFAULT FALSE,
    puede_eliminar  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_permisos_empresa_modulo_rol UNIQUE (empresa_id, modulo_id, rol)
);

CREATE TABLE IF NOT EXISTS marcas (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nombre          VARCHAR(100) NOT NULL,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_marcas_empresa_nombre UNIQUE (empresa_id, nombre)
);

CREATE TABLE IF NOT EXISTS personal (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     BIGINT REFERENCES sucursales(id) ON DELETE SET NULL,
    nombre          VARCHAR(150) NOT NULL,
    documento       VARCHAR(20)  NOT NULL,
    pin             VARCHAR(255) NOT NULL,
    rol             VARCHAR(20)  NOT NULL CHECK (rol IN ('Admin','Supervisor','Auxiliar','Montacarguista','Analista')),
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    ultimo_login    TIMESTAMP,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_personal_empresa_documento UNIQUE (empresa_id, documento)
);

CREATE TABLE IF NOT EXISTS categorias_productos (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nombre          VARCHAR(100) NOT NULL,
    descripcion     TEXT,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS marcas_pk AS SELECT 1 WHERE FALSE; -- placeholder
DROP TABLE IF EXISTS marcas_pk;

CREATE TABLE IF NOT EXISTS productos (
    id                   BIGSERIAL PRIMARY KEY,
    empresa_id           BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    marca_id             BIGINT REFERENCES marcas(id) ON DELETE SET NULL,
    categoria_id         BIGINT REFERENCES categorias_productos(id) ON DELETE SET NULL,
    codigo_interno       VARCHAR(50)   NOT NULL,
    referencia           VARCHAR(100),
    nombre               VARCHAR(300)  NOT NULL,
    descripcion          TEXT,
    imagen_url           VARCHAR(500),
    unidad_medida        VARCHAR(20)   NOT NULL DEFAULT 'UN',
    peso_unitario        NUMERIC(10,3) NOT NULL DEFAULT 0,
    volumen_unitario     NUMERIC(10,4) NOT NULL DEFAULT 0,
    unidades_caja        INTEGER       NOT NULL DEFAULT 1,
    controla_lote        BOOLEAN NOT NULL DEFAULT FALSE,
    controla_vencimiento BOOLEAN NOT NULL DEFAULT FALSE,
    vida_util_dias       INTEGER,
    temperatura_almacen  VARCHAR(30),
    area_comercial       VARCHAR(100),
    costo_promedio       NUMERIC(14,4) NOT NULL DEFAULT 0,
    activo               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP,
    CONSTRAINT uq_productos_empresa_codigo UNIQUE (empresa_id, codigo_interno)
);

CREATE TABLE IF NOT EXISTS producto_eans (
    id              BIGSERIAL PRIMARY KEY,
    producto_id     BIGINT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    codigo_ean      VARCHAR(50) NOT NULL,
    tipo            VARCHAR(20) NOT NULL DEFAULT 'EAN13' CHECK (tipo IN ('EAN13','EAN128','DUN14','QR','INTERNO')),
    es_principal    BOOLEAN NOT NULL DEFAULT FALSE,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_producto_eans_codigo UNIQUE (codigo_ean)
);

CREATE TABLE IF NOT EXISTS zonas (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    nombre          VARCHAR(100) NOT NULL,
    descripcion     TEXT,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ubicaciones (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    zona_id         BIGINT REFERENCES zonas(id) ON DELETE SET NULL,
    codigo          VARCHAR(50) NOT NULL,
    nombre          VARCHAR(100),
    tipo_ubicacion  VARCHAR(30) NOT NULL DEFAULT 'Bodega'
                    CHECK (tipo_ubicacion IN ('Bodega','Patio','Picking','Consolidacion','Cuarentena','Devolucion')),
    pasillo         VARCHAR(20),
    rack            VARCHAR(20),
    nivel           VARCHAR(20),
    posicion        VARCHAR(20),
    capacidad_m3    NUMERIC(10,3) NOT NULL DEFAULT 0,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    modulo          VARCHAR(50),
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_ubicaciones_empresa_codigo UNIQUE (empresa_id, codigo)
);

-- ============================================================
-- SECCIÓN 2 — TABLAS OPERACIONALES (009–017)
-- ============================================================

CREATE TABLE IF NOT EXISTS citas (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    fecha_cita          DATE NOT NULL,
    hora_cita           TIME NOT NULL,
    placa               VARCHAR(20),
    transportadora      VARCHAR(150),
    conductor           VARCHAR(150),
    cedula_conductor    VARCHAR(20),
    tipo_vehiculo       VARCHAR(50),
    estado              VARCHAR(20) NOT NULL DEFAULT 'Programada'
                        CHECK (estado IN ('Programada','EnPatio','EnMuelle','Finalizada','Cancelada','NoShow')),
    muelle              VARCHAR(20),
    numero_remision     VARCHAR(50),
    observaciones       TEXT,
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE IF NOT EXISTS proveedores (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nit                 VARCHAR(30),
    razon_social        VARCHAR(200) NOT NULL,
    contacto            VARCHAR(150),
    telefono            VARCHAR(30),
    email               VARCHAR(150),
    ciudad              VARCHAR(100),
    activo              BOOLEAN NOT NULL DEFAULT TRUE,
    evaluacion_score    NUMERIC(5,2) NOT NULL DEFAULT 0,
    ultima_evaluacion   DATE,
    notas_evaluacion    TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ordenes_compra (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    proveedor_id        BIGINT REFERENCES proveedores(id) ON DELETE SET NULL,
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    numero_odc          VARCHAR(30) NOT NULL,
    estado              VARCHAR(30) NOT NULL DEFAULT 'Borrador'
                        CHECK (estado IN ('Borrador','Confirmada','En Proceso','Cerrada','Cancelada','CerradaConDevolucion')),
    tiene_devolucion    BOOLEAN NOT NULL DEFAULT FALSE,
    fecha               DATE NOT NULL,
    observaciones       TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT uq_ordenes_compra_numero UNIQUE (numero_odc)
);

CREATE TABLE IF NOT EXISTS odc_detalles (
    id                  BIGSERIAL PRIMARY KEY,
    orden_compra_id     BIGINT NOT NULL REFERENCES ordenes_compra(id) ON DELETE CASCADE,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    cantidad_solicitada NUMERIC(14,4) NOT NULL,
    cantidad_recibida   NUMERIC(14,4) NOT NULL DEFAULT 0,
    precio_unitario     NUMERIC(14,4) NOT NULL DEFAULT 0,
    aprobado_admin      BOOLEAN NOT NULL DEFAULT FALSE,
    aprobado_por        BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    aprobado_at         TIMESTAMP,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recepciones (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    cita_id             BIGINT REFERENCES citas(id) ON DELETE SET NULL,
    odc_id              BIGINT REFERENCES ordenes_compra(id) ON DELETE SET NULL,
    numero_recepcion    VARCHAR(30) NOT NULL,
    auxiliar_id         BIGINT NOT NULL REFERENCES personal(id) ON DELETE RESTRICT,
    modo_ciego          BOOLEAN NOT NULL DEFAULT FALSE,
    estado              VARCHAR(20) NOT NULL DEFAULT 'Borrador'
                        CHECK (estado IN ('Borrador','Confirmada','Cerrada')),
    fecha_movimiento    DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME,
    observaciones       TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT uq_recepciones_numero UNIQUE (numero_recepcion)
);

CREATE TABLE IF NOT EXISTS recepcion_detalles (
    id                   BIGSERIAL PRIMARY KEY,
    recepcion_id         BIGINT NOT NULL REFERENCES recepciones(id) ON DELETE CASCADE,
    producto_id          BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    cantidad_esperada    INTEGER NOT NULL DEFAULT 0,
    cantidad_recibida    INTEGER NOT NULL,
    cantidad_cajas       INTEGER NOT NULL DEFAULT 0,
    lote                 VARCHAR(50),
    fecha_vencimiento    DATE,
    numero_pallet        INTEGER,
    estado_mercancia     VARCHAR(20) NOT NULL DEFAULT 'BuenEstado'
                         CHECK (estado_mercancia IN ('BuenEstado','Averia','Vencido','Sobrante','Faltante')),
    novedad_motivo       TEXT,
    ubicacion_destino_id BIGINT REFERENCES ubicaciones(id) ON DELETE SET NULL,
    aprobado_admin       BOOLEAN NOT NULL DEFAULT FALSE,
    aprobado_por         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    aprobado_at          TIMESTAMP,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);

CREATE TABLE IF NOT EXISTS devoluciones (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    recepcion_id        BIGINT REFERENCES recepciones(id) ON DELETE SET NULL,
    odc_id              BIGINT REFERENCES ordenes_compra(id) ON DELETE SET NULL,
    numero_devolucion   VARCHAR(30) NOT NULL,
    proveedor           VARCHAR(200),
    tipo                VARCHAR(30) NOT NULL
                        CHECK (tipo IN ('AProveedorAveria','AProveedorVencido','ReingresoBuenEstado','DevolucionRecepcion')),
    auxiliar_id         BIGINT NOT NULL REFERENCES personal(id) ON DELETE RESTRICT,
    fecha_movimiento    DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME,
    estado              VARCHAR(20) NOT NULL DEFAULT 'Borrador'
                        CHECK (estado IN ('Borrador','Aprobada','Procesada')),
    motivo_general      TEXT NOT NULL,
    fotos_json          TEXT,
    autorizado_por      INTEGER,
    fecha_autorizacion  TIMESTAMP,
    fecha_devolucion    DATE,
    observaciones       TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT uq_devoluciones_numero UNIQUE (numero_devolucion)
);

CREATE TABLE IF NOT EXISTS devolucion_detalles (
    id                  BIGSERIAL PRIMARY KEY,
    devolucion_id       BIGINT NOT NULL REFERENCES devoluciones(id) ON DELETE CASCADE,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    lote                VARCHAR(50),
    fecha_vencimiento   DATE,
    cantidad            INTEGER NOT NULL,
    motivo              VARCHAR(30) NOT NULL
                        CHECK (motivo IN ('Averia','Vencido','ErrorProveedor','CalidadDeficiente','Otro')),
    detalle_motivo      TEXT,
    destino             VARCHAR(30) NOT NULL
                        CHECK (destino IN ('InventarioObsoleto','Reingreso','DevolucionProveedor')),
    ubicacion_destino_id BIGINT REFERENCES ubicaciones(id) ON DELETE SET NULL,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventarios (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_id        BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    lote                VARCHAR(50) NOT NULL DEFAULT 'N/A',
    fecha_vencimiento   DATE,
    cantidad            NUMERIC(14,4) NOT NULL DEFAULT 0,
    cantidad_reservada  NUMERIC(14,4) NOT NULL DEFAULT 0,
    numero_pallet       INTEGER,
    estado              VARCHAR(20) NOT NULL DEFAULT 'Disponible'
                        CHECK (estado IN ('Disponible','Reservado','Cuarentena','Bloqueado')),
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT uq_inventarios_ubicacion_producto_lote
        UNIQUE (empresa_id, ubicacion_id, producto_id, lote, numero_pallet)
);

CREATE TABLE IF NOT EXISTS movimiento_inventarios (
    id                   BIGSERIAL PRIMARY KEY,
    empresa_id           BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id          BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id          BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_origen_id  BIGINT REFERENCES ubicaciones(id) ON DELETE SET NULL,
    ubicacion_destino_id BIGINT REFERENCES ubicaciones(id) ON DELETE SET NULL,
    tipo_movimiento      VARCHAR(30) NOT NULL
                         CHECK (tipo_movimiento IN ('Entrada','Salida','Traslado','Ajuste','Devolucion','Picking','Conteo')),
    cantidad             NUMERIC(14,4) NOT NULL,
    lote                 VARCHAR(50),
    fecha_vencimiento    DATE,
    numero_pallet        INTEGER,
    referencia           VARCHAR(100),
    usuario_id           BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    observaciones        TEXT,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conteos (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    tipo_conteo     VARCHAR(30) NOT NULL CHECK (tipo_conteo IN ('Total','Ciclico','Selectivo','Consolidado')),
    tipo_interno    VARCHAR(30),
    ronda_actual    INTEGER NOT NULL DEFAULT 1,
    estado          VARCHAR(20) NOT NULL DEFAULT 'Pendiente'
                    CHECK (estado IN ('Pendiente','EnConteo','Finalizado','Cancelado')),
    creado_por      BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    fecha_inicio    DATE,
    fecha_fin       DATE,
    observaciones   TEXT,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conteo_detalles (
    id                      BIGSERIAL PRIMARY KEY,
    conteo_id               BIGINT NOT NULL REFERENCES conteos(id) ON DELETE CASCADE,
    producto_id             BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_id            BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    lote                    VARCHAR(50),
    fecha_vencimiento       DATE,
    cantidad_sistema        NUMERIC(14,4) NOT NULL DEFAULT 0,
    cantidad_contada        NUMERIC(14,4),
    cantidad_final_aprobada NUMERIC(14,4),
    diferencia              NUMERIC(14,4),
    ajustado                BOOLEAN NOT NULL DEFAULT FALSE,
    auxiliar_id             BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    created_at              TIMESTAMP,
    updated_at              TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orden_pickings (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero_orden        VARCHAR(30) NOT NULL,
    cliente             VARCHAR(200),
    area_comercial      VARCHAR(100),
    estado              VARCHAR(20) NOT NULL DEFAULT 'Pendiente'
                        CHECK (estado IN ('Pendiente','EnProceso','Completada','Cancelada')),
    prioridad           INTEGER NOT NULL DEFAULT 5,
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    fecha_movimiento    DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME,
    fecha_requerida     DATE,
    numero_planilla     VARCHAR(30),
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT uq_orden_pickings_numero UNIQUE (numero_orden)
);

CREATE TABLE IF NOT EXISTS picking_detalles (
    id                  BIGSERIAL PRIMARY KEY,
    orden_picking_id    BIGINT NOT NULL REFERENCES orden_pickings(id) ON DELETE CASCADE,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_id        BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    lote                VARCHAR(50),
    cantidad_solicitada INTEGER NOT NULL,
    cantidad_pickeada   INTEGER NOT NULL DEFAULT 0,
    cantidad_cajas      INTEGER NOT NULL DEFAULT 0,
    costo_unitario      NUMERIC(14,4) NOT NULL DEFAULT 0,
    pasillo_lock        VARCHAR(10),
    estado              VARCHAR(20) NOT NULL DEFAULT 'Pendiente'
                        CHECK (estado IN ('Pendiente','EnProceso','Completado','Faltante')),
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    confirmado_at       TIMESTAMP,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tarea_reabastecimientos (
    id                   BIGSERIAL PRIMARY KEY,
    empresa_id           BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id          BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    orden_picking_id     BIGINT NOT NULL REFERENCES orden_pickings(id) ON DELETE CASCADE,
    producto_id          BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_origen_id  BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    ubicacion_destino_id BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    cantidad             INTEGER NOT NULL,
    asignado_a           BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    estado               VARCHAR(20) NOT NULL DEFAULT 'Pendiente'
                         CHECK (estado IN ('Pendiente','EnProceso','Completada')),
    fecha_movimiento     DATE NOT NULL,
    hora_inicio          TIME NOT NULL,
    hora_fin             TIME,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);

CREATE TABLE IF NOT EXISTS despachos (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero_despacho     VARCHAR(30) NOT NULL,
    cliente             VARCHAR(200),
    placa               VARCHAR(20),
    conductor           VARCHAR(150),
    estado              VARCHAR(20) NOT NULL DEFAULT 'Borrador'
                        CHECK (estado IN ('Borrador','Cargado','Despachado','Cancelado')),
    fecha_movimiento    DATE NOT NULL,
    hora_salida         TIME,
    observaciones       TEXT,
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT uq_despachos_numero UNIQUE (numero_despacho)
);

CREATE TABLE IF NOT EXISTS despacho_detalles (
    id                  BIGSERIAL PRIMARY KEY,
    despacho_id         BIGINT NOT NULL REFERENCES despachos(id) ON DELETE CASCADE,
    orden_picking_id    BIGINT REFERENCES orden_pickings(id) ON DELETE SET NULL,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    cantidad            NUMERIC(14,4) NOT NULL,
    lote                VARCHAR(50),
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

-- ============================================================
-- SECCIÓN 3 — TABLAS AVANZADAS
-- ============================================================

CREATE TABLE IF NOT EXISTS api_keys (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nombre          VARCHAR(100) NOT NULL,
    key_hash        VARCHAR(255) NOT NULL,
    permisos        JSONB,
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    ultimo_uso      TIMESTAMP,
    expires_at      TIMESTAMP,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_api_keys_hash UNIQUE (key_hash)
);

CREATE TABLE IF NOT EXISTS planillas_certificacion (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero_planilla VARCHAR(30) NOT NULL,
    estado          VARCHAR(20) NOT NULL DEFAULT 'Borrador'
                    CHECK (estado IN ('Borrador','EnProceso','Completada','Cancelada')),
    fecha_planilla  DATE,
    auxiliar_id     BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    supervisor_id   BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    total_lineas    INTEGER NOT NULL DEFAULT 0,
    total_cajas     INTEGER NOT NULL DEFAULT 0,
    total_unidades  NUMERIC(14,4) NOT NULL DEFAULT 0,
    observaciones   TEXT,
    started_at      TIMESTAMP,
    finished_at     TIMESTAMP,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    CONSTRAINT uq_planillas_numero UNIQUE (numero_planilla)
);

CREATE TABLE IF NOT EXISTS planilla_lineas (
    id                   BIGSERIAL PRIMARY KEY,
    planilla_id          BIGINT NOT NULL REFERENCES planillas_certificacion(id) ON DELETE CASCADE,
    orden_picking_id     BIGINT REFERENCES orden_pickings(id) ON DELETE SET NULL,
    producto_id          BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    cantidad_esperada    NUMERIC(14,4) NOT NULL DEFAULT 0,
    cantidad_certificada NUMERIC(14,4) NOT NULL DEFAULT 0,
    cantidad_cajas       INTEGER NOT NULL DEFAULT 0,
    lote                 VARCHAR(50),
    estado               VARCHAR(20) NOT NULL DEFAULT 'Pendiente'
                         CHECK (estado IN ('Pendiente','EnProceso','Completado','Faltante','Novedad')),
    novedad              TEXT,
    auxiliar_id          BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alertas (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id     BIGINT REFERENCES sucursales(id) ON DELETE CASCADE,
    tipo            VARCHAR(50) NOT NULL,
    severidad       VARCHAR(20) NOT NULL DEFAULT 'info'
                    CHECK (severidad IN ('info','warning','error','critica')),
    titulo          VARCHAR(200) NOT NULL,
    mensaje         TEXT,
    datos           JSONB,
    leida           BOOLEAN NOT NULL DEFAULT FALSE,
    leida_por       BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    leida_at        TIMESTAMP,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notificaciones (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    personal_id     BIGINT NOT NULL REFERENCES personal(id) ON DELETE CASCADE,
    tipo            VARCHAR(50) NOT NULL,
    titulo          VARCHAR(200) NOT NULL,
    mensaje         TEXT,
    datos           JSONB,
    leida           BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

-- ============================================================
-- SECCIÓN 4 — TABLAS ML/ANALYTICS (050)
-- ============================================================

CREATE TABLE IF NOT EXISTS inventory_guard_log (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL,
    sucursal_id     BIGINT,
    usuario_id      BIGINT,
    operacion       VARCHAR(60) NOT NULL,
    motivo_bloqueo  VARCHAR(120) NOT NULL,
    contexto        JSONB,
    endpoint        VARCHAR(200),
    ip              VARCHAR(45),
    created_at      TIMESTAMP DEFAULT NOW(),
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS anomaly_flags (
    id              BIGSERIAL PRIMARY KEY,
    empresa_id      BIGINT NOT NULL,
    sucursal_id     BIGINT,
    tipo            VARCHAR(50) NOT NULL,
    severidad       VARCHAR(20) NOT NULL DEFAULT 'media',
    titulo          VARCHAR(200) NOT NULL,
    descripcion     TEXT NOT NULL,
    datos_anomalia  JSONB,
    estado          VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    revisado_por    BIGINT,
    revisado_at     TIMESTAMP,
    notas_revision  TEXT,
    created_at      TIMESTAMP DEFAULT NOW(),
    updated_at      TIMESTAMP
);

CREATE TABLE IF NOT EXISTS expiry_predictions (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL,
    sucursal_id         BIGINT NOT NULL,
    producto_id         BIGINT NOT NULL,
    lote                VARCHAR(100),
    fecha_vencimiento   DATE NOT NULL,
    dias_para_vencer    INTEGER NOT NULL,
    stock_actual        NUMERIC(12,2) NOT NULL,
    consumo_diario      NUMERIC(10,4) NOT NULL,
    dias_agotamiento    NUMERIC(8,2) NOT NULL,
    unidades_en_riesgo  NUMERIC(12,2) NOT NULL DEFAULT 0,
    nivel_riesgo        VARCHAR(20) NOT NULL DEFAULT 'bajo',
    confianza           NUMERIC(5,4) NOT NULL DEFAULT 0.5,
    recomendaciones     JSONB,
    serie_consumo       JSONB,
    calculado_at        TIMESTAMP,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP,
    CONSTRAINT uq_exp_pred UNIQUE (empresa_id, sucursal_id, producto_id, lote, fecha_vencimiento)
);

CREATE TABLE IF NOT EXISTS performance_metrics (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT,
    metodo              VARCHAR(10) NOT NULL,
    endpoint            VARCHAR(250) NOT NULL,
    endpoint_pattern    VARCHAR(200),
    duracion_ms         INTEGER NOT NULL,
    status_code         INTEGER NOT NULL DEFAULT 200,
    memoria_kb          INTEGER,
    ip                  VARCHAR(45),
    usuario_id          BIGINT,
    slow_query_hint     TEXT,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP
);

-- ============================================================
-- SECCIÓN 5 — ÍNDICES DE RENDIMIENTO
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_inventarios_empresa_producto    ON inventarios (empresa_id, producto_id);
CREATE INDEX IF NOT EXISTS idx_inventarios_ubicacion           ON inventarios (ubicacion_id);
CREATE INDEX IF NOT EXISTS idx_inventarios_lote                ON inventarios (lote);
CREATE INDEX IF NOT EXISTS idx_inventarios_vencimiento         ON inventarios (fecha_vencimiento) WHERE fecha_vencimiento IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_inventarios_estado              ON inventarios (estado);
CREATE INDEX IF NOT EXISTS idx_movimientos_empresa_tipo        ON movimiento_inventarios (empresa_id, tipo_movimiento);
CREATE INDEX IF NOT EXISTS idx_movimientos_producto            ON movimiento_inventarios (producto_id);
CREATE INDEX IF NOT EXISTS idx_movimientos_created             ON movimiento_inventarios (created_at);
CREATE INDEX IF NOT EXISTS idx_picking_detalles_orden          ON picking_detalles (orden_picking_id);
CREATE INDEX IF NOT EXISTS idx_picking_detalles_estado         ON picking_detalles (estado);
CREATE INDEX IF NOT EXISTS idx_picking_detalles_auxiliar       ON picking_detalles (auxiliar_id);
CREATE INDEX IF NOT EXISTS idx_productos_empresa               ON productos (empresa_id);
CREATE INDEX IF NOT EXISTS idx_productos_nombre_trgm           ON productos USING gin (nombre gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_producto_eans_codigo            ON producto_eans (codigo_ean);
CREATE INDEX IF NOT EXISTS idx_odc_empresa_estado              ON ordenes_compra (empresa_id, estado);
CREATE INDEX IF NOT EXISTS idx_odc_proveedor                   ON ordenes_compra (proveedor_id);
CREATE INDEX IF NOT EXISTS idx_odc_auxiliar                    ON ordenes_compra (auxiliar_id);
CREATE INDEX IF NOT EXISTS idx_citas_fecha_estado              ON citas (fecha_cita, estado);
CREATE INDEX IF NOT EXISTS idx_recepciones_estado              ON recepciones (estado);
CREATE INDEX IF NOT EXISTS idx_devoluciones_odc                ON devoluciones (odc_id);
CREATE INDEX IF NOT EXISTS idx_devoluciones_empresa            ON devoluciones (empresa_id);
CREATE INDEX IF NOT EXISTS idx_planillas_estado                ON planillas_certificacion (estado);
CREATE INDEX IF NOT EXISTS idx_alertas_empresa_leida           ON alertas (empresa_id, leida);
CREATE INDEX IF NOT EXISTS idx_guard_log_empresa_op            ON inventory_guard_log (empresa_id, operacion, created_at);
CREATE INDEX IF NOT EXISTS idx_guard_log_usuario               ON inventory_guard_log (usuario_id);
CREATE INDEX IF NOT EXISTS idx_anomaly_empresa                 ON anomaly_flags (empresa_id, estado, severidad, created_at);
CREATE INDEX IF NOT EXISTS idx_anomaly_tipo                    ON anomaly_flags (empresa_id, tipo);
CREATE INDEX IF NOT EXISTS idx_expiry_riesgo                   ON expiry_predictions (empresa_id, nivel_riesgo, dias_para_vencer);
CREATE INDEX IF NOT EXISTS idx_perf_endpoint                   ON performance_metrics (endpoint_pattern, created_at);
CREATE INDEX IF NOT EXISTS idx_perf_duracion                   ON performance_metrics (duracion_ms, created_at);

-- ============================================================
-- SECCIÓN 6 — DATOS SEMILLA MÍNIMOS
-- ============================================================

INSERT INTO empresas (nit, razon_social, activo, created_at, updated_at)
VALUES ('900000001-0', 'Prooriente S.A.S.', TRUE, NOW(), NOW())
ON CONFLICT (nit) DO NOTHING;

INSERT INTO sucursales (empresa_id, nombre, codigo, activo, created_at, updated_at)
SELECT id, 'Bodega Principal', 'SEDE01', TRUE, NOW(), NOW()
FROM empresas WHERE nit = '900000001-0'
ON CONFLICT DO NOTHING;

INSERT INTO modulos (nombre, descripcion, icono, orden, activo, created_at, updated_at) VALUES
    ('Dashboard',    'Panel de control',           'fa-chart-bar',      1, TRUE, NOW(), NOW()),
    ('Recepciones',  'Recepcion de mercancia',      'fa-truck-loading',  2, TRUE, NOW(), NOW()),
    ('Inventario',   'Control de inventario',       'fa-boxes',          3, TRUE, NOW(), NOW()),
    ('Picking',      'Separacion de pedidos',       'fa-hand-holding-box',4,TRUE, NOW(), NOW()),
    ('Despachos',    'Gestion de salidas',          'fa-shipping-fast',  5, TRUE, NOW(), NOW()),
    ('Devoluciones', 'Gestion de devoluciones',     'fa-rotate-left',    6, TRUE, NOW(), NOW()),
    ('Reportes',     'Informes y exportaciones',    'fa-file-chart-line',7, TRUE, NOW(), NOW()),
    ('Parametros',   'Configuracion del sistema',   'fa-cog',            8, TRUE, NOW(), NOW())
ON CONFLICT DO NOTHING;

-- ============================================================
-- SECCIÓN 7 — VERIFICACIÓN FINAL
-- ============================================================

DO $$
DECLARE
    tbl      TEXT;
    cnt      INTEGER := 0;
    expected TEXT[] := ARRAY[
        'empresas','sucursales','parametros','modulos','permisos','marcas',
        'personal','categorias_productos','productos','producto_eans','zonas',
        'ubicaciones','citas','proveedores','ordenes_compra','odc_detalles',
        'recepciones','recepcion_detalles','devoluciones','devolucion_detalles',
        'inventarios','movimiento_inventarios','conteos','conteo_detalles',
        'orden_pickings','picking_detalles','tarea_reabastecimientos',
        'despachos','despacho_detalles','api_keys','planillas_certificacion',
        'planilla_lineas','alertas','notificaciones',
        'inventory_guard_log','anomaly_flags','expiry_predictions','performance_metrics'
    ];
BEGIN
    FOREACH tbl IN ARRAY expected LOOP
        IF EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE table_schema = 'public' AND table_name = tbl) THEN
            cnt := cnt + 1;
            RAISE NOTICE '  [OK] %', tbl;
        ELSE
            RAISE WARNING '  [FALTA] Tabla no creada: %', tbl;
        END IF;
    END LOOP;
    RAISE NOTICE '';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'RESULTADO: % / % tablas creadas', cnt, array_length(expected,1);
    IF cnt = array_length(expected,1) THEN
        RAISE NOTICE 'STATUS: SCHEMA COMPLETO - Listo para produccion!';
    ELSE
        RAISE NOTICE 'STATUS: INCOMPLETO - Revisar errores arriba';
    END IF;
    RAISE NOTICE '========================================';
END$$;
-- ============================================================
-- SECCION 10 - INVENTARIO V2 + AJUSTES (056-058, 060)
-- ============================================================

CREATE TABLE IF NOT EXISTS sesiones_inventario (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT       NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT       NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    nombre              VARCHAR(120) NOT NULL,
    descripcion         TEXT,
    tipo                VARCHAR(20)  NOT NULL DEFAULT 'Ciclico',
    num_conteos         SMALLINT     NOT NULL DEFAULT 1,
    comparar_sistema    BOOLEAN      NOT NULL DEFAULT TRUE,
    estado              VARCHAR(30)  NOT NULL DEFAULT 'Borrador',
    creado_por          BIGINT       NOT NULL REFERENCES personal(id) ON DELETE RESTRICT,
    ajustado_por        BIGINT       REFERENCES personal(id) ON DELETE SET NULL,
    fecha_inicio        DATE,
    fecha_cierre        DATE,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sesiones_empresa_estado ON sesiones_inventario (empresa_id, sucursal_id, estado);

CREATE TABLE IF NOT EXISTS sesion_asignaciones (
    id                  BIGSERIAL PRIMARY KEY,
    sesion_id           BIGINT NOT NULL REFERENCES sesiones_inventario(id) ON DELETE CASCADE,
    auxiliar_id         BIGINT NOT NULL REFERENCES personal(id) ON DELETE CASCADE,
    ronda               SMALLINT NOT NULL DEFAULT 1,
    tipo_instruccion    VARCHAR(20) NOT NULL DEFAULT 'Libre',
    pasillo             VARCHAR(60),
    modulo              VARCHAR(60),
    producto_id         BIGINT REFERENCES productos(id) ON DELETE SET NULL,
    instruccion_libre   TEXT,
    estado              VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    completado_at       TIMESTAMP,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sesion_lineas (
    id                  BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    sesion_id           BIGINT REFERENCES sesiones_inventario(id) ON DELETE SET NULL,
    ronda               SMALLINT NOT NULL DEFAULT 1,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_id        BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    lote                VARCHAR(60),
    fecha_vencimiento   DATE,
    cantidad_sistema    NUMERIC(12,3) NOT NULL DEFAULT 0,
    cantidad_contada    NUMERIC(12,3) NOT NULL DEFAULT 0,
    estado              VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    ajustado            BOOLEAN NOT NULL DEFAULT FALSE,
    observaciones       TEXT,
    contado_at          TIMESTAMP,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    CONSTRAINT conteo_unico_idx UNIQUE (sesion_id, ronda, producto_id, ubicacion_id, lote)
);

CREATE TABLE IF NOT EXISTS ajustes_inventario (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    sesion_id           BIGINT REFERENCES sesiones_inventario(id) ON DELETE SET NULL,
    producto_id         BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ubicacion_id        BIGINT NOT NULL REFERENCES ubicaciones(id) ON DELETE RESTRICT,
    auxiliar_id         BIGINT REFERENCES personal(id) ON DELETE SET NULL,
    ajustado_por        BIGINT NOT NULL REFERENCES personal(id) ON DELETE RESTRICT,
    lote                VARCHAR(60),
    fecha_vencimiento   DATE,
    cantidad_anterior   NUMERIC(12,3) NOT NULL,
    cantidad_ajustada   NUMERIC(12,3) NOT NULL,
    diferencia          NUMERIC(12,3) NOT NULL,
    motivo              VARCHAR(120),
    observaciones       TEXT,
    fecha               DATE NOT NULL,
    hora                TIME NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE productos ADD COLUMN IF NOT EXISTS unidades_caja INTEGER NOT NULL DEFAULT 1;

-- ============================================================
-- SECCION 11 - PERFORMANCE INDEXES (057)
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_inv_empresa_sucursal ON inventarios (empresa_id, sucursal_id);
CREATE INDEX IF NOT EXISTS idx_inv_empresa_ubicacion ON inventarios (empresa_id, sucursal_id, ubicacion_id);
CREATE INDEX IF NOT EXISTS idx_inv_empresa_producto  ON inventarios (empresa_id, sucursal_id, producto_id);
CREATE INDEX IF NOT EXISTS idx_inv_vencimiento       ON inventarios (empresa_id, fecha_vencimiento);
CREATE INDEX IF NOT EXISTS idx_mov_empresa_producto  ON movimiento_inventarios (empresa_id, producto_id, created_at);
CREATE INDEX IF NOT EXISTS idx_mov_ubic_destino_fecha ON movimiento_inventarios (ubicacion_destino_id, created_at);
CREATE INDEX IF NOT EXISTS idx_mov_prod_origen       ON movimiento_inventarios (producto_id, ubicacion_origen_id);

-- ============================================================
-- SECCION 12 - CROSS-DOCK Y YARD MANAGEMENT (061)
-- ============================================================

CREATE TABLE IF NOT EXISTS cross_dock_ordenes (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    numero              VARCHAR(30) NOT NULL UNIQUE,
    muelle_entrada      VARCHAR(20),
    muelle_salida       VARCHAR(20),
    transportista       VARCHAR(120),
    placa_entrada       VARCHAR(20),
    placa_salida        VARCHAR(20),
    estado              VARCHAR(30) NOT NULL DEFAULT 'Programado',
    entrada_programada  TIMESTAMP,
    salida_programada   TIMESTAMP,
    entrada_real        TIMESTAMP,
    salida_real         TIMESTAMP,
    tiempo_suelo_min    INTEGER,
    observaciones       TEXT,
    creado_por          BIGINT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_crossdock_empresa_estado ON cross_dock_ordenes (empresa_id, sucursal_id, estado);
CREATE INDEX IF NOT EXISTS idx_crossdock_empresa_fecha  ON cross_dock_ordenes (empresa_id, created_at);

CREATE TABLE IF NOT EXISTS cross_dock_detalles (
    id                      BIGSERIAL PRIMARY KEY,
    cross_dock_id           BIGINT NOT NULL REFERENCES cross_dock_ordenes(id) ON DELETE CASCADE,
    producto_id             BIGINT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    ean                     VARCHAR(30),
    lote                    VARCHAR(60),
    cantidad_esperada       NUMERIC(12,2) NOT NULL DEFAULT 0,
    cantidad_recibida       NUMERIC(12,2) NOT NULL DEFAULT 0,
    cantidad_transferida    NUMERIC(12,2) NOT NULL DEFAULT 0,
    estado                  VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    observaciones           TEXT,
    created_at              TIMESTAMP,
    updated_at              TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_crossdock_det_orden ON cross_dock_detalles (cross_dock_id);

CREATE TABLE IF NOT EXISTS yard_appointments (
    id                  BIGSERIAL PRIMARY KEY,
    empresa_id          BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id         BIGINT NOT NULL REFERENCES sucursales(id) ON DELETE CASCADE,
    muelle              VARCHAR(20) NOT NULL,
    numero              VARCHAR(30),
    transportista       VARCHAR(120),
    placa_vehiculo      VARCHAR(20),
    tipo                VARCHAR(30) NOT NULL DEFAULT 'Entrada',
    estado              VARCHAR(30) NOT NULL DEFAULT 'Programado',
    fecha_cita          TIMESTAMP NOT NULL,
    entrada_real        TIMESTAMP,
    salida_real         TIMESTAMP,
    turnaround_min      INTEGER,
    recepcion_id        BIGINT,
    despacho_id         BIGINT,
    observaciones       TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_yard_empresa_fecha  ON yard_appointments (empresa_id, sucursal_id, fecha_cita);
CREATE INDEX IF NOT EXISTS idx_yard_empresa_muelle ON yard_appointments (empresa_id, muelle, estado);
