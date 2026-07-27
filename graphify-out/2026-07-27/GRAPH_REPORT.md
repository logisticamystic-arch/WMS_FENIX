# Graph Report - WMS_FENIX  (2026-07-27)

## Corpus Check
- 317 files · ~812,015 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2647 nodes · 5415 edges · 313 communities (244 shown, 69 thin omitted)
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 462 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `4985016d`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Picking Order Management
- Returns & FEFO Alerts
- Inventory & Dashboard Controller
- Parameters & Approvals
- Cross-Dock Operations
- Packing & Expiry Control
- Master Data Management
- Picking UI Module
- Storage & Location Blocking
- Returns UI Module
- Core Models & Tenant Scope
- Inventory Adjustments UI
- Label Printing Module
- Dispatch & Certification UI
- Receiving UI Module
- Product Blocking & Quick Search
- Receiving Controller
- App Routes & Design Docs
- Reports & Exports Module
- Auth & Seeding
- Base Model & Certification
- Inbound Purchase Orders
- Base Controller Utilities
- Advanced Logistics UI
- Composer Configuration
- Picking Order Editing
- System Monitoring API
- Dispatch Controller
- Returns Model & Controller
- Quick Search UI
- Database Compatibility Layer
- Tenant Context & Middleware
- Intelligence Dashboard UI
- Traceability UI Module
- Database Schema Overview
- Picking Planilla Management
- Core Controllers Overview
- Planilla Certification Controller
- Yard Management Controller
- Inventory Assignment Editing
- Location Adjustment Controller
- ML Expiry Prediction
- Assignment Session Management
- PlanillaController
- Inventory Session Model
- Cache Helper Utility
- Master Data CRUD UI
- Reservations & Novelties UI
- ABC/XYZ Rotation Analytics
- Inventory V2 Session Controller
- Miscellaneous Items Controller
- Packing Certification UI
- Anomaly Detection Controller
- Replenishment & Notifications
- TMS Integration Controller
- Database Backup Helper
- Inventory Adjustment Model
- ML Anomaly Detection
- Company Management UI
- Receiving Dashboard UI
- Wave Management Controller
- Receiving Without PO UI
- TV Picking Dashboard
- Demand Forecasting Controller
- Outbound Certification Model
- SesionAsignacion
- Label Printing Helper
- Packing Expiry UI
- Cargue Dispatch UI
- Home Activity Dashboard
- Aisle Assignment UI
- Pallet Approval UI
- PWA Manifest Config
- Appointment Controller
- Returns Feature Design
- PermisoPersonalController
- AI Chat UI
- Cargue Approval UI
- Branch Management UI
- Location Management UI
- Purchase Order UI
- Causal Reasons Controller
- Printer Management Controller
- Packing Session UI
- Inventory Count Sessions UI
- Personnel Management UI
- Planilla Dashboard UI
- Backorder Fulfillment UI
- Picking TV Dashboard
- Location Model
- Notification Service
- Inventory Count Model
- Backend/Frontend Rewrite Plan
- TV Dashboard Service Level
- Certification Scanning UI
- Reservations UI
- Appointment Calendar UI
- Transfer Controller
- Packing Sticker Printing
- Ajuste Preview & Execution
- Ajuste Ubicación Approval
- Zonas Management
- Asignación Auxiliares
- Marketing Illustrations & Pitch Assets
- Aprobación de Vencimientos
- CSV/Excel Export
- Slotting Assignment Engine
- Packing & Picking Tables
- Performance & Cache Docs
- Ambientes Management
- Rutas Management
- Causales de Novedad
- Consola de Recepción
- Chat IA Controller
- Inventario General Diferencias
- Miscelaneos Management
- Trazabilidad Controller
- Performance Middleware
- Product Pitch Materials
- Ciclico Referencias
- Dashboard Filtering
- Conteo Manual
- Categorías Management
- Marcas Management
- Órdenes de Compra Manual
- Base Service & Tenant Context
- Cache Helpers & Auto Refresh
- Ubicación Search Helpers
- Stock Dashboard Charts
- EAN Codes Management
- Recepción Sin ODC Preview
- Captura Operativa Unidades
- Citas Scheduling
- Mobile API & Offline Queue
- Log Rotation
- Expiry Guard Approval
- TV Dashboard Picking
- Data Cache Module
- Backup Logging
- Devoluciones Cancel Endpoint
- Devoluciones Process Endpoint
- Base Model
- Orden Pickings Table
- Picking Asignaciones Log
- ML Integrity Tables
- Improvements Implemented Report
- PROORIENTE Migration Plan
- Professional Picking Plan
- Project Reorganization Plan
- Packing & Certification Plan
- Professional Picking Design
- Packing & Certification Design
- SPA Entry Page
- picking_v2_nuevos_campos.sql
- _confirmarReinicio
- 069_sprint1_indices.sql
- 2026_05_30_add_fecha_vencimiento_picking_detalles.sql
- 2026_05_30_create_aprobaciones_vencimiento.sql
- add_fv_obligatorio_sesiones_inventario.sql
- ajustes module
- conteos module
- despacho module
- picking module
- recepcion module
- WMS Fénix Architecture Design Doc
- show_sin_odc
- ImportExportController
- SystemController
- OutboundController
- PermisoPersonalController
- _actualizarPreviewSinODC
- .now
- _cargarStockGeneral
- verEventoTomaFisica
- _editarLinea
- GET /aprobaciones/vencimiento/pendientes
- POST /devoluciones/{id}/aprobar
- Warehouse/truck app icon (192x192)
- Warehouse/truck app icon (512x512)

