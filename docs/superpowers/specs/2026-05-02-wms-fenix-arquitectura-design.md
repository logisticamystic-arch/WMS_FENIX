# WMS Fénix — Documento de Diseño de Arquitectura

**Fecha:** 2026-05-02  
**Estado:** Aprobado  
**Alcance:** Backend (FastAPI + PostgreSQL) + Frontend (React + Vite + TS) — Fase 0 a Fase 8

---

## 1. Contexto y decisiones de base

| Decisión | Elección | Razón |
|----------|----------|-------|
| Estrategia de migración | Refactor incremental | Código existente conserva valor; reestructurar sin reescribir |
| SQLAlchemy | 2.0 sync (no async) | Menor riesgo de migración; suficiente para 20 usuarios |
| Datos de producción | No hay (desarrollo/staging) | BD se puede borrar y recrear limpia |
| Estructura backend | Feature-based por módulo | Aislamiento, escalabilidad, módulo nuevo = copiar template |
| Estructura frontend | Feature-based + components/wms | Misma lógica que el backend |

---

## 2. Reglas de codificación (no negociables)

Estas reglas aplican a **todo** el código del proyecto. Una tarea no está completa si las viola.

### 2.1 Tipos numéricos
- **`Numeric(18, 6)`** en PostgreSQL, **`Decimal`** en Python para cantidades, costos, precios, pesos, volúmenes, stocks.
- **`Float` está prohibido.** En modelos existentes (`Existencia.cantidad`, `Kardex.cantidad`, `Producto.peso_kg`, `Producto.volumen_m3`, `Producto.stock_minimo`) se corrigen a `Numeric(18,6)` antes de la migración inicial Alembic.

### 2.2 Fechas y horas
- **`TIMESTAMPTZ`** (con zona horaria) en PostgreSQL para todos los campos de fecha/hora.
- **`TIMESTAMP` sin zona está prohibido.** Bodegas pueden operar en husos distintos; el servidor almacena UTC, el cliente convierte.

### 2.3 Kardex append-only
- El kardex solo acepta `INSERT`. Nunca `UPDATE`, nunca `DELETE`.
- Las correcciones se hacen con asientos de ajuste (`AJUSTE_POSITIVO` / `AJUSTE_NEGATIVO`).
- La tabla `kardex` no tiene `deleted_at` ni `updated_at`.

### 2.4 Existencias sin hard delete
- La tabla `existencias` no se elimina. Cuando el stock llega a cero se deja la fila con `cantidad = 0`.
- `existencias` no tiene `deleted_at`.

### 2.5 tipo_movimiento como Enum
- El campo `tipo_movimiento` en `kardex` es un Enum de PostgreSQL.
- Valores válidos: `ENTRADA`, `SALIDA`, `TRASLADO_ENTRADA`, `TRASLADO_SALIDA`, `AJUSTE_POSITIVO`, `AJUSTE_NEGATIVO`, `CONTEO`.

### 2.6 JSONB en AuditLog
- `detalles_json` en `logs_auditoria` es `JSONB`, no `String`/`TEXT`.

### 2.7 CORS restringido
- `allow_origins` se parametriza vía variable de entorno `ALLOWED_ORIGINS`. `["*"]` está prohibido en producción.

---

## 3. Arquitectura del backend

### 3.1 Estructura de directorios

```
backend/
  app/
    core/
      config.py        # Settings via pydantic-settings
      database.py      # SessionLocal, get_db dependency
      security.py      # JWT encode/decode, bcrypt
      dependencies.py  # get_current_user(), get_bodega_activa()
    common/
      base_model.py    # BaseModel mixin (audit columns)
      base_service.py  # BaseService con filtros automáticos
      pagination.py    # paginate() helper
      audit.py         # @audit() decorator
      inventory.py     # InventoryTransaction context manager
    modules/
      empresas/        {models.py, schemas.py, service.py, router.py}
      bodegas/
      ubicaciones/
      usuarios/
      productos/
      stock/           {existencias + consultas}
      kardex/
      recepcion/
      picking/
      despacho/
      ajustes/
      conteos/
      reportes/
    main.py            # Solo monta routers, configura CORS/middleware
  alembic/
    versions/
    env.py
    alembic.ini
  tests/
  pyproject.toml
  .env
  .env.example
```

