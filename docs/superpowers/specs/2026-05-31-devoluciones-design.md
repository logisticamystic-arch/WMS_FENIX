# Devoluciones — Design Spec

> **For agentic workers:** Use `superpowers:writing-plans` to generate the implementation plan from this spec.

**Goal:** Módulo completo de gestión de devoluciones para WMS Fénix — tres tipos (cliente, proveedor, interna), flujo de aprobación por supervisor, impacto en inventario por decisión a nivel de ítem, escaneo QR reutilizando el endpoint de recepción, y pantalla mobile para devoluciones de cliente.

**Scope:** Desktop + Mobile. Sin lógica financiera — WMS solo registra la referencia ERP; el crédito lo emite el sistema contable externo.

---

## 1. Arquitectura

Dos tablas nuevas (`devoluciones`, `devolucion_items`) + un `DevolucionesController` con 8 endpoints. El impacto en inventario se ejecuta en `procesar()` dentro de una transacción. El módulo de notificaciones badge existente se extiende para incluir devoluciones pendientes de aprobación.

El endpoint `GET /api/recepciones/buscar-qr` se reutiliza directamente para escaneo QR — no requiere nuevo endpoint.

**Estado machine:**
```
Creada ──(auto)──► PendienteAprobacion ──► Aprobada ──► Procesada
                          │
                          └──► Rechazada
Creada / PendienteAprobacion ──► Anulada
```

Toda devolución nace en `PendienteAprobacion`. El operario no puede procesar sin aprobación previa del supervisor. La decisión de destino (restock / descarte / proveedor) se asigna **por ítem** en el momento de procesar.

---

## 2. Modelo de datos

### Tabla `devoluciones`

| Columna | Tipo | Notas |
|---------|------|-------|
| id | bigint PK auto | |
| empresa_id | bigint | tenant scope |
| sucursal_id | bigint | tenant scope |
| numero_devolucion | varchar(20) | auto `DEV-YYYY-NNNN` por empresa+año |
| tipo | enum(`cliente`,`proveedor`,`interna`) | |
| estado | enum(`PendienteAprobacion`,`Aprobada`,`Procesada`,`Rechazada`,`Anulada`) | |
| referencia_externa | varchar(100) nullable | número del ERP del cliente/proveedor |
| motivo | text | razón general de la devolución |
| solicitado_por | bigint FK → personal | quien crea la devolución |
| aprobado_por | bigint FK → personal nullable | supervisor que aprueba/rechaza |
| procesado_por | bigint FK → personal nullable | quien ejecuta el procesamiento |
| aprobado_at | timestamp nullable | |
| procesado_at | timestamp nullable | |
| created_at, updated_at | timestamps | |

Índice: `(empresa_id, sucursal_id, estado)`, `(empresa_id, numero_devolucion)` UNIQUE.

### Tabla `devolucion_items`

| Columna | Tipo | Notas |
|---------|------|-------|
| id | bigint PK auto | |
| devolucion_id | bigint FK → devoluciones CASCADE | |
| producto_id | bigint FK → productos | |
| lote | varchar(100) nullable | capturado desde QR o ingresado manual |
| fecha_vencimiento | date nullable | capturado desde QR o ingresado manual |
| cantidad | decimal(12,3) | |
| condicion | enum(`bueno`,`dañado`,`vencido`,`otro`) | |
| destino | enum(`restock`,`descarte`,`proveedor`) nullable | se llena en `procesar()` |
| motivo_item | text nullable | nota por ítem |
| created_at | timestamp | |

Índice: `(devolucion_id, producto_id)`.

---

## 3. API Endpoints

Todos bajo `/api/devoluciones` dentro del grupo JWT autenticado. Un solo `DevolucionesController extends BaseController`.