## God Nodes (most connected - your core abstractions)
1. `PickingController` - 94 edges
2. `BaseController` - 67 edges
3. `ParametrosController` - 66 edges
4. `BaseModel` - 64 edges
5. `Producto` - 40 edges
6. `InventarioController` - 39 edges
7. `Inventario` - 38 edges
8. `InventarioV2Controller` - 37 edges
9. `RecepcionController` - 33 edges
10. `SesionInventario` - 32 edges

## Surprising Connections (you probably didn't know these)
- `MysticFoods Logo` --conceptually_related_to--> `WMS Fénix Product`  [AMBIGUOUS]
  logo.jpg → docs/propuesta_comercial.html
- `Expiry Control (ExpiryGuard) Design Doc` --references--> `ExpiryGuard`  [EXTRACTED]
  docs/superpowers/specs/2026-05-30-expiry-control-design.md → src/Helpers/ExpiryGuard.php
- `WMS Enterprise Management Pitch Page` --references--> `ROI Growth Trend bar/line chart (94.2% FY2023)`  [AMBIGUOUS]
  public/pitch.html → public/assets/pitch/roi_chart.png
- `Devoluciones Implementation Plan` --references--> `MWMS confirmar-linea handler`  [AMBIGUOUS]
  docs/superpowers/plans/2026-05-31-devoluciones.md → public/mobile/index.html
- `bootTenantScoped()` --calls--> `TenantContext`  [INFERRED]
  src/Models/Concerns/TenantScoped.php → src/Helpers/TenantContext.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **TV Dashboard Refresh Cycle** — public_tv_picking_refresh, public_tv_picking_loadpicking, public_tv_picking_renderkpis, public_tv_picking_renderplanillastable, public_tv_picking_rendercharts, public_tv_picking_renderalertas [EXTRACTED 0.85]
- **Packing Session Data Model** — concept_packing_sesiones, concept_packing_unidades, concept_packing_items, concept_picking_detalles [EXTRACTED 0.85]
- **Mobile offline-first request handling pattern** — public_mobile_index_mapi, public_mobile_index_offlinequeue, public_mobile_index_mwms [EXTRACTED 0.85]

## Communities (313 total, 69 thin omitted)

### Community 0 - "Picking Order Management"
Cohesion: 0.04
Nodes (7): App\Models\OrdenPicking, App\Models\PickingDetalle, App\Models\Producto, OrdenPicking, PickingDetalle, Producto, PickingController

### Community 1 - "Returns & FEFO Alerts"
Cohesion: 0.09
Nodes (3): InventarioV2Controller, SesionAsignacion, SesionLinea

### Community 3 - "Parameters & Approvals"
Cohesion: 0.05
Nodes (4): Psr\Http\Message\ResponseInterface, ParametrosController, ApiKeyMiddleware, Cliente

### Community 4 - "Cross-Dock Operations"
Cohesion: 0.06
Nodes (6): date, bkpLog(), CrossDockController, NotificacionesController, ReportesController, UbicacionesController

### Community 5 - "Packing & Expiry Control"
Cohesion: 0.07
Nodes (7): DashboardController, PackingController, OrdenPicking, PackingItem, PackingSesion, PackingUnidad, Recepcion

### Community 6 - "Master Data Management"
Cohesion: 0.05
Nodes (15): addEan(), _clienteModalBody(), deleteEan(), editCliente(), editZona(), eliminarImpresora(), _esc(), guardarImpresora() (+7 more)

### Community 7 - "Picking UI Module"
Cohesion: 0.05
Nodes (17): _applyAgotFilters(), _cargarConsulta(), _clearAgotFilters(), _eliminarPendiente(), _limpiarPendientes(), nuevoPedidoManual(), _onFaltCheck(), _pmAgregarLinea() (+9 more)

### Community 8 - "Storage & Location Blocking"
Cohesion: 0.06
Nodes (32): asignarUbicacion(), _blqBloquearLote(), _blqBloquearProd(), _blqDesbloquearLote(), _blqDesbloquearProd(), _buildUbicDestino(), cargarLotesOrigen(), confirmarUbicacion() (+24 more)