### 3.2 BaseModel — columnas de auditoría en todas las tablas

```python
# app/common/base_model.py
class BaseModel(DeclarativeBase):
    id         = Column(UUID, primary_key=True, default=uuid4)
    created_at = Column(TIMESTAMPTZ, server_default=func.now(), nullable=False)
    updated_at = Column(TIMESTAMPTZ, server_default=func.now(),
                        onupdate=func.now(), nullable=False)
    created_by = Column(UUID, ForeignKey("usuarios.id"), nullable=True)
    updated_by = Column(UUID, ForeignKey("usuarios.id"), nullable=True)
```

**Excepción — `kardex`:** no tiene `updated_at` ni `updated_by` (append-only). `created_by` actúa como `usuario_id`.

### 3.3 Soft delete en maestros

Tablas con `deleted_at`: `productos`, `ubicaciones`, `proveedores`, `usuarios`, `marcas`, `categorias`, `bodegas`. (La tabla `clientes` se agrega en Fase 2 con el mismo patrón.)

- `BaseService` aplica `WHERE deleted_at IS NULL` automáticamente en `list()` y `get()`.
- Los `UNIQUE` constraints con soft delete usan **índices parciales**: `CREATE UNIQUE INDEX ... WHERE deleted_at IS NULL`. Esto permite reusar SKUs o emails de registros eliminados.

### 3.4 Cadena de aislamiento multi-tenant

```
JWT Token
  → empresa_id, bodegas_permitidas: [uuid, ...]
  → NO bodega_id fija

Cada request
  Headers:
    Authorization: Bearer <jwt>
    X-Bodega-Id: <uuid>

get_current_user() — FastAPI Dependency
  1. Decodifica JWT → empresa_id, bodegas_permitidas
  2. Lee header X-Bodega-Id
  3. Valida: bodega_id ∈ bodegas_permitidas → 403 si no
  4. Inyecta TenantContext(empresa_id, bodega_id, user_id, permisos)

BaseService
  - list()   → SELECT ... WHERE empresa_id = :eid AND deleted_at IS NULL
  - get()    → SELECT ... WHERE id = :id AND empresa_id = :eid AND deleted_at IS NULL
  - create() → asigna empresa_id automáticamente desde el contexto
  - update() → valida empresa_id antes de modificar
  - delete() → soft delete: SET deleted_at = now()
```

Ningún service ni router necesita recordar filtrar por `empresa_id`. El `BaseService` lo hace siempre.

### 3.5 InventoryTransaction — context manager atómico con concurrencia

```python
# app/common/inventory.py
with InventoryTransaction(db, producto_id, ubicacion_id, empresa_id) as tx:
    # 1. SELECT FOR UPDATE — bloquea la fila de existencias hasta el commit
    existencia = tx.lock_existencia()

    # 2. Operar sobre stock y kardex
    tx.update_stock(delta)
    tx.update_reservada(delta_reservada)  # si aplica
    tx.insert_kardex(tipo, cantidad, tipo_documento, documento_id, ...)

    # 3. Validar invariante antes del commit
    tx.assert_invariant()
    # SUM(kardex.cantidad WHERE producto+ubicacion) == existencia.cantidad
    # Si falla → ROLLBACK automático + alerta + bloqueo del SKU/ubicación

# 4. Commit automático al salir del with (o ROLLBACK si hay excepción)
```

**Orden de locks — fila única:** siempre `existencias` primero, `kardex` después.

**Orden de locks — multi-fila (traslados):** cuando la operación toca múltiples filas de existencias (origen + destino), los `SELECT FOR UPDATE` se toman en orden determinístico ascendente por `(producto_id, ubicacion_id)`. Dos transacciones concurrentes que trasladan entre las mismas ubicaciones siempre adquieren los locks en el mismo orden, eliminando el riesgo de deadlock circular.

```python
# Traslado: siempre lockear en orden (producto_id, ubicacion_id) ASC
filas = sorted([(prod_id, orig_id), (prod_id, dest_id)])
for producto_id, ubicacion_id in filas:
    tx.lock_existencia(producto_id, ubicacion_id)
```

