# Graph Report - WMS_FENIX  (2026-08-01)

## Corpus Check
- 329 files · ~827,979 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2710 nodes · 5396 edges · 328 communities (248 shown, 80 thin omitted)
- Extraction: 93% EXTRACTED · 7% INFERRED · 0% AMBIGUOUS · INFERRED: 361 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `e4495d08`
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
- Sucursal
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
- DataResetController
- Outbound Certification Model
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
- NotificacionesController
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
- Ubicacion
- Aprobación de Vencimientos
- TenantScoped.php
- Slotting Assignment Engine
- Packing & Picking Tables
- Performance & Cache Docs
- Ambientes Management
- Rutas Management
- Causales de Novedad
- Consola de Recepción
- InventoryGuard
- Inventario General Diferencias
- Sucursal
- Trazabilidad Controller
- NotificacionesController
- Performance Middleware
- Product Pitch Materials
- Ciclico Referencias
- Dashboard Filtering
- Conteo Manual
- Categorías Management
- Marcas Management
- ConteoInventario
- AlertasController
- Base Service & Tenant Context
- Cache Helpers & Auto Refresh
- AlertasController
- FefoEngine
- Stock Dashboard Charts
- DataResetController
- Recepción Sin ODC Preview
- .__invoke
- Citas Scheduling
- NotificacionesController
- ImportExportController
- Log Rotation
- Expiry Guard Approval
- TV Dashboard Picking
- Data Cache Module
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
- GET /recepciones/buscar-qr
- Expiry Control Implementation Plan
- picking_v2_nuevos_campos.sql
- Futuristic warehouse background photo with AR overlays
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
- BaseController
- ForecastController
- SystemController
- AlertasController
- ExpiryGuard
- Impresora
- WMS Fénix phoenix logo
- ImportExportController
- Traspaso
- InvGeneralAsignacion
- _cargarStockGeneral
- verEventoTomaFisica
- PermisoPersonalController
- GET /aprobaciones/vencimiento/pendientes
- POST /devoluciones/{id}/aprobar
- Warehouse/truck app icon (192x192)
- Warehouse/truck app icon (512x512)
- InvGeneralEvento

## God Nodes (most connected - your core abstractions)
1. `PickingController` - 94 edges
2. `ParametrosController` - 66 edges
3. `BaseController` - 62 edges
4. `BaseModel` - 60 edges
5. `InventarioV2Controller` - 41 edges
6. `InventarioController` - 39 edges
7. `Producto` - 37 edges
8. `RecepcionController` - 35 edges
9. `empresas` - 30 edges
10. `PackingController` - 25 edges

## Surprising Connections (you probably didn't know these)
- `MysticFoods Logo` --conceptually_related_to--> `WMS Fénix Product`  [AMBIGUOUS]
  logo.jpg → docs/propuesta_comercial.html
- `Expiry Control (ExpiryGuard) Design Doc` --references--> `ExpiryGuard`  [EXTRACTED]
  docs/superpowers/specs/2026-05-30-expiry-control-design.md → src/Helpers/ExpiryGuard.php
- `WMS Enterprise Management Pitch Page` --references--> `ROI Growth Trend bar/line chart (94.2% FY2023)`  [AMBIGUOUS]
  public/pitch.html → public/assets/pitch/roi_chart.png
- `bootTenantScoped()` --calls--> `TenantContext`  [INFERRED]
  src/Models/Concerns/TenantScoped.php → src/Helpers/TenantContext.php
- `WMS Enterprise Management Pitch Page` --references--> `FENIX AI Assistant hologram illustration`  [EXTRACTED]
  public/pitch.html → public/assets/pitch/agente_fenix.png

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **TV Dashboard Refresh Cycle** — public_tv_picking_refresh, public_tv_picking_loadpicking, public_tv_picking_renderkpis, public_tv_picking_renderplanillastable, public_tv_picking_rendercharts, public_tv_picking_renderalertas [EXTRACTED 0.85]
- **Packing Session Data Model** — concept_packing_sesiones, concept_packing_unidades, concept_packing_items, concept_picking_detalles [EXTRACTED 0.85]

