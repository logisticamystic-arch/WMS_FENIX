# Auditoría de Inventario WMS Fénix — 2026-08-05

Auditoría de 5 agentes en paralelo (orquestador `Skills/super-fenix`), alcance: **todo lo referente a movimientos de inventario** — eliminar fuga de información, reservas fantasma y cantidades fantasma; validar conexiones e interacciones entre módulos. Lanzada el 2026-08-04, consolidada el 2026-08-05 (el 5º agente, vista Transversal, quedó pendiente al cerrarse la sesión anterior y se completó hoy).

## Scorecard

| Área | Puntaje | Estado |
|---|---|---|
| Entrada (Recepción/Putaway) | 55/100 | 🔴 |
| Picking / Reabastecimiento | 40/100 | 🔴 |
| Packing / Despacho | 60/100 | 🟡 |
| Devoluciones / Traspasos / Ajustes | 55/100 | 🔴 |
| Transversal (kardex↔stock, reservas, multi-tenant, concurrencia) | 50/100 | 🔴 |
| **TOTAL** | **52/100** | 🔴 |

El sistema opera hoy sin pérdida catastrófica visible, pero **la Regla de Oro #3 (`SUM(kardex)==existencias`) está rota de forma sistémica** en varios módulos, no solo en casos límite, y hay al menos un bug de certificación activo (no teórico) en la ruta más usada del sistema (picking móvil).

## Hallazgos Críticos (acción inmediata)

1. **El "signo del kardex" está triplicado y ya diverge en producción.** `InventoryGuard::KARDEX_SIGNOS` (`src/Helpers/InventoryGuard.php:492-502`, que se autodeclara fuente única) no incluye `'Salida'` (usado por `TraspasoController.php:226` y `DevolucionController.php:296`) ni `'DescuentoCertificacion'`/`'DevolucionCertificacion'` (`PickingController.php:8509,8530`). Mientras tanto, `InventarioV2Controller.php:2534,2567,2605` y `ConsultaRapidaController.php:194-197` reimplementan el mismo mapa a mano y clasifican `'Reabastecimiento'` distinto (entrada `+` vs `0`). Además, `'DescuentoCertificacion'` (`PickingController.php:8524-8537`) ya graba `cantidad` en negativo — si se agrega el signo sin corregir esto primero, el cálculo queda con signo contrario.
   → `assertLedgerMatchesStock()` subestima el kardex real de Traspaso/Devolución/certificación desde siempre.

2. **Mismatch cajas/unidades confirmado como bug activo en el picking móvil** (`public/mobile/index.html:7662-7721` trata `cantidad_solicitada` como UNIDADES; 8 de 9 puntos de creación de línea en `PickingController.php`/`PlanillaController.php` la tratan como CAJAS). Para cualquier producto con `unidades_caja`/`factor_udm` > 1 pickeado por escaneo individual: sobre-reserva de stock, "Faltante" falso, o certificación "Completado" con una fracción mínima de lo pedido. No es un caso límite — es la ruta normal para productos empacados.

3. **Fuga cross-empresa en `PickingController::editarPedidoCompleto()` (línea 5457-5484) y `guardarObservacionOrden()` (5410-5418).** El `UPDATE`/borrado de `orden_pickings`/`picking_detalles` filtra solo por número/id, sin `empresa_id`. Cualquier usuario autenticado puede editar, borrar o escribir `cantidad_pickeada` arbitraria en pedidos de otra empresa, sin pasar por inventario/kardex.

4. **Reservas de backorder liberadas silenciosamente.** `PickingController::_sincronizarReservas()` (líneas 574-630) resetea `cantidad_reservada=0` para TODA la empresa+sucursal (paso 1) pero el recálculo (paso 2) excluye el estado `'Faltante'` — cualquier línea en backorder pierde su reserva y no se restaura; el stock queda libre para otro pedido mientras el original cree que sigue reservado.

5. **`PackingController::cancelarSesion()` (líneas 883-909) borra físicamente la evidencia de empaque de sesiones `Completada`** (pedido ya certificado, posiblemente ya despachado) sin revertir `estado_certificacion` ni verificar `estado_despacho`. Se agrava porque el flujo normal (`finalizarSesion()`) nunca llena `cantidad_certificada` — al cancelar, no queda ningún rastro de qué se certificó.

6. **Tres condiciones de carrera nuevas sin `lockForUpdate()` dentro de transacción que sí modifican `cantidad`:** `PutawayController::trasladar()` (líneas 363-368 — su hermano `ubicar()` sí tiene el lock desde el parche de 2026-05-18, pero `trasladar()` quedó fuera), `PickingController::certAdminLote()` (8480-8485) e `InventarioV2Controller::correccionManual()` (2294). Cada uno puede producir lecturas obsoletas bajo concurrencia (stock negativo o descuadre).

7. **Duplicación de aprobación en Entrada ya divergió.** `RecepcionController::aprobarDetalle()` sí genera kardex al mover pallet de "En Patio" a "Disponible"; `InboundController::aprobarLineaODC()`/`aprobarODCTodo()` hacen la misma transición vía `UPDATE` crudo **sin crear `MovimientoInventario`**. El rastro de auditoría depende de qué endpoint se usó.

8. **`factor_udm` sigue sin aplicarse en ~14 de ~17 puntos del sistema** (confirmado por grep en Picking + Entrada + Putaway), pese al incidente real del 2026-08-03. Cualquier producto con `factor_udm` configurado que pase por Putaway, Recepción-con-ODC, o cualquiera de los 12 métodos de `PickingController` listados en el informe de Picking, repite el mismo bug.