Todo servicio que toca stock (`recepcion`, `picking`, `despacho`, `ajustes`, `conteos`) usa obligatoriamente `InventoryTransaction`.

### 3.6 Reservas de stock — flujo picking

`existencias` tiene dos campos de cantidad:
- `cantidad Numeric(18,6)` — stock físico real
- `reservada Numeric(18,6) NOT NULL DEFAULT 0` — reservado para órdenes pendientes
- `disponible = cantidad − reservada` — propiedad Python `@property` en el modelo ORM; no se persiste en BD

| Evento | cantidad | reservada | kardex |
|--------|----------|-----------|--------|
| Crear orden picking | sin cambio | +qty | — |
| Confirmar pick (dentro de InventoryTransaction) | −qty | −qty | INSERT SALIDA |
| Cancelar orden | sin cambio | −qty | INSERT AJUSTE si hubo movimiento |

El campo `reservada` no entra en el invariante stock-kardex. El invariante aplica solo sobre `cantidad`.

### 3.7 Alembic — estrategia de migración

1. Borrar BD de desarrollo (sin datos reales).
2. Corregir todos los `Float` → `Numeric(18,6)` en modelos **antes** de crear la migración.
3. Mover modelos de `models.py` a `app/modules/X/models.py` — misma estructura de tablas, solo se mueven archivos.
4. `alembic init alembic/` → migración inicial `001_initial_schema.py` que crea todo el esquema.
5. Cada cambio posterior = nueva migración numerada.

### 3.8 Imports y relaciones entre módulos

Para evitar imports circulares con modelos en paquetes separados:

```
app/models/__init__.py  ← importa todos los modelos en orden de dependencia
```

Cada módulo importa desde `app.models` o directamente del módulo fuente según el patrón del proyecto.

---

## 4. Modelo de datos (ERD)

### 4.1 Tenant raíz

**EMPRESAS**
- `id UUID PK`, `nit VARCHAR UNIQUE`, `nombre`, `logo_url`, `plan_suscripcion ENUM`
- `created_at TIMESTAMPTZ`, `updated_at TIMESTAMPTZ`, `created_by`, `updated_by`

**BODEGAS** (`empresa_id FK`, `deleted_at`)
- `id`, `empresa_id`, `nombre`, `direccion`, `ciudad`, `estado ENUM`, `deleted_at`
- `timezone VARCHAR NOT NULL DEFAULT 'America/Bogota'` — nombre de zona IANA (p. ej. `America/Bogota`, `America/Lima`). El backend almacena todos los timestamps en UTC (`TIMESTAMPTZ`); el frontend usa este campo para renderizar fechas en la zona local de cada bodega. Obligatorio porque distintas bodegas de un mismo tenant pueden operar en husos diferentes.

**USUARIOS** (`empresa_id FK`, `deleted_at`)
- `id`, `empresa_id`, `username UNIQUE`, `email UNIQUE`, `password_hash`, `rol ENUM`, `deleted_at`
- Relación con bodegas vía tabla pivote (ver abajo)

**USUARIO_BODEGAS** (tabla pivote — reemplaza `bodegas_permitidas UUID[]`)
- `usuario_id UUID FK`, `bodega_id UUID FK`
- `PK(usuario_id, bodega_id)`
- Permite queries "todos los usuarios de esta bodega" y mantiene FK constraints

### 4.2 Maestros (todos: `empresa_id` + `deleted_at` + BaseModel audit)

**PRODUCTOS** — `empresa_id`, `sku`, `nombre`, `marca_id`, `categoria_id`, `ambiente ENUM`, `peso_kg Numeric(18,6)`, `volumen_m3 Numeric(18,6)`, `stock_minimo Numeric(18,6)`, `unidad_medida`, `controla_lote`, `controla_vencimiento`, `vida_util_dias`, `deleted_at`  
Índice: `UNIQUE(sku, empresa_id) WHERE deleted_at IS NULL`

**PRODUCTO_EANS** — `producto_id FK`, `codigo_ean`, `es_principal`  
Índice: `codigo_ean`