## Communities (328 total, 80 thin omitted)

### Community 0 - "Picking Order Management"
Cohesion: 0.04
Nodes (7): App\Models\OrdenPicking, App\Models\PickingDetalle, App\Models\Producto, OrdenPicking, PickingDetalle, Producto, PickingController

### Community 1 - "Returns & FEFO Alerts"
Cohesion: 0.19
Nodes (3): PDO, DashboardTVController, wmsLog()

### Community 3 - "Parameters & Approvals"
Cohesion: 0.05
Nodes (3): Psr\Http\Message\ServerRequestInterface, ImportExportController, ParametrosController

### Community 4 - "Cross-Dock Operations"
Cohesion: 0.19
Nodes (3): date, bkpLog(), ReportesController

### Community 6 - "Master Data Management"
Cohesion: 0.05
Nodes (17): addEan(), _clienteModalBody(), deleteEan(), editCliente(), editProducto(), editZona(), eliminarImpresora(), _esc() (+9 more)

### Community 7 - "Picking UI Module"
Cohesion: 0.05
Nodes (17): _applyAgotFilters(), _cargarConsulta(), _clearAgotFilters(), _eliminarPendiente(), _limpiarPendientes(), nuevoPedidoManual(), _onFaltCheck(), _pmAgregarLinea() (+9 more)

### Community 8 - "Storage & Location Blocking"
Cohesion: 0.06
Nodes (37): asignarUbicacion(), _blqBloquearLote(), _blqBloquearProd(), _blqDesbloquearLote(), _blqDesbloquearProd(), _buildUbicDestino(), cargarLotesOrigen(), confirmarUbicacion() (+29 more)

### Community 9 - "Returns UI Module"
Cohesion: 0.07
Nodes (32): _abrirModalCausal(), agregarItem(), anular(), _aplicarDashboard(), _aplicarFiltros(), aprobar(), _calcKPIsLocales(), _calcPorCausalLocal() (+24 more)

### Community 10 - "Core Models & Tenant Scope"
Cohesion: 0.08
Nodes (8): DateTimeInterface, BaseModel, ConteoDetalle, InvGeneralAsignacion, InvGeneralConteo, InvGeneralDiferencia, InvGeneralEvento, ProductoFoto

### Community 11 - "Inventory Adjustments UI"
Cohesion: 0.04
Nodes (17): _addAsigRow(), _ajustarTodo(), aprobarAjustes(), _buscarHistorialUbiProducto(), _cargarDesgloseUbicacion(), _ciCalcPreview(), _ciSearchUbic(), _ciSelProd() (+9 more)

### Community 12 - "Label Printing Module"
Cohesion: 0.10
Nodes (39): _actualizarConteoMasivo(), _actualizarFiltroMasivo(), _actualizarPreviewProd(), _actualizarPreviewUbi(), _buildDualColumnHTML(), _buildDualColumnHTMLProd(), _buildRotuloProd(), _buildRotuloProdHorizontal() (+31 more)

### Community 13 - "Dispatch & Certification UI"
Cohesion: 0.06
Nodes (26): adminOverride(), _certFiltrarGlobal(), _certFiltrarPorSucursal(), certificarTodoVisual(), _confirmarEnTransito(), generarCargue(), gestionarApiKeys(), _imprimirConsolidadoUnaPestana() (+18 more)

### Community 14 - "Receiving UI Module"
Cohesion: 0.06
Nodes (13): _devProdInput(), _devSearchProduct(), _guardarDevolucion(), _miscActualizar(), _miscBorrarFoto(), _miscEditar(), _miscEliminar(), _miscFiltrar() (+5 more)

### Community 15 - "Product Blocking & Quick Search"
Cohesion: 0.09
Nodes (4): BloqueoController, ConsultaRapidaController, BloqueoLote, Producto

### Community 16 - "Receiving Controller"
Cohesion: 0.13
Nodes (4): InboundController, OrdenCompra, OrdenCompraDetalle, Proveedor