9. **`InventarioV2Controller::ejecutarAjuste()`/`correccionManual()` (líneas 1909-1919, 2338-2348) borran/reducen `inventarios` sin chequear `cantidad_reservada`** → reserva fantasma. El propio `InventarioController::reconciliar()` (2699-2718) ya tiene una rutina reactiva que admite en su comentario que este síntoma "ocurre" en producción.

10. **`DevolucionController::store()` (líneas 231-276) puede acreditar stock fantasma en restock** cuando no hay inventario que descontar (`continue` silencioso) y luego `procesar()` (627-656) acredita incondicionalmente a `PATIO-DEV` sin verificar que hubo una salida real.

11. **Excepción silenciada en `RecepcionController::detallesOperativaSinOdc()` (líneas 962-965)** — el `catch` no hace `return`; puede reportar "recepción guardada" con `aprobado_admin=1` sin ningún respaldo en `inventarios`/kardex.

12. **`PutawayController::ubicar()`/`trasladar()` buscan la fila destino sin `estado` en la clave** (líneas 254-260, 403-410) — puede fusionar stock nuevo con una fila en Cuarentena/En Patio, reclasificando calidad sin asiento de kardex.

## Hallazgos Altos (esta semana)

- `InventoryGuard::assertLedgerMatchesStock()` solo se invoca desde `PickingController` — Traspaso, Devolución, AjusteUbicación, Putaway y Recepción no validan la invariante en ningún punto.
- `ReplenishmentController::runAutoReplenishment()` no excluye `cantidad_reservada` al calcular origen — puede competir con picking por el mismo stock; el módulo de reabastecimiento además nunca cierra el ciclo (no hay endpoint "completar tarea").
- `InventarioController::traslado()` no valida que la ubicación destino pertenezca al tenant (a diferencia de `AjusteUbicacionController`, que sí lo hace).
- TOCTOU en `AjusteUbicacionController::aprobar()` (chequeo de reserva fuera de la transacción) y en `DespachoController::agregarPedidos()` (mismo pedido puede terminar en dos despachos).
- `DespachoController` tiene docblock falso (dice que genera kardex; no genera ninguno) y lógica de reversión muerta desde que se eliminó `certify()`.
- `UbicacionesController::eliminar()` consulta inventario de una ubicación sin filtrar `empresa_id` — fuga menor de existencia de stock cross-tenant.
- `editarCertificacion()` no valida tope contra `cantidad_pickeada` — un Admin puede certificar más de lo físicamente separado.
- Condición de carrera sin lock en `PackingController::agregarItem()` — dos packers pueden empacar más de lo pickeado.

## Plan de Acción

**Sprint 1 (esta semana) — cierra las 3 fugas de fuga/pérdida de datos más graves:**
1. `editarPedidoCompleto()`/`guardarObservacionOrden()` — agregar filtro `empresa_id`, quitar la escritura arbitraria de `cantidad_pickeada`.
2. `PackingController::cancelarSesion()` — restringir a `estado==='EnProceso'`; bloquear si alguna orden ya está despachada.
3. Unificar la convención `cantidad_solicitada` (cajas vs. unidades) entre picking móvil y los 8 puntos de creación — o corregir `confirmLine()` para convertir antes de comparar.

**Sprint 2 (próximas 2 semanas) — invariante kardex↔stock:**
4. Normalizar `'DescuentoCertificacion'` a magnitud absoluta; luego completar `KARDEX_SIGNOS` con `'Salida'`, `'DescuentoCertificacion'`, `'DevolucionCertificacion'`; hacer que `InventarioV2Controller` y `ConsultaRapidaController` consuman ese mapa en vez de reimplementarlo.
5. Invocar `assertLedgerMatchesStock()` (modo alerta) al cierre de transacción en Traspaso, Devolución, AjusteUbicación y Putaway.
6. Agregar `lockForUpdate()` en `PutawayController::trasladar()`, `PickingController::certAdminLote()`, `InventarioV2Controller::correccionManual()`.
7. Bloquear `ejecutarAjuste()`/`correccionManual()` si `cantidad_reservada>0` y la nueva cantidad no la cubre.

**Sprint 3 (este mes):**
8. Aplicar `factor_udm`-first en los ~14 puntos pendientes (extraer helper `_upcEfectivo()`).
9. Unificar lógica de aprobación de Entrada (extraer `_aprobarPalletInterno()` reutilizable entre `InboundController`/`RecepcionController`).
10. Incluir `'Faltante'` en `_sincronizarReservas()`; agregar `estado` a la clave de búsqueda de destino en Putaway.
11. Cerrar el ciclo de Reabastecimiento (endpoint "completar tarea" + reserva de origen).
12. Limpieza: docblock de `DespachoController`, lógica muerta de reversión, `factor_udm` en Recepción/Entrada.

## Detalle completo por agente

Los 5 informes completos (con código de corrección línea por línea) quedaron en el historial de la sesión de auditoría del 2026-08-04/05. Índice de alcance:

- **Entrada** (Recepción/Putaway/Inbound/Yard): 5 críticos, 4 altos, 3 medios.
- **Picking/Reabastecimiento**: 6 críticos, 5 altos, 3 medios — incluye mapa completo de los 9 puntos de creación de `cantidad_solicitada` y su convención.
- **Packing/Despacho** (incluye CrossDock/Outbound): 2 críticos, 5 altos, 2 bajos.
- **Devoluciones/Traspasos/Ajustes** (incluye InventarioV2): 3 críticos, 2 altos, 3 medios.
- **Transversal** (kardex, reservas, multi-tenant, concurrencia): mapa de ~12 puntos de escritura de inventario con estado de lock, mapa completo de creadores/liberadores de `cantidad_reservada` (todos concentrados en `PickingController.php`).