**MARCAS** — `empresa_id`, `nombre`, `deleted_at`

**CATEGORIAS** — `empresa_id`, `nombre`, `deleted_at`

**PROVEEDORES** — `empresa_id`, `nit`, `nombre`, `contacto`, `deleted_at`

**UBICACIONES** — `empresa_id`, `bodega_id`, `zona`, `pasillo`, `modulo`, `nivel`, `bin`, `capacidad_peso Numeric(18,6)`, `capacidad_volumen Numeric(18,6)`, `deleted_at`

### 4.3 Inventario (sin hard delete)

**EXISTENCIAS** — `empresa_id`, `bodega_id`, `producto_id FK`, `ubicacion_id FK`, `lote VARCHAR NOT NULL DEFAULT ''`, `fecha_vencimiento TIMESTAMPTZ`, `cantidad Numeric(18,6)`, `reservada Numeric(18,6) NOT NULL DEFAULT 0`  
Índice único: `UNIQUE(empresa_id, bodega_id, producto_id, ubicacion_id, lote)`

> **Manejo de NULL en lote:** `lote` se define `NOT NULL DEFAULT ''`. Los productos sin lote almacenan cadena vacía `''`. Esto evita que PostgreSQL trate múltiples filas con `lote IS NULL` como distintas (NULL ≠ NULL en UNIQUE), lo que rompería el invariante generando filas duplicadas para el mismo producto/ubicación sin lote. La alternativa `NULLS NOT DISTINCT` (PG 15+) es válida pero menos portable; se prefiere `DEFAULT ''` por claridad.

Sin `deleted_at`. Sin `updated_at` en kardex (no aplica aquí, sí en existencias).

**KARDEX** (append-only, sin `updated_at`, sin `deleted_at`)  
`empresa_id`, `bodega_id`, `producto_id FK`, `usuario_id FK`, `tipo_movimiento ENUM`, `cantidad Numeric(18,6)`, `ubicacion_origen_id FK`, `ubicacion_destino_id FK`, `lote`, `serie`, `tipo_documento ENUM`, `documento_id UUID`, `fecha_hora TIMESTAMPTZ`, `observaciones TEXT`  
Índices: `(empresa_id, producto_id)`, `fecha_hora`, `producto_id`

### 4.4 Documentos transaccionales (header/lines)

Todas las operaciones siguen el patrón cabecera + líneas. Las cabeceras tienen `empresa_id` + `bodega_id` + BaseModel audit. Las líneas son hijas de la cabecera.

| Cabecera | Líneas | Fase |
|----------|--------|------|
| `recepciones` | `recepciones_lineas` | 4 |
| `ordenes_picking` | `ordenes_picking_lineas` | 5 |
| `ordenes_despacho` | `ordenes_despacho_lineas` | 5 |
| `ajustes` | `ajustes_lineas` | 6 |
| `conteos` | `conteos_lineas` | 6 |

Campos mínimos de cada cabecera: `empresa_id`, `bodega_id`, `estado ENUM`, `created_at TIMESTAMPTZ`, BaseModel audit.  
Kardex referencia al documento con `tipo_documento ENUM + documento_id UUID` (sin FK constraint real — la integridad la garantiza `InventoryTransaction`).

**Integridad de inserción en kardex — sentinel column:**  
Para detectar inserts a `kardex` que no pasen por `InventoryTransaction`, la tabla incluye una columna `via_context_manager BOOLEAN NOT NULL DEFAULT FALSE`. El context manager la establece en `TRUE` al insertar. Un trigger `AFTER INSERT ON kardex` registra en `logs_auditoria` cualquier fila donde `via_context_manager = FALSE`, categorizada como `accion = 'KARDEX_BYPASS'` con severidad alta. Esto no bloquea el insert (para no romper seeds/migraciones) pero genera una alerta auditable inmediata. En producción, el monitoreo de `KARDEX_BYPASS` en el log de auditoría es indicador de un bug de integración crítico.

### 4.5 Auditoría

