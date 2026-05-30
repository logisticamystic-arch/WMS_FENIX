# Packing & Certificación — Design Spec

> **For agentic workers:** Use `superpowers:writing-plans` to generate the implementation plan from this spec.

**Goal:** Extend the picking certification flow (by sucursal/branch) with a packing layer that tracks products into discrete packaging units (canasta/caja/paquete), generates per-unit stickers, and produces a full packing document on certification close.

**Scope:** Flow B — `PickingController::certDetalle / certConfirmar / certFinalizar` routes. No changes to planilla certification (Flow C) or despacho certification.

---

## 1. Architecture

The packing layer sits **on top of** the existing picking certification without modifying existing tables. Three new tables (`packing_sesiones`, `packing_unidades`, `packing_items`) track the packing session in parallel. The existing `certFinalizar()` is called at the end of the packing flow — packing completion is the trigger.

A new `PackingController` handles all packing-specific endpoints. The existing `PickingController` certification routes remain unchanged.

The sticker and closing document are generated as HTML pages with `@media print` CSS — no external PDF library required. Print targets the selected printer via `window.print()` with a dynamically injected `@page { size: ... }` rule.

The existing `impresoras` table (managed by `ImpresoraController`) is extended with a `tipos_trabajo` JSON column to filter printers by context (`sticker_packing`, `documento_packing`).

---

## 2. Data Model

### New table: `packing_sesiones`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| empresa_id | bigint FK → empresas | tenant scope |
| sucursal_id | bigint FK → sucursales | branch scope |
| sucursal_entrega | varchar(200) | branch being certified (matches picking flow) |
| tipo_empaque | enum('canasta','caja','paquete') | selected at session start |
| certificador_id | bigint FK → personal | who is certifying |
| impresora_sticker_id | bigint FK → impresoras, nullable | printer for stickers |
| impresora_doc_id | bigint FK → impresoras, nullable | printer for closing document |
| estado | enum('EnProceso','Completada') | |
| created_at, updated_at | timestamps | |

Index: `(empresa_id, sucursal_id, sucursal_entrega, estado)`

### New table: `packing_unidades`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| sesion_id | bigint FK → packing_sesiones CASCADE | |
| consecutivo | smallint | 1, 2, 3… per session |
| estado | enum('Abierta','Cerrada') | |
| total_unidades | decimal(12,3) | computed on close |
| sticker_impreso | boolean default false | |
| closed_at | timestamp nullable | set on close |

Index: `(sesion_id, consecutivo)` UNIQUE

### New table: `packing_items`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| unidad_id | bigint FK → packing_unidades CASCADE | |
| picking_detalle_id | bigint FK → picking_detalles | source line |
| producto_id | bigint FK → productos | |
| lote | varchar(100) nullable | copied from lotes at item creation |
| fecha_vencimiento | date nullable | copied from lotes at item creation |
| separador_id | bigint FK → personal nullable | auxiliar_id from orden_picking |
| cantidad | decimal(12,3) | |
| created_at | timestamp | |

Index: `(unidad_id, producto_id)`

### Extension: `impresoras.tipos_trabajo`

Add column `tipos_trabajo` JSON (array of strings) to existing `impresoras` table.

Valid values: `sticker_packing`, `documento_packing`.

Migration adds the column with default `'[]'`. Existing records remain valid (empty array = appears in all contexts).

---

## 3. API Endpoints

All routes under `/api/packing` inside the authenticated JWT group.

| Method | Path | Controller method | Description |
|--------|------|-------------------|-------------|
| POST | `/packing/sesion` | `iniciarSesion` | Create session, open Unit #1 |
| GET | `/packing/sesion/{id}` | `getSesion` | Session state + open unit + pending products |
| POST | `/packing/sesion/{id}/item` | `agregarItem` | Add product qty to open unit |
| DELETE | `/packing/item/{id}` | `eliminarItem` | Remove item from open unit |
| POST | `/packing/unidad/{id}/cerrar` | `cerrarUnidad` | Close unit, compute total |
| GET | `/packing/unidad/{id}/sticker` | `sticker` | Render sticker HTML |
| GET | `/packing/sesion/{id}/stickers` | `stickersTodos` | All stickers HTML (for bulk print) |
| POST | `/packing/sesion/{id}/finalizar` | `finalizarSesion` | Complete session + trigger certFinalizar |
| GET | `/packing/sesion/{id}/documento` | `documento` | Render closing PDF document HTML |
| PUT | `/packing/sesion/{id}/impresoras` | `actualizarImpresoras` | Update printer selection mid-session |

---

## 4. UI Flow

### Step 1 — Session start dialog

Triggered from the existing picking certification screen for a branch (replacing the current direct "Certificar" button).

Dialog fields:
- **Tipo de empaque**: radio — Canasta / Caja / Paquete
- **Impresora stickers**: `<select>` filtered by `tipos_trabajo CONTAINS 'sticker_packing'`
- **Impresora documento**: `<select>` filtered by `tipos_trabajo CONTAINS 'documento_packing'`

On confirm → `POST /packing/sesion` → navigate to packing screen.

### Step 2 — Active packing screen (two-panel layout)

**Left panel — Productos pendientes:**
- List of all products for this sucursal_entrega from picking, showing:
  - Código, nombre, cantidad total pickeada, cantidad ya empacada, **pendiente**
- Clicking a product opens an inline qty input
- "Agregar" → `POST /packing/sesion/{id}/item`
- Pending count updates in real time