### Community 9 - "Returns UI Module"
Cohesion: 0.07
Nodes (32): _abrirModalCausal(), agregarItem(), anular(), _aplicarDashboard(), _aplicarFiltros(), aprobar(), _calcKPIsLocales(), _calcPorCausalLocal() (+24 more)

### Community 10 - "Core Models & Tenant Scope"
Cohesion: 0.08
Nodes (9): App\Models\Concerns\TenantScoped, AlertaStock, AuditLog, CategoriaProducto, InvGeneralEvento, Marca, PersonalPermiso, Ruta (+1 more)

### Community 11 - "Inventory Adjustments UI"
Cohesion: 0.05
Nodes (14): _addAsigRow(), _ajustarTodo(), aprobarAjustes(), _buscarHistorialUbiProducto(), _cargarDesgloseUbicacion(), _ciCalcPreview(), _ciSelProd(), _guardarEventoTomaFisica() (+6 more)

### Community 12 - "Label Printing Module"
Cohesion: 0.10
Nodes (39): _actualizarConteoMasivo(), _actualizarFiltroMasivo(), _actualizarPreviewProd(), _actualizarPreviewUbi(), _buildDualColumnHTML(), _buildDualColumnHTMLProd(), _buildRotuloProd(), _buildRotuloProdHorizontal() (+31 more)

### Community 13 - "Dispatch & Certification UI"
Cohesion: 0.06
Nodes (22): adminOverride(), _certFiltrarGlobal(), _certFiltrarPorSucursal(), _confirmarEnTransito(), generarCargue(), gestionarApiKeys(), _imprimirConsolidadoUnaPestana(), imprimirRemision() (+14 more)

### Community 14 - "Receiving UI Module"
Cohesion: 0.06
Nodes (13): _devProdInput(), _devSearchProduct(), _guardarDevolucion(), _miscActualizar(), _miscBorrarFoto(), _miscEditar(), _miscEliminar(), _miscFiltrar() (+5 more)

### Community 15 - "Product Blocking & Quick Search"
Cohesion: 0.10
Nodes (4): BloqueoController, ConsultaRapidaController, BloqueoLote, Producto

### Community 16 - "Receiving Controller"
Cohesion: 0.07
Nodes (8): PDO, DashboardTVController, InboundController, wmsLog(), OrdenCompra, OrdenCompraDetalle, Proveedor, RecepcionDetalle

### Community 17 - "App Routes & Design Docs"
Cohesion: 0.13
Nodes (11): Expiry Control Implementation Plan, Devoluciones Implementation Plan, TV Dashboard 360 Design Doc, Futuristic warehouse background photo with AR overlays, WMS Fénix phoenix logo, Mobile App (index.html), MWMS._closeExpiryModal(), MWMS confirmar-linea handler (+3 more)

### Community 18 - "Reports & Exports Module"
Cohesion: 0.11
Nodes (29): abrirCertificacion(), _abrirReporteHtml(), abrirSeparacion(), _estadoInicialReporte(), exportar(), exportarAgotados(), exportarAudit(), exportarCertCSV() (+21 more)

### Community 19 - "Auth & Seeding"
Cohesion: 0.13
Nodes (3): AuthController, Empresa, Personal

### Community 21 - "Inbound Purchase Orders"
Cohesion: 0.04
Nodes (55): public.ajustes_inventario, public.alertas_stock, public.anomaly_flags, public.api_keys, public.archivos_planilla, public.audit_logs, public.categoria_productos, public.cert_planilla_det (+47 more)

### Community 23 - "Advanced Logistics UI"
Cohesion: 0.20
Nodes (18): autoGenerarWave(), _cdAction(), _dateFilters(), _esc(), _estadoBadge(), _fmtDate(), _fmtDT(), _getDateParams() (+10 more)

### Community 24 - "Composer Configuration"
Cohesion: 0.09
Nodes (21): autoload, psr-4, description, name, App\\, require, ext-json, ext-mbstring (+13 more)

### Community 25 - "Picking Order Editing"
Cohesion: 0.10
Nodes (23): _abrirEditar(), _abrirEditorInline(), _asignarRutaInline(), _cajasYPicos(), _cargarPedidos(), _confirmarAgregarAuxiliar(), _confirmarCambiarAuxiliar(), _dlgAgotadoLinea() (+15 more)

### Community 26 - "System Monitoring API"
Cohesion: 0.08
Nodes (28): analyzeLogErrorsRecent(), checkAndTriggerAutoReport(), forceGenerateReport(), formatBytes(), generateReportInternal(), getActiveUsers(), getLatestReportFile(), getMetrics() (+20 more)

