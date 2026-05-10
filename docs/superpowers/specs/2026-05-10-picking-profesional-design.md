# Picking Profesional — Diseño Técnico

**Fecha:** 2026-05-10  
**Módulo:** `public/assets/js/desktop/picking.js` + `src/Controllers/PickingController.php`  
**Aprobado por:** Usuario (sesión de brainstorming)

---

## Objetivo

Profesionalizar el módulo de Picking del WMS Fénix con asignación multi-auxiliar inteligente, filtros avanzados, carga agrupada por sucursal de entrega, y reporte histórico exportable a Excel.

---

## Decisiones de Diseño Aprobadas

| Decisión | Elección |
|---|---|
| Modelo de asignación | Híbrido: Ambiente por defecto, Pasillo opcional |
| Layout submodulo asignación | Tabla + Drawer lateral instantáneo |
| Totales en drawer | KPI chips por ambiente (Seco / Frío / Congelado) |
| Reporte | Resumen por pedido (una fila = un pedido) |
| Arquitectura DB | Extender tablas existentes (mínima migración) |
| `sucursal_entrega` | Viene del CSV columna "SUCURSAL ENTREGA" |
| `ruta` | Asignada manualmente por supervisor post-carga |
| Orden lógico picking | Generado por sistema en asignación (pasillo→módulo→nivel) |

---

## Sección 1 — Modelo de Datos

### Migración nueva: `065_picking_profesional.php`

```sql
-- Campo destino de entrega (viene del CSV)
ALTER TABLE orden_pickings
  ADD COLUMN sucursal_entrega VARCHAR(200) NULL AFTER cliente;

-- Ruta asignada manualmente por supervisor
ALTER TABLE orden_pickings
  ADD COLUMN ruta VARCHAR(100) NULL AFTER sucursal_entrega;

-- Orden lógico de separación generado por el sistema en asignación
ALTER TABLE orden_pickings
  ADD COLUMN orden_logico INT NULL AFTER ruta;

-- Ambiente de cada línea (calculado al asignar)
ALTER TABLE picking_detalles
  ADD COLUMN ambiente VARCHAR(30) NULL AFTER auxiliar_id;
  -- Valores: 'Seco' | 'Refrigerado' | 'Congelado'

-- Log de auditoría de asignaciones
CREATE TABLE picking_asignaciones_log (
  id             BIGINT PRIMARY KEY AUTO_INCREMENT,
  empresa_id     BIGINT NOT NULL,
  sucursal_id    BIGINT NOT NULL,
  ordenes_json   TEXT   NOT NULL,
  modo           ENUM('ambiente','pasillo') NOT NULL,
  config_json    TEXT   NOT NULL,
  lineas_total   INT    NOT NULL,
  ruta           VARCHAR(100) NULL,
  asignado_por   BIGINT NOT NULL,
  created_at     TIMESTAMP DEFAULT NOW(),
  INDEX idx_log_empresa (empresa_id, sucursal_id, created_at)
);

-- Índices de búsqueda rápida
ALTER TABLE orden_pickings
  ADD INDEX idx_picking_ruta (empresa_id, sucursal_id, ruta),
  ADD INDEX idx_picking_sucursal (empresa_id, sucursal_id, sucursal_entrega),
  ADD INDEX idx_picking_fecha_estado (empresa_id, sucursal_id, fecha_movimiento, estado);
```

### Invariante de no-duplicación

Una línea (`picking_detalles`) con `auxiliar_id IS NOT NULL` es inmutable hasta que el supervisor la libere explícitamente. El motor de asignación solo opera sobre líneas con `auxiliar_id IS NULL AND estado = 'Pendiente'`.

### Clasificación de ambiente

1. Si la línea tiene `ubicacion_id` → `JOIN ubicaciones.zona`:
   - zona contiene 'Refrig' → `'Refrigerado'`
   - zona contiene 'Congel' → `'Congelado'`
   - resto → `'Seco'`
2. Fallback (sin ubicación) → `JOIN productos.categoria` con mapeo configurable en `parametros` de empresa.

---

## Sección 2 — Arquitectura Frontend

### Archivo: `public/assets/js/desktop/picking.js`

**Submodulos activos:**

| Submodulo | Función | Cambio |
|---|---|---|
| `pedidos` | `show_pedidos()` | Rediseño completo de filtros y tabla |
| `asignacion` | `show_asignacion()` | Rediseño completo con drawer |
| `faltantes` | `show_faltantes()` | Sin cambios |
| `dashboard` | `show_dashboard()` | Sin cambios |
| `reporte` | `show_reporte()` | Rediseño con filtros + Excel |

### `show_pedidos()` — Filtros

