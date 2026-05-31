# Sub-proyecto A — Control de Vencimientos (ExpiryGuard)

**Fecha:** 2026-05-30
**Estado:** Aprobado — pendiente implementación
**Proyecto:** WMS Fénix

---

## Contexto y Motivación

El sistema actualmente permite realizar picking y packing de productos vencidos o próximos a vencer sin ningún bloqueo ni alerta. Un producto con `fecha_vencimiento` pasada puede fluir desde el inventario hasta el despacho sin fricción. Esto representa un riesgo operativo y de calidad crítico.

Este sub-proyecto implementa un guardia centralizado (`ExpiryGuard`) que:
- Bloquea **en tiempo real** el picking y el packing de productos vencidos (día 0 o posterior).
- Envía una solicitud de aprobación al admin/supervisor cuando el producto tiene ≤5 días antes de vencer.
- Auto-pone en cuarentena el inventario vencido de forma lazy cuando se detecta durante operaciones normales.

---

## Arquitectura

### Componente central: `ExpiryGuard`

Nuevo servicio en `src/Services/ExpiryGuard.php`. Es el único lugar donde vive la lógica de vencimiento; ningún controller la reimplementa.

**Interfaz pública:**

```php
ExpiryGuard::check(
    int $empresaId,
    int $sucursalId,
    int $productoId,
    string $lote,
    int $solicitadoPor
): ExpiryResult
```

`ExpiryResult` es un value object con tres estados posibles:
- `OK` — producto vigente, continuar sin fricción. También retorna `OK` si el inventario no tiene `fecha_vencimiento` registrada (no se bloquea lo que no se sabe).
- `BLOCKED` — producto vencido (`fecha_vencimiento < hoy`). No se puede proceder bajo ninguna circunstancia.
- `PENDING` — producto próximo a vencer (1–5 días). Requiere aprobación del admin/supervisor. Retorna `aprobacion_id` para que el frontend haga polling.

**`autoQuarantine(int $empresaId, int $sucursalId)`**

Escanea inventario con `fecha_vencimiento < hoy` y `estado != 'cuarentena'`, los marca como `cuarentena` en lote. Llamado lazy desde puntos estratégicos, no en un cron.

### Nueva regla en `InventoryGuard`

Se agregan dos reglas al final del pipeline de validación existente (R01–R09):

- **R10** — `fecha_vencimiento < HOY` → resultado `BLOCKED`. Sin excepciones, sin override.
- **R11** — `0 < dias_restantes ≤ 5` → resultado `PENDING`. Requiere aprobación válida del día.

Las reglas R01–R09 no cambian.

---

## Base de Datos

### Tabla nueva: `aprobaciones_vencimiento`

```sql
CREATE TABLE aprobaciones_vencimiento (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id       INT UNSIGNED NOT NULL,
    sucursal_id      INT UNSIGNED NOT NULL,
    producto_id      INT UNSIGNED NOT NULL,
    lote             VARCHAR(100) NOT NULL,
    dias_restantes   INT NOT NULL,
    solicitado_por   INT UNSIGNED NOT NULL,  -- auxiliar que intentó el picking/packing
    aprobado_por     INT UNSIGNED NULL,       -- admin/supervisor que resolvió
    estado           ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    valid_until      DATE NULL,               -- solo válida el día en que se aprueba (llenada al aprobar)
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX idx_aprobaciones_empresa (empresa_id, sucursal_id, estado),
    INDEX idx_aprobaciones_producto (producto_id, lote, valid_until)
);
```

**Regla de validez:** Una aprobación con `estado = 'aprobada'` solo es usable si `valid_until = CURDATE()`. Si el mismo lote se intenta despachar al día siguiente, se necesita una nueva aprobación.

### Columna nueva: `picking_detalles.fecha_vencimiento`

```sql
ALTER TABLE picking_detalles
    ADD COLUMN fecha_vencimiento DATE NULL;
```

Se llena cuando el auxiliar confirma una línea de picking sobre un lote que tiene `fecha_vencimiento` registrado en inventario. Permite trazabilidad directa sin lookup adicional.

---

## Flujo de Aprobación

### Auxiliar solicita picking/packing de producto próximo a vencer

1. `PickingController::confirmLine()` o `PackingController::agregarItem()` llaman `ExpiryGuard::check()`.
2. Resultado `PENDING` → se crea registro en `aprobaciones_vencimiento` con `estado = 'pendiente'`.
3. API retorna **HTTP 202** con body:
   ```json
   {
     "status": "pending_approval",
     "aprobacion_id": 47,
     "message": "Producto próximo a vencer (3 días). Esperando autorización del supervisor."
   }
   ```
4. Frontend muestra modal de espera con spinner. Hace polling cada 10 segundos a `GET /api/aprobaciones/{id}/estado`.
5. El auxiliar puede cancelar la solicitud (`DELETE /api/aprobaciones/{id}`).

