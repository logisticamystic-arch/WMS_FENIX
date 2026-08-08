# Auditoría — Agotados en la importación de pedidos del 07 de agosto de 2026

**Solicitado por:** Camilo — pregunta puntual sobre el pedido de OLIVIA TESORO, donde el atún no salió para separar pese a estar en el archivo de importación.

**Alcance real de la investigación:** se partió del caso puntual (atún, OLIVIA TESORO) y se amplió a los 69 registros de "Agotados/Faltantes" generados el 07 de agosto, cruzándolos contra el inventario real disponible hoy y contra el kardex del período.

---

## Conclusión principal

**No hubo ninguna referencia oculta ni fallida en la importación.** Las 69 líneas marcadas como agotadas el 7 de agosto **sí se cargaron correctamente al sistema** — existen como líneas de `picking_detalles` con su producto, cantidad y ambiente correctos. El problema no es de carga; es que varias de esas líneas se marcaron como "Agotado — Sin stock físico" **mientras el sistema tenía stock disponible y sin reservar para ese mismo producto**, y nadie volvió a intentarlo después.

## Clasificación de las 69 líneas contra el stock disponible hoy

| Categoría | Líneas | Productos distintos |
|---|---|---|
| Stock hoy claramente disponible (≥100 unidades) | 19 | 7 |
| Algo de stock hoy (&lt;100) | 13 | 2 |
| Sin stock hoy tampoco (agotado real, consistente) | 37 | 20 |

Es decir: **32 de 69 líneas (46%)** corresponden a productos que hoy tienen inventario disponible y sin reservar — la mayoría de ellos ya lo tenían disponible el mismo 7 de agosto, sin que nadie lo haya usado para completar el pedido.

## Caso 1 — I ATUN X UND (código 112187)

Apareció **8 veces** el 7 de agosto, en 8 pedidos distintos de la cadena OLIVIA (Tesoro, Fabricato, Manila, Oviedo, Envigado, Laureles, San Lucas, Viva Envigado), todas con causa "Agotado — Sin stock físico":

| Pedido | Hora del faltante | Cantidad faltante |
|---|---|---|
| OLIVIA ENVIGADO (17215) | 09:00 | 5 |
| OLIVIA TESORO (31) | 09:38 | 31 |
| OLIVIA MANILA (14) | 09:52 | 14 |
| OLIVIA FABRICATO (2) | 10:09 | 2 |
| OLIVIA SAN LUCAS (10) | 10:33 | 10 |
| OLIVIA SANTA FE (38) | 10:56 | 38 |
| OLIVIA VIVA ENVIGADO (5) | 11:52 | 5 |
| OLIVIA OVIEDO (10) | 11:30 | 10 |

**Demanda total: 115 unidades. Stock disponible hoy: 59 unidades.** Este caso es **parcialmente real**: no había suficiente atún para cubrir a los 8 clientes simultáneamente. Sin embargo, el registro de inventario (`inventarios.updated_at`) se actualizó a las **10:56:23** — es decir, después de que 3 de los 8 reclamos ya se habían registrado (09:00, 09:38, 09:52) pero **antes** de otros 3 (11:30, 11:52, y el de las 10:56 mismo). No existe ningún movimiento de kardex reciente para este producto, por lo que no se puede confirmar con certeza si esas 59 unidades ya estaban físicamente disponibles desde temprano o llegaron a media mañana. Lo que sí es seguro: **nadie reintentó separar el atún para ningún pedido después de las 10:56**, pese a que en ese momento ya había 59 unidades libres.

## Caso 2 — I YOGURT GRIEGO NATURAL X 4000 GR (código 109011) — el más grave

Apareció **4 veces** el 7 de agosto, en 4 pedidos distintos, todas "Agotado — Sin stock físico":

| Pedido | Hora | Faltante |
|---|---|---|
| CLAP BURGER ENVIGADO (17221) | 08:53 | 2 cajas |
| CLAP CHICKEN VIVA ENVIGADO (17218) | 09:10 | 1 caja |
| CLAP BURGER SEBASTIANA (17226) | 10:12 | 2 cajas |
| CLAP CHICKEN SANTA FE (17217) | 11:04 | 3 cajas |