- **Por defecto**: `fecha_movimiento = HOY` + `estado IN ('Pendiente','EnProceso')`
- Los pedidos Completados/Cancelados solo aparecen al cambiar filtro de fecha o estado
- Filtros disponibles: texto libre (ruta/sucursal/número), fecha desde-hasta, ruta (select), sucursal entrega (select), estado
- **Fila expandible**: al clic muestra los `numero_pedido` que componen la orden, asesor, progreso por ambiente
- **Acciones por fila**: Ver detalle (drawer), Eliminar (confirmación), Asignar ruta (edición inline)
- **Columnas**: N° Pedido · Sucursal Entrega · Ruta · 🌡️Seco · ❄️Frío · 🧊Cong · Total · Estado · Acciones

### `show_asignacion()` — Drawer de Asignación

- **Por defecto**: mismos filtros que pedidos (hoy + Pendiente)
- Columnas tabla: N° Pedido · Sucursal Entrega · Ruta · 🌡️Seco · ❄️Frío · 🧊Cong · Total · Estado
- **Fila de totales** en `<tfoot>`: suma solo de los pedidos seleccionados por ambiente
- **Drawer** aparece al marcar el primer checkbox, desaparece al desmarcar todos:
  - Header: "N pedidos · M líneas"
  - Toggle modo: Ambiente / Pasillo
  - KPI chips: Seco [N] · Frío [N] · Congelado [N]
  - Selectores de auxiliar por ambiente (con badge de líneas)
  - Input de nombre de ruta
  - Botón Confirmar (verde) / Cancelar
- **Modo Pasillo**: reemplaza selectores de ambiente por filas de rango pasillo_desde–pasillo_hasta con select de auxiliar

### `show_reporte()` — Historial

- Arranca vacío; requiere fecha_desde + fecha_hasta para buscar
- Filtros: fecha desde-hasta, ruta (select), sucursal entrega (select)
- KPI strip: total pedidos · líneas completadas · faltantes · duración promedio
- Botón "Exportar Excel" genera XLSX via SheetJS con columnas definidas en Sección 4

### Funciones internas nuevas

| Función | Propósito |
|---|---|
| `_buildFiltrosDefault()` | Aplica hoy + Pendiente/EnProceso al cargar submodulo |
| `_calcularTotalesAmbiente(pedidos)` | Suma líneas por ambiente para KPI chips del drawer |
| `_renderDrawerAsignacion()` | Recalcula KPIs al marcar/desmarcar pedidos |
| `_confirmarAsignacion()` | POST a `/picking/asignar-ambiente`, deshabilita botón durante el request |
| `_exportarExcel(rows)` | Genera XLSX via SheetJS, descarga automática |
| `_asignarRutaInline(id)` | PUT ruta a orden individual sin recargar tabla |

---

## Sección 3 — Motor de Asignación Backend

### Endpoint nuevo: `POST /picking/asignar-ambiente`

**Controller:** `PickingController::asignarPorAmbiente()`  
**Middleware:** JWT + TenantMiddleware + permiso `picking.asignaciones`

**Payload modo ambiente:**
```json
{
  "orden_ids": [101, 102, 103],
  "modo": "ambiente",
  "config": {
    "Seco":        { "auxiliar_id": 12 },
    "Refrigerado": { "auxiliar_id": 8  },
    "Congelado":   { "auxiliar_id": null }
  },
  "ruta": "Ruta 01"
}
```

**Payload modo pasillo:**
```json
{
  "orden_ids": [101, 102, 103],
  "modo": "pasillo",
  "config": {
    "rangos": [
      { "pasillo_desde": "P01", "pasillo_hasta": "P05", "auxiliar_id": 12 },
      { "pasillo_desde": "P06", "pasillo_hasta": "P10", "auxiliar_id": 8  }
    ]
  },
  "ruta": "Ruta 01"
}
```

### Algoritmo (dentro de una transacción DB)

```
1. SELECT picking_detalles WHERE orden_picking_id IN (orden_ids)
   AND auxiliar_id IS NULL AND estado = 'Pendiente'
   FOR UPDATE  ← bloqueo pesimista

2. Para cada línea: determinar ambiente
   - Con ubicacion_id: JOIN ubicaciones.zona → clasificar
   - Sin ubicacion_id: JOIN productos.categoria → clasificar

3. Agrupar líneas por ambiente:
   { "Seco": [id1,id2,...], "Refrigerado": [...], "Congelado": [...] }

4. Validar: si alguna orden_id tiene líneas con auxiliar_id NOT NULL
   → ROLLBACK + HTTP 409 con lista de pedidos en conflicto

5. UPDATE picking_detalles
   SET auxiliar_id = ?, ambiente = ?, estado = 'EnProceso'
   WHERE id IN (lote_por_ambiente)  ← un UPDATE por ambiente

6. Generar orden_logico por auxiliar:
   - Para cada auxiliar: sus líneas ordenadas por
     ubicaciones.pasillo ASC → ubicaciones.modulo ASC → ubicaciones.nivel ASC
   - UPDATE orden_pickings SET orden_logico = ROWNUM, ruta = ?

7. Reservar inventario (cantidad_reservada += cantidad_solicitada)
   vía lógica existente (lockForUpdate en inventarios)

8. INSERT picking_asignaciones_log

9. COMMIT → HTTP 200 con resumen de asignación
```

