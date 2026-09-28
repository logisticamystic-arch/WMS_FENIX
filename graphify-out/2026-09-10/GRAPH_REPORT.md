# Graph Report - WMS_FENIX  (2026-09-04)

## Corpus Check
- 393 files · ~901,181 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 3014 nodes · 5854 edges · 385 communities (296 shown, 89 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 286 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `b6e0eadf`
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
- Despacho
- Miscellaneous Items Controller
- Packing Certification UI
- Anomaly Detection Controller
- Replenishment & Notifications
- TMS Integration Controller
- UbicacionesController
- SesionInventario
- ML Anomaly Detection
- Company Management UI
- Receiving Dashboard UI
- MovimientoInventario
- Receiving Without PO UI
- TV Picking Dashboard
- CacheHelper
- Outbound Certification Model
- Label Printing Helper
- Packing Expiry UI
- Cargue Dispatch UI
- Home Activity Dashboard
- Aisle Assignment UI
- Pallet Approval UI
- PWA Manifest Config
- .__invoke
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
- BackupHelper
- .__invoke
- Backend/Frontend Rewrite Plan
- BaseController.php
- Certification Scanning UI
- Reservations UI
- Appointment Calendar UI
- SesionAsignacion
- Packing Sticker Printing
- Ajuste Preview & Execution
- Ajuste Ubicación Approval
- Zonas Management
- .now
- Marketing Illustrations & Pitch Assets
- Ubicacion
- Aprobación de Vencimientos
- TenantScoped.php
- show_miscelaneos
- Packing & Picking Tables
- Performance & Cache Docs
- Ambientes Management
- Rutas Management
- Causales de Novedad
- Consola de Recepción
- Inventario General Diferencias
- AnomalyController
- MovimientoInventario
- Product Pitch Materials
- Ciclico Referencias
- Dashboard Filtering
- Conteo Manual
- Categorías Management
- Marcas Management
- ConteoInventario
- OutboundController
- Base Service & Tenant Context
- Cache Helpers & Auto Refresh
- FefoEngine
- Stock Dashboard Charts
- Recepción Sin ODC Preview
- Impresora
- Citas Scheduling
- MovimientoInventario
- show_agotados
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
- PermisoPersonalController
- SesionInventario
- TraspasoDocumentoDetalle
- WMS Fénix phoenix logo
- ImportExportController
- Traspaso
- _cargarStockGeneral
- verEventoTomaFisica
- App\Models\Producto
- GET /aprobaciones/vencimiento/pendientes
- POST /devoluciones/{id}/aprobar
- Warehouse/truck app icon (192x192)
- Warehouse/truck app icon (512x512)
- RecepcionDetalle
- ForecastController
- BloqueoController
- CausalesController
- TrazabilidadController
- TV Dashboard 360 Design Doc
- 129_fix_factor_udm_masivo_maestro.php
- .logSlowRequest
- 130_unificar_unidades_caja_factor_udm.php
- 📦 INVENTARIO Y MOVIMIENTOS - REGLA SAGRADA DE FORMULACIÓN
- .aprobarODCTodo
- .addTenantConstraints
- wmsLog
- TraspasoDocumento
- .__invoke
- .__invoke
- TraspasoDocumentoDetalle
- InvGeneralEvento
- InvGeneralAsignacion
- _loadCausalesFifo
- _odcRenderItems
- verEans
- show_impresoras
- App\Models\OrdenPicking
- OrdenPicking

## God Nodes (most connected - your core abstractions)
1. `PickingController` - 98 edges
2. `OrdenPicking` - 84 edges
3. `BaseController` - 77 edges
4. `ParametrosController` - 67 edges
5. `Producto` - 59 edges
6. `BaseModel` - 56 edges
7. `InventarioV2Controller` - 44 edges
8. `InventarioController` - 39 edges
9. `RecepcionController` - 37 edges
10. `Devolucion` - 35 edges

## Surprising Connections (you probably didn't know these)
- `MysticFoods Logo` --conceptually_related_to--> `WMS Fénix Product`  [AMBIGUOUS]
  logo.jpg → docs/propuesta_comercial.html
- `Expiry Control (ExpiryGuard) Design Doc` --references--> `ExpiryGuard`  [EXTRACTED]
  docs/superpowers/specs/2026-05-30-expiry-control-design.md → src/Helpers/ExpiryGuard.php
- `WMS Enterprise Management Pitch Page` --references--> `ROI Growth Trend bar/line chart (94.2% FY2023)`  [AMBIGUOUS]
  public/pitch.html → public/assets/pitch/roi_chart.png
- `WMS Enterprise Management Pitch Page` --references--> `FENIX AI Assistant hologram illustration`  [EXTRACTED]
  public/pitch.html → public/assets/pitch/agente_fenix.png
- `WMS Enterprise Management Pitch Page` --references--> `AI brain over conveyor belt (FEFO analytics) illustration`  [EXTRACTED]
  public/pitch.html → public/assets/pitch/fefo_ai.png

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Packing Session Data Model** — concept_packing_sesiones, concept_packing_unidades, concept_packing_items, concept_picking_detalles [EXTRACTED 0.85]

## Communities (385 total, 89 thin omitted)

### Community 0 - "Picking Order Management"
Cohesion: 0.04
Nodes (4): App\Models\PickingDetalle, PickingDetalle, PickingController, OrdenPicking

### Community 1 - "Returns & FEFO Alerts"
Cohesion: 0.07
Nodes (5): BaseController, ConsultaRapidaController, ImportExportController, ImpresoraController, RotacionController

### Community 3 - "Parameters & Approvals"
Cohesion: 0.05
Nodes (3): Psr\Http\Message\ServerRequestInterface, DevolucionController, ParametrosController

### Community 4 - "Cross-Dock Operations"
Cohesion: 0.03
Nodes (11): Psr\Http\Message\ResponseInterface, AjusteUbicacionController, AlertasController, AprobacionController, DespachoController, DevolucionCrmController, MiscelaneoCrmController, OutboundController (+3 more)

### Community 6 - "Master Data Management"
Cohesion: 0.05
Nodes (18): _clienteModalBody(), deleteMarca(), editCliente(), editProducto(), editZona(), _esc(), filtrarPermisosUsuario(), loadPermisosRol() (+10 more)

### Community 7 - "Picking UI Module"
Cohesion: 0.04
Nodes (18): _cargarConsulta(), _eliminarPendiente(), imprimirRemisionPlanilla(), _limpiarPendientes(), nuevoPedidoManual(), _onFaltCheck(), _onTogglePlanillaCheckbox(), _openPrint() (+10 more)

### Community 8 - "Storage & Location Blocking"
Cohesion: 0.05
Nodes (40): asignarUbicacion(), _blqBloquearLote(), _blqBloquearProd(), _blqDesbloquearLote(), _blqDesbloquearProd(), _buildUbicDestino(), cargarLotesOrigen(), confirmarBajaPatio() (+32 more)

### Community 9 - "Returns UI Module"
Cohesion: 0.06
Nodes (44): _abrirModalCausal(), agregarItem(), anular(), _aplicarDashboard(), _aplicarFiltros(), aprobar(), _buscarClienteOrigen(), buscarQr() (+36 more)

### Community 11 - "Inventory Adjustments UI"
Cohesion: 0.04
Nodes (16): _addAsigRow(), _ajustarTodo(), aprobarAjustes(), _buscarHistorialUbiProducto(), _cargarDesgloseUbicacion(), _ciCalcPreview(), _ciSelProd(), _guardarEventoTomaFisica() (+8 more)

### Community 12 - "Label Printing Module"
Cohesion: 0.08
Nodes (48): _actualizarConteoMasivo(), _actualizarFiltroMasivo(), _actualizarPreviewPDV(), _actualizarPreviewProd(), _actualizarPreviewUbi(), _buildDualColumnHTML(), _buildDualColumnHTMLPDV(), _buildDualColumnHTMLProd() (+40 more)

### Community 13 - "Dispatch & Certification UI"
Cohesion: 0.05
Nodes (26): adminOverride(), _certFiltrarGlobal(), _certFiltrarPorSucursal(), certificarTodoVisual(), _confirmarAgregarPedidos(), _confirmarEnTransito(), generarCargue(), gestionarApiKeys() (+18 more)

### Community 14 - "Receiving UI Module"
Cohesion: 0.05
Nodes (13): _devProdInput(), _devSearchProduct(), _guardarDevolucion(), _miscAnularOdc(), _miscBorrarFoto(), _miscEditar(), _miscGuardarOdc(), _miscNuevo() (+5 more)

### Community 16 - "Receiving Controller"
Cohesion: 0.07
Nodes (6): InboundController, wmsLog(), OrdenCompra, OrdenCompraDetalle, Recepcion, RecepcionDetalle

### Community 17 - "App Routes & Design Docs"
Cohesion: 0.06
Nodes (10): Illuminate\Database\Eloquent\Model, ConteoDetalle, InvGeneralConteo, InvGeneralDiferencia, ProductoEan, Proveedor, RecepcionCalidad, RecepcionDetalleCalidad (+2 more)

### Community 18 - "Reports & Exports Module"
Cohesion: 0.07
Nodes (47): abrirCertificacion(), _abrirReporteHtml(), abrirSeparacion(), _chipsFiltrosDespacho(), _estadoInicialReporte(), exportar(), exportarAgotados(), exportarAudit() (+39 more)

### Community 21 - "Inbound Purchase Orders"
Cohesion: 0.04
Nodes (55): public.ajustes_inventario, public.alertas_stock, public.anomaly_flags, public.api_keys, public.archivos_planilla, public.audit_logs, public.categoria_productos, public.cert_planilla_det (+47 more)

### Community 22 - "Base Controller Utilities"
Cohesion: 0.06
Nodes (4): AuthController, BaseController, CalidadController, SystemController

### Community 23 - "Advanced Logistics UI"
Cohesion: 0.20
Nodes (18): autoGenerarWave(), _cdAction(), _dateFilters(), _esc(), _estadoBadge(), _fmtDate(), _fmtDT(), _getDateParams() (+10 more)

### Community 24 - "Composer Configuration"
Cohesion: 0.09
Nodes (21): autoload, psr-4, description, name, App\\, require, ext-json, ext-mbstring (+13 more)

### Community 25 - "Picking Order Editing"
Cohesion: 0.12
Nodes (19): _abrirEditar(), _abrirEditorInline(), _cajasYPicos(), _dlgAgotadoLinea(), _dlgAgregarRef(), _dlgConfirmarLinea(), _dlgEditarCantidad(), _eliminarLinea() (+11 more)

### Community 26 - "System Monitoring API"
Cohesion: 0.14
Nodes (13): analyzeLogErrorsRecent(), checkAndTriggerAutoReport(), forceGenerateReport(), formatBytes(), generateReportInternal(), getActiveUsers(), getLatestReportFile(), getMetrics() (+5 more)

### Community 28 - "Returns Model & Controller"
Cohesion: 0.15
Nodes (4): date, bkpLog(), CrossDockController, NotificacionesController

### Community 29 - "Quick Search UI"
Cohesion: 0.22
Nodes (16): _buscar(), _esc(), _fmt(), _fmtFecha(), init(), load(), _onInput(), _renderClientes() (+8 more)

### Community 31 - "Tenant Context & Middleware"
Cohesion: 0.09
Nodes (3): CausalDevolucion, Devolucion, DevolucionDetalle

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
Cohesion: 0.12
Nodes (17): _anularPedido(), _cerrarPlanilla(), completarPicking(), _confirmarAgregarLinea(), confirmarAsignacionPlanilla(), _confirmarRuta(), deletePicking(), _eliminarPlanillasSeleccionadas() (+9 more)

### Community 37 - "Planilla Certification Controller"
Cohesion: 0.15
Nodes (46): ajustes_inventario, alertas, anomaly_flags, api_keys, categorias_productos, citas, conteo_detalles, conteos (+38 more)

### Community 38 - "Yard Management Controller"
Cohesion: 0.07
Nodes (10): App\Models\Concerns\TenantScoped, AlertaStock, AuditLog, CategoriaProducto, Impresora, InvGeneralEvento, Marca, PersonalPermiso (+2 more)

### Community 39 - "Inventory Assignment Editing"
Cohesion: 0.11
Nodes (19): _asignarSegundosConteosBatch(), _cambiarAuxiliarAsignacion(), _deleteAsig(), _deleteIcgFile(), _editarLinea(), _editCalcPreview(), _editRenderCantidadInputs(), _eliminarAsignacionR2() (+11 more)

### Community 42 - "Assignment Session Management"
Cohesion: 0.24
Nodes (15): analyze_product(), build_recommendations(), categorize_product(), classify_risk(), confidence_score(), ema(), get_upcoming_events(), linear_regression() (+7 more)

### Community 43 - "PlanillaController"
Cohesion: 0.29
Nodes (7): _actualizarPreviewSinODC(), _actualizarPreviewUnidades(), _enviarCapturaOperativa(), _onProductoCaptura(), _procesarQrSinODC(), _seleccionarProdSinODC(), _updateSinODCLoteVencVisibility()

### Community 44 - "Inventory Session Model"
Cohesion: 0.24
Nodes (10): cross_dock_detalles, cross_dock_ordenes, ejecuciones_ml, forecast_demanda, ubicaciones, ubicaciones_optimas, ventas_agregadas_ml, wave_picking (+2 more)

### Community 45 - "Sucursal"
Cohesion: 0.07
Nodes (7): DatabaseSeeder, Empresa, Parametro, Permiso, Personal, RolPermiso, Sucursal

### Community 46 - "Master Data CRUD UI"
Cohesion: 0.14
Nodes (14): deleteCliente(), deleteProducto(), deleteProveedor(), doImportGenerico(), filtrarClientes(), filtrarProveedores(), renderClientes(), renderProveedores() (+6 more)

### Community 47 - "Reservations & Novelties UI"
Cohesion: 0.40
Nodes (5): _cargarNovedades(), _cerrarNvModal(), _confirmarNvAccion(), _renderNovedades(), show_novedades()

### Community 48 - "ABC/XYZ Rotation Analytics"
Cohesion: 0.23
Nodes (9): ejecutarAbcXyz(), ejecutarForecast(), ejecutarSlotting(), load(), renderAbcXyz(), renderForecast(), renderHeatmap(), renderSlotting() (+1 more)

### Community 51 - "Packing Certification UI"
Cohesion: 0.11
Nodes (19): cancelarSesionPacking(), _certBuscar(), _certClave(), _certEditGuardarLote(), _certFechaParams(), _certSetFechaRapida(), confirmarAsigCert(), _desmarcarDespachadoDirecto() (+11 more)

### Community 52 - "Anomaly Detection Controller"
Cohesion: 0.14
Nodes (14): anularCertificacionPlanilla(), _asignarRutaInline(), autoCertificarPlanilla(), _buscarReferenciaAutocomplete(), _cargarPedidos(), _configurarEscaneoUbicacionPlanilla(), _configurarFechaVencimientoPedido(), _configurarFechaVencimientoPlanilla() (+6 more)

### Community 53 - "Replenishment & Notifications"
Cohesion: 0.12
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
Cohesion: 0.15
Nodes (14): abrirConsolaSinODC(), _agregarLineaSinODC(), _aplicarFiltroSinODC(), _cerrarRecepcionSinODC(), _confirmarSinODC(), _eliminarDetalleSinODC(), _eliminarRecepcionSinODC(), _enviarCapturaSinODC() (+6 more)

### Community 64 - "Outbound Certification Model"
Cohesion: 0.22
Nodes (9): _autoSelectIcgNoContados(), _formatFullDate(), _loadMLTab(), _renderTabAmbientes(), _renderTabIcg(), _renderTabSegundosConteos(), _sortIcgBy(), _tab2() (+1 more)

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
Cohesion: 0.13
Nodes (16): _actualizarTotalesAsig(), _agregarRangoPasillo(), _asignarFallback(), _buildDrawerAsignacion(), _buildRangoPasillo(), _calcularTotalesAmbiente(), _cargarAsignacion(), _cargarAuxiliares() (+8 more)

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
Cohesion: 0.20
Nodes (16): _aplicarFiltrosDash(), _aplicarFiltrosMatriz(), _badgeResultado(), _buscarConsecutivo(), _cargarDashboardDevoluciones(), _kpiCard(), load(), _renderTendencia() (+8 more)

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

### Community 82 - "Printer Management Controller"
Cohesion: 0.10
Nodes (11): AjusteInventario, App\Models\AjusteInventario, App\Models\SesionAsignacion, App\Models\SesionInventario, App\Models\SesionLinea, Producto, SesionAsignacion, SesionInventario (+3 more)

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
Cohesion: 0.15
Nodes (17): abrirModalEditarPedido(), _agruparPorPlanilla(), _filtrarTablaPlanillaInline(), _fmtCajasDesglose(), _getDuration(), _initDashboardCharts(), _renderMatrixHtml(), _renderPedidosTabla() (+9 more)

### Community 87 - "Backorder Fulfillment UI"
Cohesion: 0.20
Nodes (10): _applyFaltFilters(), _clearFaltFilters(), completarReabast(), _limpiarFaltantes(), _loadSucursales(), _procesarBackorder(), show_faltantes(), _toggleFaltPanel() (+2 more)

### Community 88 - "Picking TV Dashboard"
Cohesion: 0.10
Nodes (3): BloqueoController, BloqueoLote, Producto

### Community 89 - "Location Model"
Cohesion: 0.20
Nodes (9): background_color, description, display, icons, name, scope, short_name, start_url (+1 more)

### Community 90 - "BackupHelper"
Cohesion: 0.15
Nodes (4): App\Models\PackingUnidad, PackingUnidad, PackingController, ExpiryResult

### Community 91 - ".__invoke"
Cohesion: 0.11
Nodes (3): DashboardController, Inventario, Ubicacion

### Community 92 - "Backend/Frontend Rewrite Plan"
Cohesion: 0.38
Nodes (7): backend/app/common/service.py (BaseService), backend/app/core/database.py, backend/app/core/security.py, Plan Fase 0+1 WMS Fénix, frontend AppShell.tsx, SmartGrid.tsx, frontend useAuth.ts

### Community 93 - "BaseController.php"
Cohesion: 0.23
Nodes (8): _aplicarFiltrosHist(), load(), _onFotosInspeccion(), _quitarFotoInspeccion(), _renderFotosPreview(), _renderItemRow(), show_historial(), show_nueva()

### Community 94 - "Certification Scanning UI"
Cohesion: 0.20
Nodes (10): autoCertificar(), autoCertificarTodos(), confirmarLineaCert(), confirmarLineaCertDesdeFila(), _esFechaHoy(), iniciarCertificacion(), manualCert(), procesarEscaneo() (+2 more)

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

### Community 104 - "Marketing Illustrations & Pitch Assets"
Cohesion: 0.33
Nodes (6): FENIX AI Assistant hologram illustration, AI brain over conveyor belt (FEFO analytics) illustration, ROI Growth Trend bar/line chart (94.2% FY2023), On-premise server rack with analytics overlays illustration, Smart warehouse hero illustration with AR dashboards, WMS Enterprise Management Pitch Page

### Community 105 - "Ubicacion"
Cohesion: 0.09
Nodes (3): Devoluciones Implementation Plan, wmsLog(), PlanillaController

### Community 106 - "Aprobación de Vencimientos"
Cohesion: 0.48
Nodes (5): fetchData(), load(), resolverAjuste(), resolverDevolucion(), resolverVencimiento()

### Community 107 - "TenantScoped.php"
Cohesion: 0.07
Nodes (9): Illuminate\Database\Eloquent\Builder, DataResetController, TenantContext, JwtMiddleware, TenantMiddleware, TmsAuthMiddleware, bootTenantScoped(), scopeWithCurrentTenant() (+1 more)

### Community 108 - "show_miscelaneos"
Cohesion: 0.29
Nodes (7): _miscActualizar(), _miscCambiarSucursal(), _miscEliminar(), _miscFiltrar(), _miscGuardar(), _miscGuardarTracking(), show_miscelaneos()

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

### Community 133 - "Base Service & Tenant Context"
Cohesion: 0.29
Nodes (7): agregarPedidosCargue(), despacharCargue(), _filtrarPedidosCargue(), liquidarCargue(), _renderPedidosPendientes(), saveCargueMasivo(), show_cargue()

### Community 139 - "FefoEngine"
Cohesion: 0.67
Nodes (3): _ciSearchUbic(), _ciSelUbic(), _ubicNorm()

### Community 140 - "Stock Dashboard Charts"
Cohesion: 0.33
Nodes (6): _renderMovBarChart(), _renderStockGeneralTab(), _renderStockPorReferencia(), show_stock(), _srBuscar(), _stockSetTab()

### Community 142 - "Recepción Sin ODC Preview"
Cohesion: 0.40
Nodes (4): `anomaly_flags`, `expiry_predictions`, `inventory_guard_log`, `performance_metrics`

### Community 144 - "Citas Scheduling"
Cohesion: 1.00
Nodes (3): nuevaCita(), nuevaCitaEnFecha(), _recalcHorasYMS()

### Community 147 - "show_agotados"
Cohesion: 0.40
Nodes (6): _agotPedido(), _agotUpc(), _applyAgotFilters(), _clearAgotFilters(), _exportAgotadosPDF(), show_agotados()

### Community 156 - "Base Model"
Cohesion: 0.50
Nodes (5): load(), startAutoRefresh(), stopAutoRefresh(), subLabel(), _updateAutoRefreshBadge()

### Community 293 - "_confirmarReinicio"
Cohesion: 1.00
Nodes (3): _confirmarReinicio(), _renderReinicioDatos(), show_reinicio_datos()

### Community 311 - "TraspasoDocumentoDetalle"
Cohesion: 0.20
Nodes (9): Auditoría — Agotados en la importación de pedidos del 07 de agosto de 2026, Caso 1 — I ATUN X UND (código 112187), Caso 2 — I YOGURT GRIEGO NATURAL X 4000 GR (código 109011) — el más grave, Caso 3 — PORTAVASOS OLIVIA X 400 UND (código 320302), Causa raíz identificada, Clasificación de las 69 líneas contra el stock disponible hoy, Conclusión principal, Lo que se descartó en el camino (+1 more)

### Community 315 - "ImportExportController"
Cohesion: 0.50
Nodes (4): _conteoCalcPreview(), _conteoRenderCantidadInputs(), _saveConteoManual(), _showConteoManualModal()

### Community 317 - "Traspaso"
Cohesion: 0.06
Nodes (10): DateTimeInterface, BaseModel, CertificacionDespacho, Cliente, MiscelaneoFoto, PackingItem, PackingSesion, PackingUnidad (+2 more)

### Community 319 - "_cargarStockGeneral"
Cohesion: 0.67
Nodes (3): _cargarStockGeneral(), _renderStockDonut(), _renderTop10()

### Community 320 - "verEventoTomaFisica"
Cohesion: 0.67
Nodes (3): _cerrarEventoTomaFisica(), _guardarAsignacionTomaFisica(), verEventoTomaFisica()

### Community 321 - "App\Models\Producto"
Cohesion: 0.33
Nodes (5): 1. Desglose y Equivalencia Inequívoca, 2. Presentación en Pantallas y Modales de Confirmación, 3. Registro en Base de Datos y Backend, 📋 Directriz de Implementación Estricta, REGLA MANDATORIA DE MOVIMIENTOS DE INVENTARIO EN WMS FENIX

### Community 337 - "130_unificar_unidades_caja_factor_udm.php"
Cohesion: 0.83
Nodes (3): down(), ids(), up()

### Community 338 - "📦 INVENTARIO Y MOVIMIENTOS - REGLA SAGRADA DE FORMULACIÓN"
Cohesion: 0.50
Nodes (3): 📦 INVENTARIO Y MOVIMIENTOS - REGLA SAGRADA DE FORMULACIÓN, Reglas Clave:, WMS FENIX - AGENT RULES & SYSTEM INSTRUCTIONS

### Community 342 - ".aprobarODCTodo"
Cohesion: 0.25
Nodes (3): BaseModel, PreoperacionalFoto, PreoperacionalItem

### Community 351 - "InvGeneralEvento"
Cohesion: 0.50
Nodes (5): imprimirRemision(), imprimirRemisionDirecta(), imprimirRemisionPorOrdenes(), _openPrint(), reimprimirPedidoCargue()

### Community 363 - "_loadCausalesFifo"
Cohesion: 0.40
Nodes (5): _editarCausalFifo(), _loadCausalesFifo(), _nuevaCausalFifo(), _renderCausalesFifo(), show_causales_fifo()

### Community 364 - "_odcRenderItems"
Cohesion: 0.40
Nodes (5): _odcAddDist(), _odcAddRef(), _odcRemDist(), _odcRemRef(), _odcRenderItems()

### Community 365 - "verEans"
Cohesion: 0.67
Nodes (3): addEan(), deleteEan(), verEans()

### Community 366 - "show_impresoras"
Cohesion: 0.67
Nodes (3): eliminarImpresora(), guardarImpresora(), show_impresoras()

## Ambiguous Edges - Review These
- `WMS Fénix Product` → `MysticFoods Logo`  [AMBIGUOUS]
  logo.jpg · relation: conceptually_related_to
- `WMS Enterprise Management Pitch Page` → `ROI Growth Trend bar/line chart (94.2% FY2023)`  [AMBIGUOUS]
  public/pitch.html · relation: references

## Knowledge Gaps
- **175 isolated node(s):** `Conclusión principal`, `Clasificación de las 69 líneas contra el stock disponible hoy`, `Caso 1 — I ATUN X UND (código 112187)`, `Caso 2 — I YOGURT GRIEGO NATURAL X 4000 GR (código 109011) — el más grave`, `Caso 3 — PORTAVASOS OLIVIA X 400 UND (código 320302)` (+170 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **89 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `WMS Fénix Product` and `MysticFoods Logo`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `WMS Enterprise Management Pitch Page` and `ROI Growth Trend bar/line chart (94.2% FY2023)`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **Why does `BaseModel` connect `Traspaso` to `Marcas Management`, `ConteoInventario`, `Receiving Controller`, `MovimientoInventario`, `App Routes & Design Docs`, `Tenant Context & Middleware`, `Yard Management Controller`, `Sucursal`, `Replenishment & Notifications`, `TV Picking Dashboard`, `CacheHelper`, `RecepcionDetalle`, `.__invoke`, `CausalesController`, `wmsLog`, `Picking TV Dashboard`, `.__invoke`, `InvGeneralAsignacion`, `.now`, `MovimientoInventario`?**
  _High betweenness centrality (0.031) - this node is a cross-community bridge._
- **Why does `OrdenPicking` connect `Picking Order Management` to `Cross-Dock Operations`, `Yard Management Controller`, `Dispatch Controller`, `Ubicacion`, `Product Blocking & Quick Search`, `App Routes & Design Docs`, `Replenishment & Notifications`, `.aprobarODCTodo`, `BackupHelper`, `.__invoke`, `Database Compatibility Layer`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Why does `Producto` connect `Picking TV Dashboard` to `Picking Order Management`, `Marcas Management`, `Cross-Dock Operations`, `Packing & Expiry Control`, `Yard Management Controller`, `Sucursal`, `Product Blocking & Quick Search`, `Receiving Controller`, `App Routes & Design Docs`, `Printer Management Controller`, `Replenishment & Notifications`, `.aprobarODCTodo`, `MovimientoInventario`, `.__invoke`, `Tenant Context & Middleware`?**
  _High betweenness centrality (0.014) - this node is a cross-community bridge._
- **Are the 89 inferred relationships involving `date` (e.g. with `generateReportInternal()` and `getActiveUsers()`) actually correct?**
  _`date` has 89 INFERRED edges - model-reasoned connections that need verification._
- **What connects `Conclusión principal`, `Clasificación de las 69 líneas contra el stock disponible hoy`, `Caso 1 — I ATUN X UND (código 112187)` to the rest of the system?**
  _175 weakly-connected nodes found - possible documentation gaps or missing edges._