**Right panel — Unidad actual:**
- Header: `CAJA #003` (tipo + consecutivo), estado badge, subtotal
- Table of items added: referencia, descripción, cantidad, lote, vence
- Per-item delete button (only while unit is open)
- Footer: **"Cerrar unidad e imprimir sticker"** button (enabled always — manual close)

On unit close:
- `POST /packing/unidad/{id}/cerrar`
- Print dialog opens automatically (see §5)
- New unit opens automatically (consecutivo++)

**Top bar:**
- Session progress: `Pendiente: 42 uds | Empacado: 138 uds | Unidades: 3`
- Printer selector (editable mid-session)
- "Finalizar certificación" button — enabled only when `pendiente = 0`

### Step 3 — Finalize

`POST /packing/sesion/{id}/finalizar` →
1. Marks `packing_sesiones.estado = 'Completada'`
2. Calls `PickingController::certFinalizar()` internally
3. Returns session summary
4. UI shows closing document panel with print/download options

---

## 5. Sticker Design

### Content (all sizes)

```
┌─────────────────────────────────────────┐
│  [TIPO EMPAQUE]           Unidad #001   │
│  Sucursal: [nombre sucursal_entrega]    │
├─────────────────────────────────────────┤
│  Referencia    Descripción       Cant.  │
│  SKU-001       Producto A          24   │
│  SKU-002       Producto B          12   │
│  SKU-003       Producto C           6   │
├─────────────────────────────────────────┤
│  Total unidades: 42                     │
│  Certificador: Juan Pérez               │
│  Fecha: 2026-05-30    Hora: 14:35       │
└─────────────────────────────────────────┘
```

### Sizes

| Size | CSS @page | Use case |
|------|-----------|----------|
| Media carta | `size: 5.5in 8.5in; margin: 8mm` | Canastas, empaques pequeños |
| Carta | `size: letter; margin: 12mm` | Cajas grandes |
| A5 | `size: A5; margin: 8mm` | Uso general |

Size is selected per-print (not per-session) so the same session can use different sizes for different printers.

### Print modes

| Mode | Trigger | Behavior |
|------|---------|----------|
| Esta unidad | Auto after close / manual button | `GET /packing/unidad/{id}/sticker?size=letter` → `window.print()` |
| Grupo | Checkbox multi-select from unit list | `GET /packing/sesion/{id}/stickers?ids=1,2,5&size=a5` |
| Todas | Single button on session panel | `GET /packing/sesion/{id}/stickers?size=media_carta` |

---

## 6. Closing Document (PDF)

Generated as HTML, printed via `window.print()` or downloaded via browser Save as PDF.

### Structure

**Header:**
- Company name + logo, "DOCUMENTO DE PACKING" title
- Sucursal, Fecha, Hora apertura / cierre
- Tipo empaque, Certificador: [nombre]
- Separadores: [names of all unique pickers across all items]

**Body — detail table:**

| Unidad | Tipo | Referencia | Descripción | Cantidad | Lote | Vence |
|--------|------|-----------|-------------|---------|------|-------|
| #001 | Caja | SKU-001 | Producto A | 24 | L-001 | dic-26 |
| #001 | Caja | SKU-002 | Producto B | 12 | L-002 | mar-27 |
| #002 | Caja | SKU-001 | Producto A | 36 | L-001 | dic-26 |

Rows grouped by unit (zebra shading per unit block). Page breaks between units when printing.

**Footer — totals:**
- Total unidades de empaque: N
- Total unidades de producto: N
- Referencias distintas: N
- Separó: [names] | Certificó: [name]

**Orientation:** Portrait default. Landscape selectable via button before printing (toggles `@page { size: landscape }` dynamically).

---

## 7. Printer Configuration

### `impresoras` table extension

```sql
ALTER TABLE impresoras ADD COLUMN tipos_trabajo JSON NOT NULL DEFAULT '[]';
```

Valid job types: `sticker_packing`, `documento_packing`.

An impresora with an empty array `[]` appears in all contexts (backwards compatible).

### UI — Maestro → Impresoras

Add multi-checkbox to create/edit form:
- `[ ] Stickers de packing`
- `[ ] Documento de packing`

### Session printer selection

- `impresora_sticker_id` and `impresora_doc_id` stored on `packing_sesiones`
- Both are editable mid-session via `PUT /packing/sesion/{id}/impresoras`
- If no impresora configured for a type, selector shows all printers (no filter)

---

## 8. Error Handling

| Scenario | Behavior |
|----------|----------|
| Adding qty > pending for a product | Return 422 with `"Cantidad supera el pendiente: X uds disponibles"` |
| Closing a unit with 0 items | Return 422 `"La unidad está vacía"` |
| Finalizing with pending > 0 | Return 422 `"Quedan X unidades sin empacar"` |
| Printer not reachable | `window.print()` still opens — OS handles printer availability |
| Session already Completada | Return 409 `"Sesión ya finalizada"` |

---

## 9. Security & Multi-tenancy

- All PackingController methods enforce `empresa_id` + `sucursal_id` from JWT user
- Only the `certificador_id` or a `Supervisor`/`SuperAdmin` can close a session or delete items
- `packing_sesiones` and all children are scoped to the same tenant as the picking orders they cover

---

## 10. Out of Scope

- Mobile UI (desktop only for this feature)
- Label printer integration (ZPL/ESC-POS) — uses standard print dialog only
- Packing within planilla certification (Flow C) — not in this spec
- Inventory impact (packing is a tracking layer only, no stock changes)