**Respuesta exitosa:**
```json
{
  "asignadas": 40,
  "por_ambiente": { "Seco": 32, "Refrigerado": 5, "Congelado": 3 },
  "sin_auxiliar": 0,
  "ordenes": 3
}
```

**Líneas de ambiente sin auxiliar configurado** (ej. Congelado: null): permanecen con `auxiliar_id = NULL` y `estado = 'Pendiente'`. Quedan visibles en el submodulo de asignación para ser asignadas en un paso posterior. El campo `ambiente` sí se actualiza para facilitar el filtro.

### Otros cambios backend

**`listar()`** — parámetros nuevos:
- `?sucursal_entrega=` — filtro exacto
- `?ruta=` — filtro exacto  
- `?fecha_desde=&fecha_hasta=` — rango de fechas
- `?solo_hoy=1` — activo por defecto en index y asignación
- `?incluir_finalizados=1` — desactiva el filtro de estado por defecto

**`importarPedidos()`** — alias nuevo en el mapper:
```php
'sucursal_entrega' => ['SUCURSAL ENTREGA', 'sucursal_entrega', 
                        'Sucursal Entrega', 'punto_entrega', 'destino']
```

**`eliminar()`** — verificar líneas con `auxiliar_id NOT NULL` antes de eliminar → liberar reservas → auditoría.

**`reporte()`** — nuevo método o extensión del existente:
```
GET /picking/reporte?fecha_desde=&fecha_hasta=&ruta=&sucursal_entrega=
→ Array de objetos: un objeto por orden_picking con todos los campos del resumen
```

### Garantías anti-duplicado

| Capa | Mecanismo |
|---|---|
| Base de datos | `SELECT ... FOR UPDATE` — bloqueo a nivel fila durante la transacción |
| Backend | Validación explícita paso 4 — HTTP 409 si hay colisión antes de escribir |
| Frontend | Botón "Confirmar" se deshabilita con `disabled` durante el POST |
| UI post-asignación | Pedidos asignados desaparecen del submodulo de asignación |

---

## Sección 4 — Reporte + Excel

### Columnas del Excel (una fila = un pedido)

| Columna | Fuente |
|---|---|
| Fecha | `fecha_movimiento` |
| N° Pedido | `numero_pedido` |
| Sucursal Entrega | `sucursal_entrega` |
| Ruta | `ruta` |
| Total Líneas | COUNT `picking_detalles` |
| Completadas | COUNT estado='Completado' |
| Faltantes | COUNT estado='Faltante' |
| % Cumplimiento | completadas/total×100 |
| Auxiliar(es) | DISTINCT nombres, separados por coma |
| Hora Inicio | `hora_inicio` |
| Hora Fin | `hora_fin` |
| Duración (min) | diferencia calculada |

**Nombre de archivo:** `Picking_Reporte_YYYY-MM-DD.xlsx`  
**Librería:** SheetJS — cargado dinámicamente desde CDN (`https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js`) solo cuando el usuario hace clic en "Exportar Excel". No requiere instalación.

---

## Archivos a Modificar

| Archivo | Tipo de cambio |
|---|---|
| `public/assets/js/desktop/picking.js` | Rediseño completo de 3 submodulos |
| `src/Controllers/PickingController.php` | Nuevo método + extensiones existentes |
| `database/migrations/065_picking_profesional.php` | Migración nueva |
| `src/routes/api.php` | Registro de nueva ruta POST `/picking/asignar-ambiente` |

---

## Flujo Completo del Usuario

```
1. Supervisor sube CSV con columnas (incluyendo SUCURSAL ENTREGA)
2. Sistema crea orden_pickings con sucursal_entrega poblada
3. Index de pedidos muestra hoy + Pendientes por defecto
   → columnas Seco/Frío/Cong visibles por pedido
   → clic en fila expande números de pedido de esa sucursal
4. Supervisor va a submodulo Asignación
   → marca pedidos con checkbox
   → drawer aparece con KPIs por ambiente
   → selecciona auxiliar por ambiente
   → escribe nombre de ruta
   → Confirmar → sistema asigna líneas + genera orden lógico
5. Pedidos pasan a EnProceso → desaparecen de la vista de Asignación
6. Auxiliares ven sus líneas en app móvil en orden lógico de ubicaciones
7. Reporte: supervisor filtra por fecha/ruta/sucursal → ve historial → exporta Excel
```