**Este caso NO se explica por falta de stock.** El kardex muestra que este producto tenía **200.000 g (50 cajas) disponibles desde el 5 de agosto a las 16:42**, sin ninguna reserva encima, en la ubicación REFRIGERACION/02-16-02 — **dos días antes** de que se generaran los 4 reclamos de agotado. Incluso descontando el picking normal de esos dos días (40.000 g), quedaban **238.250 g (~59 cajas) disponibles el 6 de agosto**, más que suficiente para las 8 cajas que pedían en total estos 4 clientes.

**Hallazgo adicional y más serio:** entre el 6 y el 7 de agosto, el registro de inventario de este producto bajó de 238.250 g a 38.250 g (–200.000 g) **sin ningún asiento correspondiente en el kardex** (`movimiento_inventarios`). Es la primera vez en toda la auditoría de esta semana que se detecta un cambio de cantidad en `inventarios` sin su respaldo obligatorio en el kardex — viola directamente la Regla de Oro #2 del sistema ("el kardex es inmutable y es la única fuente de movimientos"). Se identificó que el 5 de agosto ya había ocurrido un ajuste casi idéntico (–278.250 g vía una sesión de conteo físico #39, revertido minutos después por una "corrección de sistema" que restauró el valor original). Todo indica que **hay una segunda sesión de conteo o corrección que tocó este mismo producto el 6-7 de agosto sin pasar por el kardex** — no se pudo identificar cuál proceso exacto la generó porque no dejó rastro.

## Caso 3 — PORTAVASOS OLIVIA X 400 UND (código 320302)

Apareció **6 veces** el 7 de agosto en 6 sucursales OLIVIA distintas, todas "Agotado — Sin stock físico", por 1 a 4 unidades cada vez. Stock disponible hoy: **2.400 unidades**, sin reservar, en la ubicación SECO/03-12-03 (actualizada a las 10:37 del mismo 7 de agosto). Mismo patrón que el Yogurt: stock suficiente y disponible el mismo día, sin que ningún reclamo posterior a las 10:37 (San Lucas 11:18, Viva Envigado 11:32) lo haya vuelto a intentar.

## Lo que se descartó en el camino

- **No es un bug de importación.** `importarPedidos()` no marca ninguna línea como agotada; todas las líneas nacen "Pendiente".
- **No es un problema de ubicación de picking.** Se verificó que el campo `ubicacion_id` está vacío tanto en las 732 líneas completadas como en las 46 faltantes del día — es un comportamiento normal de este sistema (la ubicación se resuelve en el momento del escaneo, no se guarda de antemano), no una pista real.
- **El "cierre de ambiente" es una función legítima, no un bug.** Cuando un operario cierra su zona con líneas pendientes, el sistema exige confirmar/forzar antes de marcarlas como agotadas — es un mecanismo de escape intencional, documentado en el propio código.

## Causa raíz identificada

El sistema **nunca valida la afirmación "sin stock físico" contra su propio inventario en tiempo real.** Cuando un operario marca una línea como agotada (al cerrar ambiente, o manualmente), el sistema acepta la causa que se escribe y no compara contra `inventarios.cantidad - cantidad_reservada` antes de darla por definitiva. Si hubiera esa validación, el sistema habría podido alertar: *"Este producto tiene 238.250 g disponibles sin reservar — ¿confirma que no hay stock físico?"* en vez de aceptar el reclamo sin contraste.

## Plan de acción propuesto

1. **Inmediato (operativo):** Revisar físicamente con el equipo de bodega dónde están realmente el Yogurt Griego (ubicación REFRIGERACION/02-16-02) y los Portavasos Olivia (SECO/03-12-03) — el sistema dice que están ahí y disponibles.
2. **Corto plazo (proceso):** Antes de aceptar un "Agotado — Sin stock físico", que el sistema muestre al operario/supervisor el stock disponible actual de ese producto y exija una confirmación explícita si hay discrepancia.
3. **Investigar aparte:** Identificar qué proceso (probablemente ligado a `sesion_inventario` / conteos físicos) modificó `inventarios.cantidad` del Yogurt Griego sin generar kardex entre el 6 y 7 de agosto — es una brecha real en la garantía de trazabilidad del sistema, independiente de este caso puntual.
4. **Reprocesar:** Los pedidos de Yogurt Griego y Portavasos Olivia de ayer probablemente sí se pueden completar hoy — hay stock suficiente y sin reservar para ambos.