### Admin/Supervisor resuelve la solicitud

1. Badge en el header con contador rojo se actualiza cada 30 segundos vía `GET /api/aprobaciones/vencimiento/pendientes` (solo visible para Admin y Supervisor).
2. Al hacer clic en la campana → panel lateral (drawer) con tarjetas de solicitudes pendientes.
3. Cada tarjeta muestra: auxiliar, hora, producto, lote, días restantes, pedido, cantidad.
4. Botones `[Aprobar ✓]` y `[Rechazar ✗]` → `POST /api/aprobaciones/{id}/resolver` con `{ "decision": "aprobada" | "rechazada" }`.
5. Al aprobar: `aprobado_por = admin.id`, `estado = 'aprobada'`, `valid_until = CURDATE()`.
6. En el próximo ciclo de polling del auxiliar (≤10 s) detecta el cambio y procede o muestra rechazo.

### Producto vencido (BLOCKED)

- `ExpiryGuard::check()` retorna `BLOCKED`.
- API retorna **HTTP 422**:
  ```json
  {
    "error": true,
    "code": "PRODUCT_EXPIRED",
    "message": "El producto Leche UHT 1L (Lote L-42) está vencido (2026-05-28). No puede ser despachado."
  }
  ```
- No se crea registro en `aprobaciones_vencimiento`. No hay flujo de aprobación para productos vencidos.
- `autoQuarantine()` marca ese lote en inventario como `cuarentena`.

---

## Puntos de Integración en Controllers

| Controller | Método | Momento de llamada |
|---|---|---|
| `PickingController` | `confirmLine()` | Antes de crear `MovimientoInventario` |
| `PackingController` | `agregarItem()` | Antes de crear `packing_unidades` ítem |
| `FefoEngine` | `getSuggestedLots()` | Al inicio, llama `autoQuarantine()` y filtra lotes `cuarentena` |

`InventarioV2Controller::recepcionar()` recibe el hook de la regla de 75% de vida útil (Sub-proyecto B), no de ExpiryGuard.

---

## API Endpoints Nuevos

| Método | Ruta | Descripción | Rol mínimo |
|---|---|---|---|
| `GET` | `/api/aprobaciones/vencimiento/pendientes` | Lista aprobaciones pendientes de la empresa/sucursal | Supervisor |
| `POST` | `/api/aprobaciones/{id}/resolver` | Aprobar o rechazar una solicitud | Supervisor |
| `GET` | `/api/aprobaciones/{id}/estado` | Polling del auxiliar — retorna estado actual | Auxiliar |
| `DELETE` | `/api/aprobaciones/{id}` | Auxiliar cancela su solicitud pendiente | Auxiliar |

---

## Archivos a Crear / Modificar

### Nuevos
- `src/Services/ExpiryGuard.php` — servicio central
- `src/Models/AprobacionVencimiento.php` — modelo Eloquent
- `src/Controllers/AprobacionController.php` — 4 endpoints
- `database/migrations/add_fecha_vencimiento_picking_detalles.sql`
- `database/migrations/create_aprobaciones_vencimiento.sql`

### Modificados
- `src/Services/InventoryGuard.php` — agregar R10 y R11
- `src/Services/FefoEngine.php` — llamar `autoQuarantine()` al inicio de `getSuggestedLots()`
- `src/Controllers/PickingController.php` — `confirmLine()` llama `ExpiryGuard::check()`
- `src/Controllers/PackingController.php` — `agregarItem()` llama `ExpiryGuard::check()`
- `public/assets/js/desktop/picking.js` — manejo de 202 + polling modal
- `public/assets/js/desktop/packing.js` — idem
- `public/index.html` — badge campana en header, panel drawer admin
- `routes/api.php` — registrar 4 nuevas rutas

---

## Criterios de Aceptación

1. `POST /api/picking/confirmar-linea` con lote vencido retorna 422 con `code: PRODUCT_EXPIRED`.
2. `POST /api/picking/confirmar-linea` con lote a 3 días de vencer retorna 202 con `aprobacion_id`.
3. Tras resolver con "aprobada", el mismo endpoint procede normalmente (HTTP 200).
4. Tras resolver con "rechazada", el auxiliar ve mensaje de rechazo y el ítem se limpia.
5. Una aprobación concedida hoy no es válida mañana.
6. `FefoEngine::getSuggestedLots()` nunca sugiere lotes con `estado = 'cuarentena'`.
7. El badge del header no aparece para el rol Auxiliar.

---

## Fuera de Alcance (este sub-proyecto)

- Regla del 75% de vida útil en recepción → Sub-proyecto B.
- Reporte de trazabilidad completo → Sub-proyecto D.
- Rediseño del módulo de packing → Sub-proyecto C.