### Community 28 - "Returns Model & Controller"
Cohesion: 0.08
Nodes (4): DevolucionController, CausalDevolucion, Devolucion, DevolucionDetalle

### Community 29 - "Quick Search UI"
Cohesion: 0.22
Nodes (16): _buscar(), _esc(), _fmt(), _fmtFecha(), init(), load(), _onInput(), _renderClientes() (+8 more)

### Community 31 - "Tenant Context & Middleware"
Cohesion: 0.47
Nodes (4): Illuminate\Database\Eloquent\Builder, bootTenantScoped(), scopeWithCurrentTenant(), withoutTenantScope()

### Community 32 - "Intelligence Dashboard UI"
Cohesion: 0.20
Nodes (14): _fmtDate(), load(), _loadFefoData(), renderAnomalias(), renderFefo(), renderGuardLog(), renderPerformance(), _renderSub() (+6 more)

### Community 33 - "Traceability UI Module"
Cohesion: 0.22
Nodes (17): _buscarProducto(), _buscarUbicacion(), docHtml(), _filtersHtml(), _kpi(), load(), _loadingHtml(), _onSelect() (+9 more)

### Community 34 - "Database Schema Overview"
Cohesion: 0.36
Nodes (8): bodegas table, empresas table, existencias table, kardex table, productos table, ubicaciones table, usuario_bodegas pivot table, usuarios table

### Community 35 - "Picking Planilla Management"
Cohesion: 0.11
Nodes (18): _anularPedido(), _cerrarPlanilla(), completarPicking(), _confirmarAgregarLinea(), confirmarAsignacionPlanilla(), _confirmarRuta(), deletePicking(), filterEstado() (+10 more)

### Community 36 - "Core Controllers Overview"
Cohesion: 0.09
Nodes (4): Illuminate\Database\Eloquent\Model, ConteoDetalle, InvGeneralAsignacion, InvGeneralDiferencia

### Community 37 - "Planilla Certification Controller"
Cohesion: 0.15
Nodes (46): ajustes_inventario, alertas, anomaly_flags, api_keys, categorias_productos, citas, conteo_detalles, conteos (+38 more)

### Community 39 - "Inventory Assignment Editing"
Cohesion: 0.12
Nodes (17): _conteoCalcPreview(), _conteoRenderCantidadInputs(), _deleteAsig(), _eliminarLinea(), __execAjustarLinea(), __execAjustarTodo(), _formatFullDate(), _loadMLTab() (+9 more)

### Community 40 - "Location Adjustment Controller"
Cohesion: 0.17
Nodes (3): AjusteUbicacionController, AjusteUbicacion, AjusteUbicacionDetalle

### Community 41 - "ML Expiry Prediction"
Cohesion: 0.67
Nodes (3): _confirmarAgregarPedidos(), quitarPedidoCargue(), verCargue()

### Community 42 - "Assignment Session Management"
Cohesion: 0.11
Nodes (5): DatabaseSeeder, Parametro, Permiso, RolPermiso, Sucursal

### Community 44 - "Inventory Session Model"
Cohesion: 0.24
Nodes (10): cross_dock_detalles, cross_dock_ordenes, ejecuciones_ml, forecast_demanda, ubicaciones, ubicaciones_optimas, ventas_agregadas_ml, wave_picking (+2 more)

### Community 46 - "Master Data CRUD UI"
Cohesion: 0.14
Nodes (14): deleteCliente(), deleteProducto(), deleteProveedor(), doImportGenerico(), filtrarClientes(), filtrarProveedores(), renderClientes(), renderProveedores() (+6 more)

### Community 47 - "Reservations & Novelties UI"
Cohesion: 0.40
Nodes (5): _cargarNovedades(), _cerrarNvModal(), _confirmarNvAccion(), _renderNovedades(), show_novedades()

### Community 48 - "ABC/XYZ Rotation Analytics"
Cohesion: 0.23
Nodes (9): ejecutarAbcXyz(), ejecutarForecast(), ejecutarSlotting(), load(), renderAbcXyz(), renderForecast(), renderHeatmap(), renderSlotting() (+1 more)

### Community 50 - "Miscellaneous Items Controller"
Cohesion: 0.18
Nodes (3): MiscelaneoController, Miscelaneo, MiscelaneoFoto

### Community 51 - "Packing Certification UI"
Cohesion: 0.12
Nodes (17): cancelarSesionPacking(), _certEditGuardarLote(), _certFechaParams(), _certSetFechaRapida(), confirmarAsigCert(), _desmarcarDespachadoDirecto(), eliminarVR(), finalizarCertificacion() (+9 more)

### Community 53 - "Replenishment & Notifications"
Cohesion: 0.13
Nodes (4): ReplenishmentController, NivelReposicion, Notificacion, TareaReabastecimiento