### Community 18 - "Reports & Exports Module"
Cohesion: 0.11
Nodes (29): abrirCertificacion(), _abrirReporteHtml(), abrirSeparacion(), _estadoInicialReporte(), exportar(), exportarAgotados(), exportarAudit(), exportarCertCSV() (+21 more)

### Community 19 - "Auth & Seeding"
Cohesion: 0.06
Nodes (8): DatabaseSeeder, AuthController, Empresa, Parametro, Permiso, Personal, RolPermiso, Sucursal

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
Cohesion: 0.13
Nodes (13): analyzeLogErrorsRecent(), checkAndTriggerAutoReport(), forceGenerateReport(), formatBytes(), generateReportInternal(), getActiveUsers(), getLatestReportFile(), getMetrics() (+5 more)

### Community 29 - "Quick Search UI"
Cohesion: 0.22
Nodes (16): _buscar(), _esc(), _fmt(), _fmtFecha(), init(), load(), _onInput(), _renderClientes() (+8 more)

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
Cohesion: 0.17
Nodes (3): OutboundController, Certificacion, CertificacionDetalle

### Community 37 - "Planilla Certification Controller"
Cohesion: 0.15
Nodes (46): ajustes_inventario, alertas, anomaly_flags, api_keys, categorias_productos, citas, conteo_detalles, conteos (+38 more)

### Community 39 - "Inventory Assignment Editing"
Cohesion: 0.12
Nodes (17): _asignarSegundosConteosBatch(), _deleteAsig(), _deleteIcgFile(), _editarLinea(), _editCalcPreview(), _editRenderCantidadInputs(), _eliminarAsignacionR2(), _eliminarLinea() (+9 more)

### Community 40 - "Location Adjustment Controller"
Cohesion: 0.08
Nodes (4): AjusteUbicacionController, AjusteInventario, AjusteUbicacion, AjusteUbicacionDetalle

### Community 41 - "ML Expiry Prediction"
Cohesion: 0.67
Nodes (3): _confirmarAgregarPedidos(), quitarPedidoCargue(), verCargue()

### Community 42 - "Assignment Session Management"
Cohesion: 0.18
Nodes (3): FefoEngine, detectPython(), runPython()

### Community 43 - "PlanillaController"
Cohesion: 0.29
Nodes (7): _actualizarPreviewSinODC(), _actualizarPreviewUnidades(), _enviarCapturaOperativa(), _onProductoCaptura(), _procesarQrSinODC(), _seleccionarProdSinODC(), _updateSinODCLoteVencVisibility()

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
Cohesion: 0.29
Nodes (7): _autoSelectIcgNoContados(), _formatFullDate(), _loadMLTab(), _renderTabAmbientes(), _renderTabIcg(), _renderTabSegundosConteos(), _tab2()

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
Cohesion: 0.33
Nodes (7): Devoluciones Design Spec, GET /api/recepciones/buscar-qr (reused endpoint), devolucion_items table, devoluciones.js (desktop module), devoluciones table, DevolucionesController, Mobile devolución cliente flow

### Community 75 - "PermisoPersonalController"
Cohesion: 0.33
Nodes (6): _aplicarFiltroSinODC(), _cerrarRecepcionSinODC(), _confirmarSinODC(), _eliminarRecepcionSinODC(), _hoyFiltroSinODC(), show_sin_odc()

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

### Community 81 - "Causal Reasons Controller"
Cohesion: 0.24
Nodes (15): analyze_product(), build_recommendations(), categorize_product(), classify_risk(), confidence_score(), ema(), get_upcoming_events(), linear_regression() (+7 more)

### Community 82 - "Printer Management Controller"
Cohesion: 0.09
Nodes (10): AjusteInventario, App\Models\AjusteInventario, App\Models\SesionAsignacion, App\Models\SesionInventario, App\Models\SesionLinea, SesionAsignacion, SesionInventario, SesionLinea (+2 more)

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

### Community 91 - "Inventory Count Model"
Cohesion: 0.07
Nodes (4): DashboardController, Despacho, OrdenPicking, Recepcion

