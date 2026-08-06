# WMS FENIX - AGENT RULES & SYSTEM INSTRUCTIONS

## 📦 INVENTARIO Y MOVIMIENTOS - REGLA SAGRADA DE FORMULACIÓN

En todo el sistema WMS FENIX (móvil, escritorio, backend APIs, kardex y reportes), cualquier cálculo, desglose o descuento de inventario DEBE aplicar de forma obligatoria la fórmula:

$$\text{Total Unidades Reales (UND/TOTAL)} = (\text{Total Cajas} \times \text{Unidades por Caja}) + \text{Saldos (Sueltos)}$$

### Reglas Clave:
1. **Frontend Móvil y Escritorio:** El valor de `SEPARADO (UND/TOTAL)` y el texto informativo de confirmación (`X cajas × U/E + Y sueltos = Z und/total`) NUNCA debe mostrar el valor en cajas como el total en unidades. Siempre debe multiplicar el número de cajas por el factor `unidades_caja` (U/E) y sumar los sueltos.
2. **Backend API y Controladores (`PickingController`, `InventarioV2Controller`, `RecepcionController`):** El inventario físico descontado debe equivaler siempre a las unidades reales totales `(cajas × U/E + saldos)`.