### Community 57 - "ML Anomaly Detection"
Cohesion: 0.23
Nodes (12): detect_frequency_patterns(), detect_movement_outliers(), detect_negative_adjustments(), iqr_fences(), is_outlier(), Detecta movimientos con cantidad estadísticamente anómala     respecto al histor, Detecta ajustes negativos sospechosos.     Criterios: grandes ajustes negativos,, Detecta patrones de alta frecuencia: muchos movimientos pequeños seguidos     de (+4 more)

### Community 58 - "Company Management UI"
Cohesion: 0.32
Nodes (8): closeDrawerEmpresa(), deleteEmpresa(), editEmpresa(), filtrarEmpresas(), nuevaEmpresa(), renderEmpresas(), saveEmpresa(), show_empresa()

### Community 59 - "Receiving Dashboard UI"
Cohesion: 0.17
Nodes (12): buildCategoryReceivedChart(), buildRecepcionTrendChart(), _dashboardQuery(), load(), _renderDashboardFilter(), _resetDashboardFilters(), _setDashboardFilter(), show_dashboard() (+4 more)

### Community 61 - "Receiving Without PO UI"
Cohesion: 0.29
Nodes (8): abrirConsolaSinODC(), _agregarLineaSinODC(), _eliminarDetalleSinODC(), _enviarCapturaSinODC(), _guardarEdicionDetalleSinODC(), _resolverAutorizacionVencimiento(), _sodc_actualizarToolbar(), _verDetalleSinODC()

### Community 62 - "TV Picking Dashboard"
Cohesion: 0.18
Nodes (4): tv-picking.html loadPicking(), tv-picking.html refresh(), tv-picking.html renderCharts(), tv-picking.html renderPlanillasTable()

### Community 64 - "Outbound Certification Model"
Cohesion: 0.21
Nodes (3): OutboundController, Certificacion, CertificacionDetalle

### Community 67 - "Packing Expiry UI"
Cohesion: 0.25
Nodes (8): agregarItemPacking(), _cancelarExpiryWait(), _closeExpiryWaitModal(), _confirmarDialogPacking(), eliminarItemPacking(), _pollExpiryWait(), show_packing(), _showExpiryWaitModal()

### Community 68 - "Cargue Dispatch UI"
Cohesion: 0.29
Nodes (7): _aplicarFiltrosCargue(), _cargueQueryString(), exportCargueExcel(), _hoyFiltrosCargue(), _loadCargueTabla(), _renderPlanillasCreadas(), saveCargue()

### Community 69 - "Home Activity Dashboard"
Cohesion: 0.42
Nodes (9): _animateCounter(), destroy(), _last7(), load(), _loadActivity(), render(), _renderDonut(), _renderTrend() (+1 more)

### Community 70 - "Aisle Assignment UI"
Cohesion: 0.22
Nodes (10): _actualizarTotalesAsig(), _agregarRangoPasillo(), _buildDrawerAsignacion(), _buildRangoPasillo(), _calcularTotalesAmbiente(), _renderAsignacion(), _seleccionarYAsignarPlanilla(), _toggleAsig() (+2 more)

### Community 71 - "Pallet Approval UI"
Cohesion: 0.20
Nodes (10): _aprobarLinea(), _aprobarPallet(), _buildPalletTable(), _eliminarLinea(), _eliminarPallet(), _guardarLinea(), _guardarLineaNueva(), _guardarNovedad() (+2 more)

### Community 72 - "PWA Manifest Config"
Cohesion: 0.20
Nodes (9): background_color, description, display, icons, name, scope, short_name, start_url (+1 more)

### Community 74 - "Returns Feature Design"
Cohesion: 0.29
Nodes (8): Devoluciones Design Spec, GET /api/recepciones/buscar-qr (reused endpoint), devolucion_items table, devoluciones.js (desktop module), devoluciones table, DevolucionesController, Mobile devolución cliente flow, MWMS._devItems / _devQrProd (devolución state)

### Community 76 - "AI Chat UI"
Cohesion: 0.42
Nodes (7): _addMessage(), _enviar(), _limpiar(), load(), _md(), _scrollBottom(), _usarSug()

### Community 77 - "Cargue Approval UI"
Cohesion: 0.22
Nodes (9): _cargueAprobarTodo(), _ciAprobarLinea(), _ciEliminarPend(), _ciEnviar(), _ciRefrescarPendientes(), _ciRenderLayout(), importarSaldos(), show_cargue() (+1 more)

### Community 78 - "Branch Management UI"
Cohesion: 0.28
Nodes (9): closeDrawerSucursal(), deleteSucursal(), editSucursal(), filtrarSucursales(), nuevaSucursal(), renderSucursales(), saveSucursal(), show_sucursales() (+1 more)