| Método | Ruta | Método controller | Quién puede |
|--------|------|-------------------|-------------|
| POST | `/devoluciones` | `crear` | Cualquier autenticado |
| GET | `/devoluciones` | `listar` | Cualquier autenticado |
| GET | `/devoluciones/{id}` | `detalle` | Cualquier autenticado |
| PUT | `/devoluciones/{id}/items` | `actualizarItems` | Solicitante o Supervisor (solo estado `PendienteAprobacion`) |
| POST | `/devoluciones/{id}/aprobar` | `aprobar` | Supervisor / Admin |
| POST | `/devoluciones/{id}/rechazar` | `rechazar` | Supervisor / Admin |
| POST | `/devoluciones/{id}/procesar` | `procesar` | Cualquier autenticado (post-aprobación) |
| POST | `/devoluciones/{id}/anular` | `anular` | Supervisor / Admin |

### `POST /devoluciones` — body

```json
{
  "tipo": "cliente",
  "referencia_externa": "NC-12345",
  "motivo": "Producto incorrecto en pedido",
  "items": [
    {
      "producto_id": 42,
      "lote": "L-001",
      "fecha_vencimiento": "2026-12-31",
      "cantidad": 5,
      "condicion": "bueno",
      "motivo_item": ""
    }
  ]
}
```

Responde con `{ sesion_id, numero_devolucion }` y HTTP 201.

### `POST /devoluciones/{id}/procesar` — body

```json
{
  "items": [
    { "id": 1, "destino": "restock" },
    { "id": 2, "destino": "descarte" },
    { "id": 3, "destino": "proveedor" }
  ]
}
```

Si algún ítem tiene `destino = "proveedor"`, la respuesta incluye `devolucion_proveedor_id` con el ID de la nueva devolución creada automáticamente.

### Numeración automática

Dentro de transacción: `SELECT MAX(numero_devolucion) LIKE 'DEV-YYYY-%' + 1`. Formato: `DEV-2026-0001`.

---

## 4. Impacto en inventario (en `procesar()`)

Ejecutado dentro de una transacción única. Por cada ítem según su `destino`:

**`restock`**
- Busca fila en `inventarios` por `(empresa_id, sucursal_id, producto_id, lote, estado='Disponible')`.
- Si existe: `cantidad += item.cantidad`.
- Si no existe: INSERT nueva fila con `estado = 'Disponible'`, `cantidad_reservada = 0`.
- Registra `movimiento_inventarios` con `tipo = 'DevolucionEntrada'`.

**`descarte`**
- Registra `movimiento_inventarios` con `tipo = 'Descarte'`, cantidad negativa.
- No modifica `inventarios` directamente (el lote devuelto nunca entró como Disponible).

**`proveedor`**
- Crea nueva fila en `devoluciones` con `tipo = 'proveedor'`, `estado = 'PendienteAprobacion'`, copiando los ítems correspondientes.
- Registra `movimiento_inventarios` con `tipo = 'DevolucionProveedor'`.
- Devuelve `devolucion_proveedor_id` en la respuesta.

---

## 5. Integración con notificaciones

Al crear una devolución, se inserta en `anomaly_flags`:

```php
[
  'empresa_id'     => ...,
  'sucursal_id'    => ...,
  'tipo'           => 'devolucion',
  'severidad'      => 'media',
  'titulo'         => "Devolución {$numero} — aprobación requerida",
  'descripcion'    => "{$countItems} ítem(s). Motivo: {$motivo}",
  'estado'         => 'pendiente',
]
```

`loadBadge()` en `index.html` suma las devoluciones con `estado = 'PendienteAprobacion'` al total del badge del supervisor (misma extensión que las aprobaciones de vencimiento).

El panel de notificaciones muestra tarjetas de devoluciones pendientes con botones inline **Aprobar** / **Rechazar** para roles privilegiados.

---

## 6. UI — Desktop

### Módulo `Devoluciones`

Accesible desde el menú lateral. Archivo: `public/assets/js/desktop/devoluciones.js`.

**Lista principal**
- Tabla: N°, Tipo (badge color), Estado (badge), Referencia ERP, Ítems, Fecha, Solicitado por, Acciones
- Filtros: Tipo · Estado · Rango de fechas · Buscar por N° o referencia
- Botón "Nueva Devolución"