### Community 92 - "Backend/Frontend Rewrite Plan"
Cohesion: 0.38
Nodes (7): backend/app/common/service.py (BaseService), backend/app/core/database.py, backend/app/core/security.py, Plan Fase 0+1 WMS Fénix, frontend AppShell.tsx, SmartGrid.tsx, frontend useAuth.ts

### Community 94 - "Certification Scanning UI"
Cohesion: 0.25
Nodes (8): autoCertificar(), confirmarLineaCert(), confirmarLineaCertDesdeFila(), iniciarCertificacion(), manualCert(), procesarEscaneo(), _showPackingDialog(), verDetallesPendientes()

### Community 95 - "Reservations UI"
Cohesion: 0.29
Nodes (7): _aplicarFiltrosReservas(), _cargarReservas(), _filtrarReservasPorEstado(), _limpiarVistareservas(), _renderReservas(), show_reservas(), _sortReservas()

### Community 96 - "Appointment Calendar UI"
Cohesion: 0.29
Nodes (7): cancelarCita(), _changeYmsMonth(), _completarCitaOK(), _guardarCita(), marcarLlegadaCita(), _renderCalendario7x5(), show_citas()

### Community 97 - "Transfer Controller"
Cohesion: 0.07
Nodes (10): App\Models\PackingUnidad, Illuminate\Database\Eloquent\Model, CertificacionDespacho, PackingItem, PackingSesion, PackingUnidad, RecepcionCalidad, RecepcionDetalleCalidad (+2 more)

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

### Community 105 - "Ubicacion"
Cohesion: 0.06
Nodes (5): PutawayController, Inventario, MovimientoInventario, ProductoEan, Ubicacion

### Community 107 - "TenantScoped.php"
Cohesion: 0.14
Nodes (3): TenantContext, JwtMiddleware, TenantMiddleware

### Community 109 - "Packing & Picking Tables"
Cohesion: 0.40
Nodes (5): impresoras table, packing_items table, packing_sesiones table, packing_unidades table, picking_detalles table

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

### Community 120 - "NotificacionesController"
Cohesion: 0.24
Nodes (4): Illuminate\Database\Eloquent\Builder, bootTenantScoped(), scopeWithCurrentTenant(), withoutTenantScope()

### Community 123 - "Product Pitch Materials"
Cohesion: 0.67
Nodes (4): WMS Fénix Product, WMS Fénix Propuesta Comercial, Agente Fénix AI Assistant (concept), MysticFoods Logo

### Community 125 - "Ciclico Referencias"
Cohesion: 0.50
Nodes (4): _addCiclicRefRow(), _ciclicoRefs(), _deleteAsigCiclic(), _guardarCiclicRefs()

### Community 126 - "Dashboard Filtering"
Cohesion: 0.50
Nodes (4): _cerrarSesion(), _dashFiltrarCero(), _dashFiltrarConteos(), show_dashboard()

### Community 128 - "Categorías Management"
Cohesion: 0.50
Nodes (4): deleteCategoria(), renderCategorias(), saveCategoria(), show_categorias()

### Community 129 - "Marcas Management"
Cohesion: 0.50
Nodes (4): deleteMarca(), renderMarcas(), saveMarca(), show_marcas()

### Community 130 - "ConteoInventario"
Cohesion: 0.09
Nodes (3): App\Models\Devolucion, ConteoInventario, DevolucionDetalle

### Community 133 - "Base Service & Tenant Context"
Cohesion: 0.29
Nodes (7): agregarPedidosCargue(), despacharCargue(), _filtrarPedidosCargue(), liquidarCargue(), _renderPedidosPendientes(), saveCargueMasivo(), show_cargue()

### Community 140 - "Stock Dashboard Charts"
Cohesion: 0.33
Nodes (6): _renderMovBarChart(), _renderStockGeneralTab(), _renderStockPorReferencia(), show_stock(), _srBuscar(), _stockSetTab()

### Community 142 - "Recepción Sin ODC Preview"
Cohesion: 0.40
Nodes (4): `anomaly_flags`, `expiry_predictions`, `inventory_guard_log`, `performance_metrics`

### Community 144 - "Citas Scheduling"
Cohesion: 1.00
Nodes (3): nuevaCita(), nuevaCitaEnFecha(), _recalcHorasYMS()