**LOGS_AUDITORIA** — `empresa_id`, `bodega_id`, `timestamp TIMESTAMPTZ`, `modulo`, `accion ENUM`, `usuario_id FK`, `usuario_nombre`, `resumen`, `detalles_json JSONB`

### 4.6 Índices obligatorios

```sql
-- En todas las tablas de negocio
INDEX ON tabla (empresa_id)
INDEX ON tabla (bodega_id)          -- donde aplica

-- Inventario
INDEX ON existencias (producto_id, ubicacion_id)
INDEX ON kardex (fecha_hora)
INDEX ON kardex (producto_id)
INDEX ON kardex (empresa_id, producto_id)
INDEX ON kardex (empresa_id, fecha_hora DESC)  -- query más común: movimientos recientes por tenant

-- Búsqueda
INDEX ON producto_eans (codigo_ean)
INDEX ON usuarios (email)
INDEX ON logs_auditoria (empresa_id, timestamp)
```

---

## 5. Arquitectura del frontend

### 5.1 Estructura de directorios

```
frontend/src/
  components/
    ui/             # Button, Input, Modal, Select — genéricos sin lógica WMS
    wms/            # SmartGrid, KpiCard, KpiStrip, FormSection, AppShell
  modules/
    empresas/       {List.tsx, Form.tsx, hooks.ts, api.ts}
    bodegas/
    ubicaciones/
    usuarios/
    productos/
    stock/
    kardex/
    recepcion/
    picking/
    despacho/
    ajustes/
    conteos/
    reportes/
  hooks/
    useAuth.ts
    useBodegaActiva.ts
    useCrud.ts
  api/
    client.ts       # axiosClient con interceptores JWT + X-Bodega-Id
  mocks/            # Solo activo con VITE_USE_MOCKS=true
    browser.ts
    productos.mock.ts
    bodegas.mock.ts
    # ... un archivo por módulo
    test-fixtures/
      productos.fixtures.ts
      # ... fixtures para tests, separados de mocks de dev
  routes.tsx
  App.tsx
```

### 5.2 Bodega activa — flujo completo

El JWT carga `empresa_id` + `bodegas_permitidas: UUID[]`. No hay `bodega_id` fija en el token.

```
useBodegaActiva()               ← hook que gestiona la bodega seleccionada en estado local
  └── persiste en localStorage  ← sobrevive recargas

axiosClient interceptor
  ├── Authorization: Bearer <jwt>
  └── X-Bodega-Id: <bodega_activa.id>    ← en cada request automáticamente

AppShell
  └── selector de bodega en topbar
      └── cambia el estado de useBodegaActiva()
          └── TanStack Query invalida cache y re-fetcha con nueva bodega
```

### 5.3 Los 5 componentes WMS reutilizables

**`<AppShell />`** — layout global: sidebar colapsable (iconos solamente cuando colapsado) + topbar con selector de bodega activa + notificaciones + perfil. Responsive 640px–4K.

**`<KpiStrip items={KpiItem[]} />`** + **`<KpiCard />`** — tira de 3-5 métricas en la parte superior de cada módulo. Cada ítem: `{ label, value, trend, color, sparklineData? }`.

**`<SmartGrid columns={...} data={...} />`** — tabla universal con TanStack Table v8. Props: `columns`, `data`, `onNew`, `onEdit`, `onDelete`, `exportable`, `searchable`, `drilldown?`. Búsqueda global, filtros por columna, selección múltiple, acciones masivas, paginación 50/página.

**`<FormSection title={string}>`** — sección de formulario con grid 2 cols (1 col en móvil). Hijos: `<Field />` con react-hook-form + Zod. Combos con búsqueda async para FKs. Sin emojis ni decoración.

**`useCrud<T>(modulo: string)`** — hook que envuelve TanStack Query. Expone: `{ list, get, create, update, remove, isLoading, error }`. Siempre apunta a `/api/${modulo}` vía axiosClient. El interceptor inyecta bodega activa automáticamente.

### 5.4 Estrategia de mock data (MSW)

**Modo API real (default):** la aplicación arranca apuntando al backend FastAPI. `VITE_USE_MOCKS` ausente o `false`.