**Formulario de creación**
- Tipo (radio: Cliente / Proveedor / Interna)
- Referencia externa ERP (opcional)
- Motivo general
- **Campo QR/EAN** al inicio de la sección de ítems:
  ```
  [ 🔍 Escanear QR / EAN / Código interno  ________ ] [Buscar]
  ```
  - Llama a `GET /api/recepciones/buscar-qr?q=<valor>`
  - Auto-rellena: producto, lote, fecha_vencimiento (editables)
  - Toast de error si el código no se encuentra
  - Soporta formatos: `CODIGO/YYYYMMDD`, `CODIGO,x,YYYYMMDD,x,LOTE`, código solo
- Tabla de ítems: producto, cantidad, lote, fecha vencimiento, condición, nota, [eliminar]
- Botón "+ Agregar ítem"

**Vista de detalle**
- Header: N°, tipo, estado, fechas, personas
- Tabla de ítems (columna `Destino` vacía hasta procesar)
- Botones contextuales según estado y rol:
  - `PendienteAprobacion` + Supervisor → **Aprobar** / **Rechazar**
  - `Aprobada` + cualquier autenticado → **Procesar**
  - `PendienteAprobacion` + Supervisor → **Anular**

**Diálogo de procesamiento**
- Tabla de ítems con selector de destino por fila: `Restock / Descarte / → Proveedor`
- Aviso si algún ítem tiene destino `→ Proveedor`: *"Se creará automáticamente la devolución DEV-2026-XXXX al proveedor."*
- Botón confirmar → ejecuta `POST /devoluciones/{id}/procesar`

### Panel de notificaciones (supervisor)

Tarjetas de devoluciones pendientes de aprobación con:
- Título: `DEV-2026-XXXX — Cliente`
- Subtítulo: `N ítems · Motivo: ...`
- Botones inline **Aprobar** / **Rechazar**

---

## 7. UI — Mobile

Pantalla en `public/mobile/index.html`. Solo tipo **Cliente → WMS**.

**Acceso:** Botón "Devolución" en el menú principal mobile.

**Flujo de registro:**
1. Campo QR/EAN con teclado que activa escáner de cámara (mismo patrón que recepción mobile)
   - Llama a `GET /api/recepciones/buscar-qr?q=<valor>`
   - Auto-rellena producto, lote, fecha vencimiento
2. Campos: cantidad, condición (selector: bueno / dañado / otro)
3. Botón "+ Agregar" → suma a lista de ítems pendientes
4. Campo motivo general (textarea)
5. Botón "Enviar Devolución" → `POST /devoluciones`
6. Toast de confirmación con el N° asignado

**Vista "Mis Devoluciones"**
- Lista simple con N°, fecha, estado (badge), cantidad de ítems
- Sin acciones (solo consulta)

---

## 8. Seguridad y multi-tenancy

- Todos los endpoints verifican `empresa_id` + `sucursal_id` del JWT
- `aprobar`, `rechazar` y `anular` requieren `isSupervisorOrAbove()`
- Un auxiliar solo puede editar ítems de una devolución que él mismo creó (o Supervisor+)
- `procesar` requiere que el estado sea exactamente `Aprobada`; cualquier otro estado retorna 409

---

## 9. Manejo de errores

| Escenario | Respuesta |
|-----------|-----------|
| Procesar sin ítems | 422 `"La devolución no tiene ítems"` |
| Procesar con ítem sin destino | 422 `"Todos los ítems deben tener destino asignado"` |
| Procesar devolución no aprobada | 409 `"La devolución debe estar en estado Aprobada"` |
| Aprobar/rechazar devolución ya procesada | 409 `"Estado inválido para esta operación"` |
| Restock de lote inexistente | Se crea fila nueva en `inventarios` (no es error) |
| QR no reconocido en buscar-qr | Toast de error; campos vacíos; el usuario ingresa manual |

---

## 10. Fuera de scope

- Devoluciones parciales (reabrir una devolución procesada para agregar más ítems)
- Foto / firma de evidencia en mobile
- Integración directa con ERP (solo se registra el número de referencia)
- Impresión de etiqueta o documento de devolución
- Mobile para tipos `proveedor` e `interna` (solo desktop)