### Community 79 - "Location Management UI"
Cohesion: 0.22
Nodes (9): deleteUbi(), doImportUbicaciones(), filterUbicaciones(), _renderUbiRows(), _renderUbiShell(), saveUbicacion(), show_ubicaciones(), toggleUbiStatus() (+1 more)

### Community 80 - "Purchase Order UI"
Cohesion: 0.17
Nodes (13): _addManualItem(), _applyODCFilters(), aprobarODCTodo(), cerrarODC(), _clearODCFilters(), closeDrawerODC(), confirmarODC(), deleteODC() (+5 more)

### Community 83 - "Packing Session UI"
Cohesion: 0.25
Nodes (8): _buildItemsTable(), _buildProductosList(), finalizarPacking(), _mostrarAgotadosSesion(), _mostrarPanelDocumento(), _openPackingSession(), _renderPackingScreen(), _showCanastasDetalle()

### Community 84 - "Inventory Count Sessions UI"
Cohesion: 0.25
Nodes (8): cerrarConteo(), cerrarConteoMasivo(), _eliminarSesion(), iniciarSesion(), saveConteoV2(), show_ciclico(), show_general(), show_sesiones()

### Community 85 - "Personnel Management UI"
Cohesion: 0.32
Nodes (8): closeDrawerPersonal(), deletePersonal(), editPersonal(), filtrarPersonal(), nuevoPersonal(), renderPersonal(), savePersonal(), show_personal()

### Community 86 - "Planilla Dashboard UI"
Cohesion: 0.19
Nodes (14): abrirModalEditarPedido(), _agruparPorPlanilla(), _fmtCajasDesglose(), _getDuration(), _initDashboardCharts(), _renderMatrixHtml(), _renderPedidosTabla(), _renderPlanillaRow() (+6 more)

### Community 87 - "Backorder Fulfillment UI"
Cohesion: 0.25
Nodes (8): _applyFaltFilters(), _clearFaltFilters(), completarReabast(), _limpiarFaltantes(), _loadSucursales(), _procesarBackorder(), show_faltantes(), _toggleFaltVista()

### Community 89 - "Location Model"
Cohesion: 0.20
Nodes (9): background_color, description, display, icons, name, scope, short_name, start_url (+1 more)

### Community 92 - "Backend/Frontend Rewrite Plan"
Cohesion: 0.38
Nodes (7): backend/app/common/service.py (BaseService), backend/app/core/database.py, backend/app/core/security.py, Plan Fase 0+1 WMS Fénix, frontend AppShell.tsx, SmartGrid.tsx, frontend useAuth.ts

### Community 94 - "Certification Scanning UI"
Cohesion: 0.29
Nodes (7): autoCertificar(), confirmarLineaCert(), iniciarCertificacion(), manualCert(), procesarEscaneo(), _showPackingDialog(), verDetallesPendientes()

### Community 95 - "Reservations UI"
Cohesion: 0.29
Nodes (7): _aplicarFiltrosReservas(), _cargarReservas(), _filtrarReservasPorEstado(), _limpiarVistareservas(), _renderReservas(), show_reservas(), _sortReservas()

### Community 96 - "Appointment Calendar UI"
Cohesion: 0.29
Nodes (7): cancelarCita(), _changeYmsMonth(), _completarCitaOK(), _guardarCita(), marcarLlegadaCita(), _renderCalendario7x5(), show_citas()

### Community 99 - "Packing Sticker Printing"
Cohesion: 0.36
Nodes (8): _buildStickerBlock(), _buildStickerHtml(), cerrarUnidadPacking(), _imprimirStickerUnidad(), _imprimirTodasPacking(), imprimirTodosStickers(), _printPackingSession(), _wrapPrintPage()

### Community 100 - "Ajuste Preview & Execution"
Cohesion: 0.33
Nodes (6): _ajCalcPreview(), _ajRenderCantidadInputs(), _ajTipoChanged(), ejecutarAjuste(), _loadHoyAjustes(), show_ajuste()

### Community 101 - "Ajuste Ubicación Approval"
Cohesion: 0.33
Nodes (6): _ajusteUbiAprobar(), _ajusteUbiLoadHistorial(), _ajusteUbiLoadPendientes(), _ajusteUbiRechazar(), _ajusteUbiRefresh(), show_ajuste_ubicacion()

### Community 102 - "Zonas Management"
Cohesion: 0.33
Nodes (6): deleteZona(), filtrarZonas(), nuevaUbicacion(), renderZonas(), saveZona(), show_zonas()

### Community 103 - "Asignación Auxiliares"
Cohesion: 0.33
Nodes (6): _asignarFallback(), _cargarAsignacion(), _cargarAuxiliares(), confirmarAsignacion(), _mostrarAlertaSinAuxiliar(), show_asignacion()

