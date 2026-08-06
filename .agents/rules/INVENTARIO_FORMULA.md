# REGLA MANDATORIA DE MOVIMIENTOS DE INVENTARIO EN WMS FENIX

> [!IMPORTANT]
> **FÓRMULA SAGRADA DE MOVIMIENTOS E INVENTARIO EN WMS FENIX:**
> 
> $$\text{Total Unidades Reales (UND/TOTAL)} = (\text{Total Cajas} \times \text{Unidades por Caja}) + \text{Saldos (Sueltos)}$$

## 📋 Directriz de Implementación Estricta

Toda operación o módulo que afecte los movimientos de inventario (Picking, Recepción, Almacenamiento, Conteo/Inventarios, Despacho, Ajustes y Kardex) debe cumplir estrictamente las siguientes reglas:

### 1. Desglose y Equivalencia Inequívoca
- **Empaques (Cajas):** Representan el número de cajas/empaques completos.
- **Factor U/E (Unidades por Cajas / `unidades_caja`):** Factor de conversión de la unidad de medida master.
- **Saldos / Sueltos:** Unidades sueltas adicionales que no completan una caja.
- **Total Unidades (`cantidad_tomada` / `cantidad_total` / `cantidad`):** **SIEMPRE** representa las **unidades físicas reales totales**, obtenidas mediante la fórmula:
  $$\text{Cantidad Total (Unidades)} = (\text{Cajas} \times \text{Factor U/E}) + \text{Sueltos}$$

### 2. Presentación en Pantallas y Modales de Confirmación
En las vistas móviles, de escritorio y reportes, el desglose debe mostrarse y calcularse correctamente en la interfaz:
- **Incorrecto:** `2 cajas × 25 + 0 sueltos = 2 und/total` (ERRÓNEO: mostraba cajas como unidades).
- **Correcto:** `2 cajas × 25 + 0 sueltos = 50 und/total` (CORRECTO: resultado real en unidades totales).

### 3. Registro en Base de Datos y Backend
- Las APIs y controladores backend (`PickingController.php`, `InventarioV2Controller.php`, `RecepcionController.php`, etc.) deben procesar las cantidades recibidas en unidades reales totales para el descuento del inventario físico y la generación de `MovimientoInventario`.
- Si se reciben parámetros explícitos `cajas_tomadas` y `saldos_tomados`, el backend recalculará las unidades totales usando la fórmula mandatoria:
  `$cantidadTotalUnidades = ($cajasTomadas * $factorUe) + $saldosTomados;`
