# TV Dashboard 360° — Picking
**Fecha:** 2026-05-17  
**Estado:** Aprobado

---

## Objetivo

Rediseñar `public/tv-picking.html` para mostrar una vista completa (360°) de la operación de picking en tiempo real: planillas activas con progreso y tiempo transcurrido, KPIs globales, ranking de auxiliares con tiempos promedio, alertas de faltantes y filtros interactivos siempre visibles.

---

## Layout

```
┌─────────────┬────────────────────────────────────────────────┐
│             │  KPI STRIP (6 tarjetas)                        │
│   FILTROS   ├──────────────────────┬─────────────────────────┤
│   (panel    │                      │  RANKING AUXILIARES      │
│   lateral   │  TABLA PLANILLAS     │  — Por líneas           │
│   fijo      │  EN VIVO             │  — Tiempo prom/auxiliar │
│   ~210px)   │  (~60% ancho)        │  (~40% ancho)           │
│             ├──────────────────────┴─────────────────────────┤
│             │  FRANJA ALERTAS FALTANTES (scroll horizontal)  │
└─────────────┴────────────────────────────────────────────────┘
```

- El panel de filtros es fijo a la izquierda, siempre visible.
- El área principal ocupa el resto del ancho.
- La recepción se elimina del TV (ya existe módulo desktop dedicado).

---

## Panel de Filtros

| Control | Opciones | Parámetro API |
|---------|----------|---------------|
| Fecha | Hoy / Esta semana / Este mes / Personalizado | `fecha_inicio`, `fecha_fin` |
| Auxiliar | Todos / selector por nombre | `auxiliar_id` |
| Ruta / Área | Todos / selector | `planilla` (filtro área_comercial) |
| Botón "Actualizar" | Dispara `refresh()` inmediato | — |
| Indicador LIVE | Punto pulsante verde + countdown | — |

Al cambiar cualquier filtro se llama `refresh()` inmediatamente.

---

## KPI Strip — 6 Tarjetas

| # | Label | Cálculo | Color |
|---|-------|---------|-------|
| 1 | Planillas Activas | `pendientes + en_proceso` | Azul |
| 2 | Completadas hoy | `completadas` | Verde |
| 3 | % Progreso global | `(total_lineas_activas - lineas_pendientes) / total_lineas_activas × 100` | Cian |
| 4 | Líneas Pendientes | `lineas_pendientes` | Amarillo |
| 5 | Unidades Pendientes | `unidades_pendientes` | Púrpura |
| 6 | Con Faltantes | `alertas_faltantes.length` | Rojo |

La tarjeta de % Progreso muestra un subtítulo con `X líneas completadas de Y`.

---

## Tabla Planillas en Vivo

Fuente: `d.planillas_activas[]` (nuevo campo en dashboard API).

### Columnas

| Columna | Descripción |
|---------|-------------|
| Planilla | Código (ej. PK-20260517-28EB4) |
| Auxiliar(es) | Chips con nombre(s) |
| Progreso | Barra visual + "X / Y líneas" |
| % Avance | Número con color: rojo <50%, naranja <80%, verde ≥80% |
| ⏱ Tiempo | Reloj en vivo calculado en JS desde `hora_inicio` (campo TIME "HH:MM:SS"). JS combina la fecha local del cliente con ese tiempo para obtener un timestamp y calcula el elapsed. Formato "1h 23m". Si `hora_inicio` es null o "00:00:00": muestra "No iniciada". |
| Estado | Chip: Pendiente (gris), EnProceso (azul), Completada (verde) |
| Ruta | `area_comercial` |

### Comportamiento
- Ordenada por estado (EnProceso primero, luego Pendiente, luego Completada).
- El reloj de tiempo transcurrido se actualiza cada segundo en JS sin llamada API.
- Scroll vertical si hay más de 8 planillas.
- Filas con faltantes resaltadas con borde rojo izquierdo.

---

## Panel Derecho — Ranking + Tiempos

### Gráfica 1: Ranking por líneas separadas
- Tipo: barra horizontal (Chart.js)
- Datos: `ranking_auxiliares[].lineas` (líneas pickeadas hoy)
- Ordenado descendente