### Community 104 - "Marketing Illustrations & Pitch Assets"
Cohesion: 0.33
Nodes (6): FENIX AI Assistant hologram illustration, AI brain over conveyor belt (FEFO analytics) illustration, ROI Growth Trend bar/line chart (94.2% FY2023), On-premise server rack with analytics overlays illustration, Smart warehouse hero illustration with AR dashboards, WMS Enterprise Management Pitch Page

### Community 109 - "Packing & Picking Tables"
Cohesion: 0.40
Nodes (5): impresoras table, packing_items table, packing_sesiones table, packing_unidades table, picking_detalles table

### Community 110 - "Performance & Cache Docs"
Cohesion: 0.67
Nodes (3): DataCache Integration Guide, Performance Quick Wins Summary, mobile/index.html

### Community 111 - "Ambientes Management"
Cohesion: 0.40
Nodes (5): deleteAmbiente(), filtrarAmbientes(), renderAmbientes(), saveAmbiente(), show_ambientes()

### Community 112 - "Rutas Management"
Cohesion: 0.40
Nodes (5): deleteRuta(), filtrarRutas(), renderRutas(), saveRuta(), show_rutas()

### Community 113 - "Causales de Novedad"
Cohesion: 0.40
Nodes (5): _editarCausal(), _loadCausales(), _nuevaCausal(), _renderCausales(), show_causales_novedad()

### Community 114 - "Consola de Recepción"
Cohesion: 0.40
Nodes (5): abrirConsolaRecepcion(), _abrirConsolaRecepcionRescate(), _cerrarRecepcionOrfana(), _eliminarRecepcionOrfana(), show_operativa()

### Community 117 - "Inventario General Diferencias"
Cohesion: 0.22
Nodes (9): buscarProductos(), _cargarTodo(), consultar_productos(), load(), renderProductos(), show_landing(), subLabel(), _timerBuscar() (+1 more)

### Community 118 - "Miscelaneos Management"
Cohesion: 0.08
Nodes (7): Expiry Control (ExpiryGuard) Design Doc, ExpiryGuard, ExpiryResult, FefoEngine, InventoryGuard, detectPython(), runPython()

### Community 123 - "Product Pitch Materials"
Cohesion: 0.67
Nodes (4): WMS Fénix Product, WMS Fénix Propuesta Comercial, Agente Fénix AI Assistant (concept), MysticFoods Logo

### Community 125 - "Ciclico Referencias"
Cohesion: 0.50
Nodes (4): _addCiclicRefRow(), _ciclicoRefs(), _deleteAsigCiclic(), _guardarCiclicRefs()

### Community 126 - "Dashboard Filtering"
Cohesion: 0.50
Nodes (4): _cerrarSesion(), _dashFiltrarCero(), _dashFiltrarConteos(), show_dashboard()

### Community 127 - "Conteo Manual"
Cohesion: 0.28
Nodes (8): GET/POST /devoluciones, GET /recepciones/buscar-qr, MWMS._dvAgregarItem(), MWMS._dvBuscarQr(), MWMS._dvEnviar(), MWMS._dvRenderItems(), MWMS.showHome(), MWMS.viewDevolucion()

### Community 128 - "Categorías Management"
Cohesion: 0.50
Nodes (4): deleteCategoria(), renderCategorias(), saveCategoria(), show_categorias()

### Community 129 - "Marcas Management"
Cohesion: 0.50
Nodes (4): deleteMarca(), renderMarcas(), saveMarca(), show_marcas()

### Community 133 - "Base Service & Tenant Context"
Cohesion: 0.29
Nodes (7): agregarPedidosCargue(), despacharCargue(), _filtrarPedidosCargue(), liquidarCargue(), _renderPedidosPendientes(), saveCargueMasivo(), show_cargue()

### Community 139 - "Ubicación Search Helpers"
Cohesion: 0.67
Nodes (3): _ciSearchUbic(), _ciSelUbic(), _ubicNorm()

### Community 140 - "Stock Dashboard Charts"
Cohesion: 0.33
Nodes (6): _renderMovBarChart(), _renderStockGeneralTab(), _renderStockPorReferencia(), show_stock(), _srBuscar(), _stockSetTab()

### Community 142 - "Recepción Sin ODC Preview"
Cohesion: 0.40
Nodes (4): `anomaly_flags`, `expiry_predictions`, `inventory_guard_log`, `performance_metrics`

### Community 143 - "Captura Operativa Unidades"
Cohesion: 0.67
Nodes (3): _actualizarPreviewUnidades(), _enviarCapturaOperativa(), _onProductoCaptura()

### Community 144 - "Citas Scheduling"
Cohesion: 1.00
Nodes (3): nuevaCita(), nuevaCitaEnFecha(), _recalcHorasYMS()

### Community 145 - "Mobile API & Offline Queue"
Cohesion: 1.00
Nodes (3): mApi() fetch wrapper function, MWMS mobile controller object, _offlineQueue offline retry queue