### Community 156 - "Base Model"
Cohesion: 0.50
Nodes (5): load(), startAutoRefresh(), stopAutoRefresh(), subLabel(), _updateAutoRefreshBadge()

### Community 293 - "_confirmarReinicio"
Cohesion: 1.00
Nodes (3): _confirmarReinicio(), _renderReinicioDatos(), show_reinicio_datos()

### Community 308 - "BaseController"
Cohesion: 0.22
Nodes (3): BaseController, ChatIAController, TraspasoController

### Community 310 - "SystemController"
Cohesion: 0.06
Nodes (5): Psr\Http\Message\ResponseInterface, CrossDockController, DespachoController, InventarioController, UbicacionesController

### Community 312 - "ExpiryGuard"
Cohesion: 0.25
Nodes (3): Expiry Control (ExpiryGuard) Design Doc, ExpiryGuard, ExpiryResult

### Community 315 - "ImportExportController"
Cohesion: 0.50
Nodes (4): _conteoCalcPreview(), _conteoRenderCantidadInputs(), _saveConteoManual(), _showConteoManualModal()

### Community 319 - "_cargarStockGeneral"
Cohesion: 0.67
Nodes (3): _cargarStockGeneral(), _renderStockDonut(), _renderTop10()

### Community 320 - "verEventoTomaFisica"
Cohesion: 0.67
Nodes (3): _cerrarEventoTomaFisica(), _guardarAsignacionTomaFisica(), verEventoTomaFisica()

### Community 328 - "InvGeneralEvento"
Cohesion: 0.07
Nodes (11): App\Models\Concerns\TenantScoped, AlertaStock, Ambiente, AuditLog, CategoriaProducto, CausalDevolucion, Cliente, Marca (+3 more)

## Ambiguous Edges - Review These
- `WMS Fénix Product` → `MysticFoods Logo`  [AMBIGUOUS]
  logo.jpg · relation: conceptually_related_to
- `WMS Enterprise Management Pitch Page` → `ROI Growth Trend bar/line chart (94.2% FY2023)`  [AMBIGUOUS]
  public/pitch.html · relation: references

## Knowledge Gaps
- **163 isolated node(s):** `name`, `description`, `type`, `php`, `ext-pdo` (+158 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **80 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `WMS Fénix Product` and `MysticFoods Logo`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `WMS Enterprise Management Pitch Page` and `ROI Growth Trend bar/line chart (94.2% FY2023)`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **Why does `BaseModel` connect `Core Models & Tenant Scope` to `ConteoInventario`, `AlertasController`, `AlertasController`, `Product Blocking & Quick Search`, `Receiving Controller`, `Auth & Seeding`, `Tenant Context & Middleware`, `Core Controllers Overview`, `Location Adjustment Controller`, `Miscellaneous Items Controller`, `Anomaly Detection Controller`, `Replenishment & Notifications`, `Impresora`, `Traspaso`, `DataResetController`, `InvGeneralEvento`, `NotificacionesController`, `Inventory Count Model`, `Transfer Controller`, `Ubicacion`, `Aprobación de Vencimientos`?**
  _High betweenness centrality (0.026) - this node is a cross-community bridge._
- **Why does `analyze_product()` connect `Causal Reasons Controller` to `Cross-Dock Operations`?**
  _High betweenness centrality (0.011) - this node is a cross-community bridge._
- **Why does `OrdenCompra` connect `Receiving Controller` to `Returns & FEFO Alerts`, `ConteoInventario`, `Cross-Dock Operations`, `InvGeneralEvento`, `Core Models & Tenant Scope`, `InventoryGuard`, `Tenant Context & Middleware`?**
  _High betweenness centrality (0.010) - this node is a cross-community bridge._
- **Are the 145 inferred relationships involving `date` (e.g. with `generateReportInternal()` and `getActiveUsers()`) actually correct?**
  _`date` has 145 INFERRED edges - model-reasoned connections that need verification._
- **What connects `name`, `description`, `type` to the rest of the system?**
  _163 weakly-connected nodes found - possible documentation gaps or missing edges._