### Gráfica 2: Tiempo promedio por planilla
- Tipo: barra horizontal (Chart.js)
- Datos: `ranking_auxiliares[].avg_minutos` (nuevo campo)
- Solo auxiliares con `avg_minutos > 0` (tienen órdenes completadas)
- Eje X en minutos; tooltip muestra "X min promedio"
- Si no hay completadas, muestra mensaje "Sin datos de tiempo hoy"

---

## Franja de Alertas Faltantes

- Franja fija de ~48px en la parte inferior del área principal.
- Scroll horizontal CSS automático (animation `marquee`).
- Cada chip: `⚠ [producto] · [dif] unds faltantes · [planilla]` — usa el campo `dif` (cantidad_solicitada − cantidad_pickeada) del objeto `alertas_faltantes[]`. Fondo rojo oscuro, texto rojo claro.
- Si no hay faltantes: franja oculta (no ocupa espacio).

---

## Cambios de Backend — `PickingController::dashboard()`

### 1. Nuevo campo `planillas_activas`

```php
$stats['planillas_activas'] = OrdenPicking::where('empresa_id', $empresaId)
    ->where('sucursal_id', $user->sucursal_id)
    ->whereBetween('created_at', [$ini, $fin])
    ->whereIn('estado', ['Pendiente', 'EnProceso'])
    ->when($params['auxiliar_id'] ?? null, fn($q,$a) => $q->where('auxiliar_id', $a))
    ->when($params['planilla'] ?? null, fn($q,$p) => $q->where('area_comercial','like',"%$p%"))
    ->withCount([
        'detalles as total_lineas',
        'detalles as lineas_completadas' => fn($q) => $q->whereIn('estado',['Completado','Faltante']),
    ])
    ->with(['detalles:id,orden_picking_id,auxiliar_id,estado',
            'detalles.auxiliar:id,nombre'])
    ->orderByRaw("FIELD(estado,'EnProceso','Pendiente')")
    ->get()
    ->map(fn($o) => [
        'planilla_numero' => $o->planilla_numero ?? $o->numero_orden,
        'estado'          => $o->estado,
        'ruta'            => $o->area_comercial,
        'hora_inicio'     => $o->hora_inicio,
        'total_lineas'    => $o->total_lineas,
        'lineas_completadas' => $o->lineas_completadas,
        'auxiliares'      => $o->detalles->pluck('auxiliar.nombre')
                               ->filter()->unique()->values(),
        'tiene_faltante'  => $o->detalles->contains('estado','Faltante'),
    ]);
```

### 2. Campo `avg_minutos` en `ranking_auxiliares`

Agregar `AVG(TIMESTAMPDIFF(MINUTE, o.hora_inicio, o.hora_fin))` al SELECT del ranking:

```php
Capsule::raw('AVG(CASE WHEN o.hora_fin IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, o.hora_inicio, o.hora_fin) ELSE NULL END) as avg_minutos')
```

---

## Flujo de datos

```
1. Página carga → init() → token OK → refresh()
2. refresh() → Promise.allSettled([loadPicking()])
3. loadPicking() → GET /picking/dashboard?{filtros}
   └─ Retorna: stats KPIs + planillas_activas + ranking_auxiliares (con avg_minutos)
4. renderKPIs(d) — actualiza 6 tarjetas
5. renderPlanillasTable(d.planillas_activas) — dibuja tabla, arranca liveTimers
6. renderCharts(d.ranking_auxiliares) — 2 gráficas
7. renderAlertas(d.alertas_faltantes) — franja marquee
8. startCountdown() → a los N segundos vuelve al paso 2
9. liveTimers: setInterval cada 1s actualiza celdas de tiempo sin llamada API
```

---

## Manejo de errores

- Si `loadPicking()` falla: muestra toast rojo en esquina inferior derecha, mantiene datos anteriores en pantalla.
- Si `planillas_activas` viene vacío: tabla muestra fila "Sin planillas activas para los filtros seleccionados".
- Si `ranking_auxiliares` vacío: gráficas muestran placeholder "Sin datos hoy".

---

## Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `public/tv-picking.html` | Reescritura completa del HTML/CSS/JS |
| `src/Controllers/PickingController.php` | Agregar `planillas_activas` y `avg_minutos` al método `dashboard()` |