### Community 153 - "Backup Logging"
Cohesion: 0.15
Nodes (3): PutawayController, ProductoEan, Ubicacion

### Community 156 - "Base Model"
Cohesion: 0.50
Nodes (5): load(), startAutoRefresh(), stopAutoRefresh(), subLabel(), _updateAutoRefreshBadge()

### Community 293 - "_confirmarReinicio"
Cohesion: 1.00
Nodes (3): _confirmarReinicio(), _renderReinicioDatos(), show_reinicio_datos()

### Community 308 - "show_sin_odc"
Cohesion: 0.33
Nodes (6): _aplicarFiltroSinODC(), _cerrarRecepcionSinODC(), _confirmarSinODC(), _eliminarRecepcionSinODC(), _hoyFiltroSinODC(), show_sin_odc()

### Community 309 - "ImportExportController"
Cohesion: 0.12
Nodes (5): DateTimeInterface, BaseModel, CertificacionDespacho, InvGeneralConteo, ProductoFoto

### Community 310 - "SystemController"
Cohesion: 0.06
Nodes (7): BaseController, Psr\Http\Message\ServerRequestInterface, DespachoController, ImportExportController, InventarioController, PermisoPersonalController, TraspasoController

### Community 313 - "_actualizarPreviewSinODC"
Cohesion: 0.67
Nodes (3): _actualizarPreviewSinODC(), _procesarQrSinODC(), _seleccionarProdSinODC()

### Community 318 - ".now"
Cohesion: 0.14
Nodes (3): TenantContext, JwtMiddleware, TenantMiddleware

### Community 319 - "_cargarStockGeneral"
Cohesion: 0.67
Nodes (3): _cargarStockGeneral(), _renderStockDonut(), _renderTop10()

### Community 320 - "verEventoTomaFisica"
Cohesion: 0.67
Nodes (3): _cerrarEventoTomaFisica(), _guardarAsignacionTomaFisica(), verEventoTomaFisica()

### Community 321 - "_editarLinea"
Cohesion: 0.67
Nodes (3): _editarLinea(), _editCalcPreview(), _editRenderCantidadInputs()

## Ambiguous Edges - Review These
- `WMS Fénix Product` → `MysticFoods Logo`  [AMBIGUOUS]
  logo.jpg · relation: conceptually_related_to
- `Devoluciones Implementation Plan` → `MWMS confirmar-linea handler`  [AMBIGUOUS]
  docs/superpowers/plans/2026-05-31-devoluciones.md · relation: references
- `WMS Enterprise Management Pitch Page` → `ROI Growth Trend bar/line chart (94.2% FY2023)`  [AMBIGUOUS]
  public/pitch.html · relation: references

## Knowledge Gaps
- **162 isolated node(s):** `name`, `description`, `type`, `php`, `ext-pdo` (+157 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **69 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `WMS Fénix Product` and `MysticFoods Logo`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Devoluciones Implementation Plan` and `MWMS confirmar-linea handler`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **What is the exact relationship between `WMS Enterprise Management Pitch Page` and `ROI Growth Trend bar/line chart (94.2% FY2023)`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **Why does `BaseModel` connect `ImportExportController` to `Returns & FEFO Alerts`, `Órdenes de Compra Manual`, `Parameters & Approvals`, `Packing & Expiry Control`, `Core Models & Tenant Scope`, `Product Blocking & Quick Search`, `Receiving Controller`, `Auth & Seeding`, `Base Model & Certification`, `SPA Entry Page`, `Backup Logging`, `Returns Model & Controller`, `Core Controllers Overview`, `Location Adjustment Controller`, `Assignment Session Management`, `Inventory V2 Session Controller`, `Miscellaneous Items Controller`, `Replenishment & Notifications`, `OutboundController`, `Outbound Certification Model`, `Appointment Controller`, `Causal Reasons Controller`, `Printer Management Controller`, `Inventory Count Model`, `TV Dashboard Service Level`, `Transfer Controller`, `Aprobación de Vencimientos`?**
  _High betweenness centrality (0.029) - this node is a cross-community bridge._
- **Why does `Recepcion` connect `Packing & Expiry Control` to `Receiving Controller`, `Core Models & Tenant Scope`, `Cross-Dock Operations`, `ImportExportController`?**
  _High betweenness centrality (0.012) - this node is a cross-community bridge._
- **Why does `InventoryGuard` connect `Miscelaneos Management` to `Core Controllers Overview`?**
  _High betweenness centrality (0.011) - this node is a cross-community bridge._
- **Are the 187 inferred relationships involving `date` (e.g. with `generateReportInternal()` and `getActiveUsers()`) actually correct?**
  _`date` has 187 INFERRED edges - model-reasoned connections that need verification._