**Modo mock (solo desarrollo):** `VITE_USE_MOCKS=true` en `.env.development`. MSW (Mock Service Worker) intercepta las llamadas a `/api/...` antes de que salgan al backend. Los componentes, `useCrud` y `axiosClient` no saben que hay mocks — el código de producción es idéntico.

**Guard de compilación:** `vite.config.ts` lanza `throw new Error()` si `VITE_USE_MOCKS=true` y `mode === 'production'`. Mocks en producción son imposibles.

**Estructura de mocks:**
```
src/mocks/
  browser.ts                  ← arranca MSW, importado condicionalmente en main.tsx
  productos.mock.ts           ← handlers MSW para /api/productos
  bodegas.mock.ts
  # ... un archivo por módulo
  test-fixtures/
    productos.fixtures.ts     ← fixtures para tests (separados de mocks de dev)
```

**Tests:** usan MSW con fixtures de `test-fixtures/`. Fixtures y mocks de desarrollo son archivos separados para evitar que datos de dev contaminen asserts de tests.

**Limpieza en Fase 1:** al conectar cada módulo al backend real, se elimina su archivo `.mock.ts`. Al cerrar Fase 1 completamente, se elimina `src/mocks/` entero y la inicialización condicional de MSW en `main.tsx`.

---

## 6. Riesgos y resoluciones

| Prioridad | Riesgo | Resolución |
|-----------|--------|------------|
| 🔴 Alto | `Float` en modelos existentes heredado en migración inicial | Corregir todos a `Numeric(18,6)` **antes** de `alembic revision --autogenerate` |
| 🔴 Alto | `bodegas_permitidas UUID[]` en tabla usuarios | Tabla pivote `usuario_bodegas(usuario_id, bodega_id)` con FK constraints |
| 🟡 Medio | UNIQUE constraints + soft delete bloquean reusar valores | Índices parciales `UNIQUE ... WHERE deleted_at IS NULL` en todos los maestros |
| 🟡 Medio | `tipo_movimiento` como String en Kardex | Enum de PostgreSQL desde la migración inicial |
| 🟡 Medio | `detalles_json` como String en AuditLog | Cambiar a `JSONB` en migración inicial |
| 🟢 Bajo | CORS `allow_origins=["*"]` en main.py | Variable de entorno `ALLOWED_ORIGINS`, restringir antes del deploy |
| 🟢 Bajo | Imports circulares entre módulos feature-based | `app/models/__init__.py` importa todos los modelos en orden de dependencia |

---

## 7. Definition of Done (por tarea)

Una tarea se considera completa **solo si**:

- [ ] Funciona (probado manualmente).
- [ ] Tiene al menos 1 test si toca lógica de negocio.
- [ ] No rompe el invariante stock-kardex (si toca inventario).
- [ ] Filtra por `empresa_id` (si toca datos de tenant).
- [ ] Usa `Numeric(18,6)` / `Decimal` — ningún `Float`.
- [ ] Usa `TIMESTAMPTZ` — ningún `TIMESTAMP` sin zona.
- [ ] Lint y type-check pasan.
- [ ] La UI es responsive (probada en 640px y 1440px).

---

## 8. Roadmap de fases

| Fase | Entregable | Verificación |
|------|-----------|--------------|
| 0 | Setup back+front+db, login funcional, AppShell, layout base | Login + ver placeholder dashboard |
| 1 | Empresas, Bodegas, Ubicaciones, Usuarios/Roles + usuario_bodegas | CRUDs completos con multi-tenant verificado |
| 2 | Productos, Categorías, Marcas, Proveedores | Maestros operativos por bodega |
| 3 | Stock + Kardex + invariante | Tests prueban que el invariante se mantiene en todas las operaciones |
| 4 | Recepción → Putaway | Flujo end-to-end con kardex correcto |
| 5 | Picking (con reservas) → Packing → Despacho | Flujo de salida completo, reservas verificadas |
| 6 | Conteos, Ajustes, Trazabilidad lotes/series | Inventario avanzado |
| 7 | Dashboards + reportes Excel | KPIs por módulo + exportaciones |
| 8 | Hardening: tests E2E, docker compose, deploy | Listo para producción |
